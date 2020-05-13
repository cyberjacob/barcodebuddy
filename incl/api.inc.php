<?php
/**
 * Barcode Buddy for Grocy
 *
 * PHP version 7
 *
 * LICENSE: This source file is subject to version 3.0 of the GNU General
 * Public License v3.0 that is attached to this project.
 *
 * 
 * Helper file for Grocy API and barcode lookup
 *
 * @author    Marc Ole Bulling
 * @copyright 2019 Marc Ole Bulling
 * @license   https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since     File available since Release 1.0
 */


require_once __DIR__ . "/configProcessing.inc.php";
require_once __DIR__ . "/db.inc.php";

const API_O_PRODUCTS     = 'objects/products';
const API_PRODUCTS       = 'stock/products';
const API_SHOPPINGLIST   = 'stock/shoppinglist/';
const API_CHORES         = 'objects/chores';
const API_STOCK          = 'stock/products';
const API_CHORE_EXECUTE  = 'chores/';
const API_SYTEM_INFO     = 'system/info';

const MIN_GROCY_VERSION  = "2.7.1";


const METHOD_GET         = "GET";
const METHOD_PUT         = "PUT";
const METHOD_POST        = "POST";

const LOGIN_URL         = "loginurl";
const LOGIN_API_KEY     = "loginkey";

const DISPLAY_DEBUG     = false;

/**
 * Marker class for invalid server responses
 */
class InvalidServerResponseException extends Exception
{
}

/**
 * Marker class for unautharised API requests
 */
class UnauthorizedException          extends Exception
{
}

/**
 * Marker class for invalid JSON responses
 */
class InvalidJsonResponseException   extends Exception
{
}

/**
 * Marker class for SSL Errors
 */
class InvalidSSLException            extends Exception
{
}

/**
 * CURL request generator for calls to Grocy
 */
class CurlGenerator
{
    private $_ch = null;
    private $_method = METHOD_GET;
    private $_urlApi;

    const IGNORED_API_ERRORS_REGEX = array(
        '/No product with barcode .+ found/'
    );
    
