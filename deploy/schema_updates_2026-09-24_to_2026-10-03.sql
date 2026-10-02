-- =====================================================================
-- Schema updates: 2026-09-24 through 2026-10-03  (live deployment script)
-- =====================================================================
--
-- Covers 28 migrations, in dependency order, since the last batch taken
-- as a deployment script (everything up to and including
-- 2026_09_18_173012_create_job_apprentice_assignments_table, see
-- schema_updates_2026-09-17_to_2026-09-18.sql).
--
-- Generated from Laravel's own migration classes via
-- `php artisan migrate --pretend` against a throwaway copy of the
-- 2026-09-19 schema, then applied for real to that copy to check it.
-- The `select`-driven data steps that --pretend cannot run are written out by hand:
--   * 2026_09_30_000009: users.company_id backfilled from company_profiles.user_id.
--   * 2026_09_30_000006 sets `subscriptions.licenses`, but 000007 drops that column
--     again, so that step has no net effect and is omitted.
--
-- NOT in this file:
--   * Rows of static_takeoff_datasets / symbol_icons -> load
--     database/exports/2026-09-24_to_2026-09-25_static_takeoff_data.sql AFTER this file,
--     and deploy public/symbol-icons/ image files.
--
-- BEFORE RUNNING ON LIVE: take a full backup. Run ONCE (not idempotent).
-- Apply with:
--   mysql -h<host> -u<user> -p <database> < schema_updates_2026-09-24_to_2026-10-03.sql
-- The last block records these migrations in Laravel's `migrations` table so a
-- later `php artisan migrate` will not try to re-run them.
-- =====================================================================

SET FOREIGN_KEY_CHECKS=1;

-- 1. 2026_09_24_000001_create_static_takeoff_datasets_table
create table `static_takeoff_datasets` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) null, `file_hash` varchar(64) not null, `original_filename` varchar(255) null, `file_size` bigint unsigned null, `mime_type` varchar(255) null, `pdf_path` varchar(255) null, `takeoff_payload` json not null, `metadata` json null, `is_active` tinyint(1) not null default '1', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `static_takeoff_datasets` add unique `static_takeoff_datasets_file_hash_unique`(`file_hash`);

