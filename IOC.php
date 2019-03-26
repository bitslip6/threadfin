<?php
namespace threadfin;

use \threadfin\persistence\APIResponse;
use \threadfin\security\Authorization as Authorization;
use \threadfin\persistence\Config as Config;
use \threadfin\persistence\Logger as Logger;
use \threadfin\security\Validation_Exception as Validation_Exception;

/**
 * @property \ReflectionClass $_apiReflection ignore
 *
 * Class IOC
 */
class IOC
{
    protected $_mappedFile = "";
    protected $_className = "";
    protected $_method = "";
    protected $_apiReflection = null;
    protected $_cache;
    protected $_config;
    protected static $_instance = null;
    protected $_instances = array();

    protected $_log = null;
    /** @var \threadfin\security\JWT $_jwt */
    protected $_jwt = null;
    /** @var \threadfin\persistence\Req $_req */
    protected $_req = null;


    /**
     * connect to external resources
     */
    protected function __construct() {
        $this->_cache = persistence\LocalCache::getInstance();
        $this->_log = Logger::getLogger("ioc");
        $this->_config = Config::getInstance();

        $this->_jwt = new security\JWT();
        $this->_req = persistence\Req::getInstance();
    }

    /**
     * force the invoke method to route here
     * @param string $file - file to route to
     * @param string $class - class name
     * @param string $method - method name
     */
    public function force_route($file, $class, $method) {
        $this->_mappedFile = $file;
        $this->_className = $class;
        $this->_method = $method;
    }


    /**
     * @return IOC a singleton reference to the container
     */
    public static function getInstance() {
        if (self::$_instance == null) {
            $ioc = new IOC();
            self::$_instance = $ioc;
            self::$_instance->_instances['\threadfin\IOC'] = self::$_instance;

            // set IOC references to these classes
            self::$_instance->_instances['\threadfin\security\JWT'] = $ioc->_jwt;
            self::$_instance->_instances['\threadfin\persistence\Req'] = $ioc->_req;
        }
        return self::$_instance;
    }

    public function mapFile($file, $class, $method) {
         $this->_mappedFile = $file;
         $this->_className = $class;
         $this->_method = $method;
    }


    /**
     * purge an instance from the IOC container
     * @param $className
     */
    public function remove_instance($className) {
        $this->_log->debug("remove instance $className");
        unset($this->_instances[$className]);
    }

    /**
     * @param string $className
     * @return mixed a singleton reference to className
     * @throws \ReflectionException
     */
    public function instance($className) {
        if (isset($this->_instances[$className])) {
            $this->_log->trace("ioc return singleton instance: $className");
            return $this->_instances[$className];
        }

        $instance = $this->new(...func_get_args());
        $this->_instances[$className] = $instance;
        return $instance;
    }

    /**
     * @param string $className
     * @param string $arguments (list of arguments to pass to constructor)
     * @return mixed a singleton reference to className
     * @throws \ReflectionException
     */
    public function new($className) {
        $arguments = func_get_args();
        $this->_log->trace("ioc create new instance: $className: ");
        $doc = $this->get_docs_for_class($className);

        // if it's a singleton, then use that interface, else new it up
        array_shift($arguments);
        
        $instance = (in_array('threadfin\Singleton', $doc['interfaces']))
            ? $className::getInstance(...$arguments) : $instance = new $className(...$arguments);
            
        // recursively inject any dependencies and add this object to the container
        $this->inject_params($instance, $doc);
        return $instance;
    }


