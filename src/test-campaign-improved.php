<?php
/**
 * test-campaign-improved.php
 *
 * PURPOSE
 *
 * Does a few tests on a saved campaign:
 * 1) Uses Tidy to look for HTML errors
 * 2) Gives an approximate size of the HTML version of the message in order to determine whether it will be clipped by Gmail
 * 3) Nothing else (yet!)
 *
 * USAGE
 * After installation, run "php test-campaign-improved.sh -h" for usage instructions. If there are HTML errors you want to ignore
 * define a constant named SI_CAMPAIGN_TEST_IGNORED_ERRORS that is either an array or a serialized array (if using PHP < 5.6)
 * with the errors you want to ignore.
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
 * @category    scheduled-improved
 * @copyright   Copyright (c) 2020 Mighty Technologies LLC (www.amightygirl.com)
 * @license     https://opensource.org/licenses/MIT MIT License
 */

if (!defined('SENDY_ROOT')) {
    DEFINE("SENDY_ROOT", realpath(dirname(__FILE__)));
}

require(SENDY_ROOT . '/includes/config.php');
require(SENDY_ROOT . '/includes/helpers/locale.php');
require_once(SENDY_ROOT . '/sendy-improvements/bootstrap.php');

// Use the 'serialize' function to support PHP < 5.6; in the TestCampaign class, however,
// we'll allow either arrays or a serialized array. This constant should be defined in the config.php
// file, but the example below should give you an idea of how to set it.
if (!defined("SI_CAMPAIGN_TEST_IGNORED_ERRORS")) {
    DEFINE("SI_CAMPAIGN_TEST_IGNORED_ERRORS", serialize(array(
//        '- Info: ',
//        'Warning: <table> lacks "summary" attribute',
//        'Warning: <img> lacks "alt" attribute',
//        'Warning: replacing invalid character code',
    )));
}
use SendyImprovements\Config;

// Only run this part if this file isn't included in another script. This allows users to extend the Sender class should
// they desire to change the functionality
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

$cliClass = '\SendyImprovements\Cli\TestCampaign';
$cliObject = $cliClass::getInstance();
$mysqli = $cliObject->getDbConnection();

// This include needs to come after the $mysqli variable is defined which is why it's way down here.
require(SENDY_ROOT . '/includes/helpers/short.php');

$cliObject->run();