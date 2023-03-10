<?php

namespace RRZE\Log;

defined('ABSPATH') || exit;

use RRZE\Log\File\Flock;
use RRZE\Log\File\FlockException;

class debugLogParser
{
    /**
     * [protected description]
     * @var object
     */
    protected $options;

    /**
     * [protected description]
     * @var mixed null|\WP_Error
     */
    protected $error = null;

    /**
     * [protected description]
     * @var mixed null|\SplFileObject
     */
    protected $file = null;

    protected $maxFileSize = 10240000; // 10Mb

    /**
     * [protected description]
     * @var array
     */
    protected $search;

    /**
     * [protected description]
     * @var integer
     */
    protected $offset;

    /**
     * [protected description]
     * @var integer
     */
    protected $count;

    /**
     * [protected description]
     * @var integer
     */
    protected $totalLines = 0;

    /**
     * [__construct description]
     * @param string  $filename [description]
     * @param array   $search   [description]
     * @param integer $offset   [description]
     * @param integer $count    [description]
     */
    public function __construct($filename, $search = [], $offset = 0, $count = -1)
    {
        $this->offset = $offset;
        $this->count = $count;
        $this->search = $search;

        if (!file_exists($filename)) {
            $this->error = new \WP_Error('rrze_log_file', __('Log file not found.', 'rrze-log'));
        } else {
            if (filesize($filename) > $this->maxFileSize) {
                return false;
            }    
            $this->file = new \SplFileObject($filename);
            $this->file->setFlags(
                \SplFileObject::READ_AHEAD |
                    \SplFileObject::SKIP_EMPTY
            );
        }
    }

    public function getItems()
    {
        if (is_wp_error($this->error)) {
            return $this->error;
        }
        $errors = $this->processFileContent();
        $lines = [];
        foreach ($errors as $line) {
            $searchStr = json_encode($line);
            if (!$this->search || $this->search($searchStr)) {
                $details = explode('§§§', $line['details']);
                $lines[] = [
                    'level' => $line['level'],
                    'message' => $details[0],
                    'datetime' => $line['occurrences'][0],
                    'details' => $details,
                    'ocurrencies' => $line['occurrences']
                ];
            }
        }
        $this->totalLines = count($lines);
        if (count($lines) >= $this->offset) {
            $limitIterator = new \LimitIterator(new \ArrayIterator($lines), $this->offset, $this->count);
        } else {
            $limitIterator = new \LimitIterator(new \ArrayIterator([]));
        }
        return $limitIterator;
    }

    /**
     * [search description]
     * @param  string $haystack [description]
     * @return boolean           [description]
     */
    protected function search($haystack)
    {
        $find = true;
        foreach ($this->search as $needle) {
            if (is_array($needle) && !empty($needle)) {
                foreach ($needle as $str) {
                    if (mb_stripos($haystack, $str) === false) {
                        $find = $find && false;
                    } else {
                        $find = $find && true;
                    }
                }
            } else {
                if (mb_stripos($haystack, $needle) === false) {
                    $find = $find && false;
                }
            }
        }
        return $find;
    }