    /**
     * loop over docs and inject parameters into the class
     * @param $apiclass
     * @param array $doc
     * @throws \ReflectionException
     */
    protected function inject_params($apiclass, array $doc) {

        foreach ($doc['param'] as $paramName => $value) {
            $injected_value = null;

            assert(isset($value['required']), new \AssertErr("PHP Docs missing parameter \"required\" must be [required|optional]"));

            // find the value to set on the property (Request,jwt,server or singleton)
            // prefer request parameter
            // if we can get it from request, and it's present there by its documented name, read it

            if (isset($value['req'])) {

                $injected_value = null;

                // only load the correct type...
                $parts = explode(':', $value['type']);

                switch ($parts[0]) {
                    case 'int':
                        $injected_value = $this->_req->getNumeric($value['req']);
                        break;
                    case 'phone':
                        $injected_value = $this->_req->getRegexFilter('/^[0-9\._\(\)\s\+\-]{6,15}$/', $value['req']);
                        break;
                    case 'alphanum':
                        $injected_value = $this->_req->getAlphaNumeric($value['req']);
                        break;
                    case 'json':
                        $injected_value = json_decode($this->_req->getRaw($value['req']), true);
                        break;
                    case 'raw':
                        //$this->_log->info("raw data: " . print_r($value, true));
                        $injected_value = $this->_req->getRaw($value['req']);
                        break;
                    case 'sqldate':
                        $injected_value = $this->_req->get_sql_date($value['req']);
                        break;
                    case 'email':
                        $injected_value = $this->_req->getRegexFilter('/^[^@]+\@[^\.]+\.\w{2,10}$/', $value['req']);
                        break;
                    case 'regex':
                        //assert(count($parts) == 2, "regex property types must follow format regex:/^regex_filter$/ [{$value['type']}]");
                        $regex = substr($value['type'], 6);
                        $injected_value = $this->_req->getRegexFilter($regex, $value['name']);
                        $this->_log->info("regex: $regex - [$injected_value]");
                        break;
                    default:
                        assert(false, new \AssertErr("unknown filter type {$parts[0]}"));
                }
            }
            // fallback to jwt
            else if (isset($value['jwt'])) {
                $injected_value = $this->_jwt->get_value($value['jwt']);
            }
            // load value from $_SERVER array
            else if (isset($value['server'])) {
                if (isset($_SERVER[strtoupper($value['server'])])) {
                    $injected_value = $_SERVER[strtoupper($value['server'])];
                } else {
                    $this->_log->trace("no http request header [{$value['server']}]");
                }
            }
            // load value from $_COOKIE array
            else if (isset($value['cookie'])) {
                if (isset($_COOKIE[$value['cookie']])) {
                    $injected_value = $_COOKIE[$value['cookie']];
                }
            }
            else if (isset($value['new'])) {
                if (is_array($value['new'])) {
                    array_unshift($value['new'], $value['type']);
                    $injected_value = call_user_func_array(array($this, "new"), $value['new']);
                }
                else {
                    $injected_value = $this->new($value['type']);
                }
            }
            // inject by newing it up (could be recursive!)
            else if (isset($value['singleton'])) {
                if (is_array($value['singleton'])) {
                    array_unshift($value['singleton'], $value['type']);
                    $injected_value = call_user_func_array(array($this, "instance"), $value['singleton']);
                } else {
                    $injected_value = $this->instance($value['type']);
                }
            }
            // inject from local cache
            else if (isset($value['lcache'])) {
                $injected_value = $this->_cache->get($value['lcache']);
            }
            else {
                $this->_log->fatal("unknown parameter: ". print_r($value, true));
                assert(false, new \AssertErr("unknown parameter source: " . print_r($value, true)));
                throw new \Exception("unknown parameter source: " . print_r($value, true));
            }
            
            if ($value['required'] == "required" && $injected_value === null) {
                throw new Validation_Exception("required input for class: " . (string)$apiclass . " param name: [{$value['name']}] could not be found : [" . print_r($value, true) . "]");
            }

            // either set it as a property or a method call
            // make sure that the APICRUD class has a setter for this value
            if (isset($value['prop'])) {
                $property = $value['prop'];
                assert(property_exists($apiclass, $value['prop']), "the api class does not have a public property for $property");
                $apiclass->$property = $injected_value;
            }
            else if (isset($value['method'])) {
                $method = $value['method'];
                assert(method_exists($apiclass, $value['method']), "the api class does not have a setter for $method()");
                $apiclass->$method($injected_value);
            }
            else {
                assert(false, "doc property has no accessor method (method|prop) : " . print_r($value, true));
            }
        }
    }