-- 2. 2026_09_25_000001_create_symbol_icons_table
create table `symbol_icons` (`id` bigint unsigned not null auto_increment primary key, `name` varchar(255) not null, `slug` varchar(255) not null, `keywords` json not null, `path` varchar(255) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `symbol_icons` add unique `symbol_icons_slug_unique`(`slug`);

-- 3. 2026_09_26_000001_add_takeoff_flow_project_id_to_users_table
alter table `users` add `takeoff_flow_project_id` bigint unsigned null after `id`;
alter table `users` add constraint `users_takeoff_flow_project_id_foreign` foreign key (`takeoff_flow_project_id`) references `projects` (`id`) on delete set null;

-- 4. 2026_09_29_000001_add_team_id_to_clients_table
alter table `clients` add `team_id` bigint unsigned null after `user_id`;
alter table `clients` add constraint `clients_team_id_foreign` foreign key (`team_id`) references `teams` (`id`) on delete set null;

-- 5. 2026_09_29_000002_create_project_members_table
create table `project_members` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `foreman_id` bigint unsigned not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `project_members` add constraint `project_members_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete cascade;
alter table `project_members` add constraint `project_members_foreman_id_foreign` foreign key (`foreman_id`) references `foremen` (`id`) on delete cascade;
alter table `project_members` add unique `project_members_project_id_foreman_id_unique`(`project_id`, `foreman_id`);

-- 6. 2026_09_29_000003_add_contact_details_to_clients_table
alter table `clients` add `contact_name` varchar(160) null after `name`;
alter table `clients` add `contact_email` varchar(255) null after `contact_name`;
alter table `clients` add `contact_phone` varchar(40) null after `contact_email`;

-- 7. 2026_09_29_000004_drop_contact_name_from_clients_table
alter table `clients` drop `contact_name`;

-- 8. 2026_09_29_000005_add_company_and_website_to_clients_table
alter table `clients` add `company_name` varchar(160) null after `name`;
alter table `clients` add `website` varchar(255) null after `company_name`;

-- 9. 2026_09_29_000006_create_client_contacts_table
create table `client_contacts` (`id` bigint unsigned not null auto_increment primary key, `client_id` bigint unsigned not null, `name` varchar(160) not null, `role` varchar(80) null, `email` varchar(255) null, `phone` varchar(40) null, `is_primary` tinyint(1) not null default '0', `position` int unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `client_contacts` add constraint `client_contacts_client_id_foreign` foreign key (`client_id`) references `clients` (`id`) on delete cascade;
alter table `client_contacts` add index `client_contacts_client_id_position_index`(`client_id`, `position`);

-- 10. 2026_09_29_000007_drop_contact_details_from_clients_table
alter table `clients` drop `contact_email`, drop `contact_phone`;

-- 11. 2026_09_29_000008_add_description_to_projects_table
alter table `projects` add `description` varchar(255) null after `name`;

-- 12. 2026_09_30_000001_add_addendum_details_to_uploads_table
alter table `uploads` add `addendum_reason` text null after `addendum_for_estimate_id`;
alter table `uploads` add `affected_sheets` varchar(500) null after `addendum_reason`;

-- 13. 2026_09_30_000002_add_source_to_invoice_items_table
alter table `invoice_items` add `source` varchar(16) null after `source_category`;
update `invoice_items` set `source` = 'estimate' where `source_category` is not null;
update `invoice_items` set `source` = 'manual' where `source_category` is null;

-- 14. 2026_09_30_000003_create_subscriptions_table
create table `subscriptions` (`id` bigint unsigned not null auto_increment primary key, `plan` varchar(32) not null, `status` varchar(16) not null default 'active', `billing_cycle` varchar(16) not null default 'monthly', `renews_on` date not null, `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `subscriptions` add constraint `subscriptions_updated_by_foreign` foreign key (`updated_by`) references `users` (`id`) on delete set null;

-- 15. 2026_09_30_000004_create_company_profiles_table
create table `company_profiles` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `name` varchar(255) not null, `business_address` text not null, `primary_contact` varchar(255) not null, `phone` varchar(32) not null, `email` varchar(255) not null, `license_number` varchar(255) null, `timezone` varchar(64) not null, `logo_path` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `company_profiles` add constraint `company_profiles_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `company_profiles` add unique `company_profiles_user_id_unique`(`user_id`);
alter table `users` add `needs_company_setup` tinyint(1) not null default '0' after `status`;

-- 16. 2026_09_30_000005_create_terms_acceptances_table
create table `terms_acceptances` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `version` varchar(32) not null, `signer_name` varchar(255) not null, `signed_on` date not null, `ip_address` varchar(45) null, `accepted_at` timestamp not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `terms_acceptances` add constraint `terms_acceptances_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `terms_acceptances` add unique `terms_acceptances_user_id_version_unique`(`user_id`, `version`);
alter table `users` add `needs_terms_acceptance` tinyint(1) not null default '0' after `needs_company_setup`;

-- 17. 2026_09_30_000006_add_licenses_and_payment_setup
alter table `subscriptions` add `user_id` bigint unsigned null after `id`;
alter table `subscriptions` add constraint `subscriptions_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `subscriptions` add `licenses` int unsigned not null default '1' after `plan`;
alter table `subscriptions` add unique `subscriptions_user_id_unique`(`user_id`);
update `subscriptions` set `plan` = 'growth' where `plan` = 'pro';
update `subscriptions` set `plan` = 'enterprise' where `plan` = 'business';
create table `subscription_cards` (`id` bigint unsigned not null auto_increment primary key, `user_id` bigint unsigned not null, `cardholder_name` varchar(255) not null, `brand` varchar(40) not null, `last_four` varchar(4) not null, `exp_month` tinyint unsigned not null, `exp_year` smallint unsigned not null, `address_line1` varchar(255) not null, `address_line2` varchar(255) null, `city` varchar(255) not null, `state` varchar(64) not null, `postal_code` varchar(16) not null, `country` varchar(2) not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `subscription_cards` add constraint `subscription_cards_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `subscription_cards` add unique `subscription_cards_user_id_unique`(`user_id`);
alter table `users` add `needs_payment_setup` tinyint(1) not null default '0' after `needs_terms_acceptance`;

-- 18. 2026_09_30_000007_drop_licenses_from_subscriptions
alter table `subscriptions` drop `licenses`;

-- 19. 2026_09_30_000008_move_subscription_payment_to_stripe
alter table `subscriptions` add `stripe_customer_id` varchar(255) null after `status`;
alter table `subscriptions` add `stripe_subscription_id` varchar(255) null after `stripe_customer_id`;
alter table `subscription_cards` modify `address_line1` varchar(255) null;
alter table `subscription_cards` modify `city` varchar(255) null;
alter table `subscription_cards` modify `state` varchar(64) null;
alter table `subscription_cards` modify `postal_code` varchar(16) null;
alter table `subscription_cards` modify `country` varchar(2) null;

-- 20. 2026_09_30_000009_scope_crew_registers_to_companies
alter table `users` add `company_id` bigint unsigned null after `status`;
alter table `users` add constraint `users_company_id_foreign` foreign key (`company_id`) references `company_profiles` (`id`) on delete set null;
alter table `teams` add `company_id` bigint unsigned null;
alter table `teams` add constraint `teams_company_id_foreign` foreign key (`company_id`) references `company_profiles` (`id`) on delete cascade;
alter table `foremen` add `company_id` bigint unsigned null;
alter table `foremen` add constraint `foremen_company_id_foreign` foreign key (`company_id`) references `company_profiles` (`id`) on delete cascade;
alter table `team_members` add `company_id` bigint unsigned null;
alter table `team_members` add constraint `team_members_company_id_foreign` foreign key (`company_id`) references `company_profiles` (`id`) on delete cascade;
alter table `teams` drop index `teams_name_unique`;
alter table `teams` add unique `teams_company_id_name_unique`(`company_id`, `name`);
-- (data step from the migration) accounts that already set a company up belong to it.
update `users` join `company_profiles` on `company_profiles`.`user_id` = `users`.`id` set `users`.`company_id` = `company_profiles`.`id`;

-- 21. 2026_09_30_000010_add_confirmation_to_subscriptions
alter table `subscriptions` add `confirmation_number` varchar(32) null after `stripe_subscription_id`;
alter table `subscriptions` add `receipt_url` text null after `confirmation_number`;
alter table `subscriptions` add unique `subscriptions_confirmation_number_unique`(`confirmation_number`);

-- 22. 2026_10_01_000001_create_estimate_builder
create table `estimate_builder_lines` (`id` bigint unsigned not null auto_increment primary key, `estimate_id` bigint unsigned not null, `position` int unsigned not null default '0', `description` varchar(255) not null, `commodity` varchar(255) null, `unit` varchar(20) not null default 'EA', `material_qty` decimal(14, 4) not null default '0', `material_unit_price` decimal(14, 4) not null default '0', `labor_hours` decimal(14, 4) not null default '0', `labor_rate` decimal(14, 2) not null default '0', `markup_pct` decimal(6, 2) not null default '0', `source` varchar(20) not null default 'manual', `source_estimate_item_id` bigint unsigned null, `price_book_item_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `estimate_builder_lines` add constraint `estimate_builder_lines_estimate_id_foreign` foreign key (`estimate_id`) references `estimates` (`id`) on delete cascade;
alter table `estimate_builder_lines` add constraint `estimate_builder_lines_source_estimate_item_id_foreign` foreign key (`source_estimate_item_id`) references `estimate_items` (`id`) on delete set null;
alter table `estimate_builder_lines` add constraint `estimate_builder_lines_price_book_item_id_foreign` foreign key (`price_book_item_id`) references `price_book_items` (`id`) on delete set null;
alter table `estimates` add `builder_managed` tinyint(1) not null default '0' after `kind`;
alter table `estimates` add `takeoff_source_estimate_id` bigint unsigned null after `builder_managed`;
alter table `estimates` add constraint `estimates_takeoff_source_estimate_id_foreign` foreign key (`takeoff_source_estimate_id`) references `estimates` (`id`) on delete set null;
alter table `estimates` add `commodity_version` varchar(255) null after `takeoff_source_estimate_id`;
alter table `estimates` add `builder_labor_rate` decimal(8, 2) not null default '65' after `commodity_version`;
alter table `estimate_items` add `markup_pct` decimal(6, 2) null after `total`;
alter table `estimate_items` add `builder_line_id` bigint unsigned null after `markup_pct`;
alter table `estimate_items` add constraint `estimate_items_builder_line_id_foreign` foreign key (`builder_line_id`) references `estimate_builder_lines` (`id`) on delete cascade;

-- 23. 2026_10_01_000002_add_review_and_approval_to_estimates
alter table `estimates` add `scope_of_work` text null after `notes`;
alter table `estimates` add `exclusions` json null after `scope_of_work`;
alter table `estimates` add `approved_by` bigint unsigned null after `exclusions`;
alter table `estimates` add constraint `estimates_approved_by_foreign` foreign key (`approved_by`) references `users` (`id`) on delete set null;
alter table `estimates` add `approved_at` timestamp null after `approved_by`;
alter table `estimates` add `approved_revision` int unsigned null after `approved_at`;
alter table `estimates` add `review_notes` text null after `approved_revision`;
alter table `estimates` add `reviewed_by` bigint unsigned null after `review_notes`;
alter table `estimates` add constraint `estimates_reviewed_by_foreign` foreign key (`reviewed_by`) references `users` (`id`) on delete set null;
alter table `estimates` add `reviewed_at` timestamp null after `reviewed_by`;
create table `estimate_revisions` (`id` bigint unsigned not null auto_increment primary key, `estimate_id` bigint unsigned not null, `version` int unsigned not null, `changes` varchar(255) not null, `user_id` bigint unsigned null, `total` decimal(14, 2) not null default '0', `item_count` int unsigned not null default '0', `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `estimate_revisions` add constraint `estimate_revisions_estimate_id_foreign` foreign key (`estimate_id`) references `estimates` (`id`) on delete cascade;
alter table `estimate_revisions` add constraint `estimate_revisions_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `estimate_revisions` add unique `estimate_revisions_estimate_id_version_unique`(`estimate_id`, `version`);

-- 24. 2026_10_01_000003_add_onboarding_to_company_profiles
alter table `company_profiles` add `onboarding_skipped` json null after `logo_path`;
alter table `company_profiles` add `onboarding_finished_at` timestamp null after `onboarding_skipped`;

-- 25. 2026_10_01_000004_create_team_invitations_table
create table `team_invitations` (`id` bigint unsigned not null auto_increment primary key, `company_id` bigint unsigned not null, `invited_by` bigint unsigned null, `name` varchar(255) not null, `email` varchar(255) not null, `phone` varchar(32) null, `role` varchar(40) not null, `team_id` bigint unsigned null, `token_hash` varchar(64) not null, `status` varchar(20) not null default 'pending', `sent_at` timestamp null, `expires_at` timestamp not null, `accepted_at` timestamp null, `user_id` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `team_invitations` add constraint `team_invitations_company_id_foreign` foreign key (`company_id`) references `company_profiles` (`id`) on delete cascade;
alter table `team_invitations` add constraint `team_invitations_invited_by_foreign` foreign key (`invited_by`) references `users` (`id`) on delete set null;
alter table `team_invitations` add constraint `team_invitations_team_id_foreign` foreign key (`team_id`) references `teams` (`id`) on delete set null;
alter table `team_invitations` add constraint `team_invitations_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `team_invitations` add index `team_invitations_company_id_status_index`(`company_id`, `status`);
alter table `team_invitations` add index `team_invitations_email_index`(`email`);
alter table `team_invitations` add unique `team_invitations_token_hash_unique`(`token_hash`);

-- 26. 2026_10_01_000005_add_commodity_fields_to_price_book_items
alter table `price_book_items` add `item_code` varchar(40) null after `description`;
alter table `price_book_items` add `markup_pct` decimal(6, 2) null after `unit_manhours`;
alter table `price_book_items` add `archived_at` timestamp null after `is_pinned`;
alter table `price_book_items` add unique `price_book_items_user_id_item_code_unique`(`user_id`, `item_code`);

-- 27. 2026_10_02_000001_create_change_orders
create table `change_orders` (`id` bigint unsigned not null auto_increment primary key, `owner_id` bigint unsigned not null, `job_id` bigint unsigned not null, `number` int unsigned not null, `created_by` bigint unsigned null, `description` varchar(255) not null, `reason` text null, `source` varchar(10) not null default 'office', `status` varchar(12) not null default 'draft', `markup_pct` decimal(6, 2) not null default '0', `labor_hours` decimal(10, 2) not null default '0', `labor_cost` decimal(12, 2) not null default '0', `material_cost` decimal(12, 2) not null default '0', `cost_total` decimal(12, 2) not null default '0', `sell_total` decimal(12, 2) not null default '0', `submitted_at` timestamp null, `decided_by` bigint unsigned null, `decided_at` timestamp null, `decision_note` text null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `change_orders` add constraint `change_orders_owner_id_foreign` foreign key (`owner_id`) references `users` (`id`) on delete cascade;
alter table `change_orders` add constraint `change_orders_job_id_foreign` foreign key (`job_id`) references `work_jobs` (`id`) on delete cascade;
alter table `change_orders` add constraint `change_orders_created_by_foreign` foreign key (`created_by`) references `users` (`id`) on delete set null;
alter table `change_orders` add constraint `change_orders_decided_by_foreign` foreign key (`decided_by`) references `users` (`id`) on delete set null;
alter table `change_orders` add unique `change_orders_owner_id_number_unique`(`owner_id`, `number`);
alter table `change_orders` add index `change_orders_job_id_status_index`(`job_id`, `status`);
create table `change_order_lines` (`id` bigint unsigned not null auto_increment primary key, `change_order_id` bigint unsigned not null, `kind` varchar(10) not null, `description` varchar(255) not null, `quantity` decimal(12, 2) not null, `unit` varchar(16) null, `unit_cost` decimal(12, 2) not null, `total` decimal(12, 2) not null, `position` int unsigned not null default '0') default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `change_order_lines` add constraint `change_order_lines_change_order_id_foreign` foreign key (`change_order_id`) references `change_orders` (`id`) on delete cascade;
create table `change_order_attachments` (`id` bigint unsigned not null auto_increment primary key, `change_order_id` bigint unsigned not null, `user_id` bigint unsigned null, `name` varchar(255) not null, `path` varchar(255) not null, `size` bigint unsigned not null default '0', `mime` varchar(120) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `change_order_attachments` add constraint `change_order_attachments_change_order_id_foreign` foreign key (`change_order_id`) references `change_orders` (`id`) on delete cascade;
alter table `change_order_attachments` add constraint `change_order_attachments_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
create table `change_order_events` (`id` bigint unsigned not null auto_increment primary key, `change_order_id` bigint unsigned not null, `user_id` bigint unsigned null, `type` varchar(20) not null, `note` text null, `sell_total` decimal(12, 2) null, `created_at` timestamp not null default CURRENT_TIMESTAMP) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `change_order_events` add constraint `change_order_events_change_order_id_foreign` foreign key (`change_order_id`) references `change_orders` (`id`) on delete cascade;
alter table `change_order_events` add constraint `change_order_events_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `invoice_items` add `change_order_id` bigint unsigned null after `invoice_id`;
alter table `invoice_items` add constraint `invoice_items_change_order_id_foreign` foreign key (`change_order_id`) references `change_orders` (`id`) on delete set null;

-- 28. 2026_10_03_000001_add_role_permissions_to_company_profiles
alter table `company_profiles` add `role_permissions` json null;

-- Record the migrations as run.
set @next_batch = (select coalesce(max(batch), 0) + 1 from `migrations`);
insert into `migrations` (`migration`, `batch`) values
  ('2026_09_24_000001_create_static_takeoff_datasets_table', @next_batch),
  ('2026_09_25_000001_create_symbol_icons_table', @next_batch),
  ('2026_09_26_000001_add_takeoff_flow_project_id_to_users_table', @next_batch),
  ('2026_09_29_000001_add_team_id_to_clients_table', @next_batch),
  ('2026_09_29_000002_create_project_members_table', @next_batch),
  ('2026_09_29_000003_add_contact_details_to_clients_table', @next_batch),
  ('2026_09_29_000004_drop_contact_name_from_clients_table', @next_batch),
  ('2026_09_29_000005_add_company_and_website_to_clients_table', @next_batch),
  ('2026_09_29_000006_create_client_contacts_table', @next_batch),
  ('2026_09_29_000007_drop_contact_details_from_clients_table', @next_batch),
  ('2026_09_29_000008_add_description_to_projects_table', @next_batch),
  ('2026_09_30_000001_add_addendum_details_to_uploads_table', @next_batch),
  ('2026_09_30_000002_add_source_to_invoice_items_table', @next_batch),
  ('2026_09_30_000003_create_subscriptions_table', @next_batch),
  ('2026_09_30_000004_create_company_profiles_table', @next_batch),
  ('2026_09_30_000005_create_terms_acceptances_table', @next_batch),
  ('2026_09_30_000006_add_licenses_and_payment_setup', @next_batch),
  ('2026_09_30_000007_drop_licenses_from_subscriptions', @next_batch),
  ('2026_09_30_000008_move_subscription_payment_to_stripe', @next_batch),
  ('2026_09_30_000009_scope_crew_registers_to_companies', @next_batch),
  ('2026_09_30_000010_add_confirmation_to_subscriptions', @next_batch),
  ('2026_10_01_000001_create_estimate_builder', @next_batch),
  ('2026_10_01_000002_add_review_and_approval_to_estimates', @next_batch),
  ('2026_10_01_000003_add_onboarding_to_company_profiles', @next_batch),
  ('2026_10_01_000004_create_team_invitations_table', @next_batch),
  ('2026_10_01_000005_add_commodity_fields_to_price_book_items', @next_batch),
  ('2026_10_02_000001_create_change_orders', @next_batch),
  ('2026_10_03_000001_add_role_permissions_to_company_profiles', @next_batch);
