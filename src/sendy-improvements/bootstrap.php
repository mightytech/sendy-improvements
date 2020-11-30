<?php
/**
 * bootstrap.php
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


// To change theses settings add the appropriate constant(s) to /include/config.php.

if (!defined('SI_DEFAULT_LOG_LEVEL')) {
    define('SI_DEFAULT_LOG_LEVEL', 'ERROR');
}
if (!defined('SI_SCHEDULED_PID_FILE')) {
    define('SI_SCHEDULED_PID_FILE', '/var/run/sendy-scheduled-improved.pid');
}
if (!defined('SI_SCHEDULED_LOG_FILE')) {
    define('SI_SCHEDULED_LOG_FILE', '/var/log/sendy-scheduled-improved.log');
}
if (!defined('SI_SCHEDULED_USE_SES_BULK_TEMPLATED_API')) {
    define('SI_SCHEDULED_USE_SES_BULK_TEMPLATED_API', true);
}
if (!defined('SI_CAMPAIGN_LOG_SAVE_HOURS')) {
    define('SI_CAMPAIGN_LOG_SAVE_HOURS', 168);
}
if (!defined('SI_SCHEDULED_CLASS')) {
    define('SI_SCHEDULED_CLASS', '\SendyImprovements\Cli\Scheduled');
}
if (!defined('SI_MAILER_CLASS')) {
    define('SI_MAILER_CLASS', '\SendyImprovements\Mailer');
}
if (!defined('SI_SES_CLASS')) {
    define('SI_SES_CLASS', '\SendyImprovements\AmazonSes');
}
if (!defined('SI_SES_MAX_RETRIES')) {
    define('SI_SES_MAX_RETRIES', 3);
}

/**
 * We'll first try to load our classes using namespaces, but the old version of PHPMailer (which we're using to maximize PHP
 * commpatibility) doesn't use namespaces so we have to use a second pattern for the autoloader
 *
 * @param $classname
 */
function sendy_improvements_autoloader($classname)
{
    $basefilePath = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib';
    $filePath = $basefilePath;
    $classParts = explode('\\', $classname);

    foreach ($classParts as $part) {
        $filePath .= DIRECTORY_SEPARATOR . $part;
    }
    $filePath .= '.php';

    if (is_readable($filePath)) {
        require $filePath;
    } else {
        // The old version of PHPMailer uses a different naming convention. We'll stick to that to make things easy
        $filePath = $basefilePath . DIRECTORY_SEPARATOR . 'PHPMailer' . DIRECTORY_SEPARATOR . 'class.' . strtolower($classname) . '.php';
        if (is_readable($filePath)) {
            require $filePath;
        }
    }
}

spl_autoload_register('sendy_improvements_autoloader', true, true);