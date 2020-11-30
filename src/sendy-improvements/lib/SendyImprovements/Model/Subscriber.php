<?php
/**
 * Subscriber.php
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

class Subscriber extends Model
{
    static $customFields = array();
    protected $campaign;
    protected $webversionLink;
    protected $unsubscribeLink;
    protected $reconsentLink;
    protected $trackingPixel;

    /**
     * Returns a subscriber object and processes it for sending using the supplied campaign object
     *
     * @param $campaign
     * @param $row
     * @return Subscriber
     */
    public static function campaignFactory($campaign, $row)
    {
        return self::factory($row)->setCampaign($campaign);
    }

    /**
     * Processes a subscriber in order to add the correct template fields for sending
     *
     * @param $campaign
     * @return $this
     * @throws \Exception
     */

    protected function setCampaign($campaign)
    {
        $this->campaign = $campaign;
        
        $campaignFieldMap = $this->campaign->getFieldMap();
        $campaignHtml = $this->campaign->getProcessedHtml();
        $campaignPlainText = $this->campaign->getProcessedPlainText();
        $campaignSubject = $this->campaign->getProcessedSubject();
        $campaignLinks = $this->campaign->getLinks();

        $templateVariables = array();
        $subscriberVariables = array('name' => $this->name, 'email' => $this->email);

        $subscriberCustomFields = explode("%s%", $this->customFields);
        $listCustomFields = $this->getCustomFields();

        $subscriberVariables['Name'] = trim($this->name);
        $subscriberVariables['Email'] = trim($this->email);

        $subscriberVariables['webversion'] = $this->getWebversionLink();
        $subscriberVariables['unsubscribe'] = $this->getUnsubscribeLink();
        $subscriberVariables['reconsent'] = $this->getReconsentLink();
        $subscriberVariables['tracking-pixel'] = $this->getTrackingPixel();

        Foreach ($listCustomFields as $key => $listCustomField) {
            $fieldName = $listCustomField['field_name'];
            $dataType = $listCustomField['data_type'];

            if (isset($subscriberCustomFields[$key]) && trim($subscriberCustomFields[$key])) {
                if ($dataType == 'date') {
                    $fieldValue = strftime("%a, %b %d, %Y", trim($subscriberCustomFields[$key]));
                } else {
                    $fieldValue = trim($subscriberCustomFields[$key]);
                }
                if ($fieldValue) {
                    $subscriberVariables[$fieldName] = $fieldValue;
                }
            }
        }

        // Add campaign links to the subscriber variables
        if ($this->campaign->opensTracking) {
            foreach ($campaignLinks as $campaignLink) {
                $variableName = 'link_'. $campaignLink['id'];
                $subscriberVariables[$variableName] = $this->campaign->appPath . '/l/' . short($this->id) . '/' .
                    short($campaignLink['id']) . '/' . short($this->campaign->id);
            }
        }

        foreach ($subscriberVariables as $variableName => $value) {
            // We only want to add variables with values to our templateVariables -- this way the fallback (default) values
            // will be used if there's no value
            if ($value) {
                if (isset($campaignFieldMap[$variableName])) {
                    foreach ($campaignFieldMap[$variableName] as $fieldName) {
                        if (!$this->campaign->useSesTemplates) {
                            $templateVariables['{{' . $fieldName .'}}'] = $value;
                        } else {
                            $templateVariables[$fieldName] = $value;
                        }
                    }
                } elseif (strpos($variableName, 'link_') === 0) {
                    if (!$this->campaign->useSesTemplates) {
                        $templateVariables['{{' . $variableName .'}}'] = $value;
                    } else {
                        $templateVariables[$variableName] = $value;
                    }
                }
            }
        }

        if (!$this->campaign->useSesTemplates) {
            $campaignHtml = str_replace(array_keys($templateVariables), array_values($templateVariables), $campaignHtml);
            $campaignPlainText = str_replace(array_keys($templateVariables), array_values($templateVariables), $campaignPlainText);
            $campaignSubject = str_replace(array_keys($templateVariables), array_values($templateVariables), $campaignSubject);
        }

        $this->processedHtml = $campaignHtml;
        $this->processedPlainText = $campaignPlainText;
        $this->processedSubject = $campaignSubject;
        $this->templateVariables = $templateVariables;
        return $this;
    }

    /**
     * Pulls the custom fields for the list being used
     *
     * @return mixed
     */
    protected function getCustomFields()
    {
        $listId = $this->list;

        if (!isset( self::$customFields[$listId])) {
            $sql = 'SELECT custom_fields FROM lists WHERE id = ' . $listId;
            $result = mysqli_query($this->getDbConnection(), $sql);

            if ($result && mysqli_num_rows($result)) {
                $customFields = array();
                $row = mysqli_fetch_array($result);
                $customFieldsStrings = explode('%s%', $row['custom_fields']);

                foreach ($customFieldsStrings as $customField) {
                    $customFieldArray = explode(':', $customField);
                    $key = str_replace(' ', '', $customFieldArray[0]);
                    if (isset($customFieldArray[1])) {
                        $dataType = trim($customFieldArray[1]);
                        $customFields[] = array('field_name' => $key, 'data_type' => $dataType);
                    }
                }
                self::$customFields[$listId] = $customFields;
            } else {
                self::$customFields[$listId] =  array();
            }
        }
        return  self::$customFields[$listId];
    }

    /**
     * Gets the webversion link for the subscriber
     *
     * @return string
     * @throws \Exception
     */
    public function getWebversionLink()
    {
        if ($this->campaign === null) {
            throw new \Exception("Method not available until subscriber's campaign has been set.");
        } elseif($this->webversionLink === null) {
            $this->webversionLink = $this->campaign->getWebversion($this->campaign->id, $this->id, $this->list);
        }
        return $this->webversionLink;
    }
    /**
     * Gets the unsubscribe link for the subscriber
     *
     * @return string
     * @throws \Exception
     */
    public function getUnsubscribeLink()
    {
        if ($this->campaign === null) {
            throw new \Exception("Method not available until subscriber's campaign has been set.");
        } elseif($this->unsubscribeLink === null) {
            $this->unsubscribeLink = $this->campaign->appPath . '/unsubscribe/' . short($this->email) . '/' .
                short($this->list) . '/' . short($this->campaign->id);
        }
        return $this->unsubscribeLink;
    }

    /**
     * Gets the reconsent link for the subscriber
     *
     * @return string
     * @throws \Exception
     */
    public function getReconsentLink()
    {
        if ($this->campaign === null) {
            throw new \Exception("Method not available until subscriber's campaign has been set.");
        } elseif($this->reconsentLink === null) {
            $this->reconsentLink = $this->campaign->appPath . '/r?e=' . short($this->email) . '&a=' . short($this->campaign->app) . '&w=' .
                short($this->id) . '/' . short($this->list) . '/' . short($this->campaign->id);
        }
        return $this->reconsentLink;
    }

    /**
     * Gets the tracking pixel for the subscriber
     *
     * @return string
     * @throws \Exception
     */
    public function getTrackingPixel()
    {
        if ($this->campaign === null) {
            throw new \Exception("Method not available until subscriber's campaign has been set.");
        } elseif($this->trackingPixel === null) {
            $this->trackingPixel = '<img src="' . $this->campaign->appPath . '/t/' . short($this->campaign->id) . '/' .
                short($this->id) . '" alt="" style="width:1px;height:1px;" />';
        }
        return $this->trackingPixel;
    }
}