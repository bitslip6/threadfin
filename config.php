<?php

class Config {
    public static $_options = null;

    // set the full list of configuration options
    public static function set(array $options) : void {
        Config::$_options = $options;
    }

    // execute $fn if option enabled and return result
    public static function if_enabled(string $option_name, callable $fn) : mixed {
        if (Config::$_options[$option_name]) { return $fn(); }
    }

    // set a single value
    public static function set_value(string $option_name, array|string|int|float|bool $value) {
        Config::$_options[$option_name] = $value;
    }

    // get a string value with a default
    public static function str(string $name, string $default = '') : string {
        if (isset(Config::$_options[$name])) { return (string) Config::$_options[$name]; }
        return (string) $default;
    }

    // get a string and convert to upper case
    public static function str_up(string $name, string $default = '') : string {
        return strtoupper(Config::str($name, $default));
    }

    // get a string and convert to lower case
    public static function str_low(string $name, string $default = '') : string {
        return strtolower(Config::str($name, $default));
    }

    // get an integer value with a default
    public static function int(string $name, int $default = 0) : int {
        return intval(Config::$_options[$name] ?? $default);
    }

    // get an array value with a default
    public static function arr(string $name, array $default = array()) : array {
        return (isset(Config::$_options[$name]) && is_array(Config::$_options[$name])) ? Config::$_options[$name] : $default;
    }

    // return the boolean value of the config option
    public static function enabled(string $name, bool $default = false) : bool {
        if (!isset(Config::$_options[$name])) { return $default; }
        return (bool)Config::$_options[$name];
    }

    // return !enabled()
    public static function disabled(string $name, bool $default = true) : bool {
        return !Config::enabled($name, $default);
    }
}