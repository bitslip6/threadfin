<?php declare(strict_types=1);
/**
 * functional MySQL database abstraction
 */

namespace ThreadFin\DB;

use Attribute;
use Exception;
use mysqli;
use mysqli_result;
use OutOfBoundsException;
use RuntimeException;
use ThreadFin\Core\MaybeA;
use ThreadFin\Core\MaybeStr;

use function ThreadFin\Util\func_name;
use function ThreadFin\Core\partial_right as bind_r;
use function ThreadFin\Core\partial as bind_l;
use function ThreadFin\Log\debug;
use function ThreadFin\Util\utc_time;

const DB_FETCH_SUCCESS = 1;
const DB_FETCH_NUM_ROWS = 2;
const DB_FETCH_INSERT_ID = 4;
const DB_DUPLICATE_IGNORE = 8;
const DB_DUPLICATE_ERROR = 16;
const DB_DUPLICATE_UPDATE = 32;
const DB_MAX_BULK_INSERT = 64;


/**
 * The property is a primary key and will not update on duplicate
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT|Attribute::TARGET_PROPERTY)]
class NoUpdate { public function __construct() {} }
/** 
 * the attribute will not update on duplicate if the update would null it
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT|Attribute::TARGET_PROPERTY)]
class NotNull { public function __construct() {} }
/**
 * The property should only be updated if the value is not null and null in the DB
 */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT|Attribute::TARGET_PROPERTY)]
class IfNull { public function __construct() {} }

/**
 * Interface from mapping SQL results to objects
 */
interface FromSQL {
    #[\ReturnTypeWillChange]
    public static function from_sql(array $sql) : mixed;
}


// set the error log file if running in cli mode
if (!defined("SQL_ERROR_FILE")) {
    define("SQL_ERROR_FILE", "/tmp/php_sql_errors.log");
}

/**
 * store mysql login credentials
 * @package ThreadFin\DB
 */
class Credentials {
    public $username;
    public $password;
    public $prefix;
    public $db_name;
    public $host;

    /**
     * create database credentials
     * @return Credentials
     */
    public function __construct(string $user, string $pass, string $host, string $db_name, string $pre = "") {
        $this->username = $user;
        $this->password = $pass;
        $this->prefix = $pre;
        $this->host = $host;
        $this->db_name = $db_name;
    }
}

/** 
 * used to glue key values pairs together for SQL queries
 * if data key begins with ! then the value is not quoted
 * EG: UPDATE $table set " . glue(" = ", $data, ", ") .  where_clause($where);
 */
function glue(array $data, string $join = " = ", string $append_str = ", ") : string {
    $result = "";
    foreach ($data as $key => $value) {
        if ($result != '') { $result .= $append_str; }
        if ($key[0] === '!') { $key = substr($key, 1); $result .= "`{$key}` $join $value"; }
        else { $result .= "`{$key}`" . $join . quote($value); }
    }
    return $result;   
}

/**
 * add SQL quoting to a string, convert ints and bool to SQL types
 * @param mixed $input 
 * @return string 
 */
function quote($input) : string {
    if (is_null($input)) { return 'null'; }
    if (is_numeric($input)) { return strval($input); }
    if (is_string($input)) { return "'".addslashes($input)."'"; }
    if (is_bool($input)) { return $input ? '1' : '0'; }
    if (is_array($input)) { return implode(',', array_map('\ThreadFin\DB\quote', $input)); }
    $x = (string)$input;
    debug("implicit quote cast to string: [%s]", $x);
    return "'".addslashes($x)."'";
}

/**
 * create a where clause from an array of key value pairs
 * @param array $data 
 * @return string - the generated SQL where clause
 */
function where_clause(array $data) : string { 
    assert(count(array_filter(array_keys($data), 'is_string')) > 0, "where_clause requires an associative array");

    $result = " WHERE ";
    foreach ($data as $key => $value) {
        if (strlen($result) > 7) { $result .= " AND "; }
        if ($key[0] == '!') {
            $t = substr($key, 1);
            $result .= " `{$t}` = {$value} ";
        } else {
            $result .= " `{$key}` = " . quote($value);
        }
    }
    $x = trim($result, ",");
    return $x;
}


class DB {
    public $errors = [];
    public $logs = [];
    public $host;
    public $user;
    public $database;
    public $last_stmt = "";

    protected $_db;
    protected $_log_enabled = false;
    protected $_simulation = false;
    protected $_replay_enabled = false;
    protected $_err_filter_fn;
    protected $_replay_file = "";
    protected $_replay = [];

