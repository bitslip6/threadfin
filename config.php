<?php

class Config {
    public static $_options = null;
    private static $_nonce = null;

    public static function nonce() : string {
        if (self::$_nonce == null) {
            self::$_nonce = str_replace(array('-','+','/'), "", random_str(10));
        }
        return self::$_nonce;
    }

    // set the full list of configuration options
    public static function set(array $options) : void {
        Config::$_options = $options;
    }

    // execute $fn if option enabled
    public static function if_en(string $option_name, $fn) {
        if (Config::$_options[$option_name]) { $fn(); }
    }

    // set a single value
    public static function set_value(string $option_name, $value) {
        Config::$_options[$option_name] = $value;
    }

    // return true if value is set to true or "block"
    public static function is_block(string $name) : bool {
        return (Config::$_options[$name]??'' == 'block' || Config::$_options[$name]??'' == true) ? true : false;
    }

    // get a string value with a default
    public static function str(string $name, string $default = '') : string {
        if (isset(Config::$_options[$name])) { return (string) Config::$_options[$name]; }
        if ($name == "auto_start") { // UGLY HACK for settings.html
            $ini = ini_get("auto_prepend_file");
            debug("load file [%s]", $ini);
            $found = false;
            if (!empty($ini)) {
                if ($_SERVER['IS_WPE']??false || Config::enabled("emulate_wordfence")) {
                    $file = Config::str("wp_root")."/wordfence-waf.php";
                    if (file_exists($file)) {
                        $s = @stat($file); // cant read this file on WPE, check the size
                        $found = ($s['size']??9999 < 256);
                    }
                }
                else if (contains($ini, "bitfire")) { $found = true; }
            }
            return ($found) ? "on" : "";
        }
        return (string) $default;
    }

    public static function str_up(string $name, string $default = '') : string {
        return strtoupper(Config::str($name, $default));
    }

    // get an integer value with a default
    public static function int(string $name, int $default = 0) : int {
        return intval(Config::$_options[$name] ?? $default);
    }

    public static function arr(string $name, array $default = array()) : array {
        return (isset(Config::$_options[$name]) && is_array(Config::$_options[$name])) ? Config::$_options[$name] : $default;
    }

    public static function enabled(string $name, bool $default = false) : bool {
        if (!isset(Config::$_options[$name])) { return $default; }
        if (Config::$_options[$name] === "block" || Config::$_options[$name] === "report" || Config::$_options[$name] == true) { return true; }
        return (bool)Config::$_options[$name];
    }

    public static function disabled(string $name, bool $default = true) : bool {
        return !Config::enabled($name, $default);
    }

    public static function file(string $name) : string {
        if (!isset(Config::$_options[$name])) { return ''; }
        if (Config::$_options[$name][0] === '/') { return (string)Config::$_options[$name]; }
        return WAF_ROOT . (string)Config::$_options[$name];
    }
}