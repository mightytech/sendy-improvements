<?php
/**
 * install.php
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

use SendyImprovements\Config;

if (!defined('SENDY_ROOT')) {
    DEFINE("SENDY_ROOT", realpath(dirname(__FILE__) . '/..'));
}

require(SENDY_ROOT . '/includes/config.php');
require_once(SENDY_ROOT . '/sendy-improvements/bootstrap.php');

if (!isset($dbPort)) {
    $dbPort = null;
}
if (!isset($charset)) {
    $charset = 'utf8';
}
$config = array(
    'application' => array(
        'app_path' => SENDY_ROOT
    ),
    'database' => array(
        'host' => $dbHost,
        'username' => $dbUser,
        'password' => $dbPass,
        'database' => $dbName,
        'port' => $dbPort,
        'charset' => $charset,
    )
);
Config::set($config);

$cliClass = 'SendyImprovements\Cli\Install';
$cli = $cliClass::getInstance();
$cli->run();