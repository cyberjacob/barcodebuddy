<?php

/**
 * Barcode Buddy for Grocy
 *
 * PHP version 7
 *
 * API controller
 *
 * LICENSE: This source file is subject to version 3.0 of the GNU General
 * Public License v3.0 that is attached to this project.
 *
 * @author    Marc Ole Bulling
 * @copyright 2019 Marc Ole Bulling
 * @license   https://www.gnu.org/licenses/gpl-3.0.en.html  GNU GPL v3.0
 * @since     File available since Release 1.4
 */


require_once __DIR__ . "/../incl/configProcessing.inc.php";
require_once __DIR__ . "/../incl/db.inc.php";
require_once __DIR__ . "/../incl/processing.inc.php";

//removes Get paramterss
$requestedUrl = strtok($_SERVER["REQUEST_URI"], '?');

//removes everything before "/api"
$requestedUrl = trim(substr($requestedUrl, strpos($requestedUrl, '/api')));

$api = new BBuddyApi();
if ($requestedUrl == "/api/") {
    readfile(__DIR__ . "/doc.html");
    die();
}
if ($CONFIG->REQUIRE_API_KEY) {
    $api->checkIfAuthorized();
}
$api->execute($requestedUrl);


/**
 * API Base Class
 */
class BBuddyApi
{
    private $_routes = array();
    
    /**
     * Check if API call has the correct authorization.
     * Either from a user authentication, disabled authentication (either globally or for a specific subnet),
     * or from an API key.
     *
     * @return true | false
     */
    function checkIfAuthorized()
    {
        global $db;
        global $CONFIG;
        
        if ($CONFIG->checkIfAuthenticated(false)) {
            return true;
        }

        $apiKey = "";
        if (isset($_SERVER["HTTP_BBUDDY_API_KEY"])) {
            $apiKey = $_SERVER["HTTP_BBUDDY_API_KEY"];
        }
        if (isset($_GET["apikey"])) {
            $apiKey = $_GET["apikey"];
        }
        
        if ($apiKey == "") {
            self::sendUnauthorizedAndDie();
        }
        
        if ($db->isValidApiKey($apiKey)) {
            return true;
        } else {
            self::sendUnauthorizedAndDie();
        }
    }
    
    /**
     * Return an unauthorized result and stop.
     *
     * @return void
     */
    static function sendUnauthorizedAndDie()
    {
        self::sendResult(self::createResultArray(null, "Unauthorized", 401), 401);
        die();
    }
    
    /**
     * Complete an API request
     *
     * @param string $url URL path to execute
     *
     * @return void
     */
    function execute($url)
    {
        global $CONFIG;

        //Turn off all error reporting, as it could cause problems with parsing json clientside
        if (!$CONFIG->IS_DEBUG) {
            error_reporting(0);
        }

        if (!isset($this->_routes[$url])) {
            self::sendResult(self::createResultArray(null, "API call not found", 404), 404);
        } else {
            $this->_routes[$url]->execute();
        }
    }
    
    /**
     * Create a new API instance
     *
     * @return void
     */
    function __construct()
    {
        $this->_initRoutes();
    }
    
    /**
     * Create object for storing API call results
     *
     * @param mixed  $data     API Result
     * @param string $result   Success/Fail Description
     * @param int    $http_int Result Code intended to be returned
     *
     * @return array
     */
    static function createResultArray($data = null, $result = "OK", $http_int = 200)
    {
        return array(
            "data" => $data,
            "result" => array(
                "result" => $result,
                "http_code" => $http_int
            )
        );
    }
    
    /**
     * Helper function for creating new API routes
     *
     * @param string $route URL of route
     *
     * @return void
     */
    private function _addRoute($route)
    {
        $this->_routes[$route->path] = $route;
    }
    
