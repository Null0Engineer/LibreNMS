CREATE TABLE `ciena_lsr_ids` (
  `device_id` int(10) unsigned NOT NULL,
  `lsr_id` varchar(46) NOT NULL,
  `hostname` varchar(255) DEFAULT NULL,
  `sysName` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`device_id`),
  UNIQUE KEY `lsr_id` (`lsr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
