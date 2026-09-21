
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `ai_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `upload_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'queued',
  `progress` tinyint unsigned NOT NULL DEFAULT '0',
  `stage` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `poll_attempts` smallint unsigned NOT NULL DEFAULT '0',
  `error_message` text COLLATE utf8mb4_unicode_ci,
  `request_meta` json DEFAULT NULL,
  `last_status_payload` json DEFAULT NULL,
  `timings` json DEFAULT NULL,
  `queued_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_jobs_upload_id_foreign` (`upload_id`),
  KEY `ai_jobs_user_id_foreign` (`user_id`),
  KEY `ai_jobs_project_id_status_index` (`project_id`,`status`),
  KEY `ai_jobs_external_id_index` (`external_id`),
  CONSTRAINT `ai_jobs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_jobs_upload_id_foreign` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_jobs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ai_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_job_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `addendum_for_estimate_id` bigint unsigned DEFAULT NULL,
  `upload_id` bigint unsigned DEFAULT NULL,
  `run_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `project_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_payload` json NOT NULL,
  `original_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `final_payload` json DEFAULT NULL,
  `final_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model_version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `page_count` int unsigned NOT NULL DEFAULT '0',
  `page_sizes` json DEFAULT NULL,
  `detection_count` int unsigned NOT NULL DEFAULT '0',
  `overall_confidence` double DEFAULT NULL,
  `processing_time` double DEFAULT NULL,
  `pipeline_status` json DEFAULT NULL,
  `warnings` json DEFAULT NULL,
  `symbol_counts` json DEFAULT NULL,
  `ai_estimate` json DEFAULT NULL,
  `lifecycle_statistics` json DEFAULT NULL,
  `review_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `finalised_by` bigint unsigned DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `finalised_at` timestamp NULL DEFAULT NULL,
  `work_job_id` bigint unsigned DEFAULT NULL,
  `estimate_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_results_ai_job_id_foreign` (`ai_job_id`),
  KEY `ai_results_project_id_foreign` (`project_id`),
  KEY `ai_results_upload_id_foreign` (`upload_id`),
  KEY `ai_results_finalised_by_foreign` (`finalised_by`),
  KEY `ai_results_run_id_index` (`run_id`),
  KEY `ai_results_addendum_for_estimate_id_foreign` (`addendum_for_estimate_id`),
  CONSTRAINT `ai_results_addendum_for_estimate_id_foreign` FOREIGN KEY (`addendum_for_estimate_id`) REFERENCES `estimates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_results_ai_job_id_foreign` FOREIGN KEY (`ai_job_id`) REFERENCES `ai_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_results_finalised_by_foreign` FOREIGN KEY (`finalised_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_results_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_results_upload_id_foreign` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `app_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `app_notifications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `detail` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data` json DEFAULT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `app_notifications_user_id_foreign` (`user_id`),
  CONSTRAINT `app_notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=118 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `approval_histories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `approval_histories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `symbol_review_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `action` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `from_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `approval_histories_project_id_foreign` (`project_id`),
  KEY `approval_histories_symbol_review_id_foreign` (`symbol_review_id`),
  KEY `approval_histories_user_id_foreign` (`user_id`),
  KEY `approval_histories_ai_result_id_created_at_index` (`ai_result_id`,`created_at`),
  CONSTRAINT `approval_histories_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_histories_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `approval_histories_symbol_review_id_foreign` FOREIGN KEY (`symbol_review_id`) REFERENCES `symbol_reviews` (`id`) ON DELETE SET NULL,
  CONSTRAINT `approval_histories_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=87 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `auto_send_invoices` tinyint(1) NOT NULL DEFAULT '0',
  `include_payment_instructions` tinyint(1) NOT NULL DEFAULT '0',
  `send_payment_reminders` tinyint(1) NOT NULL DEFAULT '0',
  `apply_late_fees_automatically` tinyint(1) NOT NULL DEFAULT '0',
  `default_payment_terms` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'due-on-receipt',
  `default_currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'USD',
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `billing_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `billing_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `boq_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `boq_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned NOT NULL,
  `item` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `quantity` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ea',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `final_symbol_id` bigint unsigned DEFAULT NULL,
  `matched_symbol` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `boq_lines_ai_result_id_foreign` (`ai_result_id`),
  CONSTRAINT `boq_lines_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=226 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `breeze_bucks_redemptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `breeze_bucks_redemptions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `reward_catalog_item_id` bigint unsigned NOT NULL,
  `breeze_bucks_transaction_id` bigint unsigned NOT NULL,
  `points_spent` int unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'completed',
  `redemption_reference` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `breeze_bucks_redemptions_redemption_reference_unique` (`redemption_reference`),
  KEY `breeze_bucks_redemptions_reward_catalog_item_id_foreign` (`reward_catalog_item_id`),
  KEY `breeze_bucks_redemptions_breeze_bucks_transaction_id_foreign` (`breeze_bucks_transaction_id`),
  KEY `breeze_bucks_redemptions_user_id_index` (`user_id`),
  CONSTRAINT `breeze_bucks_redemptions_breeze_bucks_transaction_id_foreign` FOREIGN KEY (`breeze_bucks_transaction_id`) REFERENCES `breeze_bucks_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `breeze_bucks_redemptions_reward_catalog_item_id_foreign` FOREIGN KEY (`reward_catalog_item_id`) REFERENCES `reward_catalog_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `breeze_bucks_redemptions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `breeze_bucks_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `breeze_bucks_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` int NOT NULL,
  `balance_after` int NOT NULL,
  `source_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint unsigned DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `metadata` json DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `breeze_bucks_transactions_created_by_foreign` (`created_by`),
  KEY `breeze_bucks_transactions_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `breeze_bucks_transactions_user_id_type_index` (`user_id`,`type`),
  KEY `breeze_bucks_transactions_source_type_source_id_index` (`source_type`,`source_id`),
  CONSTRAINT `breeze_bucks_transactions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `breeze_bucks_transactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `circuits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `circuits` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned NOT NULL,
  `page` smallint unsigned NOT NULL,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `breaker` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `panel` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `circuits_ai_result_id_foreign` (`ai_result_id`),
  CONSTRAINT `circuits_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `client_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `client_id` bigint unsigned DEFAULT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `address` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `site_type` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `place_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '0',
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `client_addresses_project_id_position_index` (`position`),
  KEY `client_addresses_client_id_foreign` (`client_id`),
  KEY `client_addresses_place_id_index` (`place_id`),
  CONSTRAINT `client_addresses_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `clients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `labor_rate` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `clients_user_id_name_unique` (`user_id`,`name`),
  CONSTRAINT `clients_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `crew_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `crew_shifts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `team_member_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `crew` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Team A',
  `scheduled_date` date NOT NULL,
  `start_time` time NOT NULL DEFAULT '08:00:00',
  `duration_hours` decimal(5,2) NOT NULL DEFAULT '8.00',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'scheduled',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_schedules_scheduled_date_start_time_index` (`scheduled_date`,`start_time`),
  KEY `job_schedules_job_id_scheduled_date_index` (`job_id`,`scheduled_date`),
  KEY `crew_shifts_team_member_id_foreign` (`team_member_id`),
  KEY `crew_shifts_created_by_foreign` (`created_by`),
  CONSTRAINT `crew_shifts_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `crew_shifts_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `crew_shifts_team_member_id_foreign` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `detected_symbols`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `detected_symbols` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` int unsigned NOT NULL,
  `confidence` decimal(4,3) NOT NULL,
  `unit` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EA',
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `detected_symbols_project_id_foreign` (`project_id`),
  CONSTRAINT `detected_symbols_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_activities_document_id_foreign` (`document_id`),
  KEY `document_activities_user_id_foreign` (`user_id`),
  CONSTRAINT `document_activities_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_favorites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_favorites_document_id_user_id_unique` (`document_id`,`user_id`),
  KEY `document_favorites_user_id_foreign` (`user_id`),
  CONSTRAINT `document_favorites_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_favorites_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_folders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_folders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_id` bigint unsigned DEFAULT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_folders_parent_id_foreign` (`parent_id`),
  KEY `document_folders_created_by_foreign` (`created_by`),
  KEY `document_folders_job_id_parent_id_index` (`job_id`,`parent_id`),
  CONSTRAINT `document_folders_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_folders_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_folders_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `document_folders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `document_shares`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `document_shares` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_id` bigint unsigned NOT NULL,
  `shared_with_user_id` bigint unsigned NOT NULL,
  `shared_by_user_id` bigint unsigned DEFAULT NULL,
  `permission` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'view',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_shares_document_id_shared_with_user_id_unique` (`document_id`,`shared_with_user_id`),
  KEY `document_shares_shared_with_user_id_foreign` (`shared_with_user_id`),
  KEY `document_shares_shared_by_user_id_foreign` (`shared_by_user_id`),
  CONSTRAINT `document_shares_document_id_foreign` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_shares_shared_by_user_id_foreign` FOREIGN KEY (`shared_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_shares_shared_with_user_id_foreign` FOREIGN KEY (`shared_with_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `documents` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `extension` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned NOT NULL DEFAULT '0',
  `document_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Other',
  `job_id` bigint unsigned DEFAULT NULL,
  `estimate_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `folder_id` bigint unsigned DEFAULT NULL,
  `upload_id` bigint unsigned DEFAULT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `version_root_id` bigint unsigned DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `is_latest` tinyint(1) NOT NULL DEFAULT '1',
  `is_archived` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` timestamp NULL DEFAULT NULL,
  `visibility` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'team',
  `description` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documents_estimate_id_foreign` (`estimate_id`),
  KEY `documents_folder_id_foreign` (`folder_id`),
  KEY `documents_upload_id_foreign` (`upload_id`),
  KEY `documents_uploaded_by_foreign` (`uploaded_by`),
  KEY `documents_job_id_is_archived_index` (`job_id`,`is_archived`),
  KEY `documents_is_archived_is_latest_index` (`is_archived`,`is_latest`),
  KEY `documents_document_type_index` (`document_type`),
  KEY `documents_version_root_id_index` (`version_root_id`),
  KEY `documents_project_id_foreign` (`project_id`),
  CONSTRAINT `documents_estimate_id_foreign` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_folder_id_foreign` FOREIGN KEY (`folder_id`) REFERENCES `document_folders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_upload_id_foreign` FOREIGN KEY (`upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `documents_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `drawing_sheets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `drawing_sheets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `code` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_count` int unsigned NOT NULL,
  `scale` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `symbol_count` int unsigned NOT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `drawing_sheets_project_id_foreign` (`project_id`),
  CONSTRAINT `drawing_sheets_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `equipment_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned NOT NULL,
  `page` smallint unsigned NOT NULL,
  `tag` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rating` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `extra` json DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `equipment_items_ai_result_id_foreign` (`ai_result_id`),
  CONSTRAINT `equipment_items_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estimate_item_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estimate_item_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `estimate_item_id` bigint unsigned NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `estimate_item_attachments_estimate_item_id_foreign` (`estimate_item_id`),
  KEY `estimate_item_attachments_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `estimate_item_attachments_estimate_item_id_foreign` FOREIGN KEY (`estimate_item_id`) REFERENCES `estimate_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `estimate_item_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estimate_item_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estimate_item_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `estimate_item_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `estimate_item_comments_user_id_foreign` (`user_id`),
  KEY `estimate_item_comments_estimate_item_id_created_at_index` (`estimate_item_id`,`created_at`),
  CONSTRAINT `estimate_item_comments_estimate_item_id_foreign` FOREIGN KEY (`estimate_item_id`) REFERENCES `estimate_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `estimate_item_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estimate_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estimate_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `estimate_id` bigint unsigned NOT NULL,
  `job_task_id` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `final_symbol_id` bigint unsigned DEFAULT NULL,
  `source_estimate_item_id` bigint unsigned DEFAULT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ea',
  `quantity` decimal(14,4) NOT NULL,
  `unit_cost` decimal(14,4) NOT NULL,
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ai',
  `pricing_source` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `price_book_item_id` bigint unsigned DEFAULT NULL,
  `project_rate_item_id` bigint unsigned DEFAULT NULL,
  `pricing_confidence` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `estimate_items_final_symbol_id_foreign` (`final_symbol_id`),
  KEY `estimate_items_estimate_id_category_index` (`estimate_id`,`category`),
  KEY `estimate_items_job_task_id_foreign` (`job_task_id`),
  KEY `estimate_items_price_book_item_id_foreign` (`price_book_item_id`),
  KEY `estimate_items_project_rate_item_id_foreign` (`project_rate_item_id`),
  KEY `estimate_items_source_estimate_item_id_foreign` (`source_estimate_item_id`),
  CONSTRAINT `estimate_items_estimate_id_foreign` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `estimate_items_final_symbol_id_foreign` FOREIGN KEY (`final_symbol_id`) REFERENCES `final_symbols` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimate_items_job_task_id_foreign` FOREIGN KEY (`job_task_id`) REFERENCES `job_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimate_items_price_book_item_id_foreign` FOREIGN KEY (`price_book_item_id`) REFERENCES `price_book_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimate_items_project_rate_item_id_foreign` FOREIGN KEY (`project_rate_item_id`) REFERENCES `project_rate_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimate_items_source_estimate_item_id_foreign` FOREIGN KEY (`source_estimate_item_id`) REFERENCES `estimate_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1554 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estimate_merge_sources`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estimate_merge_sources` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `merged_estimate_id` bigint unsigned NOT NULL,
  `source_estimate_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `estimate_merge_sources_unique` (`merged_estimate_id`,`source_estimate_id`),
  KEY `estimate_merge_sources_source_estimate_id_foreign` (`source_estimate_id`),
  CONSTRAINT `estimate_merge_sources_merged_estimate_id_foreign` FOREIGN KEY (`merged_estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `estimate_merge_sources_source_estimate_id_foreign` FOREIGN KEY (`source_estimate_id`) REFERENCES `estimates` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `estimates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `estimates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `client_id` bigint unsigned DEFAULT NULL,
  `ai_result_id` bigint unsigned DEFAULT NULL,
  `number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `issued_on` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `material_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `labor_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `equipment_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(14,2) NOT NULL DEFAULT '0.00',
  `markup_pct` decimal(6,2) NOT NULL DEFAULT '0.00',
  `markup_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tax_pct` decimal(6,2) NOT NULL DEFAULT '0.00',
  `tax_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standalone',
  `parent_estimate_id` bigint unsigned DEFAULT NULL,
  `addendum_number` int unsigned DEFAULT NULL,
  `addendum_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `converted_project_id` bigint unsigned DEFAULT NULL,
  `converted_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `estimates_user_id_number_unique` (`user_id`,`number`),
  KEY `estimates_client_index` (`client`),
  KEY `estimates_issued_on_index` (`issued_on`),
  KEY `estimates_status_index` (`status`),
  KEY `estimates_job_id_foreign` (`job_id`),
  KEY `estimates_converted_project_id_foreign` (`converted_project_id`),
  KEY `estimates_project_id_foreign` (`project_id`),
  KEY `estimates_client_id_foreign` (`client_id`),
  KEY `estimates_parent_estimate_id_addendum_number_index` (`parent_estimate_id`,`addendum_number`),
  CONSTRAINT `estimates_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimates_converted_project_id_foreign` FOREIGN KEY (`converted_project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimates_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimates_parent_estimate_id_foreign` FOREIGN KEY (`parent_estimate_id`) REFERENCES `estimates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimates_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `estimates_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB AUTO_INCREMENT=223 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `feed_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `feed_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `scope` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `segments` json NOT NULL,
  `detail` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `meta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tile` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lilac',
  `position` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `feed_items_scope_index` (`scope`),
  KEY `feed_items_user_id_foreign` (`user_id`),
  CONSTRAINT `feed_items_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `final_symbols`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `final_symbols` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `count` int unsigned NOT NULL,
  `confidence` double NOT NULL DEFAULT '0',
  `source_template` tinyint(1) NOT NULL DEFAULT '0',
  `source_vector` tinyint(1) NOT NULL DEFAULT '0',
  `source_vision` tinyint(1) NOT NULL DEFAULT '0',
  `source_ocr` tinyint(1) NOT NULL DEFAULT '0',
  `pages` json DEFAULT NULL,
  `review_ids` json DEFAULT NULL,
  `was_modified` tinyint(1) NOT NULL DEFAULT '0',
  `was_renamed` tinyint(1) NOT NULL DEFAULT '0',
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `final_symbols_project_id_foreign` (`project_id`),
  KEY `final_symbols_ai_result_id_name_index` (`ai_result_id`,`name`),
  CONSTRAINT `final_symbols_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE,
  CONSTRAINT `final_symbols_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=196 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `foremen`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `foremen` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `initials` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `team_id` bigint unsigned DEFAULT NULL,
  `role` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'journeyman',
  `phone` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `licence_number` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_on` date DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `foremen_user_id_unique` (`user_id`),
  KEY `foremen_team_id_foreign` (`team_id`),
  CONSTRAINT `foremen_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `foremen_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint unsigned NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quantity` decimal(12,2) NOT NULL DEFAULT '1.00',
  `unit_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `invoice_items_invoice_id_foreign` (`invoice_id`),
  CONSTRAINT `invoice_items_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=330 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `invoice_number` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `job_id` bigint unsigned DEFAULT NULL,
  `estimate_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `client_id` bigint unsigned DEFAULT NULL,
  `client` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `subtotal` decimal(12,2) NOT NULL DEFAULT '0.00',
  `tax_pct` decimal(5,2) NOT NULL DEFAULT '0.00',
  `tax_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `sent_at` timestamp NULL DEFAULT NULL,
  `paid_at` timestamp NULL DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoices_user_id_invoice_number_unique` (`user_id`,`invoice_number`),
  KEY `invoices_job_id_foreign` (`job_id`),
  KEY `invoices_estimate_id_foreign` (`estimate_id`),
  KEY `invoices_created_by_foreign` (`created_by`),
  KEY `invoices_status_due_date_index` (`status`,`due_date`),
  KEY `invoices_client_index` (`client`),
  KEY `invoices_project_id_foreign` (`project_id`),
  KEY `invoices_client_id_foreign` (`client_id`),
  CONSTRAINT `invoices_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_estimate_id_foreign` FOREIGN KEY (`estimate_id`) REFERENCES `estimates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `invoices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_activities_job_id_foreign` (`job_id`),
  KEY `job_activities_user_id_foreign` (`user_id`),
  KEY `job_activities_type_index` (`type`),
  CONSTRAINT `job_activities_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_addresses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_addresses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `client_address_id` bigint unsigned NOT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_addresses_job_id_client_address_id_unique` (`job_id`,`client_address_id`),
  KEY `job_addresses_client_address_id_foreign` (`client_address_id`),
  CONSTRAINT `job_addresses_client_address_id_foreign` FOREIGN KEY (`client_address_id`) REFERENCES `client_addresses` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_addresses_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_apprentice_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_apprentice_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `journeyman_id` bigint unsigned NOT NULL,
  `apprentice_id` bigint unsigned NOT NULL,
  `assigned_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_apprentice_assignments_job_id_apprentice_id_unique` (`job_id`,`apprentice_id`),
  KEY `job_apprentice_assignments_journeyman_id_foreign` (`journeyman_id`),
  KEY `job_apprentice_assignments_apprentice_id_foreign` (`apprentice_id`),
  KEY `job_apprentice_assignments_assigned_by_foreign` (`assigned_by`),
  KEY `job_apprentice_assignments_job_id_journeyman_id_index` (`job_id`,`journeyman_id`),
  CONSTRAINT `job_apprentice_assignments_apprentice_id_foreign` FOREIGN KEY (`apprentice_id`) REFERENCES `foremen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_apprentice_assignments_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_apprentice_assignments_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_apprentice_assignments_journeyman_id_foreign` FOREIGN KEY (`journeyman_id`) REFERENCES `foremen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `team_member_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `assigned_by` bigint unsigned DEFAULT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_assignments_team_member_id_foreign` (`team_member_id`),
  KEY `job_assignments_user_id_foreign` (`user_id`),
  KEY `job_assignments_assigned_by_foreign` (`assigned_by`),
  KEY `job_assignments_job_id_role_index` (`job_id`,`role`),
  CONSTRAINT `job_assignments_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_assignments_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_assignments_team_member_id_foreign` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_assignments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size` bigint unsigned NOT NULL,
  `mime` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_attachments_job_id_foreign` (`job_id`),
  KEY `job_attachments_user_id_foreign` (`user_id`),
  CONSTRAINT `job_attachments_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_attachments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_attendances`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_attendances` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `date` date NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `check_in_at` timestamp NULL DEFAULT NULL,
  `check_in_lat` decimal(10,7) DEFAULT NULL,
  `check_in_lng` decimal(10,7) DEFAULT NULL,
  `check_in_accuracy` decimal(8,2) DEFAULT NULL,
  `check_in_distance_meters` decimal(10,2) DEFAULT NULL,
  `check_in_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `check_in_photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `check_out_at` timestamp NULL DEFAULT NULL,
  `check_out_lat` decimal(10,7) DEFAULT NULL,
  `check_out_lng` decimal(10,7) DEFAULT NULL,
  `check_out_accuracy` decimal(8,2) DEFAULT NULL,
  `check_out_distance_meters` decimal(10,2) DEFAULT NULL,
  `check_out_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `banked_seconds` int unsigned NOT NULL DEFAULT '0',
  `client_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_attendances_job_id_user_id_date_unique` (`job_id`,`user_id`,`date`),
  KEY `job_attendances_user_id_index` (`user_id`),
  KEY `job_attendances_date_index` (`date`),
  CONSTRAINT `job_attendances_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_attendances_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_cost_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_cost_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `category` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(12,2) DEFAULT NULL,
  `unit_cost` decimal(12,2) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `incurred_on` date NOT NULL,
  `recorded_by` bigint unsigned DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_cost_entries_recorded_by_foreign` (`recorded_by`),
  KEY `job_cost_entries_job_id_category_index` (`job_id`,`category`),
  CONSTRAINT `job_cost_entries_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_cost_entries_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_foreman_completions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_foreman_completions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `foreman_id` bigint unsigned NOT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `ready_for_review_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_foreman_completions_job_id_foreman_id_unique` (`job_id`,`foreman_id`),
  KEY `job_foreman_completions_foreman_id_foreign` (`foreman_id`),
  CONSTRAINT `job_foreman_completions_foreman_id_foreign` FOREIGN KEY (`foreman_id`) REFERENCES `foremen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_foreman_completions_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_journeyman_hours`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_journeyman_hours` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned NOT NULL,
  `hours` decimal(8,2) NOT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_journeyman_hours_job_id_user_id_unique` (`job_id`,`user_id`),
  KEY `job_journeyman_hours_user_id_foreign` (`user_id`),
  KEY `job_journeyman_hours_updated_by_foreign` (`updated_by`),
  CONSTRAINT `job_journeyman_hours_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_journeyman_hours_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_journeyman_hours_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_notes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_notes_job_id_foreign` (`job_id`),
  KEY `job_notes_user_id_foreign` (`user_id`),
  CONSTRAINT `job_notes_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_notes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `working_days` json NOT NULL,
  `work_start_time` time NOT NULL DEFAULT '08:00:00',
  `work_end_time` time NOT NULL DEFAULT '16:30:00',
  `break_minutes` smallint unsigned NOT NULL DEFAULT '30',
  `timezone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UTC',
  `holidays` json DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `progress_pct` tinyint unsigned NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_schedules_job_id_unique` (`job_id`),
  KEY `job_schedules_created_by_foreign` (`created_by`),
  KEY `job_schedules_status_index` (`status`),
  CONSTRAINT `job_schedules_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_schedules_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_status_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_status_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `from_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_status_changes_job_id_foreign` (`job_id`),
  KEY `job_status_changes_user_id_foreign` (`user_id`),
  CONSTRAINT `job_status_changes_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_status_changes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=24 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_task_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_task_assignments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_task_id` bigint unsigned NOT NULL,
  `team_member_id` bigint unsigned NOT NULL,
  `assigned_by` bigint unsigned DEFAULT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_task_assignments_job_task_id_team_member_id_role_unique` (`job_task_id`,`team_member_id`,`role`),
  KEY `job_task_assignments_team_member_id_foreign` (`team_member_id`),
  KEY `job_task_assignments_assigned_by_foreign` (`assigned_by`),
  CONSTRAINT `job_task_assignments_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_task_assignments_job_task_id_foreign` FOREIGN KEY (`job_task_id`) REFERENCES `job_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_task_assignments_team_member_id_foreign` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_task_attachments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_task_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_task_id` bigint unsigned NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_task_attachments_job_task_id_foreign` (`job_task_id`),
  KEY `job_task_attachments_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `job_task_attachments_job_task_id_foreign` FOREIGN KEY (`job_task_id`) REFERENCES `job_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_task_attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_task_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_task_comments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_task_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `body` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `job_task_comments_user_id_foreign` (`user_id`),
  KEY `job_task_comments_job_task_id_created_at_index` (`job_task_id`,`created_at`),
  CONSTRAINT `job_task_comments_job_task_id_foreign` FOREIGN KEY (`job_task_id`) REFERENCES `job_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_task_comments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_task_dependencies`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_task_dependencies` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_task_id` bigint unsigned NOT NULL,
  `depends_on_id` bigint unsigned NOT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'finish_to_start',
  `lag_days` smallint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_task_dependencies_job_task_id_depends_on_id_unique` (`job_task_id`,`depends_on_id`),
  KEY `job_task_dependencies_depends_on_id_foreign` (`depends_on_id`),
  CONSTRAINT `job_task_dependencies_depends_on_id_foreign` FOREIGN KEY (`depends_on_id`) REFERENCES `job_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_task_dependencies_job_task_id_foreign` FOREIGN KEY (`job_task_id`) REFERENCES `job_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_tasks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_schedule_id` bigint unsigned NOT NULL,
  `job_id` bigint unsigned NOT NULL,
  `foreman_id` bigint unsigned DEFAULT NULL,
  `supervisor_id` bigint unsigned DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `priority` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estimated_hours` decimal(6,2) DEFAULT NULL,
  `actual_hours` decimal(6,2) NOT NULL DEFAULT '0.00',
  `starts_on` date DEFAULT NULL,
  `ends_on` date DEFAULT NULL,
  `baseline_ends_on` date DEFAULT NULL,
  `completion_pct` tinyint unsigned NOT NULL DEFAULT '0',
  `position` int unsigned NOT NULL DEFAULT '0',
  `is_milestone` tinyint(1) NOT NULL DEFAULT '0',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_tasks_job_schedule_id_title_unique` (`job_schedule_id`,`title`),
  KEY `job_tasks_created_by_foreign` (`created_by`),
  KEY `job_tasks_job_id_status_index` (`job_id`,`status`),
  KEY `job_tasks_job_schedule_id_position_index` (`job_schedule_id`,`position`),
  KEY `job_tasks_ends_on_index` (`ends_on`),
  KEY `job_tasks_foreman_id_foreign` (`foreman_id`),
  KEY `job_tasks_supervisor_id_foreign` (`supervisor_id`),
  CONSTRAINT `job_tasks_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_tasks_foreman_id_foreign` FOREIGN KEY (`foreman_id`) REFERENCES `foremen` (`id`) ON DELETE SET NULL,
  CONSTRAINT `job_tasks_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_tasks_job_schedule_id_foreign` FOREIGN KEY (`job_schedule_id`) REFERENCES `job_schedules` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_tasks_supervisor_id_foreign` FOREIGN KEY (`supervisor_id`) REFERENCES `foremen` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_team_member`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_team_member` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `team_member_id` bigint unsigned NOT NULL,
  `role_on_job` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `job_team_member_job_id_team_member_id_unique` (`job_id`,`team_member_id`),
  KEY `job_team_member_team_member_id_foreign` (`team_member_id`),
  CONSTRAINT `job_team_member_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `job_team_member_team_member_id_foreign` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB AUTO_INCREMENT=291 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=137 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `panel_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `panel_schedules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned NOT NULL,
  `page` smallint unsigned NOT NULL,
  `panel_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `rows` json DEFAULT NULL,
  `raw_headers` json DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `panel_schedules_ai_result_id_foreign` (`ai_result_id`),
  CONSTRAINT `panel_schedules_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_methods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_methods` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `payment_processor_id` bigint unsigned NOT NULL,
  `brand` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_four` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `exp_month` tinyint unsigned NOT NULL,
  `exp_year` smallint unsigned NOT NULL,
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `created_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_methods_payment_processor_id_foreign` (`payment_processor_id`),
  KEY `payment_methods_created_by_foreign` (`created_by`),
  CONSTRAINT `payment_methods_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_methods_payment_processor_id_foreign` FOREIGN KEY (`payment_processor_id`) REFERENCES `payment_processors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_processors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_processors` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `display_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_connected',
  `credentials` text COLLATE utf8mb4_unicode_ci,
  `connected_at` timestamp NULL DEFAULT NULL,
  `last_tested_at` timestamp NULL DEFAULT NULL,
  `last_error` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `connected_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payment_processors_key_unique` (`key`),
  KEY `payment_processors_connected_by_foreign` (`connected_by`),
  CONSTRAINT `payment_processors_connected_by_foreign` FOREIGN KEY (`connected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_transactions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint unsigned DEFAULT NULL,
  `payment_processor_id` bigint unsigned DEFAULT NULL,
  `payment_method_id` bigint unsigned DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `external_reference` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recorded_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `payment_transactions_payment_processor_id_foreign` (`payment_processor_id`),
  KEY `payment_transactions_payment_method_id_foreign` (`payment_method_id`),
  KEY `payment_transactions_recorded_by_foreign` (`recorded_by`),
  KEY `payment_transactions_invoice_id_occurred_at_index` (`invoice_id`,`occurred_at`),
  CONSTRAINT `payment_transactions_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `payment_methods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_payment_processor_id_foreign` FOREIGN KEY (`payment_processor_id`) REFERENCES `payment_processors` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payment_transactions_recorded_by_foreign` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_book_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_book_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_cost` decimal(14,2) DEFAULT NULL,
  `labor_cost` decimal(14,2) DEFAULT NULL,
  `material_tax` decimal(14,2) DEFAULT NULL,
  `total_cost` decimal(14,2) DEFAULT NULL,
  `base_bid_price` decimal(14,2) DEFAULT NULL,
  `material_tax_pct` decimal(6,3) DEFAULT NULL,
  `overhead_pct` decimal(6,3) DEFAULT NULL,
  `profit_pct` decimal(6,3) DEFAULT NULL,
  `electrician_rate` decimal(10,2) DEFAULT NULL,
  `supervisor_rate` decimal(10,2) DEFAULT NULL,
  `unskilled_rate` decimal(10,2) DEFAULT NULL,
  `composite_labor_rate` decimal(10,2) DEFAULT NULL,
  `total_manhours` decimal(12,3) DEFAULT NULL,
  `line_count` int unsigned NOT NULL DEFAULT '0',
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_book_imports_user_id_file_hash_unique` (`user_id`,`file_hash`),
  CONSTRAINT `price_book_imports_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_book_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_book_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `match_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `section` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subsection` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_material_cost` decimal(14,4) DEFAULT NULL,
  `unit_manhours` decimal(12,6) DEFAULT NULL,
  `sample_count` int unsigned NOT NULL DEFAULT '0',
  `min_material_cost` decimal(14,4) DEFAULT NULL,
  `max_material_cost` decimal(14,4) DEFAULT NULL,
  `min_manhours` decimal(12,6) DEFAULT NULL,
  `max_manhours` decimal(12,6) DEFAULT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT '0',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `price_book_items_user_id_match_key_unit_unique` (`user_id`,`match_key`,`unit`),
  CONSTRAINT `price_book_items_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=540 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `price_book_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_book_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `price_book_import_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `section` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subsection` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sr_no` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dwg_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detail_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(14,4) DEFAULT NULL,
  `wastage` decimal(14,4) DEFAULT NULL,
  `quantity_with_wastage` decimal(14,4) DEFAULT NULL,
  `unit` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_material_cost` decimal(14,4) DEFAULT NULL,
  `material_cost` decimal(14,4) DEFAULT NULL,
  `manhour_rate` decimal(10,2) DEFAULT NULL,
  `unit_manhours` decimal(12,6) DEFAULT NULL,
  `total_manhours` decimal(14,4) DEFAULT NULL,
  `manhours_cost` decimal(14,4) DEFAULT NULL,
  `total_cost` decimal(14,4) DEFAULT NULL,
  `source_row` int unsigned DEFAULT NULL,
  `match_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `price_book_lines_price_book_import_id_foreign` (`price_book_import_id`),
  KEY `price_book_lines_match_key_index` (`match_key`),
  KEY `price_book_lines_user_id_match_key_unit_index` (`user_id`,`match_key`,`unit`),
  CONSTRAINT `price_book_lines_price_book_import_id_foreign` FOREIGN KEY (`price_book_import_id`) REFERENCES `price_book_imports` (`id`) ON DELETE CASCADE,
  CONSTRAINT `price_book_lines_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1370 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tone` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'info',
  `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_activities_project_id_foreign` (`project_id`),
  CONSTRAINT `project_activities_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_metrics`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_metrics` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `label` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `delta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trend` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `hint` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `project_metrics_project_id_foreign` (`project_id`),
  CONSTRAINT `project_metrics_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_rate_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_rate_imports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `file_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `file_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `project_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_tax_pct` decimal(6,3) DEFAULT NULL,
  `overhead_pct` decimal(6,3) DEFAULT NULL,
  `profit_pct` decimal(6,3) DEFAULT NULL,
  `electrician_rate` decimal(10,2) DEFAULT NULL,
  `supervisor_rate` decimal(10,2) DEFAULT NULL,
  `unskilled_rate` decimal(10,2) DEFAULT NULL,
  `composite_labor_rate` decimal(10,2) DEFAULT NULL,
  `total_manhours` decimal(12,3) DEFAULT NULL,
  `material_cost` decimal(14,2) DEFAULT NULL,
  `labor_cost` decimal(14,2) DEFAULT NULL,
  `material_tax` decimal(14,2) DEFAULT NULL,
  `total_cost` decimal(14,2) DEFAULT NULL,
  `base_bid_price` decimal(14,2) DEFAULT NULL,
  `line_count` int unsigned NOT NULL DEFAULT '0',
  `imported_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_rate_imports_project_id_file_hash_unique` (`project_id`,`file_hash`),
  CONSTRAINT `project_rate_imports_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_rate_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_rate_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_id` bigint unsigned NOT NULL,
  `match_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `section` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subsection` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_material_cost` decimal(14,4) DEFAULT NULL,
  `unit_manhours` decimal(12,6) DEFAULT NULL,
  `sample_count` int unsigned NOT NULL DEFAULT '0',
  `min_material_cost` decimal(14,4) DEFAULT NULL,
  `max_material_cost` decimal(14,4) DEFAULT NULL,
  `min_manhours` decimal(12,6) DEFAULT NULL,
  `max_manhours` decimal(12,6) DEFAULT NULL,
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `project_rate_items_project_id_match_key_unit_unique` (`project_id`,`match_key`,`unit`),
  CONSTRAINT `project_rate_items_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=142 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `project_rate_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `project_rate_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `project_rate_import_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `section` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subsection` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sr_no` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dwg_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detail_no` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(14,4) DEFAULT NULL,
  `wastage` decimal(14,4) DEFAULT NULL,
  `quantity_with_wastage` decimal(14,4) DEFAULT NULL,
  `unit` varchar(24) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_material_cost` decimal(14,4) DEFAULT NULL,
  `material_cost` decimal(14,4) DEFAULT NULL,
  `manhour_rate` decimal(10,2) DEFAULT NULL,
  `unit_manhours` decimal(12,6) DEFAULT NULL,
  `total_manhours` decimal(14,4) DEFAULT NULL,
  `manhours_cost` decimal(14,4) DEFAULT NULL,
  `total_cost` decimal(14,4) DEFAULT NULL,
  `source_row` int unsigned DEFAULT NULL,
  `match_key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `project_rate_lines_project_rate_import_id_foreign` (`project_rate_import_id`),
  KEY `project_rate_lines_project_id_foreign` (`project_id`),
  KEY `project_rate_lines_match_key_index` (`match_key`),
  CONSTRAINT `project_rate_lines_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `project_rate_lines_project_rate_import_id_foreign` FOREIGN KEY (`project_rate_import_id`) REFERENCES `project_rate_imports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=175 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `projects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `projects` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `client_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `code` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `client` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `place_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `drawing_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `selected_upload_id` bigint unsigned DEFAULT NULL,
  `discipline` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Electrical',
  `project_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `review_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `items_count` int unsigned NOT NULL DEFAULT '0',
  `page_count` int unsigned NOT NULL DEFAULT '0',
  `overall_confidence` decimal(4,3) DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NULL DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `estimate_target_total` decimal(12,2) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `projects_user_id_foreign` (`user_id`),
  KEY `projects_status_index` (`status`),
  KEY `projects_selected_upload_id_foreign` (`selected_upload_id`),
  KEY `projects_client_id_foreign` (`client_id`),
  KEY `projects_place_id_index` (`place_id`),
  CONSTRAINT `projects_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_selected_upload_id_foreign` FOREIGN KEY (`selected_upload_id`) REFERENCES `uploads` (`id`) ON DELETE SET NULL,
  CONSTRAINT `projects_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reward_catalog_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reward_catalog_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `points_required` int unsigned NOT NULL,
  `icon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stock` int unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `reward_catalog_items_is_active_points_required_index` (`is_active`,`points_required`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `reward_rules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reward_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `event_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `points` int unsigned NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role_restriction` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `reward_rules_event_type_unique` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `security_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_events` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `occurred_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `security_events_user_id_occurred_at_index` (`user_id`,`occurred_at`),
  CONSTRAINT `security_events_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `security_notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `security_notification_preferences` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `event_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sms_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `email_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `security_notification_preferences_user_id_event_type_unique` (`user_id`,`event_type`),
  CONSTRAINT `security_notification_preferences_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `symbol_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `symbol_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned NOT NULL,
  `external_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `origin` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'symbol',
  `ai_category` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ai_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `page` smallint unsigned DEFAULT NULL,
  `confidence` double NOT NULL DEFAULT '0',
  `bbox` json DEFAULT NULL,
  `occurrences` json DEFAULT NULL,
  `source_template` tinyint(1) NOT NULL DEFAULT '0',
  `source_vector` tinyint(1) NOT NULL DEFAULT '0',
  `source_vision` tinyint(1) NOT NULL DEFAULT '0',
  `source_ocr` tinyint(1) NOT NULL DEFAULT '0',
  `pipeline` json DEFAULT NULL,
  `evidence` json DEFAULT NULL,
  `legend` json DEFAULT NULL,
  `is_known` tinyint(1) NOT NULL DEFAULT '0',
  `ai_count` int unsigned NOT NULL DEFAULT '1',
  `final_count` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `notes` text COLLATE utf8mb4_unicode_ci,
  `merged_into_id` bigint unsigned DEFAULT NULL,
  `split_from_id` bigint unsigned DEFAULT NULL,
  `crop_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `crop_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `crop_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `crop_count` int unsigned NOT NULL DEFAULT '0',
  `final_decision` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detection_source` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stages` json DEFAULT NULL,
  `reviewed_by` bigint unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `symbol_reviews_project_id_foreign` (`project_id`),
  KEY `symbol_reviews_merged_into_id_foreign` (`merged_into_id`),
  KEY `symbol_reviews_split_from_id_foreign` (`split_from_id`),
  KEY `symbol_reviews_reviewed_by_foreign` (`reviewed_by`),
  KEY `symbol_reviews_ai_result_id_status_index` (`ai_result_id`,`status`),
  KEY `symbol_reviews_ai_result_id_name_index` (`ai_result_id`,`name`),
  CONSTRAINT `symbol_reviews_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE,
  CONSTRAINT `symbol_reviews_merged_into_id_foreign` FOREIGN KEY (`merged_into_id`) REFERENCES `symbol_reviews` (`id`) ON DELETE SET NULL,
  CONSTRAINT `symbol_reviews_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `symbol_reviews_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `symbol_reviews_split_from_id_foreign` FOREIGN KEY (`split_from_id`) REFERENCES `symbol_reviews` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=808 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `team_members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `team_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `initials` varchar(4) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `billable_rate` decimal(8,2) DEFAULT NULL,
  `cost_rate` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `team_members_user_id_foreign` (`user_id`),
  KEY `team_members_team_id_foreign` (`team_id`),
  CONSTRAINT `team_members_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `team_members_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `teams` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `teams_name_unique` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `time_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `time_entries` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `job_id` bigint unsigned NOT NULL,
  `job_task_id` bigint unsigned DEFAULT NULL,
  `user_id` bigint unsigned NOT NULL,
  `team_member_id` bigint unsigned DEFAULT NULL,
  `date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `break_minutes` smallint unsigned NOT NULL DEFAULT '0',
  `hours` decimal(5,2) NOT NULL,
  `regular_hours` decimal(5,2) NOT NULL DEFAULT '0.00',
  `overtime_hours` decimal(5,2) NOT NULL DEFAULT '0.00',
  `task_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `billable` tinyint(1) NOT NULL DEFAULT '1',
  `billable_rate` decimal(8,2) DEFAULT NULL,
  `cost_rate` decimal(8,2) DEFAULT NULL,
  `labor_cost` decimal(10,2) DEFAULT NULL,
  `billable_amount` decimal(10,2) DEFAULT NULL,
  `source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `rejected_by` bigint unsigned DEFAULT NULL,
  `rejection_reason` text COLLATE utf8mb4_unicode_ci,
  `corrects_id` bigint unsigned DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `time_entries_approved_by_foreign` (`approved_by`),
  KEY `time_entries_rejected_by_foreign` (`rejected_by`),
  KEY `time_entries_corrects_id_foreign` (`corrects_id`),
  KEY `time_entries_job_id_index` (`job_id`),
  KEY `time_entries_user_id_index` (`user_id`),
  KEY `time_entries_date_index` (`date`),
  KEY `time_entries_status_index` (`status`),
  KEY `time_entries_team_member_id_index` (`team_member_id`),
  KEY `time_entries_job_task_id_index` (`job_task_id`),
  CONSTRAINT `time_entries_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `time_entries_corrects_id_foreign` FOREIGN KEY (`corrects_id`) REFERENCES `time_entries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `time_entries_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `time_entries_job_task_id_foreign` FOREIGN KEY (`job_task_id`) REFERENCES `job_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `time_entries_rejected_by_foreign` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `time_entries_team_member_id_foreign` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `time_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `time_entry_activities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `time_entry_activities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `time_entry_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `meta` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `time_entry_activities_time_entry_id_foreign` (`time_entry_id`),
  KEY `time_entry_activities_user_id_foreign` (`user_id`),
  KEY `time_entry_activities_type_index` (`type`),
  CONSTRAINT `time_entry_activities_time_entry_id_foreign` FOREIGN KEY (`time_entry_id`) REFERENCES `time_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `time_entry_activities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `time_entry_status_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `time_entry_status_changes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `time_entry_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `from_status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `time_entry_status_changes_time_entry_id_foreign` (`time_entry_id`),
  KEY `time_entry_status_changes_user_id_foreign` (`user_id`),
  CONSTRAINT `time_entry_status_changes_time_entry_id_foreign` FOREIGN KEY (`time_entry_id`) REFERENCES `time_entries` (`id`) ON DELETE CASCADE,
  CONSTRAINT `time_entry_status_changes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `time_tracking_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `time_tracking_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `regular_daily_hours` decimal(4,2) NOT NULL DEFAULT '8.00',
  `regular_weekly_hours` decimal(5,2) NOT NULL DEFAULT '40.00',
  `overtime_multiplier` decimal(3,2) NOT NULL DEFAULT '1.50',
  `weekend_overtime` tinyint(1) NOT NULL DEFAULT '1',
  `holiday_overtime` tinyint(1) NOT NULL DEFAULT '1',
  `holiday_dates` json DEFAULT NULL,
  `timezone` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UTC',
  `default_billable_rate` decimal(8,2) DEFAULT NULL,
  `default_cost_rate` decimal(8,2) DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `time_tracking_settings_updated_by_foreign` (`updated_by`),
  CONSTRAINT `time_tracking_settings_updated_by_foreign` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `timer_sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `timer_sessions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `job_id` bigint unsigned NOT NULL,
  `job_task_id` bigint unsigned DEFAULT NULL,
  `team_member_id` bigint unsigned DEFAULT NULL,
  `task_label` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `started_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `paused_at` timestamp NULL DEFAULT NULL,
  `accumulated_seconds` int unsigned NOT NULL DEFAULT '0',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `billable` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `timer_sessions_job_id_foreign` (`job_id`),
  KEY `timer_sessions_job_task_id_foreign` (`job_task_id`),
  KEY `timer_sessions_team_member_id_foreign` (`team_member_id`),
  KEY `timer_sessions_user_id_status_index` (`user_id`,`status`),
  CONSTRAINT `timer_sessions_job_id_foreign` FOREIGN KEY (`job_id`) REFERENCES `work_jobs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `timer_sessions_job_task_id_foreign` FOREIGN KEY (`job_task_id`) REFERENCES `job_tasks` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timer_sessions_team_member_id_foreign` FOREIGN KEY (`team_member_id`) REFERENCES `team_members` (`id`) ON DELETE SET NULL,
  CONSTRAINT `timer_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `two_factor_recovery_codes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `two_factor_recovery_codes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `code_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `two_factor_recovery_codes_user_id_index` (`user_id`),
  CONSTRAINT `two_factor_recovery_codes_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `uploads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `uploads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `addendum_for_estimate_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `format` varchar(8) COLLATE utf8mb4_unicode_ci NOT NULL,
  `page_count` smallint unsigned NOT NULL DEFAULT '0',
  `size_bytes` bigint unsigned NOT NULL,
  `path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `thumbnail_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `preview_paths` json DEFAULT NULL,
  `annotated_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `final_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'processing',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `uploads_user_id_foreign` (`user_id`),
  KEY `uploads_project_id_foreign` (`project_id`),
  KEY `uploads_status_index` (`status`),
  KEY `uploads_addendum_for_estimate_id_foreign` (`addendum_for_estimate_id`),
  CONSTRAINT `uploads_addendum_for_estimate_id_foreign` FOREIGN KEY (`addendum_for_estimate_id`) REFERENCES `estimates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `uploads_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `uploads_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `device_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_agent` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_devices_user_id_device_hash_unique` (`user_id`,`device_hash`),
  CONSTRAINT `user_devices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_security_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_security_settings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `two_factor_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_security_settings_user_id_unique` (`user_id`),
  CONSTRAINT `user_security_settings_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `role` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'Project Manager',
  `registration_source` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web',
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `approved_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint unsigned DEFAULT NULL,
  `initials` varchar(4) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_approved_by_foreign` (`approved_by`),
  CONSTRAINT `users_approved_by_foreign` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wire_sizes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `wire_sizes` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `ai_result_id` bigint unsigned NOT NULL,
  `page` smallint unsigned NOT NULL,
  `size` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `context` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `count` int unsigned NOT NULL DEFAULT '1',
  `position` int unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `wire_sizes_ai_result_id_size_index` (`ai_result_id`,`size`),
  CONSTRAINT `wire_sizes_ai_result_id_foreign` FOREIGN KEY (`ai_result_id`) REFERENCES `ai_results` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=106 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `work_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `foreman_id` bigint unsigned DEFAULT NULL,
  `team_id` bigint unsigned DEFAULT NULL,
  `project_id` bigint unsigned DEFAULT NULL,
  `client_id` bigint unsigned DEFAULT NULL,
  `ai_result_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `location` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `geofence_radius` int unsigned DEFAULT NULL,
  `place_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `symbol_counts` json DEFAULT NULL,
  `boq` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `job_type` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `priority` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'medium',
  `estimated_hours` decimal(6,2) DEFAULT NULL,
  `actual_hours` decimal(8,2) DEFAULT NULL,
  `delay_hours` decimal(8,2) DEFAULT NULL,
  `delay_reason` text COLLATE utf8mb4_unicode_ci,
  `ready_for_review_at` timestamp NULL DEFAULT NULL,
  `required_skills` json DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(12,2) DEFAULT NULL,
  `create_estimate` tinyint(1) NOT NULL DEFAULT '0',
  `assign_team` tinyint(1) NOT NULL DEFAULT '0',
  `notify_client` tinyint(1) NOT NULL DEFAULT '0',
  `archived_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `work_jobs_status_index` (`status`),
  KEY `work_jobs_archived_at_index` (`archived_at`),
  KEY `work_jobs_project_id_foreign` (`project_id`),
  KEY `work_jobs_priority_index` (`priority`),
  KEY `work_jobs_foreman_id_foreign` (`foreman_id`),
  KEY `work_jobs_client_id_foreign` (`client_id`),
  KEY `work_jobs_place_id_index` (`place_id`),
  KEY `work_jobs_team_id_foreign` (`team_id`),
  KEY `work_jobs_user_id_foreign` (`user_id`),
  CONSTRAINT `work_jobs_client_id_foreign` FOREIGN KEY (`client_id`) REFERENCES `clients` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_jobs_foreman_id_foreign` FOREIGN KEY (`foreman_id`) REFERENCES `foremen` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_jobs_project_id_foreign` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_jobs_team_id_foreign` FOREIGN KEY (`team_id`) REFERENCES `teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `work_jobs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

