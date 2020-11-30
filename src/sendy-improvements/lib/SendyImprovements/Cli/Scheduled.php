<?php
/**
 * Scheduled.php
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

namespace SendyImprovements\Cli;

use SendyImprovements\AmazonSes;
use SendyImprovements\Logger;
use SendyImprovements\Model\Campaign;
use SendyImprovements\Model\Subscriber;

class Scheduled extends Cli
{
    /**
     * This is the prefix we'll use in Amazon SES for our templates.
     */
    const TEMPLATE_PREFIX = 'Sendy_Campaign_';

    /**
     * This will (hopefully) never be used, but if you see this value in the campaign_log table, this is where it's coming from.
     * It can only occur if we get a different number of recipient responses tham we requested using the SES sendBulkTemplatedEmail
     * operation -- which so far has never happened.
     */
    const INVALID_SES_RESPONSE = 'INVALID_SES_RESPONSE';
    /**
     * If this is true, data will not be saved to the database. Only use this for testing purposes. It allows a developer
     * to re-reun the same campaign over and over again without having to re-create it.
     */
    const TEST_MODE_NO_SAVE = false;

    protected $pidFile = SI_SCHEDULED_PID_FILE;
    protected $useSesTemplates = SI_SCHEDULED_USE_SES_BULK_TEMPLATED_API;
    protected $time;
    protected $convertedDate;
    protected $sesTemplates = array();
    protected $customFields = array();
    protected $attachments = array();
    protected $campaigns;
    protected $activeCampaign;
    protected $campaignStats = array();
    protected $certificateFile;
    protected $dbConnection;

    /**
     * If AWS throttles our sending request, we'll retry up to this many times before giving up
     *
     * @var int
     */
    protected static $maxRetries = SI_SES_MAX_RETRIES;

    /**
     * The main controller for this script
     */
    public function run()
    {
        Logger::debug("Running...");

        $config = $this->getConfig();
        $this->certificateFile = $config['application']['app_path'] . '/certs/cacert.pem';

        if (self::TEST_MODE_NO_SAVE) {
            $this->transactionStart();
        }

        $this->setCron();
        // This will set the DB connection for all our model classes so that we can ensure we're using a shared connection
        Campaign::setDbConnection(self::getDbConnection());

        $campaigns = $this->getCampaigns();

        if ($campaigns) {
            foreach ($campaigns as $campaignId => $campaign) {
                $this->activeCampaign = $campaignId;

                set_locale($campaign->language);

                // Only use SES templates if we're using AWS and we've manually decided to use them
                if ($campaign->s3Key && $campaign->s3Secret && $this->useSesTemplates && !$campaign->getAttachments()) {
                    $campaign->useSesTemplates = true;
                } else {
                    $campaign->useSesTemplates = false;
                }

                $this->runCampaign();
            }
        } else {
            logger::info("No campaigns to send.");
        }

        if (self::TEST_MODE_NO_SAVE) {
            $this->transactionRollback();
        }
    }

    /**
     * Interpret any command line flags
     */
    protected function getOpts()
    {
        $options = getopt("l:sh", array('log-level:', 'screen', 'help'));

        if (isset($options['h']) || isset($options['help'])) {
            $this->usage();
            exit;
        }

        // If the user submits a log level, verify that it's an actual log level
        // and then update the Logger class accordingly
        $logLevels = array_flip(Logger::$text);
        if (isset($options['l']) || isset($options['log-level'])) {
            if (isset($options['log-level'])) {
                $logLevel = $options['log-level'];
            } else {
                $logLevel = $options['l'];
            }
            $logLevel = trim(strtoupper($logLevel));

            if (!$logLevel || !isset($logLevels[$logLevel])) {
                $this->usage();
                exit;
            }
            Logger::$logLevel = $logLevels[$logLevel];
        } else {
            Logger::$logLevel = $logLevels[SI_DEFAULT_LOG_LEVEL];
        }

        // If the user wants the output formatted for screen, we'll print the output in color, show which line/file the
        // debug message was printed from and suppress the printing of the date/time
        if (isset($options['s'])) {
            Logger::$backtraceBase = SENDY_ROOT;
            Logger::$showColors = true;
            Logger::$showBacktrace = true;
            Logger::$showDate = false;
        } else {
            if (SI_SCHEDULED_LOG_FILE) {
                Logger::$logFile = SI_SCHEDULED_LOG_FILE;
            }
            Logger::$showColors = false;
            Logger::$showBacktrace = false;
            Logger::$showDate = true;
        }
    }

    /**
     * Print a usage message
     */
    protected function usage()
    {
        echo "Usage:\n";
        echo "php scheduled-improved.php [options]\n";
        echo "Options:\n";
        echo " -l<level>, --log-level=<level>  Log level: debug, info, notice, warning, error, emergency, critical, emergency\n";
        echo " -s, --screen                    Optimize log data for display on screen (rather than in a file)\n";
        echo " -h, --help                      Show this help text\n";
    }

    /**
     * Return the database connection, creating a connection if necessary
     *
     * @return \mysqli
     */
    public function getDbConnection()
    {
        if ($this->dbConnection !== null) {
            return $this->dbConnection;
        }

        $config = $this->getConfig();
        $dbConfig = $config['database'];

        // Attempt to connect to database server
        if ($dbConfig['port']) {
            $connection = new \mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['database'], $dbConfig['port']);
        } else {
            $connection = new \mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['database']);
        }

        // If connection failed...
        if ($connection->connect_error) {
            Logger::emergency("Database error");
            exit;
        }

        mysqli_set_charset($connection, $dbConfig['charset']);

        $this->dbConnection = $connection;
        return $connection;
    }

    /**
     * Turn the cron notification off.
     */
    protected function setCron()
    {
        $db = $this->getDbConnection();

        $sql = <<<SQL
                UPDATE login
                        JOIN
                    (SELECT 
                        MIN(id) AS min_id
                    FROM
                        login) t_min_id ON login.id = t_min_id.min_id
                        AND login.cron != 1 
                SET 
                    cron = 1;
SQL;

        $result = mysqli_query($db, $sql);

        if (mysqli_affected_rows($db)) {
            Logger::info("Cron now on. Exiting.");
            exit;
        } else {
            Logger::debug("Cron already on.");
        }
    }

    /**
     * Get an array of campaigns that we need to send emails for
     *
     * @return array|null
     */
    protected function getCampaigns()
    {
        if ($this->campaigns === null) {
            $this->campaigns = Campaign::getCampaignsToSend();
        }
        return $this->campaigns;
    }

    /**
     * Returns a campaign object for the currently running campaign
     *
     * @return Campaign|null
     */
    protected function getActiveCampaign()
    {
        if (isset($this->campaigns[$this->activeCampaign])) {
            return $this->campaigns[$this->activeCampaign];
        } else {
            return null;
        }
    }

    /**
     * Send a campaign
     *
     * @throws \Exception
     */
    protected function runCampaign()
    {
        $campaign = $this->getActiveCampaign();

        $campaign->setStats('start_time', time());

        if ($campaign->firstRun) {
            Logger::info("Starting Campaign ID:", $campaign->id);

            // When running our campaign for the first time, we need to wrap our campaign database manipulations into a transaction

            $this->transactionStart();
            $campaign->insertCampaignLogPending()
                ->updateSentStatus()
                ->updateLinksTracking();

            // We only commit if we're not in test mode
            if (!self::TEST_MODE_NO_SAVE) {
                $this->transactionCommit();
            }

            Logger::info("Emails to send:", $campaign->toSend . ' of ' . $campaign->toSend);
        } else {
            $leftToSend = $campaign->logSummaryStart['pending'];
            Logger::info("Resuming Campaign ID:", $campaign->id);
            Logger::info("Emails to send:", $leftToSend . ' of ' . $campaign->toSend);
        }

        $subscribersResult = $campaign->getSubscribers();

        if ($subscribersResult) {
            $runResult = $this->runSubscribers($subscribersResult);
        }
        Logger::debug("Sending complete. Running cleanup actions.");

        // Get stats and send the completion notification
        $campaignLogSummary = $campaign->getCampaignLogSummary();

        $campaign->setStats(array(
            'recipients' => array_sum($campaignLogSummary),
            'campaign_log_summary' => $campaignLogSummary,
            'end_time' => time()
        ));

        $this->sendCompletionNotification();

        // We need to run this *AFTER* sending the campaign notification as that function will increment the send_count,
        // which we don't really want
        $campaign->markCompleted();

        $this->runZapierIntegration();

        // Finally do some cleanup
        $this->cleanSesTemplates();

        // If the prune hours is set to 0, don't prune the table at all, otherwise prune the table to the amount of hours that
        // have been set.
        $hours = SI_CAMPAIGN_LOG_SAVE_HOURS;
        if ($hours !== 0) {
            $this->pruneCampaignLog($hours);
        }

        Logger::info("Campaign Summary:");
        foreach ($campaignLogSummary as $status => $count) {
            Logger::info(ucfirst($status) . ': ', $count);
        }
        Logger::info('Total: ', array_sum($campaignLogSummary));

        $elapsedTimeFormatted = $campaign->getStats('elapsed_time_formatted');
        Logger::info("Elapsed time:", $elapsedTimeFormatted);
    }

    /**
     * Gets the SES Object
     *
     * @return AmazonSes
     */
    public function getSesObject()
    {
        $campaign = $this->getActiveCampaign();
        return new AmazonSes($campaign->sesEndpoint, $campaign->s3Key, $campaign->s3Secret, $campaign->sendRate, $this->certificateFile);
    }

    /**
     * Starts a MySQL transaction
     * @return bool|mysqli_result
     */
    protected function transactionStart()
    {
        return $this->getDbConnection()->autocommit(false);
    }

    /**
     * Rollsback a MySQL transaction. Currently only used during testing
     *
     * @param $autocommit boolean Whether we want transactions to autocommit after running this
     * @return bool|mysqli_result
     */
    protected function transactionRollback($autocommit = true)
    {
        mysqli_rollback($this->getDbConnection());
        if ($autocommit) {
            $this->getDbConnection()->autocommit(true);
        }
        return true;
    }

    /**
     * Commits a MySQL transaction
     *
     * @param $autocommit boolean Whether we want transactions to autocommit after running this
     * @return bool|mysqli_result
     */
    protected function transactionCommit($autocommit = true)
    {
        mysqli_commit($this->getDbConnection());
        if ($autocommit) {
            $this->getDbConnection()->autocommit(true);
        }
        return true;
    }

    /**
     * Run the campaign for its subscribers
     *
     * @param $subscribersResult
     * @throws \Exception
     */
    protected function runSubscribers($subscribersResult)
    {
        $campaign = $this->getActiveCampaign();

        $processedSubscriberQueue = array();
        $batchCounter = 0;

        while ($row = mysqli_fetch_array($subscribersResult)) {
            //prevent execution timeout
            set_time_limit(0);

            $processedSubscriber = Subscriber::campaignFactory($campaign, $row);

            $processedSubscriberQueue[] = $processedSubscriber;
            if (count($processedSubscriberQueue) == $campaign->sendRate) {
                $batchCounter = $this->runBatch($processedSubscriberQueue, $batchCounter);
                $processedSubscriberQueue = array();
            }
        }

        // If there are any subscribers left in the queue mail them now
        if (count($processedSubscriberQueue)) {
            $batchCounter = $this->runBatch($processedSubscriberQueue, $batchCounter);
        }
    }

    /**
     * Sends a batch of messages from the subscriberQueue
     *
     * @param $processedSubscriberQueue
     * @param $batchCounter
     * @return int
     * @throws \Exception
     */
    protected function runBatch($processedSubscriberQueue, $batchCounter)
    {
        $campaign = $this->getActiveCampaign();
        $batchCounter = $batchCounter + 1;
        Logger::debug("Sending batch", $batchCounter, "(" . count($processedSubscriberQueue) . " recipients)");
        if ($campaign->useSesTemplates) {
            $result = $this->emailBulkSubscribers($processedSubscriberQueue);
        } else {
            $result = $this->emailSingleSubscribers($processedSubscriberQueue);
        }

        $campaign->incrementSentCount($result['total_sends']);
        $campaign->updateSubscribers($result);

        return $batchCounter;
    }

    /**
     * Email subscribers one email at a time using the sendRawEmail action of the SES api
     *
     * @param $processedSubscriberQueue
     * @param int $resendCount
     * @return array
     * @throws \Exception
     */
    protected function emailSingleSubscribers($processedSubscriberQueue, $resendCount = 0)
    {
        $campaign = $this->getActiveCampaign();
        $messageArray = array();

        $subscribersByEmail = array();
        $source = array('email' => $campaign->fromEmail, 'name' => $campaign->fromName);

        foreach ($processedSubscriberQueue as $processedSubscriber) {
            $destinationEmail = $this->formatEmailAddress($processedSubscriber->email, $processedSubscriber->name);
            $messageArray[] = array(
                'to_email' => $processedSubscriber->email,
                'to_name' => $processedSubscriber->name,
                'subject' => $processedSubscriber->processedSubject,
                'html' => $processedSubscriber->processedHtml,
                'plain_text' => $processedSubscriber->processedPlainText,
                'attachments' => $campaign->getAttachments(),
                'headers' => array('List-Unsubscribe' => '<' . $processedSubscriber->getUnsubscribeLink() . '>')
            );
            $subscribersByEmail[$destinationEmail] = $processedSubscriber;
        }

        $sesObject = $this->getSesObject();
        $sendResults = $sesObject->sendRawEmail($source, $messageArray);

        return $this->processSingleSendResult($sendResults, $subscribersByEmail, $resendCount);
    }

    /**
     * Takes raw send results from SES and processes them into a more usable format. To do so, it divides the list into three groups:
     *   1) subscriber_successes: These are subscribers for whom the campaign was sent successfully.
     *
     *   2) subscriber_throttles: These are subscribers who SES wasn't able to send the campaign to, due to some sort of error. (A frequent
     *                            error seems to be the SES sometimes can't seem to find the SES template. Another might be due to your
     *                            SES account being throttled -- which should only occur if you are sending mail outside of Sendy in addition
     *                            to using this script.) This method attempts to resend the message to all subscriber_throttles up to
     *                            a maximum of SI_SES_MAX_RETRIES times after which they become subscriber_errors.
     *
     *   3) subscriber_errors:   These are subscribers who we weren't able to mail for some reason. We'll log the reason to our log file
     *                           and also include it in the campaign_log table.
     *
     *
     * @param $sendResults
     * @param $subscribersByEmail
     * @param $resendCount
     * @return array
     * @throws \Exception
     */
    protected function processSingleSendResult($sendResults, $subscribersByEmail, $resendCount)
    {
        $subscriberSuccesses = array();
        $subscriberThrottles = array();
        $subscriberErrors = array();
        $throttledSubscriberQueue = array();
        $totalCounter = 0;

        foreach ($sendResults as $sendResultArray) {
            $sendResult = $sendResultArray['send-result'];
            $responseEmail = $sendResultArray['destination-emails'][0];
            $responseXml = $sendResult['response_xml'];
            $subscriber = $subscribersByEmail[$responseEmail];

            // Check for errors
            if ($responseXml->Error) {
                switch ($responseXml->Error->Code) {
                    case 'InternalFailure':
                    case 'RequestExpired':
                    case 'AccountThrottled':
                    case 'ThrottlingException':
                        // For the errors above, we'll add the subscriber to our subscriber_throttles list so we can retry
                        // sending the message to them
                        Logger::warning("Send Result:", $responseEmail, "=>", (string)$responseXml->Error->Message);
                        $subscriberThrottles[$subscriber->id] = $responseXml->Error->Code;
                        $throttledSubscriberQueue[] = $subscriber;
                        break;
                    default:
                        // For any other type of error, we'll add the user to the subscriber_errors list
                        Logger::error("Fatal error attempting to send email.");
                        Logger::error((string)$responseXml->Error->Code . ':', (string)$responseXml->Error->Message);
                        Logger::error("Raw response\n", $sendResult['response_raw']);
                        $subscriberErrors[$subscriber->id] = $responseXml->Error->Code;
                        break;
                }
            }

            // If the sending of the message was successful, we'll save the message ID for the subscriber
            if (isset($responseXml->SendRawEmailResult)) {
                $totalCounter = $totalCounter + 1;
                $subscriber = $subscribersByEmail[$responseEmail];
                $messageId = (string)$responseXml->SendRawEmailResult->MessageId;
                Logger::debug("Send Result:", $responseEmail, "=> Success");
                $subscriberSuccesses[$subscriber->id] = $messageId;
            }
        }

        // A little recursion here. We'll keep rerunning the sends on subscriber throttles ontil we reach our maximum send count.
        // At that point, subscriber_throttles become subscriber_errors and we give up on them.
        if ($subscriberThrottles) {
            $resendCount = $resendCount + 1;
            if ($resendCount >= self::$maxRetries) {
                // If we've already reached our resend limit, we'll add these emails to the error list and move on
                $subscriberErrors = $subscriberErrors + $subscriberThrottles;
            } else {
                // If the request was throttled, let's wait one second per retry attempt then retry with the throttled email addresses
                sleep($resendCount);
                $resendResult = $this->emailSingleSubscribers($throttledSubscriberQueue, $resendCount);
                $subscriberSuccesses = $subscriberSuccesses + $resendResult['subscriber_successes'];
                $subscriberErrors = $subscriberErrors + $resendResult['subscriber_errors'];
            }
        }

        // Return the result. Note that we never return any subscriber_throttles as by this point they should all be successes or errors.
        $processedResult = array(
            'total_sends' => $totalCounter,
            'subscriber_successes' => $subscriberSuccesses,
            'subscriber_errors' => $subscriberErrors,
        );
        return $processedResult;
    }

    /**
     * Email multiple subscribers in bulk
     *
     * @param $processedSubscriberQueue
     * @param $resendCount
     * @return array
     * @throws \Exception
     */
    protected function emailBulkSubscribers($processedSubscriberQueue, $resendCount = 0)
    {
        $campaign = $this->getActiveCampaign();
        $destinations = array();
        $defaultTemplateVariables = $campaign->getDefaultValues();

        // $subscribersByEmail makes it easy for us to get the subscriber ID for the emails we've just sent via SES
        $subscribersByEmail = array();

        $source = $this->formatEmailAddress($campaign->fromEmail, $campaign->fromName);

        if (!isset($campaign->sesTemplate) && $processedSubscriberQueue) {
            $campaign->sesTemplate = $this->createSesTemplate();
        }

        foreach ($processedSubscriberQueue as $processedSubscriber) {
            $destinationEmail = $this->formatEmailAddress($processedSubscriber->email, $processedSubscriber->name);
            $destinations[$destinationEmail] = $processedSubscriber->templateVariables;
            $subscribersByEmail[$destinationEmail] = $processedSubscriber;

            if (!$defaultTemplateVariables) {
                foreach ($processedSubscriber['template_variables'] as $key => $value) {
                    $defaultTemplateVariables[$key] = '';
                }
            }
        }

        $sesObject = $this->getSesObject();

        $sendResults = $sesObject->sendBulkTemplatedEmail($source, $campaign->sesTemplate, $destinations, $defaultTemplateVariables);
        return $this->processBulkSendResult($sendResults, $subscribersByEmail, $resendCount);
    }

    /**
     * Takes the result we get from the bulk send operation and processes the data into a useable format. See notes for
     * the "processSingleSendResult" method for more information. Additionally, it should be noted that the sendBulkTemplatedEmail
     * action can return results on two different levels:
     *
     * 1) Results the apply to the entire request (for up to 50 subscribers) -- these can only be errors of some kind.
     * 2) Results that apply to a specific recipient of a message.
     *
     * We obviously need to handle both levels.
     *
     * @param $sendResults
     * @param $subscribersByEmail
     * @param int $resendCount
     * @return array
     * @throws \Exception
     */
    protected function processBulkSendResult($sendResults, $subscribersByEmail, $resendCount = 0)
    {
        $subscriberSuccesses = array();
        $subscriberThrottles = array();
        $subscriberErrors = array();
        $throttledSubscriberQueue = array();
        $totalCounter = 0;

        foreach ($sendResults as $sendResultArray) {
            $sendResult = $sendResultArray['send-result'];
            $destinationEmails = $sendResultArray['destination-emails'];
            $responseXml = $sendResult['response_xml'];
            $responseCounter = 0;

            // These are errors that apply to the entire sendBulkTemplatedEmail operation
            if ($responseXml->Error) {
                // For some reason AWS on some occasions doesn't recognize a template that has already been in use. If this happens
                // we'll just try to rerun the send up to the maxResends.
                if ($responseXml->Error->Code == "TemplateDoesNotExist" && $resendCount < self::$maxRetries) {
                    Logger::warning("Template error:", (string) $responseXml->Error->Message);
                    foreach ($destinationEmails as $responseEmail) {
                        $subscriber = $subscribersByEmail[$responseEmail];
                        $subscriberId = $subscriber->id;
                        $subscriberThrottles[$subscriberId] = 'TEMPLATE_ERROR';
                        $throttledSubscriberQueue[] = $subscriber;
                    }
                } else {
                    // As we're just starting with this script, we're going to resend our campaigns
                    Logger::error("Fatal error attempting to bulk send emails.");
                    Logger::error((string)$responseXml->Error->Code . ':', (string)$responseXml->Error->Message);
                    Logger::error("Raw response\n", $sendResult['response_raw']);
                    foreach ($destinationEmails as $responseEmail) {
                        $subscriber = $subscribersByEmail[$responseEmail];
                        $subscriberId = $subscriber->id;
                        $subscriberThrottles[$subscriberId] = (string) $responseXml->Error->Code;
                        $throttledSubscriberQueue[] = $subscriber;
                    }
                }
            }

            // These are the results for specific subscribers of our sendBulkTemplatedEmail call.
            if (isset($responseXml->SendBulkTemplatedEmailResult->Status->member)) {
                // If for some reason we tried to email more/less subscribers than we got responses, that's a major problem!
                // This should *never* happen (and so far never has!), but... you never know, right?
                if (count($destinationEmails) !== count($responseXml->SendBulkTemplatedEmailResult->Status->member)) {
                    Logger::error("Fatal Error. Destination count not equal to response count");
                    Logger::error("Destinations:", array_keys($destinationEmails));
                    Logger::error("Raw response\n", $sendResult['response_raw']);
                    foreach ($destinationEmails as $responseEmail) {
                        $subscriber = $subscribersByEmail[$responseEmail];
                        $subscriberErrors[$subscriber->id] = self::ERROR_INVALID_SES_RESPONSE;
                    }
                } else {
                    // Here's where we loop through our recipient responses so we can determine which messages were sent
                    // succesfully and which were not. Unfortunately, AWS is not always consistent, even within a single
                    // sendBulkTemplated email call
                    foreach ($responseXml->SendBulkTemplatedEmailResult->Status->member as $memberResponse) {
                        $totalCounter = $totalCounter + 1;
                        $responseEmail = $destinationEmails[$responseCounter];
                        $subscriber = $subscribersByEmail[$responseEmail];

                        switch ($memberResponse->Status) {
                            // Successes are easy. Just add their message IDs to our subscriberSuccesses result. Yay!
                            case 'Success':
                                Logger::debug("Send Result:", $destinationEmails[$responseCounter], "=>", (string)$memberResponse->Status);
                                $subscriberSuccesses[$subscriber->id] = $memberResponse->MessageId;
                                break;
                            // If we get Failed or AccountThrottled for a specific recipient, add them to subscriber throttles so we
                            // can retry sending them the message
                            case 'Failed':
                            case 'AccountThrottled':
                                Logger::warning("Send Result:", $destinationEmails[$responseCounter], "=>", (string)$memberResponse->Status);
                                Logger::warning($memberResponse);
                                $subscriberThrottles[$subscriber->id] = strtoupper($memberResponse->Status);
                                $throttledSubscriberQueue[] = $subscriber;
                                break;
                            // Anything else and we'll add them to our errors list.
                            default:
                                Logger::warning("Send Result:", $destinationEmails[$responseCounter], "=>", (string)$memberResponse->Status);
                                Logger::warning($memberResponse);
                                $subscriberErrors[$subscriber->id] = strtoupper($memberResponse->Status);
                                break;
                        }
                        $responseCounter = $responseCounter + 1;
                    }
                }
            }
        }

        if ($subscriberThrottles) {
            $resendCount = $resendCount + 1;
            if ($resendCount >= self::$maxRetries) {
                // If we've already reached our resend limit, we'll add these emails to the error list and move on
                $subscriberErrors = $subscriberErrors + $subscriberThrottles;
            } else {
                // If the request was throttled, let's wait one second per retry attempt then retry with the throttled email addresses
                sleep($resendCount);
                $resendResult = $this->emailBulkSubscribers($throttledSubscriberQueue, $resendCount);
                $subscriberSuccesses = $subscriberSuccesses + $resendResult['subscriber_successes'];
                $subscriberErrors = $subscriberErrors + $resendResult['subscriber_errors'];
            }
        }

        $processedResult = array(
            'total_sends' => $totalCounter,
            'subscriber_successes' => $subscriberSuccesses,
            'subscriber_errors' => $subscriberErrors,
        );
        return $processedResult;
    }

    /**
     * Takes an email address (and optional name) and puts it into the correct format based on RFC 5322.
     *
     * @param $emailAddress
     * @param null $name
     * @return string
     */
    protected function formatEmailAddress($emailAddress, $name = null)
    {
        // These characters are defined in 3.2.3 of RFC 5322 (See: https://tools.ietf.org/html/rfc5322#section-3.2.3)
        $specialCharacters = "()<>[]:;@\\,.\"";
        $name = trim($name);
        $emailAddress = trim($emailAddress);

        // Add a double quote around addresses to prevent errors when commas or other special characters appear in names;
        // we also need to escape any double quotes that may exist for the same reason
        if ($name) {
            if (preg_match('/[' . preg_quote($specialCharacters, '/') . ']/', $name)) {
                $name = '"' . str_replace(array('\\', '"'), array('\\\\', '\"'), $name) . '"';
            }
            return $name . ' <' . $emailAddress . '>';
        } else {
            return $emailAddress;
        }
    }

    /**
     * Gets or creates an SES template
     *
     * @return string
     * @throws \Exception
     */

    protected function createSesTemplate($retries=0)
    {
        $campaign = $this->getActiveCampaign();

        $templateName = self::TEMPLATE_PREFIX . $campaign->id;
        $sesObject = $this->getSesObject();
        $templateResult = $sesObject->getTemplate($templateName);

        $campaignHtml = $campaign->getProcessedHtml();
        $campaignPlaintext = $campaign->getProcessedPlainText();
        $campaignSubject = $campaign->getProcessedSubject();

        if ($templateResult['error_code'] === 'TemplateDoesNotExist') {
            try {
                $createTemplateResult = $sesObject->createTemplate($templateName, $campaignSubject, $campaignHtml, $campaignPlaintext);
                if ($createTemplateResult['error_code']) {
                    throw new \Exception(("Error creating template " . $templateName));
                }
            } catch (\Exception $exception) {
                if ($retries < SI_SES_MAX_RETRIES) {
                    $retries = $retries + 1;
                    $this->createSesTemplate($retries);
                } else {
                    Logger::error($exception->getMessage());
                    exit;
                }
            }
        } elseif (!isset($templateResult['response_xml']->GetTemplateResult->Template->TemplateName)) {
            throw new \Exception("Error retrieving template " . $templateName);
        }
        return $templateName;
    }

    /**
     * Deletes any Sendy templates
     */
    protected function cleanSesTemplates()
    {
        $sesObject = $this->getSesObject();
        $templates = $sesObject->listTemplates();

        foreach ($templates as $template) {
            if (strpos($template, self::TEMPLATE_PREFIX) === 0) {
                $sesObject->deleteTemplate($template);
            }
        }
    }

    /**
     * Replaces any tags with either the correct SES template field name or with the correct user data
     *
     * @param $text
     * @param bool $includeTrackingPixel
     * @return array
     */
    protected function processCampaignText($text, $campaign, $includeTrackingPixel = false)
    {
        $fieldMap = array();
        $defaultValues = array();

        $queryString = $this->getCampaignVariable($campaign->id, 'query_string');
        $campaignLinks = $this->getCampaignVariable($campaign->id, 'campaign_links');
        $text = str_replace($this->unconvertedDate, $this->getConvertedDate(), $text);

        $linksQueryStringMap = array_flip($this->getLinksFromText($text, $queryString));

        foreach ($campaignLinks as $campaignLink) {
            $sesFieldName = 'link_' . $campaignLink['id'];
            $defaultValues[$sesFieldName] = $campaignLink['link'];

            if ($queryString) {
                if (isset($linksQueryStringMap[$campaignLink['link']])) {
                    $linkWithOutQueryString = $linksQueryStringMap[$campaignLink['link']];

                    $text = preg_replace('/(href=["\'])(' . preg_quote($linkWithOutQueryString, '/') . '+)(["\'])/i', '$1{{' . $sesFieldName . '}}$3', $text);
//                    $text = str_replace($linkWithOutQueryString, '{{' . $sesFieldName . '}}', $text);
                }

            }
            $text = preg_replace('/href=["\'](' . preg_quote($campaignLink['link'], '/') . ')+["\']/i', '$1{{' . $sesFieldName . '}}$3', $text);
        }
        // Allows the user to embed the tracking pixel wherever they might want it by adding one of the two:
        // 1) [tracking-pixel] => A tracking pixel tag
        // 2) <i class="t-pix"></i> => An empty <i> tag with the class "t-pix"
        $trackingPixelTag = '[tracking-pixel,fallback=]';
        $altTrackingPixelTag1 = '[tracking-pixel]';
        $altTrackingPixelTag2 = '<i class="t-pix"></i>';

        /*
         *  Change special html tags to special data tags. This will allow us to process them in one go.
         *  We also need to make sure that all special tags have a fallback so that they're picked up by preg_match_all below.
         *    For example:
         *      <webversion>Text</webversion>
         *    Becomes:
         *      <a href="[webversion,fallback=]">Text</a>
         */
        $replaceStrings = array(
            '[Email]' => '[Email,fallback=]',
            '[webversion]' => '[webversion,fallback=]',
            '<webversion' => '<a href="[webversion,fallback=]"',
            '</webversion>' => '</a>',
            '[unsubscribe]' => '[unsubscribe,fallback=]',
            '<unsubscribe' => '<a href="[unsubscribe,fallback=]"',
            '</unsubscribe>' => '</a>',
            $altTrackingPixelTag1 => $trackingPixelTag,
            $altTrackingPixelTag2 => $trackingPixelTag,
        );
        $text = strtr($text, $replaceStrings);

        // Append the tracking pixel tag if opens tracking is set and the tag isn't anywhere else and this is an HTML email with a body tag in it
        if ($includeTrackingPixel && strpos($text, $trackingPixelTag) === false) {
            $text = str_replace("</body>", $trackingPixelTag . '</body>', $text);
        } elseif (!$includeTrackingPixel) {
            $text = str_replace($trackingPixelTag, '', $text);
        }

        // Get matches with all the custom tags
        preg_match_all('/\[([^\]]+),\s*fallback=([^\]]*)\]/i', $text, $matches, PREG_PATTERN_ORDER);

        $matchesAll = $matches[0];
        $matchesField = $matches[1];
        $matchesFallback = $matches[2];

        $matches_count = count($matchesField);

        for ($i = 0; $i < $matches_count; $i++) {
            $field = $matchesField[$i];
            $fallback = $matchesFallback[$i];
            $tag = $matchesAll[$i];

            $sesFieldName = 'field_' . $field;

            if ($fallback) {
                $sesFieldName = $sesFieldName . '_fallback_' . $fallback;
            } else {
                // The webversion and unsubscribe links won't have a user-supplied fallback, so we'll create one ourselves
                switch ($field) {
                    case 'webversion':
                        $fallback = $campaign->getWebversion();
                        break;
                }
            }

            // Change the text to be in the correct form for SES templates
            $text = str_replace($tag, '{{' . $sesFieldName . '}}', $text);

            // Add the field to the defaults
            $defaultValues[$sesFieldName] = $fallback;
            $fieldMap[$field][$sesFieldName] = $sesFieldName;
        }
        return array('text' => $text, 'field_map' => $fieldMap, 'default_values' => $defaultValues);
    }

    /**
     * The purpose of this function is to condense the number of variables that we send to SES. The problem is that we might
     * have two variables with the same name, but with different fallbacks, so in that case we'll want to send SES two different
     * variables, each with a different value. However, if the variables have the same value, we are free to just reuse the variable name.
     *
     * As an example, let's take this text:
     *
     *      Dear [Name,fallback=Friend],
     *      Your name is [Name,falback=Unknown].
     *
     * In the case that we actually know the user's name (e.g. "Bob") we can just create a single variable. We'd send SES data like this:
     *
     *      Dear {{field_Name}},
     *      Your name is {{field_Name}}.
     *
     * Variables: array('field_Name' => 'Bob')
     *
     * However, if we didn't know the user's name, we'd want to send SES different values for each different fallback:
     *
     *      Dear {{field_Name}},
     *      Your name is {{field_Name2}}.
     *
     * Variables: array('field_Name' => 'Friend', 'field_Name2', 'Unknown')
     *
     * @param $templateVariables The variables that have already been set
     * @param $baseTemplateFieldName The base field name
     * @param $value The value for this variable
     * @param $counter The number of times this function has run recursively
     * @return string The field name that we'll use
     */
    protected function getTemplateFieldName($templateVariables, $baseTemplateFieldName, $value, $counter)
    {
        if ($counter == 0) {
            $templateFieldName = $baseTemplateFieldName;
        } else {
            $templateFieldName = $baseTemplateFieldName . ($counter + 1);
        }

        if (!isset($templateVariables[$templateFieldName])) {
            return $templateFieldName;
        } elseif ($templateVariables[$templateFieldName] == $value) {
            return $templateFieldName;
        } else {
            return $this->getTemplateFieldName($templateVariables, $baseTemplateFieldName, $value, $counter + 1);
        }
    }

    /**
     * Send emails to the sender of the campaign, both the user's email as well as the from address for the campaign.
     *
     * @throws \Exception
     */
    protected function sendCompletionNotification()
    {
        $campaign = $this->getActiveCampaign();
        $app = $campaign->app;
        $recipients = $campaign->getStats('recipients');
        $appPath = $campaign->appPath;
        $campaignLabel = $campaign->label;

        $startTime = new \DateTime();
        $startTime->setTimestamp($campaign->getStats('start_time'));
        $startTimeFormatted = $startTime->format('Y-m-d H:i:s');

        $endTime = new \DateTime();
        $endTime->setTimestamp($campaign->getStats('end_time'));
        $endTimeFormatted = $endTime->format('Y-m-d H:i:s');

        $elapsedTime = $campaign->getStats('elapsed_time');
        $elapsedTimeFormatted = $campaign->getStats('elapsed_time_formatted');

        if (!$elapsedTime) {
            $elapsedTime = 1;
        }

        $actualSendRate = number_format($recipients / $elapsedTime, 1) . '/' . _('second');

        $subject = $campaign->getProcessedSubject();
        foreach ($campaign->getDefaultValues() as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
        }
        $subject = '[' . _('Campaign sent') . '] ' . $subject;

        $reportLink = $appPath . '/report?i=' . $app . '&c=' . $campaign->id;
        $logoSrc = $appPath . '/img/email-icon.gif';

        $labelCampaignSent = _('Your campaign has been successfully sent') . '!';
        $labelCampaignSentTo = _('Your campaign has been successfully sent to');
        $labelCampaign = _('Campaign');
        $labelRecipients = _('Recipients');
        $labelRecipientsSmall = _('recipients');
        $labelStartTime =  _('Start Time');
        $labelEndTime =  _('End Time');
        $labelRunTime = _('Run Time');
        $labelSendRate = _('Send Rate');
        $labelViewReport = _('View report');

        $plainText = <<<PLAIN
$labelCampaignSent
$labelCampaign: $campaignLabel
$labelRecipients: $recipients
$labelStartTime: $startTimeFormatted
$labelEndTime: $endTimeFormatted
$labelRunTime: $elapsedTimeFormatted
$labelSendRate: $actualSendRate
$labelViewReport: $reportLink
PLAIN;

        $html = <<<HTML
<div style="margin: -10px -10px; padding:50px 30px 50px 30px; height:100%;">
    <div style="margin:0 auto; max-width:660px;">
	    <div style="float: left; background-color: #FFFFFF; padding:10px 30px 10px 30px; border: 1px solid #f6f6f6;">
		    <div style="float: left; max-width: 106px; margin: 10px 20px 15px 0;">
			    <img src="$logoSrc" style="width: 100px;"/>
			</div>
			<div style="float: left; max-width:470px;">
				<p style="line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;">
				    <strong style="line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 18px;">$labelCampaignSent</strong>
			    </p>	
			    <div style="line-height: 21px; min-height: 100px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;">
					<p style="line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;">$labelCampaignSentTo $recipients $labelRecipientsSmall!</p>
					<table style="line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px; margin-bottom: 25px; background-color:#f7f9fc; padding: 15px;">
					    <tr><td><strong>$labelCampaign:</strong></td><td>$campaignLabel</td></tr>
					    <tr><td><strong>$labelRecipients: </strong></td><td>$recipients</td></tr>
				        <tr><td><strong>$labelStartTime: </strong></td><td>$startTimeFormatted</td></tr>
					    <tr><td><strong>$labelEndTime: </strong></td><td>$endTimeFormatted</td></tr>
					    <tr><td><strong>$labelRunTime: </strong></td><td>$elapsedTimeFormatted</td></tr>
					    <tr><td><strong>$labelSendRate: </strong></td><td>$actualSendRate</td></tr>
					    <tr><td><strong>$labelViewReport: </strong></td><td><a style="color:#4371AB; text-decoration:none;" href="$reportLink">$reportLink</a></td></tr>
					</table>
					<p style="line-height: 21px; font-family: Helvetica, Verdana, Arial, sans-serif; font-size: 12px;"></p>
                </div>
            </div>
        </div>
    </div>
</div> 
HTML;

        $source = $campaign->fromEmail;
        $destinations = array($campaign->userEmail => $campaign->userName, $campaign->fromEmail => $campaign->fromName);

        $messageTemplate = array(
            'subject' => $subject,
            'html' => $html,
            'plain_text' => $plainText,
        );
        $messages = array();

        foreach ($destinations as $toEmail => $toName) {
            $messages[] = $messageTemplate + array('to_email' => $toEmail, 'to_name' => $toName);
        }

        $sesObject = $this->getSesObject();
        $sesObject->sendRawEmail($source, $messages);
    }

    /**
     * Runs the zapier integration.
     */
    protected function runZapierIntegration()
    {
        $campaign = $this->getActiveCampaign();

        $subject = $campaign->getProcessedSubject();
        foreach ($campaign->getDefaultValues() as $key => $value) {
            $subject = str_replace('{{' . $key . '}}', $value, $subject);
        }

        zapier_trigger_new_campaign_sent($subject, $campaign->fromName, $campaign->fromEmail,
            $campaign->replyTo, strftime("%a, %b %d, %Y, %I:%M%p", $campaign->sent),
            $campaign->getWebversion(), $campaign->app);
    }

    /**
     * Deletes extra rows in the campaign_log table so that the table doesn't become overly bloated
     *
     * @param $hours
     * @return int
     */
    protected function pruneCampaignLog($hours)
    {
        $sql = <<<SQL
            DELETE campaign_log FROM campaign_log
                    LEFT JOIN
                campaigns ON (campaign_id = campaigns.id) 
            WHERE
                (status_date <= DATE_SUB(NOW(), INTERVAL $hours HOUR)
                AND campaigns.recipients >= campaigns.to_send) OR campaigns.id IS NULL
SQL;
        $result = mysqli_query($this->getDbConnection(), $sql);
        return mysqli_affected_rows($this->getDbConnection());
    }
}