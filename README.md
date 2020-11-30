# sendy-improvements

## Purpose

This repository will include improvements built for the [Sendy](https:://sendy.co) email newsletter application. At present it contains just one major improvement: scheduled-improved and a much smaller proof-of-concept script test-campaign.

### [scheduled-improved](#scheduled-improved)

This script is intended as a replacement for Sendy's `scheduled.php` script. It has been completely rewritten in order to massively increase the speed with which a campaign is sent. Currently only supports Amazon SES. Performance improvements should be especially noticable for users who are sending large emails and/or whose Sendy installations are located far from the SES endpoints. 

### [test-campaign](#test-campaign)

More of a proof-of-concept than anything else. At present this script gives a tiny amount of (hopefully) helpful information about a saved campaign. First, it uses PHP's Tidy library to alert you to errors you might have in your campaign's HTML. Second, it gives you an approximate size of your campaign's HTML, which might be useful if you want to know whether your email will be clipped by Gmail.

## <a name="scheduled-improved"></a>scheduled-improved

### Features

* Fast! (See benchmark below.)
* Logging: A log file is created containing helpful information on the sending of the campaign. Multiple log levels are supported allowing you to customize the granularity of the messages. Moreover, a campaign_log MySQL table allows you to quickly query the results of a campaign for a specific subscriber/campaign. (By default this table is pruned after a week to prevent it from growing indefinitely, though this is configurable.)
* Better notifications: Find out exactly how long your campaigns took to send.
* Configurable and extensible: There are numerous options available to change the way the script works in ways small and large. Moreover, developers should be able to 
* Did I mention it's fast?

#### Example benchmark
2000 sends of a 100kb message on an EC2 m6g.medium instance located in the us-east region with a maximum send rate of 60/s:
* **Original Sendy Code:** 3:12
* **sendy-improvements with SES templates disabled:** 0:49 (3.9 times faster)
* **sendy-improvements with SES templates enabled:** 0:35 (5.5 times faster)

### How to Use

Remove `scheduled.php` from your crontab replacing it with `scheduled-improved.php`. More specifically:

Replace this:

```/5 * * * * php /var/www/lists.amightygirl.com/scheduled.php > /dev/null 2>&1```

With this:

```/5 * * * * php /var/www/lists.amightygirl.com/scheduled-improved.php > /dev/null 2>&1```

The above runs ```scheduled-improved.php``` every five minutes; however, you are free to adjust the send time up or down depending on your needs. When running, a lockfile is created to ensure that only one instance of the script is ever run at a time, so unlike with the original `scheduled.php` script -- which you should not have run more frequently than every five minutes -- you can run the ```scheduled-improved.php``` script as often as you'd like (e.g., every minute).

You can also run this script manually from the command line. Running `php scheduled-improved -h` will give you a list of command-line options.

## <a name="test-campaign"></a>test-campaign

### Features

Not much (yet!) As it says above, right now it only prints out HTML errors and the simulated size of the HTML portion of your email.

### How to Use

Simply type `php test-campaign-improved.php -c CAMPAIGN_ID` (replacing 'CAMPAIGN_ID' with the actual ID of your campaign) from the root of your Sendy installation.

## Installation

1. Clone/download this repository 
2. Run `install.sh` or manually save the files in `/src` to the root of your sendy installation
3. Define any desired optional constants in "includes/config.php". These include:
    * **SI_SCHEDULED_PID_FILE**: The full path to the PID file created when running this script. Ensures only one instance of this script is run at a time
    * **SI_DEFAULT_LOG_LEVEL**: The log level to use when running the script. DEBUG is useful for testing, INFO provides less detailed, but still potentially useful information about runs of this script. This can be overridden on a per run basis with the "-l" option. If not set, this defaults to WARNING
    * **SI_SCHEDULED_LOG_FILE**: The full path to the log file for this script. If not set, it defaults to /var/log/sendy-scheduled-improved.log
    * **SI_SCHEDULED_USE_SES_BULK_TEMPLATED_API**: Whether or not to use the SES [SendBulkTemplatedEmail](https://docs.aws.amazon.com/ses/latest/APIReference/API_SendBulkTemplatedEmail.html) operation when sending campaigns. If not set, this defaults to TRUE which is almost certainly what you want as most of the performance improvements rely on this API function. Moreover, the `scheduled-improved.php` script automatically detects cases where the campaign you're sending isn't compatible with this operation (namely if you're including an attachment) and runs accordingly. The one use case where you might wish to turn off this setting is if having the 'List-Unsubscribe' header is important to you as it is impossible to add custom headers (including the 'List-Unsubscribe' header) to messages sent using SES's [SendBulkTempatedEmail](https://docs.aws.amazon.com/ses/latest/APIReference/API_SendBulkTemplatedEmail.html). 
    * **SI_SCHEDULED_CLASS**: Modify functionality by setting the name of the sender class (should extend class `\SendyImprovements\Cli\Scheduled`)
    * **SI_MAILER_CLASS**: Modify functionality by setting the name of the mailer class (should extend class `\SendyImprovements\Mailer`)
    * **SI_SES_CLASS**: Modify functionality by setting the name of the SES class (should extend class `\SendyImprovements\AmazonSes`)
4. Run the script. To see command line options run "php schedule-improved.php -h"
5. Add this script to crontab and remove scheduled.php.

## Programming Notes

* The improvements contained in this repository are designed to be 100% compatible with the distributed Sendy code. Moreover, to ease the Sendy upgrade path, aside from optional changes to the `/includes/config.php` file, **no core Sendy files are modified**. 
* While the original Sendy code is done using procedural programming techniques, `sendy-improvements` seeks to use OOP best-practices. That said, the (very) early versions of this code more-or-less mimicked Sendy's original procedural code, so there is still some work to be done making sendy-improvements more object-oriented.
* One of the more difficult decisions we've had to make was deciding when to reuse original Sendy code and when to completely re-write it. As an example, the Zapier integration code has not been rewritten at all. On the other hand, in the original Sendy code, the very popular PHPMailer class is used; however, rather than extending this class the developer(s) modified its source, effectively making it nearly impossible to reuse. For this reason we use a separate, unmodified version of PHPMailer, bypassing Sendy's modifications. Ideally, we'd reuse/extend the original code when at all possible; however, given the procedural nature of Sendy, this often just isn't possible.
* In the interest of reuse, we've tried to make the code as extensible as possible. To do that, we've created constants for many of the classnames, which can be overridden to your own classes, should you want to modify functionality without modifying the base code.

## Known Issues / Limitations

* **This software package should be considered "alpha" software.** While the developers have endeavored to create high quality, bugfree software, **it has not undergone thorough testing on multiple environments and significant bugs may exist**. Furthermore some features (such as Zapier integration) have not been tested at all. For this reason, please test it yourself in your own environment(s) before using this package, especially for production use. AWS provides [test email addresses](https://docs.aws.amazon.com/ses/latest/DeveloperGuide/send-email-simulator.html) that may be helpful for this purpose. In short, use it at your own risk!
* This script has only been tested with Sendy version 4.1.0.1. It will **likely** work with other versions of Sendy 4, but that's far from guaranteed. It is definitely not (yet) compatible with Sendy 5. (For example, rules and webhooks will not be triggered by this script.)
* `scheduled-improved.php` only works with Amazon SES configured using ACCESS KEY ID/SECRET KEY. Sendy configurations using SMTP for mail sending is not supported at this time.
* Due to a limitation with the SES API, emails that are sent using the SES `sendBulkEmail` action will not include the 'list-unsubscribe' mail header. So far our own usage has not show the lack of this header to be impacting our open/click rates, but if this header is important to you, be sure to turn of bulk email sending by setting `SI_SCHEDULED_USE_SES_BULK_TEMPLATED_API` to `false` in your config file.

## Possible Future Plans

These are some ideas we've had on how to improve Sendy further. They may or may not be implemented here, but it's an idea of some things we've been thinking about.

* Make `scheduled-improved.php` compatible with Sendy 5
* Support for sending with SMTP in `scheduled-improved.php`
* Automatic CSS inlining in `scheduled-improved.php`
* Create segments for subscribers who didn't receive a specific campaign because of an error. Perhaps this should be done automatically once a campaign is run should there been any errors for that campaign.
* Automatic A/B testing/sending for subject lines: User creates test groups and after sending mail to a sample of the list (e.g., 10%) using different options, `scheduled-improved` send the remaining messages using the 'winning' subject line.
* Multi-server sending support (using SQS?)
* Preprocessing of scheduled campaigns to reduce processing when actually sending them
* Better click/open tracking/logging

### License
[MIT License](https://opensource.org/licenses/MIT)

Please note that included libraries/packages may be under different licenses. Copies of their licenses are included their respective directories.