    protected function __construct(?\mysqli $db) { $this->_db = $db; }

    public function __destruct() {
        if ($this->_db) { $this->close(); }
    }

    public function connected() : bool {
        return (!empty($this->_db));
    }

    /**
     * @param null|mysqli $mysqli 
     * @return DB a new DB object, from existing connection
     */
    public static function from(?\mysqli $mysqli) : DB { 
        return new DB($mysqli);
    }

    /**
     * @param bool $enable - enable or disable logging
     * @return DB 
     */
    public function enable_log(bool $enable) : DB {
        $this->_log_enabled = $enable;
        return $this;
    }

    /**
     * @param string $replay_file_name - the name of the replay log to record to
     * @return DB 
     */
    public function enable_replay(string $replay_file_name) : DB {
        // attempt to create the replay file if it does not exist
        if (!file_exists($replay_file_name)) {
            touch($replay_file_name);
            if (!file_exists($replay_file_name)) {
                die("could not create replay file: $replay_file_name");
            }
        }
        $this->_replay_file = $replay_file_name;
        $this->_replay_enabled = true;
        return $this;
    }


    /**
     * @param bool $enable - enable or disable simulation (no queries, just the query log)
     * @return DB 
     */
    public function enable_simulation(bool $enable) : DB {
        $this->_simulation = $enable;
        $this->enable_log(true);
        return $this;
    }


    /**
     * @param null|Credentials $cred create a new DB connection
     * @return DB 
     */
    public static function cred_connect(?Credentials $cred) : DB {
        if ($cred == NULL) { return DB::from(NULL); }
        return DB::connect($cred->host, $cred->username, $cred->password, $cred->db_name);
    }

    /**
     * @todo: add support for retry.  busy network environments can cause connection failures
     * 
     * @param string $host 
     * @param string $user 
     * @param string $passwd 
     * @param string $db_name 
     * @return DB 
     */
    public static function connect(string $host, string $user, string $passwd, string $db_name) : DB {
        $db = mysqli_init();
        mysqli_options($db, MYSQLI_OPT_CONNECT_TIMEOUT, 3);
        if(mysqli_real_connect($db, $host, $user, $passwd, $db_name)) {
            $db = DB::from($db);
            $db->host = $host;
            $db->user = $user;
            $db->database = $db_name;
            return $db;
        }
        return DB::from(NULL);
    }

    /**
     * calls _qb internally to run a insert/update/delete statement.
     * not for fetching data.  see @fetch instead
     * 
     * @param string $sql raw SQL to run.  be careful, this must be pre-escaped!
     * @param int $mode, one of DB_FETCH_SUCCESS, DB_FETCH_NUM_ROWS, DB_FETCH_INSERT_ID
     * @return DB_FETCH_SUCCESS -1 on error, 1 on success
     *  DB_FETCH_NUM_ROWS -1 on error, 0 if no rows affected, >0 if rows affected
     *  DB_FETCH_INSERT_ID -1 on error, >0 if insert id
     */
    public function unsafe_raw(string $sql, int $mode = DB_FETCH_SUCCESS) : int {
        assert(in_array($mode, [DB_FETCH_SUCCESS, DB_FETCH_NUM_ROWS, DB_FETCH_INSERT_ID], true), "invalid mode: $mode");

        return intval($this->_qb($sql, $mode));
    }

    /**
     * run SQL $sql return result as bool. errors stored tail($this->errors)
     * @return int -1 on error, 0 if no rows affected / no insert id, >0 if rows affected / insert id, 1 = success
     */
    protected function _qb(string $sql, int $return_type = DB_FETCH_SUCCESS) : int {
        assert(!empty($this->_db), "database: {$this->database} is not connected");

        $this->last_stmt = "$sql\n";
        $r = false;
        $affected = -1;
        $errno = 0;
        try {
            if (!$this->_simulation) {
                $r = mysqli_query($this->_db, $sql); 
            }
        }
        // silently swallow exceptions, will catch them in next line
        catch (Exception $ex) { $r = false; }
        if ($r === false) {
            $errno = mysqli_errno($this->_db);
            $err = "[$sql] errno($errno) " . mysqli_error($this->_db);
            $this->errors[] = $err;
            return -1;
        }
        else {
            if ($this->_replay_enabled || $this->_log_enabled || $return_type == DB_FETCH_NUM_ROWS) {
                $affected = mysqli_affected_rows($this->_db);
                if ($this->_log_enabled) {
                    $msg = "# [$sql] errno($errno) affected rows($affected)";
                    $this->logs[] = $msg;
                }
                if ($this->_replay_enabled && $affected > 0) {
                    $this->_replay[] = $sql;
                }
            }
            if ($return_type == DB_FETCH_NUM_ROWS) {
                return intval($affected);
            } else if ($return_type == DB_FETCH_INSERT_ID) {
                $id = intval(mysqli_insert_id($this->_db));
                return ($id == 0) ? -1 : $id;
            }
            return 1;
        }
    }