    /**
     * Create a new CURL request
     *
     * @param string       $url           URL to call
     * @param method       $method        HTTP Request Method
     * @param string       $jasonData     Request Body Data to be sent as JSON
     * @param null | array $loginOverride Array with `LOGIN_API_KEY` and `LOGIN_URL`
                                    specifying the Grocy API key and URL respectively
     * @param true | false $noApiCall     If true, $url is a full URL, otherwise it is treated as relative
                                    to the Grocy API root
     *
     * @return void
     */
    function __construct($url, $method = METHOD_GET, $jasonData = null, $loginOverride = null, $noApiCall = false)
    {
        global $CONFIG;
        
        $this->_method  = $method;
        $this->_urlApi  = $url;
        $this->_ch      = curl_init();

        if ($loginOverride == null) {
            global $BBCONFIG;
            $apiKey = $BBCONFIG["GROCY_API_KEY"];
            $apiUrl = $BBCONFIG["GROCY_API_URL"];
        } else {
            $apiKey = $loginOverride[LOGIN_API_KEY];
            $apiUrl = $loginOverride[LOGIN_URL];
        }

        $headerArray = array(
            'GROCY-API-KEY: ' . $apiKey
        );
        if ($jasonData != null) {
            array_push($headerArray, 'Content-Type: application/json');
            array_push($headerArray, 'Content-Length: ' . strlen($jasonData));
            curl_setopt($this->_ch, CURLOPT_POSTFIELDS, $jasonData);
        }
        
        if ($noApiCall) {
            curl_setopt($this->_ch, CURLOPT_URL, $url);
        } else {
            curl_setopt($this->_ch, CURLOPT_URL, $apiUrl . $url);
        }
        curl_setopt($this->_ch, CURLOPT_HTTPHEADER, $headerArray);
        curl_setopt($this->_ch, CURLOPT_CUSTOMREQUEST, $this->_method);
        curl_setopt($this->_ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($this->_ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($this->_ch, CURLOPT_USERAGENT, 'BarcodeBuddy v' . BB_VERSION_READABLE);
        curl_setopt($this->_ch, CURLOPT_TIMEOUT, $CONFIG->CURL_TIMEOUT_S);
        if ($CONFIG->CURL_ALLOW_INSECURE_SSL_CA) {
            curl_setopt($this->_ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        if ($CONFIG->CURL_ALLOW_INSECURE_SSL_HOST) {
            curl_setopt($this->_ch, CURLOPT_SSL_VERIFYHOST, false);
        }
    }
    
    /**
     * Execute the request and return the results
     * 
     * @param true | false $decode If true, API response will be treated as JSON and decoded before being returned
     *
     * @return string | array API call response
     */
    function execute($decode = false)
    {
        if (DISPLAY_DEBUG) {
            global $db;
            $startTime = microtime(true);
            $db->saveLog("<i>Executing API call: " . $this->urlApi. "</i>", false, false, true);
        }
        $curlResult = curl_exec($this->_ch);
        $this->_checkForErrorsAndThrow($curlResult);
        curl_close($this->_ch);

        $jsonDecoded = json_decode($curlResult, true);
        if ($decode && isset($jsonDecoded->response->status) && $jsonDecoded->response->status == 'ERROR') {
            throw new InvalidJsonResponseException($jsonDecoded->response->errormessage);
        }
        
        if (isset($jsonDecoded["error_message"])) {
            $isIgnoredError = false;
            foreach (self::IGNORED_API_ERRORS_REGEX as $ignoredError) {
                if (preg_match($ignoredError, $jsonDecoded["error_message"])) {
                    $isIgnoredError = true;
                }
            }
            if (!$isIgnoredError) {
                throw new InvalidJsonResponseException($jsonDecoded["error_message"]);
            }
        }
        if (DISPLAY_DEBUG) {
            $totalTimeMs = round((microtime(true)- $startTime) * 1000);
            $db->saveLog("<i>Executing took " . $totalTimeMs . "ms</i>", false, false, true);
        }
        if ($decode) {
            return $jsonDecoded;
        } else {
            return $curlResult;
        }
    }

    /**
     * Check for errors encountered while making an API call
     *
     * @param mixed $curlResult Result of CURL execution
     *
     * @return void
     */
    private function _checkForErrorsAndThrow($curlResult)
    {
        $curlError    = curl_errno($this->_ch);
        $responseCode = curl_getinfo($this->_ch, CURLINFO_RESPONSE_CODE);

        if ($responseCode == 401) {
            throw new UnauthorizedException();
        }
        if ($curlResult === false) {
            if (self::_isErrorSslRelated($curlError)) {
                throw new InvalidSSLException();
            } else {
                throw new InvalidServerResponseException();
            }
        } elseif ($curlResult == "" && $responseCode != 204) {
                throw new InvalidServerResponseException();
        }
    }

    /**
     * Detects if a CURL error is related to SSL
     *
     * @param int $curlError CURL error number
     *
     * @return true | false If error is related to SSL
     */
    private static function _isErrorSslRelated($curlError)
    {
        return ($curlError == CURLE_SSL_CERTPROBLEM
                || $curlError == CURLE_SSL_CIPHER
                || $curlError == CURLE_SSL_CACERT
               );
    }
}

/**
 * API Class
 */
class API
{
    /**
     * Getting info about one or all Grocy products.
     * 
     * @param string $productId or none, to get a list of all products
     * 
     * @return array Product info or array of products
     */
    public static function getProductInfo($productId = "")
    {
        if ($productId == "") {
            $apiurl = API_O_PRODUCTS;
        } else {
            $apiurl = API_PRODUCTS . "/" . $productId;
        }

        $curl = new CurlGenerator($apiurl);
        try {
            $result = $curl->execute(true);
        } catch (Exception $e) {
            self::processError($e, "Could not lookup Grocy product info");
        }
        if ($productId != "") {
            if (isset($result["product"]["id"])) {
                checkIfNumeric($result["product"]["id"]);
                $resultArray                             = array();
                $resultArray["id"]                       = $result["product"]["id"];
                $resultArray["barcode"]                  = $result["product"]["barcode"];
                $resultArray["name"]                     = sanitizeString($result["product"]["name"]);
                $resultArray["unit"]                     = sanitizeString($result["quantity_unit_stock"]["name"]);
                $resultArray["stockAmount"]              = sanitizeString($result["stock_amount"]);
                $resultArray["default_best_before_days"] = $result["product"]["default_best_before_days"];
                if ($resultArray["stockAmount"] == null) {
                    $resultArray["stockAmount"] = "0";
                }
                return $resultArray;
            } else {
                return null;
            }
        }
        return $result;
    }
    
    
    /**
     * Open product with $id
     * 
     * @param String $id Product ID
     *
     * @return none
     */
    public static function openProduct($id)
    {
        $data = json_encode(
            array(
                'amount' => "1"
            )
        );
        $apiurl = API_STOCK . "/" . $id . "/open";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (Exception $e) {
            self::processError($e, "Could not open Grocy product");
        }
    }
    
    /**
     *   Check if API details are correct
     * 
     * @param String $givenurl URL to Grocy API
     * @param String $apikey   API key
     *
     * @return Returns String with error or true if connection could be established
     */
    public static function checkApiConnection($givenurl, $apikey)
    {
        $loginInfo = array(LOGIN_URL => $givenurl, LOGIN_API_KEY => $apikey);

        $curl = new CurlGenerator(API_SYTEM_INFO, METHOD_GET, null, $loginInfo);
        try {
            $result = $curl->execute(true);
        } catch (InvalidServerResponseException $e) {
            return "Could not connect to server<br>";
        } catch (InvalidJsonResponseException $e) {
            return "Error: ". $e->getMessage();
        } catch (UnauthorizedException $e) {
            return "Invalid API key<br>";
        } catch (InvalidSSLException $e) {
            return"Invalid SSL certificate!<br>".
                "If you are using a self-signed certificate, you can disable the check in config.php<br>";
        }
        if (isset($result["grocy_version"]["Version"])) {
            $version = $result["grocy_version"]["Version"];
            
            if (!API::isSupportedGrocyVersion($version)) {
                return "Grocy ".MIN_GROCY_VERSION.
                    " or newer required. You are running ".$version.
                    ", please upgrade your Grocy instance.<br>";
            } else {
                return true;
            }
        }
        return "Invalid response. Are you using the correct URL?<br>";
    }
    
    /**
     * Check if the installed Grocy version is equal or newer to the required version
     * 
     * @param String $version reported Grocy version
     *
     * @return boolean true if version supported
     */
    public static function isSupportedGrocyVersion($version)
    {
        if (!preg_match("/\d+.\d+.\d+/", $version)) {
            return false;
        }
        
        $version_ex    = explode(".", $version);
        $minVersion_ex = explode(".", MIN_GROCY_VERSION);
        
        if ($version_ex[0] < $minVersion_ex[0]) {
            return false;
        } else if ($version_ex[0] == $minVersion_ex[0] && $version_ex[1] < $minVersion_ex[1]) {
            return false;
        } else if ($version_ex[0] == $minVersion_ex[0]
            && $version_ex[1] == $minVersion_ex[1]
            && $version_ex[2] < $minVersion_ex[2]
        ) {
            return false;
        } else {
            return true;
        }
    }
    
    
    /**
     * Requests the version of the Grocy instance
     * 
     * @return String Reported Grocy version
     */
    public static function getGrocyVersion()
    {

        $curl = new CurlGenerator(API_SYTEM_INFO);
        try {
            $result = $curl->execute(true);
        } catch (Exception $e) {
            self::processError($e, "Could not lookup Grocy version");
        }

        if (isset($result["grocy_version"]["Version"])) {
            return $result["grocy_version"]["Version"];
        }
        self::logError("Grocy did not provide version number");
        return null;
    }
    
    
    /**
     *  Adds a Grocy product.
     * 
     * @param String $id                id of product
     * @param int    $amount            amount of product
     * @param String $bestbefore        Date of best before Default: null (requests default BestBefore date from grocy)
     * @param String $price             price of product Default: null
     * @param ?      $fileLock          Lock to remove once transaction is complete
     * @param ?      $defaultBestBefore Override defaul;t best-before date
     *
     * @return false if default best before date not set
     */
    public static function purchaseProduct(
        $id,
        $amount,
        $bestbefore = null,
        $price = null,
        &$fileLock = null,
        $defaultBestBefore = null
    ) {
        global $BBCONFIG;
        
        $daysBestBefore = 0;
        $data = array(
            'amount' => $amount,
            'transaction_type' => 'purchase'
        );

        if ($price != null) {
            $data['price'] = $price;
        }
        if ($bestbefore != null) {
            $daysBestBefore           = $bestbefore;
            $data['best_before_date'] = self::_formatBestBeforeDays($bestbefore);
        } else {
            if ($defaultBestBefore != null) {
                $daysBestBefore       = $defaultBestBefore;
            } else {
                $daysBestBefore       = self::_getDefaultBestBeforeDays($id);
            }
            $data['best_before_date'] = self::_formatBestBeforeDays($daysBestBefore);
        }
        
        $data_json = json_encode($data);
        $apiurl = API_STOCK . "/" . $id . "/add";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data_json);
        try {
            $curl->execute();
        } catch (Exception $e) {
            self::processError($e, "Could not add product to inventory");
        }
        if ($fileLock != null) {
            $fileLock->removeLock();
        }
        if ($BBCONFIG["SHOPPINGLIST_REMOVE"]) {
            self::removeFromShoppinglist($id, $amount);
        }
        return ($daysBestBefore != 0);
    }
    
    /**
     * Removes an item from the default shoppinglist
     * 
     * @param String $productid product id
     * @param Int    $amount    amount
     *
     * @return none
     */
    public static function removeFromShoppinglist($productid, $amount)
    {
        $data = json_encode(
            array(
                'product_id' => $productid,
                'product_amount' => $amount
            )
        );
        $apiurl = API_SHOPPINGLIST . "remove-product";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (Exception $e) {
            self::processError($e, "Could not remove item from shoppinglist");
        }
    }
    
 
    /**
     * Adds an item to the default shoppinglist
     * 
     * @param String $productid product id
     * @param Int    $amount    amount
     *
     * @return none
     */
    public static function addToShoppinglist($productid, $amount)
    {
        $data = json_encode(
            array(
                'product_id' => $productid,
                'product_amount' => $amount
            )
        );
        $apiurl = API_SHOPPINGLIST . "add-product";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (Exception $e) {
            self::processError($e, "Could not add item to shoppinglist");
        }
    }
    
    
   /**
    * Consumes a product
    * 
    * @param int     $id      id
    * @param int     $amount  amount
    * @param boolean $spoiled set true if product was spoiled. Default: false 
    *
    * @return none
    */
    public static function consumeProduct($id, $amount, $spoiled = false)
    {
        $data = json_encode(
            array(
                'amount' => $amount,
                'transaction_type' => 'consume',
                'spoiled' => $spoiled
            )
        );
        
        $apiurl = API_STOCK . "/" . $id . "/consume";

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $curl->execute();
        } catch (Exception $e) {
            self::processError($e, "Could not consume product");
        }
    }
    
    /**
     * Sets barcode to a Grocy product (replaces all old ones,
     *  so make sure to request them first)
     *
     * @param int    $id product id
     * @param String $barcode barcode(s) to set
     *
     * @return void
     */
    public static function setBarcode($id, $barcode)
    {
        $data = json_encode(
            array(
                'barcode' => $barcode
            )
        );

        $apiurl    = API_O_PRODUCTS . "/" . $id;

        $curl = new CurlGenerator($apiurl, METHOD_PUT, $data);
        try {
            $curl->execute();
        } catch (Exception $e) {
            self::processError($e, "Could not set Grocy barcode");
        }
    }
    
    
    /**
     * Formats the amount of days into future date
     *
     * @param int $days Amount of days a product is consumable, or -1 if it does not expire
     *
     * @return String Formatted date
     */
    private static function _formatBestBeforeDays($days)
    {
        if ($days == "-1") {
            return "2999-12-31";
        } else {
            $date = date("Y-m-d");
            return date('Y-m-d', strtotime($date . " + $days days"));
        }
    }
    
    /**
     * Retrieves the default best before date for a product
     *
     * @param int $id Product id
     *
     * @return int Amount of days or -1 if it does not expire
     */
    private static function _getDefaultBestBeforeDays($id)
    {
        $info = self::getProductInfo($id);
        $days = $info["default_best_before_days"];
        checkIfNumeric($days);
        return $days;
    }
    
    
    /**
     * Look up a barcode using openfoodfacts
     *
     * @param String $barcode Input barcode
     *
     * @return String Returns product name or "N/A" if not found
     */
    public static function lookupNameByBarcodeInOpenFoodFacts($barcode)
    {
        global $BBCONFIG;
        
        $url = "https://world.openfoodfacts.org/api/v0/product/" . $barcode . ".json";

        $curl = new CurlGenerator($url, METHOD_GET, null, null, true);
        try {
            $result = $curl->execute(true);
        } catch (InvalidServerResponseException $e) {
            self::logError("Could not connect to OpenFoodFacts.", false);
            return "N/A";
        } catch (UnauthorizedException $e) {
            self::logError("Could not connect to OpenFoodFacts - unauthorized");
            return "N/A";
        } catch (InvalidJsonResponseException $e) {
            self::logError("Error parsing OpenFoodFacts response: ".$e->getMessage(), false);
            return "N/A";
        } catch (InvalidSSLException $e) {
            self::logError("Could not connect to OpenFoodFacts - invalid SSL certificate");
            return "N/A";
        }
        if (!isset($result["status"]) || $result["status"] !== 1) {
            return "N/A";
        }

        $genericName = null;
        $productName = null;
        if (isset($result["product"]["generic_name"]) && $result["product"]["generic_name"] != "") {
            $genericName = sanitizeString($result["product"]["generic_name"]);
        }
        if (isset($result["product"]["product_name"]) && $result["product"]["product_name"] != "") {
            $productName = sanitizeString($result["product"]["product_name"]);
        }

        if ($BBCONFIG["USE_GENERIC_NAME"]) {
            if ($genericName != null) {
                return $genericName;
            }
            if ($productName != null) {
                return $productName;
            }
        } else {
            if ($productName != null) {
                return $productName;
            }
            if ($genericName != null) {
                return $genericName;
            }
        }
        return "N/A";
    }
    
    
    /**
     * Get a Grocy product by barcode
     *
     * @param String $barcode barcode to lookup
     *
     * @return Array Array if product info, or null if barcode is not associated with a product
     */
    public static function getProductByBardcode($barcode)
    {
        $apiurl = API_STOCK . "/by-barcode/" . $barcode;

        $curl = new CurlGenerator($apiurl);
        try {
            $result = $curl->execute(true);
        } catch (Exception $e) {
            self::processError($e, "Could not lookup Grocy barcode");
        }
        
        if (isset($result["product"]["id"])) {
            checkIfNumeric($result["product"]["id"]);
            $resultArray                      = array();
            $resultArray["id"]                = $result["product"]["id"];
            $resultArray["name"]              = sanitizeString($result["product"]["name"]);
            $resultArray["unit"]              = sanitizeString($result["quantity_unit_stock"]["name"]);
            $resultArray["stockAmount"]       = sanitizeString($result["stock_amount"]);
            $resultArray["tareWeight"]        = sanitizeString($result["product"]["tare_weight"]);
            $resultArray["isTare"]            = ($result["product"]["enable_tare_weight_handling"] == 1);
            $resultArray["quFactor"]          = sanitizeString($result["product"]["qu_factor_purchase_to_stock"]);
            $resultArray["defaultBestBefore"] = sanitizeString($result["product"]["default_best_before_days"]);
            if ($resultArray["stockAmount"] == null) {
                $resultArray["stockAmount"] = "0";
            }
            return $resultArray;
        } else {
            return null;
        }
    }

    /**
     * Gets location and amount of stock of a product
     *
     * @param String $productid Product id
     *
     * @return Array Array with location info, null if none in stock
     */
    public static function getProductLocations($productid)
    {
        $apiurl = API_STOCK . "/" . $productid . "/locations";

        $curl = new CurlGenerator($apiurl);
        try {
            $result = $curl->execute(true);
        } catch (Exception $e) {
            self::processError($e, "Could not lookup product location");
        }
        return $result;
    }
    
    /**
     * Getting info of a Grocy chore
     *
     * @param string $choreId Chore ID. If not passed, all chores are looked up
     *
     * @return array Either chore if ID, or all chores
     */
    public static function getChoresInfo($choreId = "")
    {
        if ($choreId == "") {
            $apiurl = API_CHORES;
        } else {
            $apiurl = API_CHORES . "/" . $choreId;
        }
        
        $curl = new CurlGenerator($apiurl);
        try {
            $result = $curl->execute(true);
        } catch (Exception $e) {
            self::processError($e, "Could not get chore info");
        }
        return $result;
    }
    
    /**
     * Executes a Grocy chore
     *
     * @param int $choreId Chore id
     *
     * @return void
     */
    public static function executeChore($choreId)
    {
        $apiurl    = API_CHORE_EXECUTE . $choreId . "/execute";
        $data      = json_encode(
            array(
                'tracked_time' => "",
                'done_by' => ""
            )
        );

        $curl = new CurlGenerator($apiurl, METHOD_POST, $data);
        try {
            $result = $curl->execute(true);
        } catch (Exception $e) {
            self::processError($e, "Could not execute chore");
        }
    }

    /**
     * Handle errors incurred while making API calls to Grocy
     *
     * @param exception $e            Exception that occurred
     * @param string    $errorMessage Error from Grocy
     *
     * @return string
     */
    public static function processError($e, $errorMessage)
    {
        $class = get_class($e);
        switch($class) {
            case 'InvalidServerResponseException':
                self::logError("Could not connect to Grocy server: " . $errorMessage);
                break;
            case 'UnauthorizedException':
                self::logError("Invalid API key: " . $errorMessage);
                break;
            case 'InvalidJsonResponseException':
                self::logError("Invalid JSON: " . $errorMessage . " " . $e->getMessage());
                break;
            case 'InvalidSSLException':
                self::logError("Invalid API key: " . $errorMessage);
                break;
        }
    }

    /**
     * Log an error to the database
     *
     * @param string $errorMessage Error Message to record
     * @param bool   $isFatal      If the error was/is fatal
     *
     * @return void
     */
    public static function logError($errorMessage, $isFatal = true) {
        require_once __DIR__ . "/db.inc.php";
        global $db;
        if ($db != null) {
            $db->saveError($errorMessage, $isFatal);
        }
    }   
}
