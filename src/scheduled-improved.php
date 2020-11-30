<?php
/**
 * scheduled-improved.php
 *
 * PURPOSE
 *
 * This script is intended as a replacement for scheduled.php. Currently only Amazon SES is supported but users of SES
 * should notice significant speed increases when sending campaigns, especially for users who are sending large emails and/or
 * whose Sendy installations are located far from the SES endpoints. In tests, the author has experienced up to a 700% increase
 * in sending speed when sending 100kb emails from an account with a 50 email/sec sending limit. YMMV.
 *
 * USAGE
 * After installation, run "php scheduled-improved.sh -h" for usage instructions.
 *
 * KNOWN ISSUES/LIMITATIONS
 *
 * This script only works with Amazon SES configured using ACCESS KEY ID/SECRET KEY. Due to a limitation with
 * the SES API, emails that are sent will not include the 'list-unsubscribe' mail header.
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


// The default setting for SENDY_ROOT will work if schedule-improved.php is located in the SENDY_ROOT, but if you move it
// change it here too. Unfortunately, moving it will also break the notification emails that are sent after a campaign has
// been successfully sent, so you'd need to fix that too.

if (!defined('SENDY_ROOT')) {
    DEFINE("SENDY_ROOT", realpath(dirname(__FILE__)));
}

require(SENDY_ROOT . '/includes/config.php');
require(SENDY_ROOT . '/includes/helpers/locale.php');
require_once(SENDY_ROOT . '/sendy-improvements/bootstrap.php');
require('includes/helpers/integrations/zapier/triggers/functions.php');

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

$scheduledClass = SI_SCHEDULED_CLASS;
$scheduled = $scheduledClass::getInstance();
$mysqli = $scheduled->getDbConnection();

// This include needs to come after the $mysqli variable is defined which is why it's way down here.
require(SENDY_ROOT . '/includes/helpers/short.php');

$scheduled->run();