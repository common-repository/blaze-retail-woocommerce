<?php

/**
 * WooCommerce BLAZE Error Logger
 *
 * @author      BLAZE
 * @category    API
 * @package     WooCommerce/API
 */
if (!defined('ABSPATH'))
    exit;

class Blaze_error_logger {
    /*
     * path to log file
     */
    private $filedir;

    /*
     * path to error file
     */
    private $error_file;


    /*
     * path to log file
     */
    private $log_file;

    /*
     * path to log file
     */
    private $api_log_file;


    private $timer;

    public function __construct() {
        $this->filedir = plugin_dir_path(__FILE__);
        $this->log_file = $this->filedir . 'log.txt';
        $this->error_file = $this->filedir . 'errors.txt';
        $this->api_log_file = $this->filedir . 'execute_log.txt'; 
        $this->timer = microtime(true);
    }

    public function timer_start() {
        $this->timer = microtime(true);
    }

    
    public function timer_stop($name) {
        $time_end = microtime(true);
        $time = $time_end - $this->timer;
        $this->log($name . " took: " . $this->to_ms($time) . "ms");
    }

    private function to_ms($time) {
        return round($time*1000);
    }

    public function set_error_handler() {
        set_error_handler(array($this, 'err_handler'));
        register_shutdown_function(array($this, 'shut_down_handler'));
    }

    public function remove_error_handler() {
        restore_error_handler();
    }

    public function log_execute_api($method, $output, $response) {

        $time = date('Y-m-d H:i:s', time());

        $output_array = array(
            'time' => $time,
            'method' => $method,
            'output' => $output,
            'response' => $response
        );

        $message = var_export($output_array, true) . PHP_EOL;
        $message .= '-----------------------------------------------------------------------------------' . PHP_EOL;
        $this->write_log_file($this->api_log_file, $message);
    }

    public function err_handler($errno, $errmsg, $filename, $linenum) {
        $debugMode = get_option('blaze_debug_log') == 'yes';

        
        $filename = str_replace($_SERVER['DOCUMENT_ROOT'], '', $filename);
        $time = date('Y-m-d H:i:s', time());
        $message = "$errmsg = $filename = $linenum = $time" . PHP_EOL;

        if($debugMode) {
            $this->write_log_file($this->error_file, $message);
        }
    }

    public function shut_down_handler() {
        $error = error_get_last();
        $debugMode = get_option('blaze_debug_log') == 'yes';
        if ($error && $error["type"] == E_ERROR) {
            $time = date('Y-m-d H:i:s', time());
            $message = '-----------------------------------------------------------------------------------' . PHP_EOL;
            $message .= $error["message"] . ' = ' . $error["file"] . ' = ' . $error["line"] . ' = ' . $time . PHP_EOL;
            $message .= '-----------------------------------------------------------------------------------' . PHP_EOL;
            
            if($debugMode) {
                $this->write_log_file($this->error_file, $message);
            }
        }
    }

    public function log($message) {
        
        $debugMode = get_option('blaze_debug_log') == 'yes';
        $time = date('Y-m-d H:i:s', time());
        
        if($debugMode) {
            $this->write_log_file($this->log_file, "[".$time."] : ". $message . "\n");
        }
    }

    private function write_log_file($filepath, $errmsg) {
        @file_put_contents($filepath, $errmsg, FILE_APPEND | LOCK_EX);
    }

    public function file_force_download($file) {
        if (file_exists($file)) {
            // clear PHP buffer
            if (ob_get_level()) {
                ob_end_clean();
            }

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename=' . basename($file));
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));

            readfile($file);
            exit;
        } else {
            echo "File not exist!";
            exit;
        }
    }

    public function delete_file($file) {
        if (file_exists($file)) {
            if (@unlink($file)) {
                echo "Deleted file ";
            } else {
                echo "File can't be deleted";
            }
            exit;
        } else {
            echo "File not exist!";
            exit;
        }
    }

    /*
     * Get error log file
     */

    public function get_error_log() {
        $this->file_force_download($this->error_file);
    }


    /*
     * Get debug log file
     */

    public function get_debug_log() {
        $this->file_force_download($this->log_file);
    }

    /*
     * Get execute api log file
     */

    public function get_execute_api_log() {
        $this->file_force_download($this->api_log_file);
    }

    /*
     * Delete error log file
     */

    public function delete_error_log() {
        $this->delete_file($this->error_file);
    }

    /*
     * Delete execute api log file
     */

    public function delete_execute_api_log() {
        $this->delete_file($this->api_log_file);
    }

    
    /*
     * Delete execute log file
     */

    public function delete_debug_log() {
        $this->delete_file($this->log_file);
    }
}