    /**
     * @param $class_name
     * @throws \ReflectionException
     */
    public function set_reflection_class($class_name) {
        if ($this->_apiReflection === null || $this->_apiReflection->name !== $class_name) {
            $this->_apiReflection = new \ReflectionClass($class_name);
        }
    }


   /**
     * load the phpdoc for a class method
     * @param string $method the method name of the class
     * @return array|mixed
     */
    public function get_docs_for_method($method) {
        assert($this->_apiReflection instanceof \ReflectionClass, "unable to get doc for $method, reflection class is not loaded");

        // get the last modified time so that we can invalidate the cache when we need to...
        $mtime = stat($this->_mappedFile)['mtime'];

        // use apc cached parsed docs
        $cache_key = "ioc_doc_{$this->_className}-{$this->_method}_" . $this->_config->get("appver");
        $doc = $this->_cache->get($cache_key);
        if ($doc == persistence\LocalCache::CACHE_MISS || $doc['mtime'] != $mtime) {
            $this->_log->trace("reading docs for method [$method] from disk");

            // get the php docs
            $read = $this->_apiReflection->getMethod($method);
            //$this->_log->warn("parse docs: " . print_r($read, true));
            //$this->_log->warn("parse docs: " . print_r($read->getDocComment(), true));
            $doc = $this->parse_doc($read->getDocComment(), $this->_className);

            $interfaces = $this->_apiReflection->getInterfaceNames();
            $doc['interfaces'] = $interfaces;
            $doc['mtime'] = $mtime;

            $this->_cache->set($doc);
        }

        return $doc;
    }

    /**
     * @param $class_name
     * @return array|mixed
     * @throws \ReflectionException
     */
    public function get_docs_for_class($class_name)
    {
        // use apc cached parsed docs
        $cache_key = "ioc_doc_{$class_name}_" . $this->_config->get("appver");
        $doc = $this->_cache->get($cache_key);
        if ($doc == persistence\LocalCache::CACHE_MISS) {
            $this->set_reflection_class($class_name);

            $com = $this->_apiReflection->getDocComment();
            $doc = $this->parse_doc($com, $class_name);
            $interfaces = $this->_apiReflection->getInterfaceNames();
            $doc['interfaces'] = $interfaces;

            $this->_cache->set($doc);
        }
        return $doc;
    }