    /**
     * run SQL $sql return result as bool. errors stored tail($this->errors)
     * convert $sql to SQL result
     */
    protected function _qr(string $sql, $mode = MYSQLI_ASSOC) : SQL {
        assert(! empty($this->_db), "database: {$this->database} is not connected");
        $r = false;
        $errno = 0;
        try {
            if (! $this->_simulation) {
                $r = mysqli_query($this->_db, $sql); 
            }
        }
        // silently swallow exceptions, will catch them in next line
        catch (Exception $ex) { $r = false; }
        if ($r == false || !$r instanceof mysqli_result) {
            $errno = mysqli_errno($this->_db);
            $err = "[$sql] errno($errno) " . mysqli_error($this->_db);
            $this->errors[] = $err;
            return SQL::from(NULL, $sql);
        }
        else {
            if ($this->_log_enabled) {
                $e = mysqli_affected_rows($this->_db);
                // $this->logs[] = $sql;
                $msg = "# [$sql] errno($errno) selected rows($e)";
                $this->logs[] = $msg;
            }
        }

        //return SQL::from(mysqli_fetch_all($r, $mode), $sql);
        return SQL::fetch($r, $sql);
    }

    /**
     * build sql replacing {name} with values from $data[name] = value
     * auto quote values,  use {!name} to not quote the value
     * 
     * $sql = $db->fetch("SELECT * FROM my_table WHERE id = {id} AND name = {name}", ['id' => 1, 'name' => 'bob']);
     * 
     * @see SQL
     * @param string $sql - SELECT QUERY FROM your_table WHERE id = {id} 
     * @param array|object $data - data to replace {params} with, key/values
     * @return SQL - SQL result abstraction
     */
    public function fetch(string $sql, $data = NULL, $mode = MYSQLI_ASSOC) : SQL {
        $new_sql = $this->fetch_to_statement($sql, $data, $mode);

        // runtime errors
        if ($this->_db == NULL) { return SQL::from(NULL, $sql); }
        return $this->_qr($new_sql, $mode);
    }

    /**
     * 
     * @param string $sql 
     * @param mixed $data 
     * @param int $mode 
     * @return string 
     */
    protected function fetch_to_statement(string $sql, $data = NULL, $mode = MYSQLI_ASSOC) : string {
        // programming errors
        //assert(!is_null($data) && !is_array($data) && !is_object($data), "$data must be null, array or object");
        assert(is_null($data) || is_object($data) || (is_array($data) && count(array_filter(array_keys($data), 'is_string')) > 0), print_r($data, true) . " requires an associative array");

        $type = (is_array($data)) ? 'array' : ((is_object($data)) ? 'object' : 'scalar');
        // replace {} with named values from $data, or $this->_x
        $new_sql = preg_replace_callback("/{!?\w+}/", function ($x) use ($data, $type) {
            // strip off the template brackets
            $param = str_replace(array('{', '}'), '', $x[0]);
            $quote_fn = '\ThreadFin\DB\quote';
            // if the parameter should not be quoted, strip off the ! and use id for quote function
            if ($param[0] == "!") {
                $param = substr($param, 1);
                $quote_fn = '\ThreadFin\core\ident';
            }
            // default to the scalar value 
            $data_param = $param;
            if ($type === "array") {
                $data_param = $data[$param]??"NO_SUCH_KEY_$param";
            } else if ($type === "object") {
                $data_param = $data->$param;
            }
            $result = (string)$quote_fn($data_param);
            return $result;
        }, $sql);

        return $new_sql;
    }


    /**
     * delete entries from $table where $data matches
     * @param string $table table name
     * @param array $where key value pairs of column names and values
     * @return int -1 on error, 0 if no rows were deleted, > 0 number rows that were deleted
     */
    public function delete(string $table, array $where) : int {
        $sql = "DELETE FROM $table " . where_clause($where);
        return intval($this->_qb($sql, DB_FETCH_NUM_ROWS));
    }

