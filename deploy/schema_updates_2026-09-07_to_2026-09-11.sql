-- =====================================================================
-- Schema updates: 2026-09-07 through 2026-09-11
-- =====================================================================
--
-- Covers 15 migrations, in dependency order, since the last batch that
-- was taken as a deployment script (everything up to and including
-- 2026_09_04_100000_backfill_estimate_clients):
--
--   1.  2026_09_07_090000_add_approval_status_to_users_table
--   2.  2026_09_07_090100_add_team_id_to_team_members_table
--   3.  2026_09_07_100000_create_the_price_book
--   4.  2026_09_07_110000_record_where_an_estimate_line_was_priced_from
--   5.  2026_09_07_110500_widen_estimate_rates_for_per_foot_pricing
--   6.  2026_09_08_072100_add_registration_source_to_users_table
--   7.  2026_09_08_120000_add_user_id_to_foremen_table
--   8.  2026_09_08_130000_add_completed_at_to_estimate_items_table
--   9.  2026_09_08_140000_add_delay_tracking_to_jobs_table
--   10. 2026_09_09_090000_add_ready_for_review_at_to_jobs_table
--   11. 2026_09_10_090000_add_estimate_target_total_to_projects_table
--   12. 2026_09_10_110000_add_user_id_to_price_book_tables
--   13. 2026_09_11_090000_add_user_id_to_jobs_estimates_invoices
--   14. 2026_09_11_090100_scope_invoice_numbers_per_manager
--   15. 2026_09_11_100000_add_user_id_to_feed_items
--
-- Generated directly from Laravel's own migration classes (via
-- `DB::connection()->pretend()`), not hand-written — the SQL below is
-- exactly what `php artisan migrate --force` would run for these 15
-- files, in the same order.
--
-- BEFORE RUNNING ON LIVE:
--   1. Take a full database backup. Several statements below rewrite
--      `user_id` on existing rows (work_jobs, estimates, invoices,
--      price_book_*) — get a restore point first.
--   2. MySQL DDL (CREATE TABLE / ALTER TABLE) auto-commits — it is NOT
--      transactional. If this script is interrupted partway through,
--      some migrations will be applied and some won't. Re-running it
--      from the top will fail on `CREATE TABLE` / duplicate-column
--      errors for whatever already landed — if that happens, open this
--      file, find where it stopped (check `SHOW TABLES` / `DESCRIBE`
--      against the list above), and resume from the next statement by
--      hand rather than re-running the whole file.
--   3. Run this against the LIVE database only. Confirm you are
--      connected to the right one before executing.
--
-- AFTER RUNNING:
--   The very last block below records these 15 migrations in Laravel's
--   own `migrations` table, so a later `php artisan migrate` on this
--   server does not try to run them again (and fail on "column already
--   exists"). Do not skip it.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 2026_09_07_090000_add_approval_status_to_users_table
-- ---------------------------------------------------------------------

alter table `users` add `status` varchar(255) not null default 'active' after `role`;
alter table `users` add `approved_at` timestamp null after `status`;
alter table `users` add `approved_by` bigint unsigned null after `approved_at`;
alter table `users` add constraint `users_approved_by_foreign` foreign key (`approved_by`) references `users` (`id`) on delete set null;

-- ---------------------------------------------------------------------
-- 2. 2026_09_07_090100_add_team_id_to_team_members_table
-- ---------------------------------------------------------------------

alter table `team_members` add `team_id` bigint unsigned null after `user_id`;
alter table `team_members` add constraint `team_members_team_id_foreign` foreign key (`team_id`) references `teams` (`id`) on delete set null;

-- ---------------------------------------------------------------------
-- 3. 2026_09_07_100000_create_the_price_book
-- ---------------------------------------------------------------------

create table `price_book_imports` (`id` bigint unsigned not null auto_increment primary key, `file_name` varchar(255) not null, `file_hash` varchar(64) not null, `project_name` varchar(255) null, `material_cost` decimal(14, 2) null, `labor_cost` decimal(14, 2) null, `material_tax` decimal(14, 2) null, `total_cost` decimal(14, 2) null, `base_bid_price` decimal(14, 2) null, `material_tax_pct` decimal(6, 3) null, `overhead_pct` decimal(6, 3) null, `profit_pct` decimal(6, 3) null, `electrician_rate` decimal(10, 2) null, `supervisor_rate` decimal(10, 2) null, `unskilled_rate` decimal(10, 2) null, `composite_labor_rate` decimal(10, 2) null, `total_manhours` decimal(12, 3) null, `line_count` int unsigned not null default '0', `imported_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `price_book_imports` add unique `price_book_imports_file_hash_unique`(`file_hash`);
create table `price_book_lines` (`id` bigint unsigned not null auto_increment primary key, `price_book_import_id` bigint unsigned not null, `section` varchar(120) null, `subsection` varchar(120) null, `sr_no` varchar(24) null, `dwg_no` varchar(60) null, `detail_no` varchar(60) null, `description` text not null, `quantity` decimal(14, 4) null, `wastage` decimal(14, 4) null, `quantity_with_wastage` decimal(14, 4) null, `unit` varchar(24) null, `unit_material_cost` decimal(14, 4) null, `material_cost` decimal(14, 4) null, `manhour_rate` decimal(10, 2) null, `unit_manhours` decimal(12, 6) null, `total_manhours` decimal(14, 4) null, `manhours_cost` decimal(14, 4) null, `total_cost` decimal(14, 4) null, `source_row` int unsigned null, `match_key` varchar(255) not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `price_book_lines` add constraint `price_book_lines_price_book_import_id_foreign` foreign key (`price_book_import_id`) references `price_book_imports` (`id`) on delete cascade;
alter table `price_book_lines` add index `price_book_lines_match_key_index`(`match_key`);
create table `price_book_items` (`id` bigint unsigned not null auto_increment primary key, `match_key` varchar(255) not null, `unit` varchar(24) not null, `description` varchar(255) not null, `section` varchar(120) null, `subsection` varchar(120) null, `unit_material_cost` decimal(14, 4) null, `unit_manhours` decimal(12, 6) null, `sample_count` int unsigned not null default '0', `min_material_cost` decimal(14, 4) null, `max_material_cost` decimal(14, 4) null, `min_manhours` decimal(12, 6) null, `max_manhours` decimal(12, 6) null, `is_pinned` tinyint(1) not null default '0', `last_seen_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `price_book_items` add unique `price_book_items_match_key_unit_unique`(`match_key`, `unit`);

-- ---------------------------------------------------------------------
-- 4. 2026_09_07_110000_record_where_an_estimate_line_was_priced_from
-- ---------------------------------------------------------------------

alter table `estimate_items` add `pricing_source` varchar(24) null after `source`;
alter table `estimate_items` add `price_book_item_id` bigint unsigned null after `pricing_source`;
alter table `estimate_items` add constraint `estimate_items_price_book_item_id_foreign` foreign key (`price_book_item_id`) references `price_book_items` (`id`) on delete set null;
alter table `estimate_items` add `pricing_confidence` varchar(16) null after `price_book_item_id`;

-- ---------------------------------------------------------------------
-- 5. 2026_09_07_110500_widen_estimate_rates_for_per_foot_pricing
-- ---------------------------------------------------------------------

alter table `estimate_items` modify `quantity` decimal(14, 4) not null;
alter table `estimate_items` modify `unit_cost` decimal(14, 4) not null;

-- ---------------------------------------------------------------------
-- 6. 2026_09_08_072100_add_registration_source_to_users_table
-- ---------------------------------------------------------------------

alter table `users` add `registration_source` varchar(255) not null default 'web' after `role`;

-- ---------------------------------------------------------------------
-- 7. 2026_09_08_120000_add_user_id_to_foremen_table
-- ---------------------------------------------------------------------

alter table `foremen` add `user_id` bigint unsigned null after `id`;
alter table `foremen` add constraint `foremen_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `foremen` add unique `foremen_user_id_unique`(`user_id`);

-- ---------------------------------------------------------------------
-- 8. 2026_09_08_130000_add_completed_at_to_estimate_items_table
-- ---------------------------------------------------------------------

alter table `estimate_items` add `completed_at` timestamp null after `job_task_id`;

-- ---------------------------------------------------------------------
-- 9. 2026_09_08_140000_add_delay_tracking_to_jobs_table
-- ---------------------------------------------------------------------

alter table `work_jobs` add `actual_hours` decimal(8, 2) null after `estimated_hours`;
alter table `work_jobs` add `delay_hours` decimal(8, 2) null after `actual_hours`;
alter table `work_jobs` add `delay_reason` text null after `delay_hours`;

-- ---------------------------------------------------------------------
-- 10. 2026_09_09_090000_add_ready_for_review_at_to_jobs_table
-- ---------------------------------------------------------------------

alter table `work_jobs` add `ready_for_review_at` timestamp null after `delay_reason`;

-- ---------------------------------------------------------------------
-- 11. 2026_09_10_090000_add_estimate_target_total_to_projects_table
-- ---------------------------------------------------------------------

alter table `projects` add `estimate_target_total` decimal(12, 2) null after `due_date`;

-- ---------------------------------------------------------------------
-- 12. 2026_09_10_110000_add_user_id_to_price_book_tables
-- ---------------------------------------------------------------------

alter table `price_book_imports` add `user_id` bigint unsigned null after `id`;
alter table `price_book_imports` add constraint `price_book_imports_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `price_book_imports` drop index `price_book_imports_file_hash_unique`;
alter table `price_book_imports` add unique `price_book_imports_user_id_file_hash_unique`(`user_id`, `file_hash`);
alter table `price_book_lines` add `user_id` bigint unsigned null after `price_book_import_id`;
alter table `price_book_lines` add constraint `price_book_lines_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `price_book_lines` add index `price_book_lines_user_id_match_key_unit_index`(`user_id`, `match_key`, `unit`);
alter table `price_book_items` add `user_id` bigint unsigned null after `id`;
alter table `price_book_items` add constraint `price_book_items_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `price_book_items` drop index `price_book_items_match_key_unit_unique`;
alter table `price_book_items` add unique `price_book_items_user_id_match_key_unit_unique`(`user_id`, `match_key`, `unit`);

-- ---------------------------------------------------------------------
-- 13. 2026_09_11_090000_add_user_id_to_jobs_estimates_invoices
--
-- The three UPDATE...JOIN statements below backfill `user_id` on
-- existing rows from their project/client — this is the one block
-- where live data volume matters. On a large `work_jobs`/`estimates`/
-- `invoices` table this can take a few seconds; that's expected.
-- ---------------------------------------------------------------------

alter table `work_jobs` add `user_id` bigint unsigned null after `id`;
alter table `work_jobs` add constraint `work_jobs_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
update `work_jobs` inner join `projects` on `projects`.`id` = `work_jobs`.`project_id` set `work_jobs`.`user_id` = projects.user_id;
update `work_jobs` inner join `clients` on `clients`.`id` = `work_jobs`.`client_id` set `work_jobs`.`user_id` = clients.user_id where `work_jobs`.`user_id` is null;
alter table `estimates` add `user_id` bigint unsigned null after `id`;
alter table `estimates` add constraint `estimates_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
update `estimates` inner join `projects` on `projects`.`id` = `estimates`.`project_id` set `estimates`.`user_id` = projects.user_id;
update `estimates` inner join `clients` on `clients`.`id` = `estimates`.`client_id` set `estimates`.`user_id` = clients.user_id where `estimates`.`user_id` is null;
alter table `estimates` drop index `estimates_number_unique`;
alter table `estimates` add unique `estimates_user_id_number_unique`(`user_id`, `number`);
alter table `invoices` add `user_id` bigint unsigned null after `id`;
alter table `invoices` add constraint `invoices_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
update `invoices` inner join `projects` on `projects`.`id` = `invoices`.`project_id` set `invoices`.`user_id` = projects.user_id;
update `invoices` inner join `clients` on `clients`.`id` = `invoices`.`client_id` set `invoices`.`user_id` = clients.user_id where `invoices`.`user_id` is null;
update `invoices` inner join `work_jobs` on `work_jobs`.`id` = `invoices`.`job_id` set `invoices`.`user_id` = work_jobs.user_id where `invoices`.`user_id` is null;

-- ---------------------------------------------------------------------
-- 14. 2026_09_11_090100_scope_invoice_numbers_per_manager
-- ---------------------------------------------------------------------

alter table `invoices` drop index `invoices_invoice_number_unique`;
alter table `invoices` add unique `invoices_user_id_invoice_number_unique`(`user_id`, `invoice_number`);

-- ---------------------------------------------------------------------
-- 15. 2026_09_11_100000_add_user_id_to_feed_items
-- ---------------------------------------------------------------------

alter table `feed_items` add `user_id` bigint unsigned null after `id`;
alter table `feed_items` add constraint `feed_items_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;

-- =====================================================================
-- Bookkeeping — tells Laravel these 15 are already applied, so a later
-- `php artisan migrate` on this server does not try to re-run them.
-- Run this LAST, only after every statement above has succeeded.
-- =====================================================================

set @next_batch = (select coalesce(max(batch), 0) + 1 from `migrations`);

insert into `migrations` (`migration`, `batch`) values
    ('2026_09_07_090000_add_approval_status_to_users_table', @next_batch),
    ('2026_09_07_090100_add_team_id_to_team_members_table', @next_batch),
    ('2026_09_07_100000_create_the_price_book', @next_batch),
    ('2026_09_07_110000_record_where_an_estimate_line_was_priced_from', @next_batch),
    ('2026_09_07_110500_widen_estimate_rates_for_per_foot_pricing', @next_batch),
    ('2026_09_08_072100_add_registration_source_to_users_table', @next_batch),
    ('2026_09_08_120000_add_user_id_to_foremen_table', @next_batch),
    ('2026_09_08_130000_add_completed_at_to_estimate_items_table', @next_batch),
    ('2026_09_08_140000_add_delay_tracking_to_jobs_table', @next_batch),
    ('2026_09_09_090000_add_ready_for_review_at_to_jobs_table', @next_batch),
    ('2026_09_10_090000_add_estimate_target_total_to_projects_table', @next_batch),
    ('2026_09_10_110000_add_user_id_to_price_book_tables', @next_batch),
    ('2026_09_11_090000_add_user_id_to_jobs_estimates_invoices', @next_batch),
    ('2026_09_11_090100_scope_invoice_numbers_per_manager', @next_batch),
    ('2026_09_11_100000_add_user_id_to_feed_items', @next_batch);