    /**
     * Create all standard routes
     *
     * @return void
     */
    private function _initRoutes()
    {    
        $this->_addRoute(
            new ApiRoute(
                "/action/scan",
                function () {
                    $barcode = "";
                    if (isset($_GET["text"])) {
                        $barcode = $_GET["text"];
                    }
                    if (isset($_GET["add"])) {
                        $barcode = $_GET["add"];
                    }
                    if (isset($_POST["barcode"])) {
                        $barcode = $_POST["barcode"];
                    }
                    if ($barcode == "") {
                        return self::createResultArray(null, "No barcode supplied", 400);
                    } else {
                        $bestBefore = null;
                        $price = null;
                        if (isset($_POST["bestBeforeInDays"]) && $_POST["bestBeforeInDays"] != null) {
                            if (is_numeric($_POST["bestBeforeInDays"])) {
                                $bestBefore = $_POST["bestBeforeInDays"];
                            } else {
                                return self::createResultArray(null, "Invalid parameter bestBeforeInDays: needs to be type int", 400);
                            }
                        }
                        if (isset($_POST["price"]) && $_POST["price"] != null) {
                            if (is_numeric($_POST["price"])) {
                                $price = $_POST["price"];
                            } else {
                                return self::createResultArray(null, "Invalid parameter price: needs to be type float", 400);
                            }
                        }
                        $result = processNewBarcode(sanitizeString($barcode), true, $bestBefore, $price);
                        return self::createResultArray(array("result" => sanitizeString($result)));
                    }
                }
            )
        );
        
        $this->_addRoute(
            new ApiRoute(
                "/state/getmode",
                function () {
                    global $db;
                    return self::createResultArray(
                        array(
                            "mode" => $db->getTransactionState()
                        )
                    );
                }
            )
        );
        
        $this->_addRoute(
            new ApiRoute(
                "/state/setmode",
                function () {
                    global $db;
                    //Also check if value is a valid range:
                    //    STATE_CONSUME the lowest and
                    //    STATE_CONSUME_ALL the highest value
                    if (!isset($_POST["state"])
                     || !is_numeric($_POST["state"])
                     || $_POST["state"] < STATE_CONSUME
                     || $_POST["state"] > STATE_CONSUME_ALL
                    ) {
                        return self::createResultArray(null, "Invalid state provided", 400);
                    } else {
                        $db->setTransactionState(intval($_POST["state"]));
                        return self::createResultArray();
                    }
                }
            )
        );
        
        $this->_addRoute(
            new ApiRoute(
                "/system/barcodes",
                function () {
                    global $BBCONFIG;
                    return self::createResultArray(
                        array(
                            "BARCODE_C"  => $BBCONFIG["BARCODE_C"],
                            "BARCODE_CS" => $BBCONFIG["BARCODE_CS"],
                            "BARCODE_P"  => $BBCONFIG["BARCODE_P"],
                            "BARCODE_O"  => $BBCONFIG["BARCODE_O"],
                            "BARCODE_GS" => $BBCONFIG["BARCODE_GS"],
                            "BARCODE_Q"  => $BBCONFIG["BARCODE_Q"],
                            "BARCODE_AS" => $BBCONFIG["BARCODE_AS"],
                            "BARCODE_CA" => $BBCONFIG["BARCODE_CA"]
                        )
                    );
                }
            )
        );
        
        $this->_addRoute(
            new ApiRoute(
                "/system/info",
                function () {
                    return self::createResultArray(
                        array(
                            "version"    => BB_VERSION_READABLE,
                            "version_int" => BB_VERSION
                        )
                    );
                }
            )
        );
    }
    
    /**
     * Return API function results to the client
     *
     * @param array $data   Result to be sent to the client
     * @param int   $result HTTP Response code to be sent to the client
     * @returns void
     */
    static function sendResult($data, $result)
    {
        header('Content-Type: application/json');
        http_response_code($result);
        echo trim(json_encode($data, JSON_HEX_QUOT));
        die();
    }
}

/**
 * API Routing information
 */
class ApiRoute
{
    public $path;
    private $_function;
    
    /**
     * Create a new route
     *
     * @param string   $path     URL Path to invoke this route
     * @param function $function Function to execute when this route is called
     *
     * @return void
     */
    function __construct($path, $function)
    {
        $this->path     = '/api' . $path;
        $this->_function = $function;
    }
    
    /**
     * Run the API Function
     *
     * @return void
     */
    function execute()
    {
        $result = $this->_function->__invoke();
        BBuddyApi::sendResult($result, $result["result"]["http_code"]);
    }
}
