-- =====================================================================
-- Schema updates: 2026-09-12 through 2026-09-15
-- =====================================================================
--
-- Covers 7 migrations, in dependency order, since the last batch that
-- was taken as a deployment script (everything up to and including
-- 2026_09_11_100000_add_user_id_to_feed_items, see
-- schema_updates_2026-09-07_to_2026-09-11.sql):
--
--   1. 2026_09_12_090000_create_project_rate_book
--      — a project's own uploaded vendor rate list (workbook import,
--        per-line rates, and the matched/aggregated item lookup an
--        estimate prices lines from) — three new tables:
--        project_rate_imports, project_rate_lines, project_rate_items.
--   2. 2026_09_12_090100_add_project_rate_item_id_to_estimate_items
--      — records which project_rate_items row (if any) priced a given
--        estimate line, alongside the existing price_book_item_id.
--   3. 2026_09_15_090000_add_labor_rate_to_clients_table
--      — a client's own labor rate ($/hr), overriding the configured
--        default and the project's own rate list on every estimate
--        raised against that client's projects.
--   4. 2026_09_15_100000_add_geofence_radius_to_work_jobs_table
--      — how close counts as "on site" for mobile GPS check-in, per
--        job; null falls back to the mobile app's own 100 m default.
--   5. 2026_09_15_100100_create_job_attendances_table
--      — the mobile app's GPS check-in/check-out feature: one row per
--        technician, per job, per day.
--   6. 2026_09_15_120000_create_job_foreman_completions_table
--      — one row per (job, foreman) so each foreman's own completion
--        is tracked independently on a job worked by several.
--   7. 2026_09_15_130000_add_started_at_to_job_foreman_completions_table
--      — same independence for starting a job as for finishing it.
--
-- Generated directly from Laravel's own migration classes (via
-- `DB::connection()->pretend()`), not hand-written — the SQL below is
-- exactly what `php artisan migrate --force` would run for these 7
-- files, in the same order. Two of the newer migrations (#4 and #7)
-- guard themselves with a `Schema::hasColumn()` check for idempotency;
-- the introspection SELECT that guard issues is not schema-changing
-- and has been left out below — only the actual DDL is included.
--
-- BEFORE RUNNING ON LIVE:
--   1. Take a full database backup. Nothing below rewrites existing
--      row data (all seven are additive: new tables/columns, nothing
--      dropped or backfilled), but get a restore point first anyway.
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
--   The very last block below records these 7 migrations in Laravel's
--   own `migrations` table, so a later `php artisan migrate` on this
--   server does not try to run them again (and fail on "table/column
--   already exists"). Do not skip it.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 2026_09_12_090000_create_project_rate_book
-- ---------------------------------------------------------------------

create table `project_rate_imports` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `file_name` varchar(255) not null, `file_hash` varchar(64) not null, `project_name` varchar(255) null, `material_tax_pct` decimal(6, 3) null, `overhead_pct` decimal(6, 3) null, `profit_pct` decimal(6, 3) null, `electrician_rate` decimal(10, 2) null, `supervisor_rate` decimal(10, 2) null, `unskilled_rate` decimal(10, 2) null, `composite_labor_rate` decimal(10, 2) null, `total_manhours` decimal(12, 3) null, `material_cost` decimal(14, 2) null, `labor_cost` decimal(14, 2) null, `material_tax` decimal(14, 2) null, `total_cost` decimal(14, 2) null, `base_bid_price` decimal(14, 2) null, `line_count` int unsigned not null default '0', `imported_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `project_rate_imports` add constraint `project_rate_imports_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete cascade;
alter table `project_rate_imports` add unique `project_rate_imports_project_id_file_hash_unique`(`project_id`, `file_hash`);
create table `project_rate_lines` (`id` bigint unsigned not null auto_increment primary key, `project_rate_import_id` bigint unsigned not null, `project_id` bigint unsigned not null, `section` varchar(120) null, `subsection` varchar(120) null, `sr_no` varchar(24) null, `dwg_no` varchar(60) null, `detail_no` varchar(60) null, `description` text not null, `quantity` decimal(14, 4) null, `wastage` decimal(14, 4) null, `quantity_with_wastage` decimal(14, 4) null, `unit` varchar(24) null, `unit_material_cost` decimal(14, 4) null, `material_cost` decimal(14, 4) null, `manhour_rate` decimal(10, 2) null, `unit_manhours` decimal(12, 6) null, `total_manhours` decimal(14, 4) null, `manhours_cost` decimal(14, 4) null, `total_cost` decimal(14, 4) null, `source_row` int unsigned null, `match_key` varchar(255) not null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `project_rate_lines` add constraint `project_rate_lines_project_rate_import_id_foreign` foreign key (`project_rate_import_id`) references `project_rate_imports` (`id`) on delete cascade;
alter table `project_rate_lines` add constraint `project_rate_lines_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete cascade;
alter table `project_rate_lines` add index `project_rate_lines_match_key_index`(`match_key`);
create table `project_rate_items` (`id` bigint unsigned not null auto_increment primary key, `project_id` bigint unsigned not null, `match_key` varchar(255) not null, `unit` varchar(24) not null, `description` varchar(255) not null, `section` varchar(120) null, `subsection` varchar(120) null, `unit_material_cost` decimal(14, 4) null, `unit_manhours` decimal(12, 6) null, `sample_count` int unsigned not null default '0', `min_material_cost` decimal(14, 4) null, `max_material_cost` decimal(14, 4) null, `min_manhours` decimal(12, 6) null, `max_manhours` decimal(12, 6) null, `last_seen_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `project_rate_items` add constraint `project_rate_items_project_id_foreign` foreign key (`project_id`) references `projects` (`id`) on delete cascade;
alter table `project_rate_items` add unique `project_rate_items_project_id_match_key_unit_unique`(`project_id`, `match_key`, `unit`);

-- ---------------------------------------------------------------------
-- 2. 2026_09_12_090100_add_project_rate_item_id_to_estimate_items
-- ---------------------------------------------------------------------

alter table `estimate_items` add `project_rate_item_id` bigint unsigned null after `price_book_item_id`;
alter table `estimate_items` add constraint `estimate_items_project_rate_item_id_foreign` foreign key (`project_rate_item_id`) references `project_rate_items` (`id`) on delete set null;

-- ---------------------------------------------------------------------
-- 3. 2026_09_15_090000_add_labor_rate_to_clients_table
-- ---------------------------------------------------------------------

alter table `clients` add `labor_rate` decimal(8, 2) null after `notes`;

-- ---------------------------------------------------------------------
-- 4. 2026_09_15_100000_add_geofence_radius_to_work_jobs_table
-- ---------------------------------------------------------------------

alter table `work_jobs` add `geofence_radius` int unsigned null after `longitude`;

-- ---------------------------------------------------------------------
-- 5. 2026_09_15_100100_create_job_attendances_table
-- ---------------------------------------------------------------------

create table `job_attendances` (`id` bigint unsigned not null auto_increment primary key, `job_id` bigint unsigned not null, `user_id` bigint unsigned not null, `date` date not null, `status` varchar(255) not null, `check_in_at` timestamp null, `check_in_lat` decimal(10, 7) null, `check_in_lng` decimal(10, 7) null, `check_in_accuracy` decimal(8, 2) null, `check_in_distance_meters` decimal(10, 2) null, `check_in_method` varchar(255) null, `check_in_photo_path` varchar(255) null, `check_out_at` timestamp null, `check_out_lat` decimal(10, 7) null, `check_out_lng` decimal(10, 7) null, `check_out_accuracy` decimal(8, 2) null, `check_out_distance_meters` decimal(10, 2) null, `check_out_method` varchar(255) null, `banked_seconds` int unsigned not null default '0', `client_id` varchar(255) null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `job_attendances` add constraint `job_attendances_job_id_foreign` foreign key (`job_id`) references `work_jobs` (`id`) on delete cascade;
alter table `job_attendances` add constraint `job_attendances_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `job_attendances` add unique `job_attendances_job_id_user_id_date_unique`(`job_id`, `user_id`, `date`);
alter table `job_attendances` add index `job_attendances_user_id_index`(`user_id`);
alter table `job_attendances` add index `job_attendances_date_index`(`date`);

-- ---------------------------------------------------------------------
-- 6. 2026_09_15_120000_create_job_foreman_completions_table
-- ---------------------------------------------------------------------

create table `job_foreman_completions` (`id` bigint unsigned not null auto_increment primary key, `job_id` bigint unsigned not null, `foreman_id` bigint unsigned not null, `ready_for_review_at` timestamp null, `approved_at` timestamp null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `job_foreman_completions` add constraint `job_foreman_completions_job_id_foreign` foreign key (`job_id`) references `work_jobs` (`id`) on delete cascade;
alter table `job_foreman_completions` add constraint `job_foreman_completions_foreman_id_foreign` foreign key (`foreman_id`) references `foremen` (`id`) on delete cascade;
alter table `job_foreman_completions` add unique `job_foreman_completions_job_id_foreman_id_unique`(`job_id`, `foreman_id`);

-- ---------------------------------------------------------------------
-- 7. 2026_09_15_130000_add_started_at_to_job_foreman_completions_table
-- ---------------------------------------------------------------------

alter table `job_foreman_completions` add `started_at` timestamp null after `foreman_id`;

-- =====================================================================
-- Bookkeeping — tells Laravel these 7 are already applied, so a later
-- `php artisan migrate` on this server does not try to re-run them.
-- Run this LAST, only after every statement above has succeeded.
-- =====================================================================

set @next_batch = (select coalesce(max(batch), 0) + 1 from `migrations`);

insert into `migrations` (`migration`, `batch`) values
    ('2026_09_12_090000_create_project_rate_book', @next_batch),
    ('2026_09_12_090100_add_project_rate_item_id_to_estimate_items', @next_batch),
    ('2026_09_15_090000_add_labor_rate_to_clients_table', @next_batch),
    ('2026_09_15_100000_add_geofence_radius_to_work_jobs_table', @next_batch),
    ('2026_09_15_100100_create_job_attendances_table', @next_batch),
    ('2026_09_15_120000_create_job_foreman_completions_table', @next_batch),
    ('2026_09_15_130000_add_started_at_to_job_foreman_completions_table', @next_batch);
