-- =====================================================================
-- Schema updates: 2026-09-16
-- =====================================================================
--
-- Covers 1 migration, the only one since the last batch that was taken
-- as a deployment script (everything up to and including
-- 2026_09_15_130000_add_started_at_to_job_foreman_completions_table,
-- see schema_updates_2026-09-12_to_2026-09-15.sql):
--
--   1. 2026_09_16_090000_create_estimate_item_comments_and_attachments_tables
--      — a note or photo about ONE material line, not the whole task —
--        mirrors job_task_comments/job_task_attachments exactly, just
--        scoped one level finer (the mobile Materials screen moved
--        entirely to per-material notes/photos). Two new tables:
--        estimate_item_comments, estimate_item_attachments.
--
-- Generated directly from Laravel's own migration class (via
-- `DB::connection()->pretend()`), not hand-written — the SQL below is
-- exactly what `php artisan migrate --force` would run for this file.
-- The migration guards itself with `Schema::hasTable()` checks for
-- idempotency; the introspection SELECTs those guards issue are not
-- schema-changing and have been left out below — only the actual DDL
-- is included.
--
-- BEFORE RUNNING ON LIVE:
--   1. Take a full database backup. This migration is purely additive
--      (two new tables, nothing existing touched), but get a restore
--      point first anyway.
--   2. MySQL DDL (CREATE TABLE) auto-commits — it is NOT transactional.
--      If this script is interrupted partway through, re-running it
--      from the top will fail on `CREATE TABLE` for whatever already
--      landed — check `SHOW TABLES` against the list above and resume
--      from the next statement by hand rather than re-running the file.
--   3. Run this against the LIVE database only. Confirm you are
--      connected to the right one before executing.
--
-- AFTER RUNNING:
--   The very last block below records this migration in Laravel's own
--   `migrations` table, so a later `php artisan migrate` on this server
--   does not try to run it again (and fail on "table already exists").
--   Do not skip it.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 2026_09_16_090000_create_estimate_item_comments_and_attachments_tables
-- ---------------------------------------------------------------------

create table `estimate_item_comments` (`id` bigint unsigned not null auto_increment primary key, `estimate_item_id` bigint unsigned not null, `user_id` bigint unsigned null, `body` text not null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `estimate_item_comments` add constraint `estimate_item_comments_estimate_item_id_foreign` foreign key (`estimate_item_id`) references `estimate_items` (`id`) on delete cascade;
alter table `estimate_item_comments` add constraint `estimate_item_comments_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete set null;
alter table `estimate_item_comments` add index `estimate_item_comments_estimate_item_id_created_at_index`(`estimate_item_id`, `created_at`);
create table `estimate_item_attachments` (`id` bigint unsigned not null auto_increment primary key, `estimate_item_id` bigint unsigned not null, `uploaded_by` bigint unsigned null, `name` varchar(255) not null, `path` varchar(255) not null, `mime_type` varchar(255) null, `size_bytes` bigint unsigned not null default '0', `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `estimate_item_attachments` add constraint `estimate_item_attachments_estimate_item_id_foreign` foreign key (`estimate_item_id`) references `estimate_items` (`id`) on delete cascade;
alter table `estimate_item_attachments` add constraint `estimate_item_attachments_uploaded_by_foreign` foreign key (`uploaded_by`) references `users` (`id`) on delete set null;

-- =====================================================================
-- Bookkeeping — tells Laravel this migration is already applied, so a
-- later `php artisan migrate` on this server does not try to re-run it.
-- Run this LAST, only after every statement above has succeeded.
-- =====================================================================

set @next_batch = (select coalesce(max(batch), 0) + 1 from `migrations`);

insert into `migrations` (`migration`, `batch`) values
    ('2026_09_16_090000_create_estimate_item_comments_and_attachments_tables', @next_batch);
