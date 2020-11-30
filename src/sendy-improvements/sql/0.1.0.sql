CREATE TABLE IF NOT EXISTS `campaign_log` (
 `campaign_id` INT(11) NOT NULL,
 `subscriber_id` INT(11) NOT NULL,
 `status` VARCHAR(50) DEFAULT NULL,
 `status_date` DATETIME DEFAULT NULL,
 `message_id` VARCHAR(100) DEFAULT NULL,
 PRIMARY KEY (`campaign_id` , `subscriber_id`),
 KEY `index_status` (`status`),
 KEY `index_status_date` (`status_date`),
 KEY `index_campaign_id` (`campaign_id`),
 KEY `index_subscriber_id` (`subscriber_id`)
);