    /**
     * helper function to create an insert statement
     * @param string $table table name to insert
     * @param array $data key value pairs column -> data
     * @param int $on_duplicate, must be one of DB_DUPLICATE_IGNORE, DB_DUPLICATE_UPDATE
     * @param null|array $no_update kvp of column names to not update on duplicate, key is column name value is true
     * @param null|array $if_null kvp of column names to only update if there are null, key is column name value is true
     * @return string - the resulting SQL
     */
    protected function insert_stmt(string $table, array $data, int $on_duplicate = DB_DUPLICATE_IGNORE, ?array $no_update = null, ?array $if_null = null) : string {
        
        $ignore = "";
        // ignore duplicates
        if ($on_duplicate === DB_DUPLICATE_IGNORE) {
            $ignore = "IGNORE";
        }

        if (array_is_list($data)) {
            $sql = "INSERT $ignore INTO `$table` (`" . join("`,`", array_keys($data)) . 
            "`) VALUES (" . join(",", array_map('\ThreadFin\DB\quote', array_values($data))).")";
        } else {
            $sql = "INSERT $ignore INTO `$table` VALUES (" . join(",", array_map('\ThreadFin\DB\quote', $data)).")";
        }

        // update on duplicate, exclude any PKS
        if ($on_duplicate === DB_DUPLICATE_UPDATE) {
            if (!array_is_list($data)) {
                throw new RuntimeException('Can only update on duplicate update with KVP for $data');
            }
            $update_data = array_diff_key($data, $no_update);
            $suffix = "";
            foreach($update_data as $key => $value) {
                $q_value = quote($value);
                if (isset($if_null[$key])) {
                    $suffix .= "`$key` = IF(`$key` = '' OR `$key` IS NULL, $q_value, `$key`), ";
                } else {
                    $suffix .= "`$key` = $q_value, ";
                }
            }
            if (!empty($suffix)) {
                $sql .= " ON DUPLICATE KEY UPDATE " . substr($suffix, 0, -2);
            }
        }

        return $sql;
    }

    /**
     * insert $data into $table 
     * @param string $table 
     * @param array $kvp 
     * @param int $on_duplicate - DB_DUPLICATE_IGNORE, DB_DUPLICATE_UPDATE. 
     *   IMPORTANT! for update be sure auto incrementing PK is not in $data
     * @return int 
     */
    public function insert(string $table, array $kvp, int $on_duplicate = DB_DUPLICATE_IGNORE) : int {
        $sql = $this->insert_stmt($table, $kvp, $on_duplicate);

        return intval($this->_qb($sql));
    }

    /**
     * return a function that will insert key value pairs into $table.  
     * keys are column names, values are data to insert.
     * @param string $table the table name
     * @param ?array $keys list of allowed key names from the passed $data
     * @return callable(array $data) insert $data into $table - return newly created db id
     */
    public function insert_fn(string $table, ?array $keys = null, bool $ignore_duplicate = true) : callable { 
        $t = $this;
        $ignore = ($ignore_duplicate) ? "IGNORE" : "";
        $prefix = "INSERT $ignore INTO $table ";
        return function(array $data) use ($prefix, &$t, $keys) : int {
            // set flag to ignore dupliactes
            if (array_is_list($data)) {
                $sql = "$prefix VALUES (" . join(",", array_map('\ThreadFin\DB\quote', $data)) . ")";
            } else {
                // filter out unwanted key/values
                if (!empty($keys)) {
                    $data = array_filter($data, bind_r('in_array', $keys), ARRAY_FILTER_USE_KEY);
                }
                $sql = "$prefix (" . join(",", array_keys($data)) . 
                    ") VALUES (" . join(",", array_map('\ThreadFin\DB\quote', array_values($data))) . ")";
            }

            $id = $t->_qb($sql, DB_FETCH_INSERT_ID);
            return $id;
        };
    }