    /**
     * parse a phpdoc comment into an array of known things
     *
     * TODO: refactor this to have a single return
     * @param string $com the doc comment
     * @param string $class_name the name of the class the doc is from
     * @return array|string
     */
    public function parse_doc($com, $class_name = null)
    {
        // default to close api endpoint
        $auth = null;
        //assert(strstr($com, "@ioc") !== false, "class: [$class_name] missing @ioc annotation, [$com] won't load");
        assert(strlen($com) > 10, "class [$class_name] docs not found: [$com]");

        // split on new line
        $p = explode("\n", $com);
        $text_line = array();
        $params = array();
        $throws = array();
        $responses = array();
        $example = null;
        $tags = array();
        $summary = "no @summary";
        foreach ($p as $x) {
            // skip short lines
            if (strlen($x) <= 10) {
                continue;
            }
            // ignore leading comment spaces
            $x = preg_replace("/\s*\*\s+/", "", $x);

            // pull out auth line
            if (strstr($x, "@auth ")) {
                $annotation_value = substr($x, 6);
                assert(is_null($auth), new \AssertErr("annotation for class $class_name has multiple @auth lines"));
                $auth = $this->_auth->validate_annotation($annotation_value);
            }
            // ignore throws lines, find location of @throws annotation
            else if (($pos = strpos($x, "@response")) !== false) {
                // get everything after the @response annotation
                $trimmed_line = substr($x, $pos + 10);
                $responses[substr($trimmed_line, 0, 4)] = substr($trimmed_line, 4);
            }

            // pull out any params, and parse them
            // format: @param (int|string|float|class|namespace\class) name req:param_name,jwt:param_name,? required|optional - doc line
            else if (strstr($x, "@prop") !== false || strstr($x, "@input") !== false) {
                // ignored by IOC
                if (strstr($x, "ignore")) {
                    continue;
                }
                $p = preg_split("/\s+/", $x);
                if (isset($p[4])) {

                    // comments come after the -
                    $p2 = explode("-", $x);
                    $info = (isset($p2[1])) ? $p2[1] : "no comment info";

                    // remove $ from variable names (if it's there)
                    $variable_name = str_replace("$", "", $p[2]);

                    assert(in_array($p[4], array("required", "optional")), new \AssertErr("parameter {$variable_name}: phpdoc error type ({$p[4]}) is unknown must be (optional|required): " . print_r($p, true)));
                    // test that the class has the correct methods to set the injected parameters, dev problem
                    $param = null;

                    // try to set via public setter
                    if ($param === null) {
                        if ($this->_apiReflection->hasMethod("set_{$variable_name}")) {
                            $param = array("required" => $p[4], "name" => $variable_name, "type" => $p[1], "info" => $info, "method" => "set_{$variable_name}");
                        } else if ($this->_apiReflection->hasMethod("set{$variable_name}")) {
                            $param = array("required" => $p[4], "name" => $variable_name, "type" => $p[1], "info" => $info, "method" => "set{$variable_name}");
                        }
                    }
                    // else fall back try to set via public property
                    if($param === null && $this->_apiReflection->hasProperty($variable_name)) {
                        $prop = $this->_apiReflection->getProperty($variable_name);
                        if ($prop->isPublic()) {
                            $param = array("required" => $p[4], "name" => $variable_name, "type" => $p[1], "info" => $info, "prop" => $variable_name);
                        }
                    }
                    assert(($param != null), "Class: {$class_name} could not find a method to access {$variable_name}");

                    $t1 = explode(",", $p[3]);
                    // add a variable source
                    foreach ($t1 as $input_src) {
                        $p3 = explode(":", $input_src);
                        // handle req:param_name
                        if (isset($p3[1]) && ($p3[0] !== "singleton" && $p3[0] !== "new")) {
                            $param[$p3[0]] = $p3[1];
                        }
                        // handle singleton
                        else {
                            $param[$p3[0]] = (isset($p3[1])) ? array_slice($p3, 1, 10) : $p3[0];
                        }
                    }
                    $params[$variable_name] = $param;
                }
            }
            // add the throws class to the throw list
            else if (($pos = strpos($x, "@throws")) !== false) {
                // get everything after the @throws up to 32 characters or first space
                $parts = explode(" ", substr($x, $pos + 8, 48));
                array_push($throws, $parts[0]);
            }
            // load all comma separated tags
            else if (($pos = strpos($x, "@tags")) !== false) {
                // get everything after the @response annotation
                $tags = explode(',', substr($x, $pos + 6));
            }
            // load all comma separated tags
            else if (($pos = strpos($x, "@summary")) !== false) {
                // get everything after the @response annotation
                $summary = substr($x, $pos + 9);
            }
            else if ((($pos = strpos($x, "@return")) !== false) || (($pos = strpos($x, "@ioc") !== false))) {
                // ignore return (Interface enforces APIResponse, IOC is not doc)
            }
            // handle list of example parameters
            else if (($pos = strpos($x, "@example")) !== false) {
                // get the example key value pairs
                $split_space = explode(' ', substr($x, $pos + 9));
                $example = array();
                foreach ($split_space as $part) {
                    $split_colon = explode(':', $part);
                    assert((count($split_colon) ==  2), new \AssertErr("colon separated @example line invalid format [$x]"));
                    $example[$split_colon[0]] = $split_colon[1];
                }
            }
            // simple text documenting the endpoint
            else {
                array_push($text_line, $x);
            }
        }

        return array("auth" => $auth, "summary" => $summary, "param" => $params, "com" => $text_line, 'tags' => $tags, 'throws' => $throws, 'responses' => $responses, 'example' => $example);
    }
}
