<?php
/**
 * Campaign.php
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

namespace SendyImprovements\Model;

use SendyImprovements\Logger;

class Campaign extends Model
{
    protected $convertedDate;
    protected $unconvertedDate = array('[currentdaynumber]', '[currentday]', '[currentmonthnumber]', '[currentmonth]', '[currentyear]');
    protected $stats = array();
    protected $links;
    protected $processedHtml;
    protected $processedPlainText;
    protected $processedSubject;
    protected $fieldMap;
    protected $defaultValues;
    protected $attachments;

    /**
     * Create a campaign object from a database row
     *
     * @param $row
     * @return mixed
     */
    public static function factory($row)
    {
        $campaign = parent::factory($row);

        $campaign->emailListExcl = $row['lists_excl'];
        $campaign->currentRecipientCount = $row['recipients'];

        $campaign->plainText = stripslashes($row['plain_text']);
        $campaign->html = stripslashes($row['html_text']);
        $campaign->queryString = stripslashes($row['query_string']);
        $campaign->fromName = stripslashes($row['from_name']);
        $campaign->fromEmail = stripslashes($row['from_email']);
        $campaign->replyTo = stripslashes($row['reply_to']);
        $campaign->title = stripslashes($row['title']);

        $campaign->toSendNum = $row['to_send'];
        $campaign->toSend = $campaign->toSendNum;
        $campaign->userName = $row['name'];
        $campaign->userEmail = $row['username'];
        $campaign->gdprLine = $row['gdpr_only'] ? 'AND gdpr = 1 ' : '';

        if ($campaign->customDomain != '' && $campaign->customDomainEnabled) {
            $parse = parse_url(APP_PATH);
            $campaign->domain = $parse['host'];
            $campaign->protocol = $parse['scheme'];
            $campaign->appPath = str_replace($campaign->domain, $campaign->customDomain, APP_PATH);
            $campaign->appPath = str_replace($campaign->protocol, $campaign->customDomainProtocol, $campaign->appPath);
        } else {
            $campaign->appPath = APP_PATH;
        }

        if ($row['label'] == '') {
            $campaign->label = $campaign->title;
        } else {
            $campaign->label = stripslashes(htmlentities($row['label'], ENT_QUOTES, "UTF-8"));
        }

        if ($campaign->timezone) {
            $timezone = $campaign->timezone;
        } else {
            $timezone = $campaign->userTimezone;
        }
        date_default_timezone_set($timezone);

        // We only want to include campaigns that haven't been scheduled into the future
        if ($campaign->sendDate <= time() || $campaign->toSend > $campaign->currentRecipientCount) {
            $campaign->readyToRun = true;
            $campaign->logSummaryStart = $campaign->getCampaignLogSummary();

            if (empty($campaign->logSummaryStart)) {
                $campaign->firstRun = true;
            } else {
                $campaign->firstRun = false;
            }

            // Not sure why, but after the first run, the lists field gets moved to the to_send_lists field, so
            // if this isn't the first run of this campaign, we'll use the to_send_lists field for our lists
            if ($campaign->firstRun) {
                $campaign->emailList = $row['lists'];
            } else {
                $campaign->emailList = $row['to_send_lists'];
            }
        } else {
            $campaign->readyToRun = false;
        }
        return $campaign;
    }


    /**
     * Retuns an array of campaign objects for any campaigns that should be sent right now -- or if a campaign id is passed
     * to this method will return a campaign object for the specified campaign
     *
     * @return array|null
     */
    public static function getCampaignsToSend($campaignId=null)
    {
        //Check campaigns database
        $db = self::getDbConnection();
        $campaigns = array();

        if ($campaignId) {
            // Where searches for the specified campaign
            $where = "campaigns.id = $campaignId";
        } else {
            // Where searches for unsent campaigns
            $where = "
                    (send_date != '' AND lists != ''
                    AND campaigns.timezone != '')
                    OR (to_send > recipients)
            ";
        }

        $sql = <<<SQL
            SELECT 
                campaigns.timezone AS timezone,
                sent,
                campaigns.id,
                campaigns.app,
                campaigns.userID,
                to_send,
                to_send_lists,
                recipients,
                timeout_check,
                send_date,
                lists,
                lists_excl,
                segs,
                segs_excl,
                campaigns.from_name,
                campaigns.from_email,
                campaigns.reply_to,
                title,
                label,
                plain_text,
                html_text,
                campaigns.query_string,
                opens_tracking,
                links_tracking,
                login_sender.language,
                login_owner.s3_key,
                login_owner.s3_secret,
                login_owner.name,
                login_owner.username,
                login_owner.timezone AS user_timezone,
                login_owner.ses_endpoint,
                login_owner.send_rate,
                smtp_host,
                smtp_port,
                smtp_ssl,
                smtp_username,
                smtp_password,
                notify_campaign_sent,
                gdpr_only,
                custom_domain,
                custom_domain_protocol,
                custom_domain_enabled
            FROM
                campaigns
                    INNER JOIN
                login AS login_sender ON (login_sender.app = campaigns.app)
                    INNER JOIN
                apps ON (campaigns.app = apps.id)
                    INNER JOIN
                login AS login_owner ON (login_owner.id = campaigns.userID)
            WHERE
                $where
            ORDER BY sent DESC
SQL;

        $result = mysqli_query($db, $sql);

        if ($result && mysqli_num_rows($result) > 0) {

            while ($row = mysqli_fetch_assoc($result)) {
                $campaign = self::factory($row);

                if ($campaign->readyToRun) {
                    $campaigns[$campaign->id] = $campaign;
                } else {
                    unset($campaign);
                }
            }
            return $campaigns;
        } else {
            return null;
        }
    }

    /**
     * Gets summary data for the campaign_log table
     *
     * @return array
     */
    public function getCampaignLogSummary()
    {
        $summary = array();

        $sql = sprintf("SELECT status, count(*) AS count FROM campaign_log WHERE campaign_id=%s group by status;",
            $this->id);
        $result = mysqli_query($this->getDbConnection(), $sql);

        if ($result) {
            while ($row = mysqli_fetch_array($result)) {
                $status = strtolower($row['status']);
                $summary[$status] = $row['count'];
            }
        }
        return $summary;
    }

    /**
     * Set stats for tracking
     *
     * @param $name
     * @param null $value
     */
    public function setStats($name, $value = null)
    {
        if (is_array($name)) {
            foreach ($name as $key => $value) {
                $this->stats[$key] = $value;
            }
        } else {
            $this->stats[$name] = $value;
        }
    }

    /**
     * Get stats that we're tracking for a campaign
     *
     * @param null $name
     * @return array|mixed|string|null
     */
    public function getStats($name = null)
    {
        if (!$name) {
            return $this->stats;
        } elseif ($name == 'elapsed_time') {
            return $this->getStats('end_time') - $this->getStats('start_time');
        } elseif ($name == 'elapsed_time_formatted') {
            $elapsedTime = $this->getStats('elapsed_time');
            if (!$elapsedTime) {
                $elapsedTime = 1;
            }
            return sprintf('%02d:%02d:%02d', ($elapsedTime / 3600), ($elapsedTime / 60 % 60), $elapsedTime % 60);
        } elseif (!isset($this->stats[$name])) {
            return null;
        } else {
            return $this->stats[$name];
        }
    }

    /**
     * Adds campaign_log entries for all the subscribers who will receive a campaign, setting their status to PENDING so that
     * we can track if/when they were emailed
     *
     * @param $campaign ->id
     * @return int
     */
    public function insertCampaignLogPending()
    {
        $mainQuery = '';

        if ($this->emailList) {
            $mainQuery .= 'subscribers.list in (' . $this->emailList . ') ';
        }

        //Include segmentation query
        $segmentQuery = $mainQuery != '' && $this->segs != 0 ? 'OR ' : '';
        $segmentQuery .= $this->segs == 0 ? '' : '(subscribers_seg.seg_id IN (' . $this->segs . ')) ';

        //Exclude list query
        $excludeQuery = $this->emailListExcl == 0 ? '' : 'subscribers.email NOT IN 
            (SELECT email FROM subscribers WHERE list IN (' . $this->emailListExcl . ')) ';

        //Exclude segmentation query
        $excludeSegmentQuery = $excludeQuery != '' && $this->segsExcl != 0 ? 'AND ' : '';
        $excludeSegmentQuery .= $this->segsExcl == 0 ? '' : 'subscribers.email NOT IN 
            (SELECT subscribers.email FROM subscribers LEFT JOIN subscribers_seg 
            ON (subscribers.id = subscribers_seg.subscriber_id) 
            WHERE subscribers_seg.seg_id IN (' . $this->segsExcl . '))';

        $sql = 'INSERT INTO campaign_log (campaign_id, subscriber_id, status, status_date, message_id) ' .
            'SELECT %s, subscribers.id, "PENDING", NOW(), NULL FROM subscribers ';

        if ($this->segs != 0 || $this->segsExcl != 0) {
            $sql .= 'LEFT JOIN subscribers_seg ON (subscribers.id = subscribers_seg.subscriber_id) ';
        }

        $sql .= 'WHERE (' . $mainQuery . $segmentQuery . ') ';

        if ($excludeQuery != '' || $excludeSegmentQuery != '') {
            $sql .= 'AND (' . $excludeQuery . $excludeSegmentQuery . ') ';
        }

        $sql .= 'AND subscribers.unsubscribed = 0 
                    AND subscribers.bounced = 0 
                    AND subscribers.complaint = 0 
                    AND subscribers.confirmed = 1 '
            . $this->gdprLine . '
                    AND subscribers.id NOT IN 
                    (SELECT subscriber_id FROM campaign_log WHERE campaign_log.campaign_id = %s)
                    GROUP BY subscribers.email 
                    ORDER BY subscribers.id ASC';

        $sql = sprintf($sql, $this->id, $this->id);

        $result = mysqli_query($this->getDbConnection(), $sql);
        if ($result) {
            $this->toSend = mysqli_affected_rows($this->getDbConnection());
        } else {
            $this->toSend = 0;
        }
        return $this;
    }

    /**
     * Mark a campaign as having been sent
     *
     * @param $campaign ->id
     * @param $toSend
     * @param $toSendLists
     * @return bool
     */
    public function updateSentStatus()
    {
        $dbConnection = $this->getDbConnection();
        $now = time();

        $sql = sprintf('UPDATE campaigns SET sent = "%s", to_send = %s, to_send_lists = "%s", send_date=NULL, lists=NULL, timezone=NULL WHERE id = %s',
            $now, $this->toSend, $this->emailList, $this->id);

        $result = mysqli_query($dbConnection, $sql);
        $this->sent = $now;
        $this->toSendLists = $this->emailList;
        return $this;
    }

    /**
     * Add all the various links to the database that we need in order to do link tracking
     */
    public function updateLinksTracking()
    {
        $dbConnection = $this->getDbConnection();
        $values = [];

        if ($this->linksTracking) {
            //Insert web version link
            $links = $this->getLinksFromText($this->html);

            if (strpos($this->html, '</webversion>') == true || strpos($this->html, '[webversion]') == true) {
                $links['webversion'] = $this->getWebversion();
            }

            //Insert reconsent link
            if (strpos($this->html, '[reconsent]') == true) {
                $links['reconsent'] = $this->appPath . '/r?c=' . short($this->id);
            }

            $values = array();
            foreach ($links as $link) {
                $values[] = sprintf('(%s, "%s")', $this->id, $link);
            }
            if ($values) {
                $sql = "INSERT INTO links (campaign_id, link) VALUES " . implode(",", $values);
                mysqli_query($dbConnection, $sql);
            }
        }
    }

    public function getLinksFromText($text)
    {
        $links = array();
        //extract all links from HTML
        preg_match_all('/href=["\']([^"\']+)["\']/i', $text, $matches, PREG_PATTERN_ORDER);
        $matches = array_unique($matches[1]);

        foreach ($matches as $link) {
            // Append the campaign's query string if appropriate
            if (substr($link, 0, 1) != "#" &&
                substr($link, 0, 6) != "mailto" &&
                substr($link, 0, 3) != "ftp" &&
                substr($link, 0, 3) != "tel" &&
                substr($link, 0, 3) != "sms" &&
                substr($link, 0, 13) != "[unsubscribe]" &&
                substr($link, 0, 12) != "[webversion]" &&
                substr($link, 0, 11) != "[reconsent]" &&
                !strpos($link, 'fonts.googleapis.com') &&
                !strpos($link, 'use.typekit.net') &&
                !strpos($link, 'use.fontawesome.com')) {
                $links[$link] = $this->getLinkWithQueryString($link, $this->queryString);
            }
        }
        return $links;
    }

    /**
     * Adds a query string to a link
     *
     * @param $link
     * @return string
     */
    public function getLinkWithQueryString($link)
    {
        if ($this->queryString) {
            if (strpos($link, '?') !== false) {
                /*
                 *  If there's already a query string in the URL, we don't want to overwrite any variables that may already
                 *  in the URL. For example, when including an ad in an email a client may want specific Google Analytics tracking codes
                 *  (e.g., utm_source=XXXX) in their links, but we may also want to add Google Analytics tracking codes
                 *  of our own -- but only in the case where those tracking codes don't already exist! Otherwise the client
                 *  won't be able to track their ads in the way they want to
                 */
                $linkQueryArray = array();
                $queryArray = array();
                $parsedUrl = parse_url($link);

                parse_str($parsedUrl['query'], $linkQueryArray);
                parse_str($this->queryString, $queryArray);

                $queryString = http_build_query(array_merge($queryArray, $linkQueryArray));

                $link = '';
                if (isset($parsedUrl['scheme'])) {
                    $link .= $parsedUrl['scheme'] . ':';
                }
                $link .= '//' . $parsedUrl['host'];
                if (isset($parsedUrl['path'])) {
                    $link .= $parsedUrl['path'];
                }
                $link .= '?' . $queryString;
            } else {
                // If there's no query string in the URL, we can just append our query string to the URL.
                $link = $link . '?' . $this->queryString;
            }
        }
        return $link;
    }

    public function getWebversion($subscriberId = null, $listId = null)
    {
        if ($subscriberId) {
            return $this->appPath . '/w/' . short($subscriberId) . '/' .
                short($listId) . '/' . short($this->id);
        } else {
            return $this->appPath . '/w/' . short($this->id);
        }
    }

    /**
     * Gets the campaign links
     *
     * @return array|null
     */
    public function getLinks()
    {
        if ($this->links === null) {
            $sql = 'SELECT id, link FROM links WHERE campaign_id = ' . $this->id;

            $result = mysqli_query($this->getDbConnection(), $sql);
            if ($result && mysqli_num_rows($result)) {
                $this->links = mysqli_fetch_all($result, MYSQLI_ASSOC);
            } else {
                $this->links = array();
            }
        }
        return $this->links;
    }

    /**
     * Replaces any tags with either the correct SES template field name or with the correct user data
     *
     * @param $text
     * @param bool $includeTrackingPixel
     * @return array
     */
    protected function processText($text, $includeTrackingPixel = false)
    {
        $fieldMap = array();
        $defaultValues = array();

        $text = str_replace($this->unconvertedDate, $this->getConvertedDate(), $text);
        $linksQueryStringMap = array_flip($this->getLinksFromText($text, $this->queryString));

        $links = $this->getLinks();
        foreach ($links as $campaignLink) {
            $sesFieldName = 'link_' . $campaignLink['id'];
            $defaultValues[$sesFieldName] = $campaignLink['link'];

            if ($this->queryString) {
                if (isset($linksQueryStringMap[$campaignLink['link']])) {
                    $linkWithOutQueryString = $linksQueryStringMap[$campaignLink['link']];

                    $text = preg_replace('/(href=["\'])(' . preg_quote($linkWithOutQueryString, '/') . '+)(["\'])/i', '$1{{' . $sesFieldName . '}}$3', $text);
//                    $text = str_replace($linkWithOutQueryString, '{{' . $sesFieldName . '}}', $text);
                }

            }
            $text = preg_replace('/href=["\'](' . preg_quote($campaignLink['link'], '/') . ')+["\']/i', '$1{{' . $sesFieldName . '}}$3', $text);
//            $text = str_replace($campaignLink['link'], '{{' . $sesFieldName . '}}', $text);
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
                        $fallback = $this->getWebversion();
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
     * Processes the all the campaign text: subject line, html, and plaintext
     */
    protected function processAllText()
    {
        $htmlArray = $this->processText($this->html, $this->opensTracking);
        $this->processedHtml = $htmlArray['text'];

        $plainTextArray = $this->processText($this->plainText, false);
        $this->processedPlainText = $plainTextArray['text'];

        $subjectArray = $this->processText($this->title, false);
        $this->processedSubject = $subjectArray['text'];

        $this->fieldMap = $this->processFieldMap($htmlArray['field_map'], $plainTextArray['field_map'], $subjectArray['field_map']);
        $this->defaultValues = $htmlArray['default_values'] + $plainTextArray['default_values'] + $subjectArray['default_values'];
    }

    /**
     * The fieldmap is a mapping between variables (e.g., a user's name as they exist in the database and the fields as they
     * are used in a template. The reason a mapping is needed is because the same variable can have different fallbacks.
     * This method combines the fieldmaps for the html, plaintext and subject into one fieldmap
     *
     * @param $fieldMapHtml
     * @param $fieldMapPlaintext
     * @param $fieldMapSubject
     * @return mixed
     */
    protected function processFieldMap($fieldMapHtml, $fieldMapPlaintext, $fieldMapSubject)
    {
        $fieldMap = $fieldMapHtml;

        foreach ($fieldMapPlaintext as $variableName => $fieldArray) {
            if (!isset($templateVariables[$variableName])) {
                $templateVariables[$variableName] = array();
            }
            foreach ($fieldArray as $fieldName => $fieldValue) {
                $templateVariables[$variableName][$fieldName] = $fieldValue;
            }
        }
        foreach ($fieldMapSubject as $variableName => $fieldArray) {
            if (!isset($templateVariables[$variableName])) {
                $templateVariables[$variableName] = array();
            }
            foreach ($fieldArray as $fieldName => $fieldValue) {
                $templateVariables[$variableName][$fieldName] = $fieldValue;
            }
        }
        return $fieldMap;
    }

    /**
     * Gets the processed html for a campaign
     *
     * @return string
     */
    public function getProcessedHtml()
    {
        if ($this->processedHtml === null && $this->processedPlainText === null && $this->processedSubject === null) {
            $this->processAllText();
        }
        return $this->processedHtml;
    }

    /**
     * Gets the processed plain text for a campaign
     *
     * @return string
     */
    public function getProcessedPlainText()
    {
        if ($this->processedHtml === null && $this->processedPlainText === null && $this->processedSubject === null) {
            $this->processAllText();
        }
        return $this->processedPlainText;
    }

    /**
     * Gets the processed subject line for a campaign
     *
     * @return string
     */
    public function getProcessedSubject()
    {
        if ($this->processedHtml === null && $this->processedPlainText === null && $this->processedSubject === null) {
            $this->processAllText();
        }
        return $this->processedSubject;
    }

    /**
     * Returns a map of campaign fields to their SES template fields
     *
     * @return mixed
     */
    public function getFieldMap()
    {
        if ($this->processedHtml === null && $this->processedPlainText === null && $this->processedSubject === null) {
            $this->processAllText();
        }
        return $this->fieldMap;
    }

    /**
     * Gets the default values for the fields in a campaign
     *
     * @return mixed
     */
    public function getDefaultValues()
    {
        if ($this->processedHtml === null && $this->processedPlainText === null && $this->processedSubject === null) {
            $this->processAllText();
        }
        return $this->defaultValues;
    }

    /**
     * Get an array of localized date formats, which we can use in our email tags
     *
     * @return array
     */
    protected function getConvertedDate()
    {
        if ($this->convertedDate === null) {
            //convert date tags
            $today = time();
            $day_word = array(_('Sunday'), _('Monday'), _('Tuesday'), _('Wednesday'), _('Thursday'), _('Friday'), _('Saturday'));
            $month_word = array('', _('January'), _('February'), _('March'), _('April'), _('May'), _('June'), _('July'), _('August'), _('September'), _('October'), _('November'), _('December'));

            $currentdaynumber = strftime('%d', $today);
            $currentday = $day_word[strftime('%w', $today)];
            $currentmonthnumber = strftime('%m', $today);
            $currentmonth = $month_word[ltrim($currentmonthnumber, '0')];
            $currentyear = strftime('%Y', $today);

            $this->convertedDate = array($currentdaynumber, $currentday, $currentmonthnumber, $currentmonth, $currentyear);
        }
        return $this->convertedDate;
    }

    /**
     * Get the subscribers we still need to mail to for a campaign
     *
     * @return bool|mysqli_result
     */
    public function getSubscribers()
    {
        $sql = <<<SQL
            SELECT 
                subscribers.id,
                subscribers.name,
                subscribers.email,
                subscribers.list,
                subscribers.custom_fields
            FROM
                subscribers
            INNER JOIN
                campaign_log 
            ON 
                (subscribers.id = campaign_log.subscriber_id)
            WHERE
                campaign_log.status = 'PENDING'
            AND 
                campaign_log.campaign_id = %s
            ORDER BY 
                campaign_log.subscriber_id 
SQL;
        $result = mysqli_query($this->getDbConnection(), sprintf($sql, $this->id));
        if ($result && mysqli_num_rows($result) > 0) {
            return $result;
        } else {
            return false;
        }
    }


    /**
     * Increments the send count in the campaign table
     *
     * @param $incrementCount
     * @return $this
     */
    public function incrementSentCount($incrementCount)
    {
        Logger::debug("Increment by", $incrementCount);

        //increment recipients number in campaigns table
        $sql = sprintf('UPDATE campaigns SET recipients = recipients + %s WHERE id = %s',
            $incrementCount, $this->id);

        $result = mysqli_query($this->getDbConnection(), $sql);
        return $this;
    }

    /**
     *  Updates the subscribers in the campaign_log and subscribers table
     *
     * @param $sendResult
     */
    public function updateSubscribers($sendResult)
    {
        $campaignLogRows = array();
        $campaignLogValuesArray = array();

        // First we'll update the campaign_log table
        foreach ($sendResult['subscriber_successes'] as $subscriberId => $messageId) {
            $campaignLogRows[] = array(
                'subscriber_id' => $subscriberId,
                'status' => '"SUCCESS"',
                'message_id' => '"' . $messageId . '"',
            );
        }
        foreach ($sendResult['subscriber_errors'] as $subscriberId => $status) {
            $campaignLogRows[] = array(
                'subscriber_id' => $subscriberId,
                'status' => '"' . $status . '"',
                'message_id' => 'NULL',
            );
        }

        foreach ($campaignLogRows as $row) {
            $campaignLogValuesArray[] = sprintf('(%s, %s, %s, NOW(), %s)',
                $this->id,
                $row['subscriber_id'],
                $row['status'],
                $row['message_id']
            );
        }

        $sql = "INSERT INTO campaign_log (campaign_id, subscriber_id, status, status_date, message_id) VALUES " .
            implode($campaignLogValuesArray, ',') .
            ' ON DUPLICATE KEY UPDATE status=VALUES(status), status_date=VALUES(status_date), message_id=VALUES(message_id)';
        $result = mysqli_query($this->getDbConnection(), $sql);

        // Next we'll update the subscribers table
        if ($sendResult['subscriber_successes']) {
            $subscriberIds = implode(", ", array_keys($sendResult['subscriber_successes']));
            //update last_campaign / message id
            $sql = sprintf('UPDATE subscribers INNER JOIN campaign_log ON subscribers.id = campaign_log.subscriber_id 
                        SET messageID = message_id, last_campaign = %s
                        WHERE subscribers.id IN (%s) AND campaign_log.campaign_id = %s', $this->id, $subscriberIds, $this->id);

            $result = mysqli_query($this->getDbConnection(), $sql);
        }
    }

    /**
     * Sets the campaign as completed in the database which is done by making the recipients column equal to the to_send column.
     * Theoretically, this shouldn't be necessary, but should something weird happen this will prevent our campaigns from reunning
     * over and over again.
     */
    public function markCompleted()
    {
        Logger::debug("Mark completed");
        $sql = sprintf('UPDATE campaigns SET recipients = to_send WHERE id = %s',
            $this->id);
        return mysqli_query($this->getDbConnection(), $sql);
    }

    /**
     * Gets an array of file paths for any attachments that a campaign might have
     *
     * @return array|null
     */
    public function getAttachments()
    {
        if ($this->attachments === null) {
            $this->attachments = array();
            //check if attachments are available for this campaign to attach
            if (file_exists(SENDY_ROOT . '/uploads/attachments/' . $this->id)) {
                foreach (glob(SENDY_ROOT . '/uploads/attachments/' . $this->id . '/*') as $attachment) {
                    if (file_exists($attachment)) {
                        $this->attachments[] = $attachment;
                    }
                }
            }
        }
        return $this->attachments;

    }


}