    /**
     * return a function that will upsert key value pairs into $table. will always return the PK id for the row effected 
     * keys are column names, values are data to insert.
     * @param string $table the table name
     * @param ?array $keys list of allowed key names from the passed $data
     * @return callable(array $data) insert $data into $table - return newly created db id
     */
    public function upsert_fn(string $table, ?array $keys = null, string $pk = "id") : callable { 
        $t = $this;
        $prefix = "INSERT INTO $table ";
        return function(array $data) use ($prefix, &$t, $keys, $pk) : int {
            // set flag to ignore dupliactes
            if (array_is_list($data)) {
                $sql = "$prefix VALUES (" . join(',', array_map('\ThreadFin\DB\quote', $data)) . ')';
            } else {
                // filter out unwanted key/values
                if (!empty($keys)) {
                    $data = array_filter($data, bind_r('in_array', $keys), ARRAY_FILTER_USE_KEY);
                }
                //$sql = "$prefix (" . join(',', array_keys($data)) .  ') VALUES (';
				$key_names = "";
				$values = "";
                foreach ($data as $column => $value) {

                    if ($column[0] === '!') {
						$key_names .= substr($column, 1);
                        $values .= $value;
                    } else {
						$key_names .= $column;
                        $values .= quote($value);
                    }
                    $values .= ', ';
                    $key_names .= ', ';
                }
                $values = trim($values, ' ,');
                $key_names = trim($key_names, ' ,');
				$sql = "$prefix ($key_names) VALUES ($values)";
            }

            $sql .= " ON DUPLICATE KEY UPDATE $pk = LAST_INSERT_ID($pk)";

            $id = $t->_qb($sql, DB_FETCH_INSERT_ID);
            echo "[$sql] = $id\n";
            return $id;
        };
    }


    /**
     * TODO: test if we can just pass $this->qb to the closure, instead of $this
     * return a function that will insert key value pairs into $table.
     * does not support {} replacement
     * @param string $table the table name
     * @param array $columns column names in (col1, col2) or (col->data) format
     * @return callable(?array $data) that takes KVP where array_values($columns)
     *         value are indexes into $data. 
     *         pass null as KVP data to run the bulk query and return 1 - success
     *         0 - failure;
     * 
     */
    public function bulk_fn(string $table, array $columns, bool $ignore_duplicate = true) : callable { 
        $t = $this;
        $ignore = ($ignore_duplicate) ? "IGNORE" : "";
        $num_columns = count($columns);
        return function(?array $data = null) use (&$t, $table, $columns, $ignore, $num_columns) : int {
            static $ctr = 0;
            static $sql = "";
            if ($data !== null) {
                $ctr++;
                $sql .= "(";
                $column_count = 0;
                foreach ($columns as $column_name => $key_name) {
                    $sql .= quote($data[$key_name]);
                    $sql .= (++$column_count < $num_columns) ? "," : "),\n";
                }
            }
            if ($ctr > 0 || $ctr > DB_MAX_BULK_INSERT) {
                $stmt = "INSERT $ignore INTO $table (" . join(",", array_keys($columns)) . ") VALUES " . substr($sql, 0, -2);
                $ctr = 0;
                $sql = "";
                return $t->_qb($stmt);
            }
            return $ctr;
        };
    }



    /**
     * update $table and set $data where $where
     * @return int num updated rows (determined by $return_type parameter)
     */
    public function update(string $table, array $data, array $where, int $return_type = DB_FETCH_NUM_ROWS) : int {
        // unset all where keys in data. this makes no sense when where is a PK
        //do_for_all_key($where, function ($x) use (&$data) { unset($data[$x]); });
        array_walk($where, function ($value, $key) use (&$data) { unset($data[$key]); });

        // glue does the escaping for us here...
        $sql = "UPDATE `$table` set " . glue($data) .  where_clause($where);
        return $this->_qb($sql, $return_type);
    }

    /**
     * store object data into table.  data must have public members and have the 
     * same names as the table
     * @return bool true if the SQL write is successful
     */
    public function store(string $table, Object $data, int $on_duplicate = DB_DUPLICATE_IGNORE) : int {
        assert(is_resource($this->_db), "database not connected");

        // TODO: this should be it's own object to array function with tests
        $r = new \ReflectionClass($data);
        $props = $r->getProperties(\ReflectionProperty::IS_PUBLIC);
        $no_updates = [];
        $if_null = [];
        // turn the object into an array, update PKS for update list
        $kvp = array_reduce($props, function($kvp, $item) use ($data, $on_duplicate, &$no_updates, &$if_null) {
            $name = $item->name;
            $attrs = $item->getAttributes();

            foreach ($attrs as $attr) {
                $attribute = $attr->getName();
                switch($attribute) {
                    case "ThreadFinDB\NoUpdate":
                        $no_updates[$name] = true;
                        break;
                    case "ThreadFinDB\NotNull":
                        if (!isset($data->$name) || empty($data->$name)) {
                            return $kvp;
                        }
                        break;
                    case "ThreadFinDB\IfNull":
                        $if_null[$name] = true;
                }
            }

            if (isset($data->$name)) {
                $kvp[$name] = $data->$name;
            }
            return $kvp;
        }, []);

        $sql = $this->insert_stmt($table, $kvp, $on_duplicate, $no_updates, $if_null);
        return $this->_qb($sql, DB_FETCH_INSERT_ID);
    }

