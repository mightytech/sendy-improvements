<?php

namespace SendyImprovements\Cli;

use SendyImprovements\Logger;
use SendyImprovements\Model\Campaign;

class TestCampaign extends Scheduled
{
    protected $simulatedHtml;
    protected $pidFile = null;
    protected $ignoredErrors;

    public function run()
    {
        Campaign::setDbConnection(self::getDbConnection());
        $this->campaigns = Campaign::getCampaignsToSend($this->activeCampaign);
        if (!$this->campaigns) {
            Logger::info("Campaign not found");
        } else {
            Logger::info("HTML Validation:\n", $this->isValidHtml());
            Logger::info("HTML Size:", $this->getHtmlSize());
        }
    }

    protected function getOpts()
    {
        $options = getopt("c:h", array('campaign:', 'help'));

        if (isset($options['h']) || isset($options['help'])) {
            $this->usage();
            exit;
        }

        if (isset($options['c']) || isset($options['campaign'])) {
            $this->activeCampaign = (int)(isset($options['c']) ? $options['c'] : $options['campaign']);
        }
        if (!$this->activeCampaign) {
            echo "You must supply an active campaign";
            exit;
        }

        Logger::$logLevel = Logger::DEBUG;
        Logger::$backtraceBase = SENDY_ROOT;
        Logger::$showColors = true;
        Logger::$showBacktrace = false;
        Logger::$showDate = false;
    }

    protected function getSimulatedHtml()
    {
        if (!$this->simulatedHtml) {
            $campaign = $this->getActiveCampaign();
            $processedHtml = $campaign->getProcessedHtml();
            $defaultValues = $campaign->getDefaultValues();

            $linkShortUrl = $campaign->appPath . '/l/' . short($campaign->id) . '/' . short($campaign->id) . '/' . short($campaign->id);
            $trackingPixel = '<img src="' . $campaign->appPath . '/t/' . short($campaign->id) . '/' . short($campaign->id) . '" alt="" style="width:1px;height:1px;" />';

            foreach ($defaultValues as $key => $value) {
                $replace = false;
                switch ($key) {
                    case 'field_tracking-pixel':
                        $replaceString = $trackingPixel;
                        break;
                    case 'field_tracking-pixel':
                    case 'field_unsubscribe':
                    case 'field_reconsent':
                    case 'field_webversion':
                        $replaceString = $linkShortUrl;
                        break;
                    default:
                        $replaceString = $key;
                }

                if (strpos($key, 'link_') === 0) {
                    $replaceString = $linkShortUrl;
                }
                $this->simulatedHtml = str_replace('{{' . $key . '}}', $replaceString, $processedHtml);
            }
        }

        return $this->simulatedHtml;
    }

    /**
     * Returns an approximate size for the HTML portion of a campaign in bytes
     * @return int
     */
    protected function getHtmlSize()
    {
        return $this->formatSizeUnits(strlen($this->getSimulatedHtml()));
    }

    protected function formatSizeUnits($bytes)
    {
        if ($bytes >= 1073741824) {
            $bytes = number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            $bytes = number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            $bytes = number_format($bytes / 1024, 2) . ' KB';
        } elseif ($bytes > 1) {
            $bytes = $bytes . ' bytes';
        } elseif ($bytes == 1) {
            $bytes = $bytes . ' byte';
        } else {
            $bytes = '0 bytes';
        }

        return $bytes;
    }

    protected function isValidHtml()
    {
        $filteredErrors = array();
        $ignoredErrors = $this->getIgnoredErrors();
        $campaign = $this->getActiveCampaign();
        $tidy = new \Tidy;
        $tidy->parseString($campaign->html);
        $tidy->cleanRepair();
        $errors = explode("\n",  $tidy->errorBuffer);
        foreach ($errors as $error) {
            $ignore = false;
            foreach ($ignoredErrors as $ignoredError) {
                if (strpos($error, $ignoredError) !== false) {
                    $ignore = true;
                }
            }
            if (!$ignore) {
                $filteredErrors[] = $error;
            }
        }
        return implode("\n", $filteredErrors);
    }

    protected function getIgnoredErrors()
    {
        if ($this->ignoredErrors === null) {
            $ignoredErrors = SI_CAMPAIGN_TEST_IGNORED_ERRORS;
            if (is_string($ignoredErrors) && $ignoredErrors) {
                $this->ignoredErrors = unserialize($ignoredErrors);
            } elseif (is_array($ignoredErrors)) {
                $this->ignoredErrors = $ignoredErrors;
            } else {
                $this->ignoredErrors = array();
            }
        }
        return $this->ignoredErrors;
    }
}