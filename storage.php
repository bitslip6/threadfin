<?php
namespace BitSlip;

interface Storage {
    public function save_data($key_name, $data);
    public function load_data($key_name);
}

/**
 * trivial cache abstraction with support for apc, apcu and zend opcache 
 */
class CacheStorage implements Storage {
    protected $_type = null;
    protected $_config;

    public function __construct($configuration = null) {
        // make sure we have a valid config
        if ($configuration == null || !isset($configuration['cache_type'])) {
            throttle_error(1004);
            return;
        }

        // make sure we have a good cache environment
        $this->_type = $configuration['cache_type'];
        // zend op cache requires that we write php code to disk...
        if ($this->_type === "zend opcache") {
            if (!isset($configuration['opcache_path']) || !is_writable($configuration['opcache_path'])) {
                throttle_error(1003);
            }
        }
        if ($this->_type === "shmop") {
            require "cuckoo.php";
            $ctx = cuckoo_connect(2048, 1024);
        }

        $this->_config = $configuration;
    }



    /**
     * save data to keyname
     */
    public function save_data($key_name, $data) {
        switch ($this->_type) {
            case "shmop":
                cuckoo_write($ctx, $key_name, $data);
                return;
            case "apcu":
                apcu_store($key_name, $data);
                return;
            case "apc":
                apc_store($key_name, $data);
                return;
            case "zend opcache":
                $s = var_export($data);
                file_put_contents($this->_config['opcache_path'] . "{$key_name}", "<?php \$value = $s; $success = true;");
                return;
            default:
                return;
        }
    }

    public function load_data($key_name) {
        $value = null;
        $success = false;
        switch ($this->_type) {
            case "shmop":
                $value = cuckoo_read($ctx, $key_name);
                break;
            case "apcu":
                $value = apcu_fetch($key_name, $success);
                break;
            case "apc":
                $value = apc_fetch($key_name, $success);
                break;
            case "zend opcache":
                @include ($this->_config['opcache_path'] . "{$key_name}");
                break;
        }

        // force false return on error
        if ($success == false) { $value = null; }
        return $value;
    }
}

class FileStorage implements Storage {
    private $_write_path = null;

    public function __construct($configuration = null) {
        if ($configuration == null || !isset($configuration['filepath'])) {
            $this->scanAllDir($_SERVER['DOCUMENT_ROOT'] . "../");
            if ($this->_write_path == null) {
                $this->_write_path = sys_get_temp_dir();
                bit_warn("using temp directory, learned profiles will not persist reboot");
            }
            if (!is_writable($this->_write_path)) {
                bit_warn("unable to find writable directory to store learned profiles");
            }
        }
    }

    private function scanAllDir($dir) {
        // bail if we already found a writable path
        if ($this->_write_path != null) {
            return;
        }

        $dir = dirname($dir);
        $result = [];
        foreach(scandir($dir) as $filename) {
            if ($filename[0] === '.') { continue; }
            $filePath = $dir . '/' . $filename;
            if (is_dir($filePath)) {
                if (is_writable(dirname($filePath))) {
                    $this->_write_path = dirname($filePath);
                    return; 
                }
                $this->scanAllDir($filePath);
            }
        }
    }

    public function save_data($key_name, $data) {
        if ($this->_write_path != null) {
            file_put_contents($this->_write_path . "/$key_name", json_encode($data, true), LOCK_EX);
            return true;
        }
        return false;
    }

    public function load_data($key_name) {
        if ($this->_write_path != null) {
            return json_decode(file_get_contents($this->_write_path . "/$key_name"), true);
        }
        return null;
    }
}




interface BitInspectStore {
    public function save_page(array $page);
    public function load_page(array $page);
    public function load_page_list();
    public function save_page_list($list);
}

class BitInspectFileStore implements BitInspectStore {
    private $_path;

    public function __construct($path) {

        $cache_path = $path['profile_path'];

        if (!is_dir($cache_path) || !is_writable($cache_path) ) {
            $cache_path = sys_get_temp_dir();
        }
        $this->_path = $cache_path;
    }

    private function request_to_name(array $page) {
        $data = "{$page['METHOD']}:{$page['HOST']}:{$page['PATH']}";
        return crc32($data).substr($page['PATH'], -5);
    }

    public function save_page(array $page) {
        $data = json_encode($page);
        file_put_contents($this->_path . DIRECTORY_SEPARATOR . $this->request_to_name($page) . ".json", $data);
    }

    public function load_page(array $page) {
        $path = $this->_path . DIRECTORY_SEPARATOR . $this->request_to_name($page) . ".json";
        if (!is_file($path)) { return null; }
        $data = file_get_contents($path);
        return ($data !== false) ? json_decode($data, true) : null;
    }

    public function load_page_list() {
        $path = $this->_path . DIRECTORY_SEPARATOR . 'bitwaf_pagelist.json';
        if (!is_file($path)) { return null; }
        $data = file_get_contents($path);
        return ($data !== false) ? json_decode($data, true) : null;
    }

    public function save_page_list($list) {
        $data = json_encode($list);
        file_put_contents($this->_path . DIRECTORY_SEPARATOR . 'bitguard_pagelist.json', $data);
    }
}