    /**
     * close the database handle and write the transaction log if we have one
     * @return void 
     */
    public function close() : void {
        if (!empty($this->_db)) { mysqli_close($this->_db); $this->_db = NULL; }
        if (SQL_ERROR_FILE) {
            if (count($this->errors) > 0) {
                $errors = array_filter($this->errors, function($x) { return stripos($x, "Duplicate") != false; });
                if (count($this->errors) > 0) {
                    file_put_contents(SQL_ERROR_FILE, print_r($errors, true), FILE_APPEND);
                }
            }
        }
        if (!empty($this->_replay_file)) {
            echo "writing replay log to: ". $this->_replay_file . "\n";
            if (stristr($this->_replay_file, ".php")) {
                die("refusing to write replay log to: ". $this->_replay_file);
            }
        }
        if (count($this->_replay) >= 1) { 
            $attempts = 10;
            while ($attempts-- > 0) {
                echo "writing replay log to: ". $this->_replay_file . "\n";
                $fp = fopen($this->_replay_file, "a+");
                if (flock($fp, LOCK_EX)) {  // acquire an exclusive lock
                    fwrite($fp, "\n".implode(";\n", $this->_replay).";\n");
                    fflush($fp);            // flush output before releasing the lock
                    flock($fp, LOCK_UN);    // release the lock
                    fclose($fp);
                    $attempts = -1;
                } else {
                    fclose($fp);
                    sleep(1);
                    echo "!!!! PANIC !!!! Couldn't get the lock for replay file!\n";
                }
            }
            //file_put_contents($this->_replay_file, "\n".implode(";\n", $this->logs).";\n", FILE_APPEND);
        }
    }
}


/**
 * SQL result abstraction
 */
class SQL implements \ArrayAccess, \Iterator, \SeekableIterator, \Countable {
    protected $_x;
    protected $_data = NULL;
    protected $_position = 0;
    protected $_errors;
    protected $_sql;
    protected $_len;
    protected $_fetch_all;
    protected $_mysqli_result;

    public function count(): int {
        if (empty($this->_mysqli_result)) {
            return 0;
        }
        return intval(mysqli_num_rows($this->_mysqli_result));
    }

    public function empty(): bool {
        return ($this->count() == 0);
    }

    public function as_array(): array {
        return mysqli_fetch_all($this->_mysqli_result, MYSQLI_ASSOC);
    }

    public function offsetExists(mixed $offset): bool {
        return $offset <= $this->_len;
    }

    public function offsetGet(mixed $offset): array {
        $this->_mysqli_result->data_seek($offset);
        return $this->_mysqli_result->fetch_assoc();
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        throw new OutOfBoundsException("set not implemented");
    }

    public function offsetUnset(mixed $offset): void {
        throw new OutOfBoundsException("unset not implemented");
    }

    /**
     * create a new SQL result abstraction from a SQL associative result
     * @param null|array $x 
     * @param string $sql the sql that generated the result
     * @return SQL 
     */
    public static function from(?array $x, string $in_sql="", bool $fetch_all = true) : SQL { 
        $sql = new SQL();
        $sql->_x = $x;
        $sql->_len < (is_array($x)) ? count($x) : 0;
        $sql->_sql = $in_sql;
        $sql->_fetch_all = $fetch_all;
        return $sql; 
    }

    public static function fetch(mysqli_result $result, string $sql) : SQL {
        $sql = new SQL();
        $sql->_mysqli_result = $result;
        $sql->_sql = $sql;
        $sql->_x = $result->fetch_assoc();
        $sql->_len = $result->num_rows;
        return $sql;
    }

    
    /**
     * set internal dataset to row  at current row index 
     */
    public function seek(int $offset = 0) : void {
        if (!$this->_mysqli_result->data_seek($offset)) {
            $this->_errors[] = "seek to [$offset] failed";
        }
    }

    public function current() : array {
        return $this->_x;
    }

    public function key() : int {
        return $this->_position;
    }

