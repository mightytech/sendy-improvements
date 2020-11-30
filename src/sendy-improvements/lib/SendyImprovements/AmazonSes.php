<?php
/**
 * AmazonSes.php
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

namespace SendyImprovements;

use \Curl\Curl;
use \Curl\MultiCurl;

class AmazonSes
{
    const SERVICE = 'email';
    const DOMAIN = 'amazonaws.com';
    const ALGORITHM = 'AWS4-HMAC-SHA256';
    const CONTENT_TYPE_FORM_URLENCODED = 'application/x-www-form-urlencoded';
    const METHOD_POST = 'POST';

    protected $sleepMicroSeconds = 1000000; // 1 second
    protected $region;
    protected $apiBaseUrl;
    protected $accessKeyId;
    protected $secretKey;
    protected $certificateFile;
    protected static $throttleLog = array();
    protected $maxDestinationsPerBulkEmail = 50; // Should be no more than 50
    protected $sendRate;

    public function __construct($endPoint, $accessKeyId, $secretKey, $sendRate, $certificateFile)
    {
        // Set region based on the endpoint given
        preg_match("/\.(.*)\.amazonaws.com/i", $endPoint, $matches);
        if (isset($matches[1])) {
            $this->region = strtolower($matches[1]);
        }
        $this->apiBaseUrl = 'https://' . $endPoint;
        $this->accessKeyId = $accessKeyId;
        $this->secretKey = $secretKey;
        $this->sendRate = $sendRate;
        $this->certificateFile = $certificateFile;
    }

    /**
     * Returns the number of SES sends that can be made before throttling. If a count is supplied, it will also
     * check to see if the next request will exceed the SES send rate. If so, throttle the request (sleep)
     *
     * @param $count int The amount of NEW emails that we want to send with the next request
     * @return int The number of allowed sends
     */
    public function checkThrottle($count=0)
    {
        $oneSecond = 1000000;
        $totalCount = 0;
        $now = microtime(true);
        $sleepTime = 0;
        $maxSends = $this->sendRate;

        foreach (self::$throttleLog as $key => $log) {
            $logCount = $log['count'];
            $logTime = $log['time'];
            $potentialSleepTime =  (1 - ($now - $logTime)) * $oneSecond;

            if (($now - $logTime) <= 1) {
                if ($potentialSleepTime >= $sleepTime) {
                    $sleepTime = $potentialSleepTime;
                }
                $totalCount = $totalCount + $logCount;
            } else {
                unset(self::$throttleLog[$key]);
            }
        }
        self::$throttleLog = array_values(self::$throttleLog);

        if ($count && ($totalCount + $count) > $maxSends) {
            logger::debug("Sleeping for", $sleepTime, "microseconds");
            usleep($sleepTime);
            // Since we've just called usleep(), we can now reset the $throttleLog and set our allowedSends to the maximum
            self::$throttleLog = array();
            $allowedSends = $maxSends;
        } else {
            $allowedSends = ($maxSends + $count) - $totalCount;
        }

        if ($allowedSends <= 0) {
            return 0;
        } else {
            return $allowedSends;
        }
    }

    /**
     * Log a request into our throttle counter so we ensure we don't exceed our SES send rate
     *
     * @param $count
     */
    protected function addThrottleCount($count)
    {
        $now = microtime(true);
        self::$throttleLog[] = array('time' => $now, 'count' => $count);
    }

    /**
     * Get an SES template by its name
     *
     * @param $templateName
     * @return array
     * @throws \Exception
     */
    public function getTemplate($templateName)
    {
        $requestParameters = array(
            'Action' => "GetTemplate",
            'TemplateName' => $templateName
        );

        $response = $this->sendRequest($requestParameters);
        return $this->parseResponse($response);
    }

    /**
     * Get a list of SES templates that have been saved
     *
     * @param null $nextToken
     * @return array
     * @throws \Exception
     */
    public function listTemplates($nextToken=null)
    {
        $templates = array();

        $requestParameters = array(
            'Action' => "ListTemplates",
        );
        if ($nextToken) {
            $requestParameters['NextToken'] = $nextToken;
        }

        $response = $this->sendRequest($requestParameters);
        $responseArray = $this->parseResponse($response);

        if (isset($responseArray['response_xml']->ListTemplatesResult->TemplatesMetadata->member)) {
            foreach ($responseArray['response_xml']->ListTemplatesResult->TemplatesMetadata->member as $member) {
                $templates[] = (string) $member->Name;
            }
        }

        if (isset($responseArray['response_xml']->ListTemplatesResult->NextToken)) {
            $nextToken =  (string) $responseArray['response_xml']->ListTemplatesResult->NextToken;
            $templates = array_merge($templates, $this->listTemplates($nextToken));
        }
        return $templates;
    }

    /**
     * Create a new SES template
     *
     * @param $templateName
     * @param $subject
     * @param $html
     * @param $plainText
     * @return array
     * @throws \Exception
     */
    public function createTemplate($templateName, $subject, $html, $plainText)
    {
        Logger::debug("Creating template", $templateName);

        $requestParameters = array(
            'Action' => "CreateTemplate",
            'Template.HtmlPart' => $html,
            'Template.SubjectPart' => $subject,
            'Template.TextPart' => $plainText,
            'Template.TemplateName' => $templateName,
        );

        $response = $this->sendRequest($requestParameters);
        return $this->parseResponse($response);
    }

    /**
     * Delete an SES template
     *
     * @param $templateName
     * @return array
     * @throws \Exception
     */
    public function deleteTemplate($templateName)
    {
        Logger::debug("Deleting template", $templateName);

        $requestParameters = array(
            'Action' => "DeleteTemplate",
            'TemplateName' => $templateName
        );

        $response = $this->sendRequest($requestParameters);
        return $this->parseResponse($response);
    }

    /**
     * Sends messages using the SES sendRawEmail action. Will send messages using curl_multi if the message array contains more than
     * one address. The message array should be in the following format:
     * $messageArray = array(
     *  'to_email' => 'email@domain.com',
     *  'to_name' => 'Recipient Name',
     *  'subject' => 'Subject to be sent',
     *  'html' => '<html>HTML version of email</html',
     *  'plain_text' => 'Plain text version of email',
     *  'attachments' => array('/path/to/attachment_1', '/path/to/attachment_2'),
     *  'headers' => array('x-custom-header' => 'header-value'),
     * );
     *
     * Note that 'to_name', 'attachments' and 'headers' are optional.
     *
     * @param $source
     * @param $messageArray
     * @return array
     * @throws \Exception
     */
    public function sendRawEmail($source, $messageArray)
    {
        // If $messageArray is a single message, make it an array with just the one message in it to simplify processing
        if (isset($messageArray['to']) && isset($messageArray['subject'])) {
            $messageArray = array($messageArray);
        }

        $destinationCounter = count($messageArray);
        $this->checkThrottle($destinationCounter);
        $this->addThrottleCount($destinationCounter);

        foreach ($messageArray as $message) {
            $mailerClass = SI_MAILER_CLASS;
            $mailer = new $mailerClass();
            if (is_array($source) && isset($source['name']) && isset($source['email'])) {
                $mailer->setFrom($source['email'], $source['name']);
            } elseif (is_array($source) && isset($source['email'])) {
                $mailer->setFrom($source['email']);
            } elseif (!is_array($source)) {
                $mailer->setFrom($source);
            } else {
                throw new \Exception("Email 'source' is not in a valid format.");
            }
            if (isset($message['to_name'])) {
                $mailer->addAddress($message['to_email'], $message['to_name']);     // Add a recipient
            } else {
                $mailer->addAddress($message['to_email']);
            }

            if (isset($message['attachments'])) {
                if (!is_array($message['attachments'])) {
                    $message['attachments'] = array($message['attachments']);
                }
                foreach ($message['attachments'] as $attachment) {
                    if ($attachment) {
                        $mailer->addAttachment($attachment);
                    }
                }
            }

            if (isset($message['headers'])) {
                foreach ($message['headers'] as $header => $value) {
                    if ($header) {
                        $mailer->addCustomHeader($header, $value);
                    }
                }
            }

            $mailer->isHTML(true);                                  // Set email format to HTML

            $mailer->Subject = $message['subject'];
            $mailer->Body    = $message['html'];
            $mailer->AltBody = $message['plain_text'];

            $mailer->preSend();
            $rawMessage = $mailer->getSentMIMEMessage();

            $requestParameters = array(
                'Action' => 'SendRawEmail',
                'Source' => $source,
                'RawMessage.Data' => base64_encode($rawMessage),
            );

            $requestParametersArray[] = $requestParameters;
        }

        // If there is only one $requestParametersArray, we don't need multithreading, but to keep things easy for post-processing
        // we'll return our result as an array as if we did do multithreading
        if (count($requestParametersArray) == 1) {
            $response = $this->sendRequest($requestParametersArray[0]);
            $destinationEmails = $this->requestParametersToDestinationEmails($response->getOpt(CURLOPT_POSTFIELDS));
            $responses = array(
                array(
                    'destination-emails' => $destinationEmails,
                    'send-result' => $this->parseResponse($response)
                )
            );
        } else {
            $responses = $this->sendMultiRequest($requestParametersArray);
        }
        return $responses;
    }

    /**
     * @param $source string The "From" name/address of the sender
     * @param $template string The name of the template
     * @param $destinations array Array of destinations with the key being the ToAddress and the value being an array of template variables
     * @param $defaultTemplateData array The default template data to use
     * @param $resendCounter integer How many resend attaempts we've tried with this request
     * @return array
     * @throws \Exception
     */
    public function sendBulkTemplatedEmail($source, $template, $destinations, $defaultTemplateData, $resendCounter=0)
    {
        $totalDestinations = count($destinations);
        Logger::debug("Sending messages to", $totalDestinations, "recipients");
        $requestParametersArray = array();

        $destinationsChunks = array_chunk($destinations, $this->maxDestinationsPerBulkEmail, true);

        foreach ($destinationsChunks as $destinations) {
            $destinationCounter = 0;
            $requestParameters = array(
                'Action' => 'SendBulkTemplatedEmail',
                'Source' => $source,
                'Template' => $template,
                "DefaultTemplateData" => json_encode($defaultTemplateData),
            );

            foreach ($destinations as $toAddress => $templateData) {
                $destinationCounter = $destinationCounter + 1;
                $requestParameters['Destinations.member.' . $destinationCounter . '.Destination.ToAddresses.member.1'] = $toAddress;
                $requestParameters['Destinations.member.' . $destinationCounter . '.ReplacementTemplateData'] = json_encode($templateData);
            }

            $requestParametersArray[] = $requestParameters;
        }

        $this->checkThrottle($totalDestinations);
        $this->addThrottleCount($totalDestinations);

        // If there is only one $requestParametersArray, we don't need multithreading, but to keep things easy for post-processing
        // we'll return our result as an array as if we did do multithreading
        if (count($requestParametersArray) == 1) {
            $response = $this->sendRequest($requestParametersArray[0]);
            $destinationEmails = $this->requestParametersToDestinationEmails($response->getOpt(CURLOPT_POSTFIELDS));
            $responses = array(
                array(
                    'destination-emails' => $destinationEmails,
                    'send-result' => $this->parseResponse($response)
                )
            );
        } else {
            $responses = $this->sendMultiRequest($requestParametersArray);
        }

        return $responses;

        $parsedResponses = array();
        foreach ($unprocessedResponses as $response) {
            $parsedResponses[] = array('destination-emails' => $response['destination-emails'], 'send-result' => $this->parseResponse($response['curl']));
        }
        return $parsedResponses;
    }

    /**
     * Takes the request parameters sent to SES in an API call and pulls out the email addresses that correspond to the destinations
     * for the API request
     *
     * @param $requestParameters
     * @return array
     */
    public function requestParametersToDestinationEmails($requestParameters)
    {
        $destinations = array();
        $parameterArray = array();
        if (is_array($requestParameters)) {
            $parameterArray = $requestParameters;
        } else {
            parse_str($requestParameters, $parameterArray);
        }

        foreach ($parameterArray as $key => $value) {
            if (preg_match('/^Destinations[._]member[._]([0-9]{1,2})[._]Destination/', $key, $matches)) {
                $key = $matches[1] - 1;
                $destinations[$key] = $value;
            } elseif ($key == 'RawMessage_Data' || $key == "RawMessage.Data") {
                $rawMessage = base64_decode($value);
                $matches = array();
                // The header is the part of message that occurs before the first blank line in the raw message
                list($header) = explode("\n\n", $rawMessage);
                if (preg_match('/^To: (.*)$/im', $header, $matches)) {
                    $destinations = explode(',', trim($matches[1]));
                }
            }
        }
        return $destinations;
    }

    /**
     * Sends a CURL request to SES using curl_multi_exec
     *
     * @param $requestParametersArray
     * @return array
     * @throws \ErrorException
     */
    public function sendMultiRequest($requestParametersArray)
    {
        $multiCurl = new MultiCurl();

        $certificateFile = $this->getCertificateFile();
        $result = array();

        $multiCurl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $multiCurl->setOpt(CURLOPT_HEADER, true);
        $multiCurl->setOpt(CURLOPT_RETURNTRANSFER, true);
        $multiCurl->setOpt(CURLOPT_SSL_VERIFYHOST, 2);
        $multiCurl->setOpt(CURLOPT_SSL_VERIFYPEER, 1);
        $multiCurl->setOpt(CURLOPT_CAINFO, $certificateFile);
        $multiCurl->setOpt(CURLOPT_VERBOSE, false);

        $multiCurl->complete(function($instance) use (&$result) {
            $destinationEmails = $this->requestParametersToDestinationEmails($instance->getOpt(CURLOPT_POSTFIELDS));
            $result[] = array('destination-emails' => $destinationEmails, 'send-result' => $this->parseResponse($instance));
        });

        foreach ($requestParametersArray as $requestParameters) {
            if (!isset($requestParameters['Action'])) {
                throw new \Exception("Send Request Needs an Action");
            }

            ksort($requestParameters);
            $curl = $this->getCurl($requestParameters);
            $curl->setOpt(CURLOPT_POST, true);
            $curl->setOpt(CURLOPT_POSTFIELDS, $curl->buildPostData($requestParameters));

            $multiCurl->addCurl($curl);
        }
        $multiCurl->start();

        return $result;
    }

    /**
     * Gets a new Curl object
     *
     * @param $requestParameters
     * @return Curl
     * @throws \ErrorException
     */
    public function getCurl($requestParameters)
    {
        ksort($requestParameters);

        if (!isset($requestParameters['Action'])) {
            throw new \Exception("Send Request Needs an Action");
        }

        $method = self::METHOD_POST;

        $requestUrl = $this->apiBaseUrl;
        $requestHeaders = $this->getRequestHeaders($method, $requestParameters, true);
        $certificateFile = $this->getCertificateFile();

        $curl = new Curl();
        $curl->setUrl($requestUrl);
        foreach ($requestHeaders as $header => $value) {
            $curl->setHeader($header, $value);
        }
        $curl->setOpt(CURLOPT_FOLLOWLOCATION, true);
        $curl->setOpt( CURLOPT_HEADER, true);
        $curl->setOpt( CURLOPT_RETURNTRANSFER, true);
        $curl->setOpt( CURLOPT_SSL_VERIFYHOST, 2);
        $curl->setOpt( CURLOPT_SSL_VERIFYPEER, 1);
        $curl->setOpt( CURLOPT_CAINFO, $certificateFile);
        $curl->setOpt( CURLOPT_VERBOSE, false);

        return $curl;
    }

    /**
     * Sends a CURL request to SES
     *
     * @param $requestParameters
     * @return Curl
     * @throws \ErrorException
     */
    public function sendRequest($requestParameters)
    {
        ksort($requestParameters);

        $curl = $this->getCurl($requestParameters);
        $requestUrl = $this->apiBaseUrl;

        $response = $curl->post(
            $requestUrl,
            $requestParameters
        );

        if ($response === false) {
            throw new \Exception("Curl error: " . $curl->getErrorMessage());
        }

        return $curl;
    }

    /**
     * Does basic parsing on an SES response, primarily looking to see if there were errors
     *
     * @param Curl $curl
     * @return array
     */
    public function parseResponse(Curl $curl)
    {
        $errorCode = null;
        $errorMessage = null;

        $responseCode = $curl->getHttpStatusCode();
        $response = $curl->getRawResponse();
        $responseHeaders = $curl->getRawResponseHeaders();
        $responseContent = substr($response, strlen($responseHeaders));

        $responseXml = simplexml_load_string($responseContent);

        if ($responseXml && $responseXml->Error) {
            $errorCode = (string) $responseXml->Error->Code;
            $errorMessage = (string) $responseXml->Error->Message;
        }

        return array(
            'response_raw' => $response,
            'response_code' => $responseCode,
            'response_headers' => $responseHeaders,
            'response_content' => $responseContent,
            'response_xml' => $responseXml,
            'error_code' => $errorCode,
            'error_message ' => $errorMessage,
        );

    }

    /**
     * Returns the certificate file used for SES
     *
     * @return mixed
     */
    protected function getCertificateFile()
    {
        return $this->certificateFile;
    }

    /**
     * Builds the request headers that we need to send to SES including the authentication signature
     *
     * @param $method
     * @param $queryParameters
     * @param bool $returnArray
     * @return array
     */
    protected function getRequestHeaders($method, $queryParameters, $returnArray=false)
    {
        $amzDate = gmdate('Ymd\THis\Z');
        $date = $date = gmdate('Ymd');
        $contentType = self::CONTENT_TYPE_FORM_URLENCODED;

        $signatureArray = $this->generateSignature($date, $amzDate, $method, $queryParameters);

        $host = 'email' . '.' . $this->region . '.' . 'amazonaws.com';

        $headersArray = array();
        $headersArray['Authorization'] = $signatureArray['Authorization'];
        $headersArray['Content-Type'] = $contentType;
        $headersArray['Host'] = $host;
        $headersArray['X-Amz-Date'] = $signatureArray['X-Amz-Date'];

        if ($returnArray) {
            return $headersArray;
        } else {
            $headers = array();
            foreach ($headersArray as $header => $value) {
                $headers[] = $header . ': ' . $value;
            }
        }
        return $headers;
    }

    /**
     * Generates an authentication signature for SES
     *
     * @param $date
     * @param $amzDate
     * @param $method
     * @param array $queryParameters
     * @return array
     */
    protected function generateSignature($date, $amzDate, $method, $queryParameters = array())
    {
        $result = array();
        $canonicalUri = '/';
        $contentType = self::CONTENT_TYPE_FORM_URLENCODED;

        //Build parameters to send to API
        ksort($queryParameters);
        $requestParameters = $this->formatParameters($queryParameters);

        $host = 'email' . '.' . $this->region . '.' . 'amazonaws.com';

        $canonicalHeaders = 'content-type:' . $contentType . "\n" . 'host:' . $host . "\n" . 'x-amz-date:' . $amzDate . "\n";
        $signedHeaders = 'content-type;host;x-amz-date';
        $payloadHash = hash('sha256', $requestParameters);

        // task1
        $canonicalRequest = $method . "\n" . $canonicalUri . "\n" . '' . "\n" .
            $canonicalHeaders . "\n" . $signedHeaders . "\n" . $payloadHash;

        // task2
        $credentialScope = $date . '/' . $this->region . '/' . 'email' . '/aws4_request';
        $stringToSign =  'AWS4-HMAC-SHA256' . "\n" . $amzDate . "\n" . $credentialScope . "\n" .
            hash('sha256', $canonicalRequest);

        // task3
        $signingKey = $this->generateSignatureKey($date);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);
        $result['Authorization'] = 'AWS4-HMAC-SHA256' .
            ' Credential=' . $this->accessKeyId . '/' . $credentialScope .
            ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
        $result['X-Amz-Date'] = $amzDate;

        return $result;
    }

    /**
     * Create and returns binary hmac sha256
     *
     * @return hmac sha256.
     */
    private function generateSignatureKey($date)
    {
        $dateHash = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $regionHash = hash_hmac('sha256', $this->region, $dateHash, true);
        $serviceHash = hash_hmac('sha256', 'email', $regionHash, true);
        $signingHash = hash_hmac('sha256', 'aws4_request', $serviceHash, true);

        return $signingHash;
    }

    /**
     * Formats an array of parameters into a query string
     *
     * @param $requestParameters
     * @return string
     */
    public function formatParameters($requestParameters)
    {
        return http_build_query($requestParameters);
    }
}