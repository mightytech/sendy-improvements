<?php
/**
 * Logger.php
 *
 * LICENSE
 *
 * Copyright 2020 Mighty Technologies LLC
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy,
 * modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 * @package     sendy-improvements
 * @category    sendy-improvements-core
 * @copyright   Copyright (c) 2020 Mighty Technologies LLC (www.amightygirl.com)
 * @license     https://opensource.org/licenses/MIT MIT License
 */

namespace SendyImprovements;

class Logger {
    const DEBUG = 0;
    const INFO = 10;
    const NOTICE = 20;
    const WARNING = 30;
    const ERROR = 40;
    const CRITICAL = 50;
    const ALERT = 60;
    const EMERGENCY = 70;
    const SILENT = 1000;
    const RESET = 10000;

    public static $logLevel = self::WARNING;
    public static $logFile;
    public static $showBacktrace = true;
    public static $showDate = false;
    public static $showColors = true;
    public static $backtraceBase = null;

    public static $colors = array(
        self::RESET => "\033[0m",
        self::DEBUG => "\033[92m", // Light green
        self::INFO => "\033[96m", // Light cyan
        self::NOTICE => "\033[93m", // Yellow
        self::WARNING => "\033[91m", // Light red
        self::ERROR => "\033[91m", // Light red
        self::CRITICAL => "\033[91m", // Light red
        self::ALERT => "\033[91m", // Light red
        self::EMERGENCY => "\033[91m", // Light red
    );

    public static $text = array(
        self::DEBUG => "DEBUG",
        self::INFO => "INFO",
        self::NOTICE => "NOTICE",
        self::WARNING => "WARNING",
        self::ERROR => "ERROR",
        self::CRITICAL => "CRITICAL",
        self::ALERT => "ALERT",
        self::EMERGENCY => "EMERGENCY",
    );

    public static function debug($args)
    {
        $logLevel = self::DEBUG;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function info($args)
    {
        $logLevel = self::INFO;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function notice($args)
    {
        $logLevel = self::NOTICE;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function warn($args)
    {
        $logLevel = self::WARNING;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function warning($args)
    {
        $logLevel = self::WARNING;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function error($args)
    {
        $logLevel = self::ERROR;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function critical($args)
    {
        $logLevel = self::CRITICAL;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function alert($args)
    {
        $logLevel = self::ALERT;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function emergency($args)
    {
        $logLevel = self::EMERGENCY;
        $args = array_merge(array($logLevel), func_get_args());
        return call_user_func_array(array('self', 'printLog'), $args);
    }

    public static function printLog($args=null)
    {
        $args = func_get_args();
        $logLevel = array_shift($args);
        $separator = '';

        if (self::$logFile) {
            $showColors = false;
        } else {
            $showColors = self::$showColors;
        }

        if (self::$showDate) {
            $message = date('Y-m-d H:i:s') . "\t";
        } else {
            $message = '';
        }

        if (!$showColors) {
            $message = $message . self::$text[$logLevel] . "\t";
        }

        if (self::$showBacktrace) {
            $backtrace = debug_backtrace();
            $file = null;
            $line = null;

            while ($file === null) {
                $caller = array_shift($backtrace);
                if (isset($caller['file']) && isset($caller['line']) && $caller['file'] !== __FILE__) {
                    $file = str_replace(self::$backtraceBase, '', $caller['file']);
                    $line = $caller['line'];
                }
                if (!$file && !$backtrace) {
                    $file = 'N/A';
                    $line = 'N/A';
                }
            }
            $caller = array_shift($backtrace);
            $message = $message . $file  . ' (' . $line . ")\t";
        }

        foreach ($args as $arg) {
            if (is_array($arg) || is_object($arg)) {
                $arg = print_r($arg, true);
                if ($separator) {
                    $separator = "\n";
                }
                $message .= $separator . $arg;
                $separator = '';
            } else {
                $message .= $separator . $arg;
                $separator = ' ';
            }
        }

        if ($logLevel >= self::$logLevel) {
            if ($showColors) {
                $message = self::$colors[$logLevel] . $message . self::$colors[self::RESET];
            }
            if (self::$logFile) {
                file_put_contents(self::$logFile, $message . "\n", FILE_APPEND);
            } else {
                echo $message . "\n";
            }
        }
    }
}