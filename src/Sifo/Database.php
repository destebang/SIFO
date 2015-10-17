<?php

/**
 * LICENSE.
 *
 * Copyright 2010 Albert Lombarte
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */
namespace Sifo;

use Sifo\Exception\Http\InternalServerError;
use Sifo\LoadBalancer\LoadBalancerADODB;

// Some stuff needed by ADODb:
$ADODB_CACHE_DIR = ROOT_PATH.'/cache';

/**
 * Handles the interaction with a database using ADODB, and adds load balancing. Many drivers are supported, see ADODB.
 */
class Database
{
    private static $adodb = null;
    private static $instance = null;
    public static $launch_in_master = false;

    /**
     * Stores the current query type needed.
     *
     * @var int
     */
    private static $destination_type;

    /**
     * Identifies a query as write operation and is sent to the master.
     *
     * @var int
     */
    const TYPE_MASTER = 'master';

    /**
     * Identifies a query as read operation and is sent to a slave.
     *
     * @var int
     */
    const TYPE_SLAVE = 'slave';

    /**
     * No need to identify a query because is a single server.
     *
     * @var int
     */
    const TYPE_SINGLE_SERVER = 'single_server';

    // Methods capable to be marked as duplicates:
    // Input in lower case:
    private $methods_whitout_duplicated_validation = array(
        'prepare',
        'affected_rows',
        'insert_id',
        'errorno',
        'errormsg',
    );

    /**
     * Dummy Singleton.
     *
     * @return Db
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self(); //create class instance
        }

        return self::$instance;
    }

    /**
     * Creates a DB object if necessary depending on the current operation requested.
     * an action is triggered.
     */
    private function _lazyLoadAdodbConnection()
    {
        $db_params = Domains::getInstance()->getDatabaseParams();

        // When adodb is instantiated for the first time the object becomes in an array with a type of operation.
        if (!is_array(self::$adodb)) {
            include_once ROOT_PATH."/vendor/sifophp/sifo/src/adodb/adodb-exceptions.inc.php"; //include exceptions for php5
            include_once ROOT_PATH."/vendor/sifophp/sifo/src/adodb/adodb.inc.php";

            if (!isset($db_params['profile'])) {
                // No Master/Slave schema expected:
                self::$destination_type = self::TYPE_SINGLE_SERVER;
            }
        }

        if (!isset(self::$adodb[self::$destination_type])) {
            Benchmark::getInstance()->timingStart('db_connections');

            try {
                if (self::TYPE_SINGLE_SERVER == self::$destination_type) {
                    $db_params = Domains::getInstance()->getDatabaseParams();
                } else {
                    // Instance uses MASTER/SLAVE schema:

                    $db_profiles = Config::getInstance()->getConfig('db_profiles', $db_params['profile']);

                    if (self::$launch_in_master || self::TYPE_MASTER == self::$destination_type) {
                        $db_params = $db_profiles['master'];
                    } else {
                        $lb = new LoadBalancerADODB();
                        $lb->setNodes($db_profiles['slaves']);
                        $selected_slave = $lb->get();
                        $db_params = $db_profiles['slaves'][$selected_slave];
                    }
                }
                self::$adodb[self::$destination_type] = \NewADOConnection($db_params['db_driver']);
                self::$adodb[self::$destination_type]->Connect($db_params['db_host'], $db_params['db_user'], $db_params['db_password'], $db_params['db_name']); //connect to database constants are taken from config
                if (isset($db_params['db_init_commands']) && is_array($db_params['db_init_commands'])) {
                    foreach ($db_params['db_init_commands'] as $command) {
                        self::$adodb[self::$destination_type]->Execute($command);
                    }
                }
                $ADODB_FETCH_MODE = ADODB_FETCH_ASSOC;
            }
                // If connection to database fails throw a SIFO 500 error.
            catch (\ADODB_Exception $e) {
                throw new InternalServerError($e->getMessage(), $e->getCode());
            }

            Benchmark::getInstance()->timingCurrentToRegistry('db_connections');
        }
    }

    /**
     * Return properly escaped string to be passed to SQL query.
     *
     * @param string $string
     *
     * @return string
     */
    public function escapeSqlString($string)
    {
        $this->_lazyLoadAdodbConnection();

        return self::$adodb[self::$destination_type]->qstr($string);
    }

    public function __call($method, $args) //call adodb methods
    {
        // Method provides a valid comment to associate to this query:
        if (isset($args[1]) && is_array($args[1]) && key_exists('tag', $args[1])) {
            // Arg could be a single string, not an array. Do not do isset($args[1]['tag'])

            $tag = $args[1]['tag'];
            unset($args[1]['tag']);
        } else {
            // No comment provided by programmer, set a default comment:
            $tag = 'Query from '.get_class($this).' ('.$this->__getMethodName($this).')';
        }

        // Clean '?' character from SQL Query TAG (to avoid problems with AdoDB bindings).
        $tag = str_replace('?', '', $tag);

        $query = ''; // Methods like Affected_Rows that don't have a query associated nor $args.
        $read_operation = false;

        // What kind of query are we passing? Goes to master o to slave:
        if (isset($args[0])) {
            // Prepend comment to the beggining of the query. Helps when looking debug and error.log:
            $args[0] = $query = "/* {$tag} */\n{$args[0]}";
            $query = trim(trim(trim(preg_replace('/\/\*.*\*\//', '', $args[0])), '('));
            $read_operation = preg_match('/^SELECT|^SHOW |^DESC /i', $query);
        }

        // Query goes to a single server configuration? to a master? a slave?
        if (self::TYPE_SINGLE_SERVER != self::$destination_type) {
            self::$destination_type = (($read_operation && false == self::$launch_in_master) ? self::TYPE_SLAVE : self::TYPE_MASTER);
            // Some methods must be triggered in the master always.
            if (in_array($method, array('Affected_Rows', 'Insert_ID'))) {
                self::$destination_type = self::TYPE_MASTER;
            }
        }

        Benchmark::getInstance()->timingStart('db_queries');

        $this->_lazyLoadAdodbConnection();

        try {
            $answer = call_user_func_array(array(self::$adodb[self::$destination_type], $method), $args);
        } catch (\ADODB_Exception $e) {
            $answer = false;
            $error = $e->getMessage();

            // Log mysql_errors to disk:
            $this->writeDiskLog($error);

            // Command Line scripts show the exception since there is no debug to getvacvar it.
            if (class_exists('Sifo\CLBootstrap', false)) {
                throw $e;
            }
        }

        if ($answer && ('GetRow' == $method || 'GetOne' == $method)) {
            $resultset = array($answer);
        } else {
            $resultset = $answer;
        }

        $this->queryDebug($resultset, $tag, $method, $read_operation, isset($error) ? $error : null);

        // Reset queries in master flag:
        self::$launch_in_master = false;

        return $answer;
    }