    protected function processFileContent()
    {
        // Read the errors log file
        $logSize = $this->file->getSize();
        if (!$logSize) {
            return [];
        }

        $content = $this->file->fread($logSize);
        // Certain error message contains a string, 
        // which will make the following split via explode() to split lines 
        // at places in the message it's not supposed to. 
        // So it will temporarily be replaced with another string.
        $content = str_replace("[]", "^^^^", $content);
        $content = str_replace("[\\", "^\\", $content);
        $content = str_replace("[\"", "^\"", $content);
        $content = str_replace("[internal function]", "^internal function^", $content);

        // Split content without using PHP_EOL to preserve the stack traces 
        // for PHP Fatal Errors among other things.
        $lines = explode("[", $content);
        $prependLines = [];

        // Pluck out the last 100k entries, the newest entry is last.
        $lines = array_slice($lines, -100000);

        foreach ($lines as $line) {
            if (!empty($line)) {
                // Replace ABSPATH
                $line = str_replace(ABSPATH, ".../", $line);

                // Add '@@@' as marker/separator after time stamp.
                $line = str_replace("UTC]", "UTC]@@@", $line);

                // Add '§§§' as marker/separator after time stamp.
                $line = str_replace("Stack trace:", "§§§Stack trace:", $line);

                if (strpos($line, 'PHP Fatal') !== false) {
                    // Add '§§§' as marker/separator on PHP Fatal error's stack trace lines.
                    $line = str_replace("#", "§§§#", $line);
                }

                // Remove §§§ on certain error messages.
                $line = str_replace("Argument §§§#", "Argument #", $line);
                $line = str_replace("parameter §§§#", "parameter #", $line);
                $line = str_replace("the §§§#", "the #", $line);

                // Reverse the temporary replacement of strings.
                $line = str_replace("^^^^", "[]", $line);
                $line = str_replace("^\\", "[\\", $line);
                $line = str_replace("^\"", "[\"", $line);
                $line = str_replace("^internal function^", "[internal function]", $line);

                // Put back the missing '[' after explode operation
                $prependLine = '[' . $line;

                $prependLines[] = $prependLine;
            }
        }

        // Reverse the order of the entries, so the newest entry is first.
        $latestLines = array_reverse($prependLines);

        // Will hold error details types
        $errorList = [];

        foreach ($latestLines as $line) {
            // Split the line using the '@@@' marker/separator defined earlier. 
            // '@@@' will be deleted by explode().
            $line = explode("@@@ ", trim($line));

            $timestamp = $line[0];
            //$timestamp = str_replace(["[", "]"], "", $line[0]);

            // Initialize error-related variables
            $error = '';
            //$errorFile = '';

            if (array_key_exists('1', $line)) {
                $error = $line[1];
            } else {
                $error = 'No error message.';
            }

            if ((false !== strpos($error, 'PHP Fatal')) || (false !== strpos($error, 'FATAL')) || (false !== strpos($error, 'E_ERROR'))) {
                $errorLevel = 'FATAL';
                $errorDetails = str_replace("PHP Fatal error: ", "", $error);
                $errorDetails = str_replace("PHP Fatal: ", "", $errorDetails);
                $errorDetails = str_replace("FATAL ", "", $errorDetails);
                $errorDetails = str_replace("E_ERROR: ", "", $errorDetails);
            } elseif ((false !== strpos($error, 'PHP Warning')) || (false !== strpos($error, 'E_WARNING'))) {
                $errorLevel = 'WARNING';
                $errorDetails = str_replace("PHP Warning: ", "", $error);
                $errorDetails = str_replace("E_WARNING: ", "", $errorDetails);
            } elseif ((false !== strpos($error, 'PHP Notice')) || (false !== strpos($error, 'E_NOTICE'))) {
                $errorLevel = 'NOTICE';
                $errorDetails = str_replace("PHP Notice: ", "", $error);
                $errorDetails = str_replace("E_NOTICE: ", "", $errorDetails);
            } elseif (false !== strpos($error, 'PHP Deprecated')) {
                $errorLevel = 'DEPRECATED';
                $errorDetails = str_replace("PHP Deprecated: ", "", $error);
            } elseif ((false !== strpos($error, 'PHP Parse')) || (false !== strpos($error, 'E_PARSE'))) {
                $errorLevel = 'PARSE';
                $errorDetails = str_replace("PHP Parse error: ", "", $error);
                $errorDetails = str_replace("E_PARSE: ", "", $errorDetails);
            } elseif (false !== strpos($error, 'EXCEPTION:')) {
                $errorLevel = 'EXCEPTION';
                $errorDetails = str_replace("EXCEPTION: ", "", $error);
            } elseif (false !== strpos($error, 'WordPress database error')) {
                $errorLevel = 'DATABASE';
                $errorDetails = str_replace("WordPress database error ", "", $error);
            } elseif (false !== strpos($error, 'JavaScript Error')) {
                $errorLevel = 'JAVASCRIPT';
                $errorDetails = str_replace("JavaScript Error: ", "", $error);
            } else {
                $errorLevel = 'OTHER';
                $errorDetails = $error;
                if (Utils::isJson($errorDetails)) {
                    // For JSON string in error message, originally added via error_log(json_encode($variable))
                    // This will output said JSON string as well-formated array in the log entries table
                    $errorDetails = print_r(json_decode($errorDetails, true), true);
                }
            }

            $timestamp = str_replace(["[", "]"], "", $timestamp);

            if (array_search(trim($errorDetails), array_column($errorList, 'details')) === false) {
                $errorList[] = [
                    'level' => $errorLevel,
                    'details' => trim(preg_replace('/([\r\n\t])/', '', wp_kses_post($errorDetails))),
                    'occurrences' => [$timestamp],
                ];
            } else {
                $errorPosition = array_search(trim($errorDetails), array_column($errorList, 'details'));
                array_push($errorList[$errorPosition]['occurrences'], $timestamp);
            }
        }

        return $errorList;
    }

    /**
     * [getTotalLines description]
     * @return integer [description]
     */
    public function getTotalLines()
    {
        return $this->totalLines;
    }
}