    public function next() : void {
        $this->_position++;
        if ($this->_mysqli_result) {
            $this->_x = $this->_mysqli_result->fetch_assoc();
        }
    }

    public function rewind() : void {
        $this->_position = 0;
        if ($this->_mysqli_result) {
            $this->_mysqli_result->data_seek(0);
            $this->_x = $this->_mysqli_result->fetch_assoc();
        }
    }

    public function valid() : bool {
        return $this->_position < $this->_len;
    }

    /**
     * @return MaybeStr of column $name at current row index
     */
    public function col(string $name) : MaybeStr {
        if (isset($this->_x[$this->_position])) {
            return MaybeStr::of($this->_x[$this->_position][$name]??NULL);
        } 
        return MaybeStr::of(NULL);
    }

    /**
     * @return bool true if column name has a row with at least one value of $value 
     *
    public function in_set(string $name, string $value) : bool {
        return array_reduce($this->_x, function ($carry, $item) use ($name, $value) {
            return $carry || $item[$name] == $value;
        }, false);
    }

    /**
     * @return MaybeA of result row at $idx or current row indx
     *
    public function row(?int $idx = NULL) : MaybeA {
        $idx = ($idx !== NULL) ? $idx : $this->_position;
        if (isset($this->_x[$idx])) {
            return MaybeA::of($this->_x[$idx]);
        }
        return MaybeA::of(NULL);
    }

    /**
     * return true if data has a row at index $idx
     *
    public function has_row(int $idx = 0) : bool {
        return isset($this->_x[$idx]);
    }

    /**
     * call $fn on current $this->_data (see set_row, set_col)
     * @param bool $spread if true, call $fn(...$this->_data)
     *
    public function ondata(callable $fn, bool $spread = false) : SQL {
        if (!empty($this->_data)) {
            $this->_data = 
                ($spread) ?
                $fn(...$this->_data) :
                $fn($this->_data);
        } else {
            $this->_errors[] = "wont call " . func_name($fn) . " on data : " . var_export($this->_data, true);
        }

        return $this;
    }

    /**
     * map $fn on each row in entire result (works on raw result, no set necessary)
     *
    public function map(callable $fn) : array {
        if (is_array($this->_x) && !empty($this->_x)) {
            return array_map($fn, $this->_x);
        } else {
            $this->_errors[] = "wont call " . func_name($fn) . " on data : " . var_export($this->_data, true);
        }
        return [];
    }

    /**
     * reduce $fn($carry, $item) on each row in entire result (works on raw result, no set necessary)
     * $fn may return any type, but should be a string in 99% cases
     * @return mixed return type of $fn, false if rows (_x) is empty
     *
    public function reduce(callable $fn, $initial = "") : mixed {
        if (is_array($this->_x) && !empty($this->_x)) {
            return array_reduce($this->_x, $fn, $initial);
        } else {
            $this->_errors[] = "wont call " . func_name($fn) . " on data : " . var_export($this->_data, true);
        }
        return false;
    }
    */

    /*
    // run an a function that has external effect on current data
    public function effect(callable $fn) : SQL { 
        if (!empty($this->_data)) { $fn($this->_data); } return $this;
    }
    // set data to NULL if $fn returns false
    public function if(callable $fn) : SQL {
        if ($fn($this->_data) === false) { $this->_data = NULL; } return $this;
    }
    // set data to NULL if $fn returns true
    public function if_not(callable $fn) : SQL {
        if ($fn($this->_data) !== false) { $this->_data = NULL; } return $this;
    }
    // return true if we have an empty result set
    public function empty() : bool {
        return empty($this->_x);
    } 
    public function count() : int {
        return is_array($this->_x) ? count($this->_x) : 0;
    } 
    // get all errors
    public function errors() : array {
        return $this->_errors;
    }
    // size of result set
    public function size() : int {
        return is_array($this->_x) ? count($this->_x) : ((empty($this->_x)) ? 0 : 1);
    }
    public function data() : ?array {
        return $this->_x;
    }
    public function __toString() : string {
        return (string)$this->_data;
    }
    */
    public function close() {
        if ($this->_mysqli_result) {
            $this->_mysqli_result->free();
        }
        $this->_mysqli_result = null;
    }
}


/**
 * database backup checkpoint offset
 * @package ThreadFinDB
 */
class Offset {
    public $table;
    public $limit_sz = 0;
    public $offset = 0;
    const TABLE_COMPLETE = -1;

