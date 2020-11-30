<?php
/**
 * Cli.php
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

namespace SendyImprovements\Cli;

use SendyImprovements\Logger;

/**
 * CLI scripts should not been accessible through a web browser
 */
if (php_sapi_name() !== "cli") {
    echo "Script must be run from the terminal\n";
    exit;
}

abstract class Cli
{
    private static $instance;
    protected $configClass = '\SendyImprovements\Config';
    protected $pidFile;
    protected $config;

    /**
     * Constructor. Visibility is set to protected because this class should only be instantiated via the getInstance() method as
     * it uses the singleton design pattern.
     */
    protected function __construct()
    {
        $this->setConfig();
        $this->getOpts();
        $this->lock();
    }

    /**
     * Gets a singleton instance of the class.
     *
     * @return Cli
     */
    public static function getInstance()
    {
        if (!isset(self::$instance)) {
            $className = get_called_class();
            self::$instance = new $className();
        }

        return  self::$instance;
    }

    /**
     * Set the config
     *
     * @param $config
     */
    protected function setConfig()
    {
        $configClass = $this->configClass;
        $this->config = $configClass::get();
    }

    /**
     * Get the config
     *
     * @return array
     */
    protected function getConfig()
    {
        return $this->config;
    }

    /**
     * Locks this script to prevent it from being run more than once at a time
     */
    private function lock()
    {
        if (file_exists($this->pidFile)) {
            Logger::debug("Script already running.");
            exit;
        } elseif ($this->pidFile) {
            file_put_contents($this->pidFile, getmypid());
            register_shutdown_function(array($this, 'unlock'));

            // Make sure script unlocks if process is killed
            declare(ticks = 1);
            pcntl_signal(SIGTERM, array($this, "unlock"));
            pcntl_signal(SIGHUP,  array($this, "unlock"));
            pcntl_signal(SIGINT, array($this, "unlock"));
            pcntl_signal(SIGQUIT, array($this, "unlock"));
        }
    }

    /**
     * Unlocks the script so that it can be run again
     */
    public function unlock()
    {
        if (file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
        exit;
    }

    abstract protected function getOpts();
    abstract protected function usage();
    abstract public function run();

}