    public function __get($property)
    {
        $this->_lazyLoadAdodbConnection();

        return self::$adodb[self::$destination_type]->$property;
    }

    public function __set($property, $value)
    {
        $this->_lazyLoadAdodbConnection();
        self::$adodb[self::$destination_type][$property] = $value;
    }

    private function __clone()
    {
    }

    private function __getMethodName($object)
    {
        $trace_steps = debug_backtrace();
        $class_name = get_class($object);
        foreach (array_reverse($trace_steps) as $step) {
            if ((isset($step['class'])) && ($step['class'] == $class_name)) {
                return $step['function'];
            }
        }

        return 'undefined';
    }

    /**
     * Forces next query (only one) to be executed in the master.
     */
    public function nextQueryInMaster()
    {
        return self::$launch_in_master = true;
    }

    /**
     * Close database connection.
     */
    protected function closeConnectionDatabase()
    {
        $this->close();
        // Unset current connection. In the next query execution it will reconnect automatically.
        unset(self::$adodb[self::$destination_type]);
    }

    /**
     * Set Query Debug. Used in '__call' method. It checks if dev mode is enabled and then stores debug data in registry.
     *
     * @param $resultset
     * @param $tag
     * @param $method
     * @param $read_operation
     * @param $error
     */
    protected function queryDebug($resultset, $tag, $method, $read_operation, $error)
    {
        if (!Domains::getInstance()->getDebugMode()) {
            return false;
        }

        $query = self::$adodb[self::$destination_type]->_querySQL;

        $query_time = Benchmark::getInstance()->timingCurrentToRegistry('db_queries');

        $debug_query = array(
            'tag' => $tag,
            'sql' => in_array(strtolower($method), $this->methods_whitout_duplicated_validation) ? $method : $query,
            'type' => ($read_operation ? 'read' : 'write'),
            'destination' => self::$destination_type,
            'host' => self::$adodb[self::$destination_type]->host,
            'database' => self::$adodb[self::$destination_type]->database,
            'user' => self::$adodb[self::$destination_type]->user,
            'controller' => $this->getCallerClass(),
            // Show a table with the method name and number (functions: Affected_Rows, Last_InsertID
            'resultset' => is_integer($resultset) ? array(array($method => $resultset)) : $resultset,
            'time' => $query_time,
            'error' => (isset($error) ? $error : false),
            'duplicated' => false,
        );

        if ($debug_query['type'] == 'read') {
            $debug_query['rows_num'] = count($resultset);
        } else {
            $debug_query['rows_num'] = 0;
            if ($method != 'close') {
                $debug_query['rows_num'] = self::$adodb[self::$destination_type]->Affected_Rows();
            }
        }

        // Check duplicated queries.
        if (!in_array(strtolower($method), $this->methods_whitout_duplicated_validation)) {
            $queries_executed = Debug::get('executed_queries');
            if (!empty($queries_executed) && isset($queries_executed[ $debug_query['sql'] ])) {
                $debug_query['duplicated'] = true;
                Debug::push('duplicated_queries', 1);
            }
        }
        Debug::subSet('executed_queries', $debug_query['sql'], 1);

        // Save query info in debug and add query errors if it's necessary.
        Debug::push('queries', $debug_query);
        if (isset($error)) {
            Debug::push('queries_errors', $error);
        }
    }

    /**
     * Build the caller classes stack.
     *
     * @return string
     */
    public function getCallerClass()
    {
        $trace = debug_backtrace();
        $i = 1;
        foreach ($trace as $steps) {
            if (!isset($steps['class'])) {
                $steps['class'] = 'Undefined '.$i;
                ++$i;
            }
            $classes[$steps['class']] = $steps['class'];
        }

        return implode(' > ', array_slice($classes, 0, 4));
    }

    /**
     * Log mysql_errors to disk:.
     *
     * @param $error
     */
    protected function writeDiskLog($error)
    {
        $date = date('d-m-Y H:i:s');
        $referer = Filter\Server::getInstance()->getString('HTTP_REFERER');
        $current_url = Filter\Server::getInstance()->getString('SCRIPT_URI');

        // Log mysql_errors to disk:
        $message = <<<MESSAGE
================================
Date: $date
URL: $current_url
Referer: $referer

Error: $error
MESSAGE;

        $database_data = Domains::getInstance()->getDatabaseParams();
        $path = !empty($database_data['error_log_path']) ? $database_data['error_log_path'] : ROOT_PATH.'/logs/errors_database.log';

        file_put_contents($path, $message, FILE_APPEND);
    }
}