    public function __construct(string $table, int $limit_sz = 300) {
        $this->limit_sz = $limit_sz;
        $this->table = $table;
    }

    /**
     * update a table saved offset
     * @param string $table 
     * @param int $offset 
     * @param int $limit 
     * @return void 
     */
    public function set_check_point(int $offset) {
        $this->offset = $offset;
    }

    /**
     * @param string $table 
     * @return bool true if the table is completely dumped, false if not or incomplete
     */
    public function is_table_complete() : bool {
        return $this->offset == Offset::TABLE_COMPLETE;
    }
}

/**
 * function suitable for database dumping to gz compressed output file
 * this is equal to calling stream_output_fn($data, $stream, "gzwrite")
 * @param string $data 
 * @param mixed $stream 
 * @return int -1 on error, else total byte length written to stream across all writes
 */
function gz_output_fn(?string $data, $stream) : int {
    return stream_output_fn($data, $stream, "gzwrite");
}

/**
 * function suitable for database dumping to gz compressed output file
 * @param string $data 
 * @param mixed $stream 
 * @return int -1 on error, else total byte length written to stream across all writes
 */
function stream_output_fn(?string $data, $stream, $fn = "fwrite") : int {
    assert(is_resource($stream), "stream must be a resource");
    static $total_bytes = 0;

    if ($data && strlen($data) > 0) {
        $bytes = $fn($stream, $data);
        if (!$bytes) {
            return -1;
        }
        $total_bytes += $bytes;
    }
    return $total_bytes;
}


/**
 * dump a single SQL table 100 rows at a time
 * @param DB $db 
 * @param string $db_dump_file 
 * @param mixed $row 
 * @return int number of uncompressed bytes written
 */
function dump_table(DB $db, callable $write_fn, array $row) : ?Offset {
    $idx = 0;
    $limit = 300;
    $db_name = $db->database;
    $table = $row["Tables_in_$db_name"];
    $offset = new Offset($table);

    // find number of rows
    $num_rows = intval($db->fetch("SELECT count(*) as count FROM $table")->col("count")());
    // the create statement
    $create = $db->fetch("SHOW CREATE TABLE $table");
    // table header line
    $write_fn("# Export of $table\n# $num_rows rows in $table\n");
    // drop table if it exists
    $write_fn("DROP TABLE IF EXISTS `$table`;\n");
    // add create statement
    $write_fn($create->col("Create Table")() . ";\n\n");

    // insert $limit rows at a time
    while($idx < $num_rows) {
        $limit = min($limit, $num_rows - $idx);
        $rows = $db->fetch("SELECT * FROM $table LIMIT $limit OFFSET $idx", NULL, MYSQLI_NUM);
        // create the output string
        $result = $rows->reduce(function(string $carry, array $row) {
            return $carry . "(" . implode(",", array_map('\ThreadFin\DB\quote', $row)) . "),\n";
        }, "INSERT IGNORE INTO $table VALUES");
        // write to the output stream
        $bytes_written = $write_fn(substr($result, 0, -2) . ";\n\n");
        if ($bytes_written < 0 || $bytes_written > 1048576*20) {
            return $offset;
        }

        // increment the offset by the limit
        $idx += $limit;
        $offset->set_check_point($idx);

        // let the database rest a second
        usleep(100000);
    }
    $offset->set_check_point(Offset::TABLE_COMPLETE);

    return $offset;
}

/**
 * dump all database tables to the function $write_fn
 * @param Credentials $cred Access credentials to the database
 * @param string $db_name the name of the database (eg, wordpress)
 * @param callable $write_fn a function that takes a string and 
 *      writes it to the output stream (fwrite, gzwrite, etc)
 * @return array of Offset objects. one for each table in $db_name
 */
function dump_database(Credentials $cred, string $db_name, callable $write_fn, int $max_bytes = 1024*1024*50) : array {
    $header = "# Database export of ($db_name) began at UTC: " 
            . date(DATE_RFC3339) . "\n# UTC tv: " . utc_time() . "\n\n";
    $init_sql = "SET NAMES 'utf8'\n";

    $db = DB::cred_connect($cred);
    $db->unsafe_raw($init_sql);
    $tables = $db->fetch("SHOW TABLES");
    $write_fn($header . $init_sql);

    $t = bind_l('\ThreadFin\DB\dump_table', $db, $write_fn);
    $data = $tables->map($t);
    return (!$data || empty($data) || !is_array($data)) ? [] : $data;
}
