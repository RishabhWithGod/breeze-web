-- =====================================================================
-- Schema updates: 2026-09-17 through 2026-09-18
-- =====================================================================
--
-- Covers 9 migrations, in dependency order, since the last batch that
-- was taken as a deployment script (everything up to and including
-- 2026_09_16_090000_create_estimate_item_comments_and_attachments_tables,
-- see schema_updates_2026-09-16.sql):
--
--   1. 2026_09_18_115931_migrate_crew_roles_to_three_tier
--      — the crew register grows a third tier: Foreman / Journeyman /
--        Apprentice. Old Supervisor -> new Foreman, old Foreman -> new
--        Journeyman. Rewrites `foremen.role` and `users.role` data, and
--        changes `foremen.role`'s column default from 'foreman' to
--        'journeyman'. NOT PURELY ADDITIVE — see the DATA WARNING below.
--   2. 2026_09_18_150000_add_addendum_fields_to_estimates_table
--      — `kind` ('standalone' default / 'addendum' / 'merged'),
--        `parent_estimate_id`, `addendum_number`, `addendum_name` — the
--        Addendum feature's estimate-level fields.
--   3. 2026_09_18_150100_create_estimate_merge_sources_table
--      — which estimates a `kind = merged` estimate was built from
--        (an original plus whichever addenda were selected).
--   4. 2026_09_18_150200_add_source_estimate_item_id_to_estimate_items_table
--      — for a line cloned into a merged estimate, the line it was
--        cloned from — traceability back to the original PDF/Addendum.
--   5. 2026_09_18_150300_add_addendum_for_estimate_id_to_uploads_table
--      — set when a drawing is uploaded via "Upload Addendum" for a
--        specific estimate, rather than a fresh standalone takeoff.
--   6. 2026_09_18_150400_add_addendum_for_estimate_id_to_ai_results_table
--      — copied from the upload above onto the AiResult it produces.
--   7. 2026_09_18_160000_create_job_journeyman_hours_table
--      — a manager's own saved total hours per person on a job — the
--        Billing screen's "Journeyman Labor Hours" card, no approval
--        wait; overrides a person's raw time-entry total once it exists.
--   8. 2026_09_18_170000_add_source_category_to_invoice_items_table
--      — marks an invoice line as the one mirroring a job's actual
--        Labor/Material/Equipment/Other cost (`ActualCostInvoiceSync`).
--   9. 2026_09_18_173012_create_job_apprentice_assignments_table
--      — who an apprentice reports to on a job (which journeyman), one
--        active assignment per apprentice per job.
--
-- Generated directly from Laravel's own migration classes (via
-- `php artisan migrate --pretend` against a throwaway copy of
-- yesterday's schema) — the SQL below is exactly what
-- `php artisan migrate --force` would run for these 9 files, in the
-- same order. It was then run for real against that same throwaway
-- copy and completed with no errors.
--
-- DATA WARNING (migration #1 only):
--   The two `update` statements for #1 rewrite existing `role` values
--   using a `CASE` on each row's CURRENT value. They are safe to run
--   ONCE. Do NOT run this file a second time from the top without
--   skipping #1's two `update` statements — running the `CASE` again
--   would incorrectly advance an already-migrated 'foreman' row (a
--   former supervisor) on to 'journeyman' a second time. If you must
--   re-run part of this file, resume from statement 2 onward by hand.
--
-- BEFORE RUNNING ON LIVE:
--   1. Take a full database backup — migration #1 rewrites existing
--      `role` values on `foremen` and `users`; #2-#9 are additive
--      (new tables/columns, nothing else dropped or backfilled), but
--      get a restore point first regardless, especially for #1.
--   2. MySQL DDL (CREATE TABLE / ALTER TABLE) auto-commits — it is NOT
--      transactional. If this script is interrupted partway through,
--      some statements will be applied and some won't. Re-running it
--      from the top will fail on `CREATE TABLE` / duplicate-column
--      errors for whatever already landed, AND would double-apply
--      migration #1's data rewrite (see DATA WARNING above) — if
--      interrupted, check `SHOW TABLES` / `DESCRIBE` / a sample of
--      `foremen.role` against the list above, and resume from the next
--      statement by hand rather than re-running the whole file.
--   3. Run this against the LIVE database only. Confirm you are
--      connected to the right one before executing.
--
-- AFTER RUNNING:
--   The very last block below records these 9 migrations in Laravel's
--   own `migrations` table, so a later `php artisan migrate` on this
--   server does not try to run them again (and fail on "table/column
--   already exists"). Do not skip it.
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1. 2026_09_18_115931_migrate_crew_roles_to_three_tier
-- ---------------------------------------------------------------------

update `foremen` set `role` = CASE role WHEN 'supervisor' THEN 'foreman' WHEN 'foreman' THEN 'journeyman' ELSE role END;
alter table `foremen` modify `role` varchar(24) not null default 'journeyman';
update `users` set `role` = CASE role WHEN 'Site Supervisor' THEN 'Foreman' WHEN 'Foreman' THEN 'Journeyman' ELSE role END;

-- ---------------------------------------------------------------------
-- 2. 2026_09_18_150000_add_addendum_fields_to_estimates_table
-- ---------------------------------------------------------------------

alter table `estimates` add `kind` varchar(255) not null default 'standalone' after `status`;
alter table `estimates` add `parent_estimate_id` bigint unsigned null after `kind`;
alter table `estimates` add constraint `estimates_parent_estimate_id_foreign` foreign key (`parent_estimate_id`) references `estimates` (`id`) on delete set null;
alter table `estimates` add `addendum_number` int unsigned null after `parent_estimate_id`;
alter table `estimates` add `addendum_name` varchar(255) null after `addendum_number`;
alter table `estimates` add index `estimates_parent_estimate_id_addendum_number_index`(`parent_estimate_id`, `addendum_number`);

-- ---------------------------------------------------------------------
-- 3. 2026_09_18_150100_create_estimate_merge_sources_table
-- ---------------------------------------------------------------------

create table `estimate_merge_sources` (`id` bigint unsigned not null auto_increment primary key, `merged_estimate_id` bigint unsigned not null, `source_estimate_id` bigint unsigned not null, `created_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `estimate_merge_sources` add constraint `estimate_merge_sources_merged_estimate_id_foreign` foreign key (`merged_estimate_id`) references `estimates` (`id`) on delete cascade;
alter table `estimate_merge_sources` add constraint `estimate_merge_sources_source_estimate_id_foreign` foreign key (`source_estimate_id`) references `estimates` (`id`) on delete cascade;
alter table `estimate_merge_sources` add unique `estimate_merge_sources_unique`(`merged_estimate_id`, `source_estimate_id`);

-- ---------------------------------------------------------------------
-- 4. 2026_09_18_150200_add_source_estimate_item_id_to_estimate_items_table
-- ---------------------------------------------------------------------

alter table `estimate_items` add `source_estimate_item_id` bigint unsigned null after `final_symbol_id`;
alter table `estimate_items` add constraint `estimate_items_source_estimate_item_id_foreign` foreign key (`source_estimate_item_id`) references `estimate_items` (`id`) on delete set null;

-- ---------------------------------------------------------------------
-- 5. 2026_09_18_150300_add_addendum_for_estimate_id_to_uploads_table
-- ---------------------------------------------------------------------

alter table `uploads` add `addendum_for_estimate_id` bigint unsigned null after `project_id`;
alter table `uploads` add constraint `uploads_addendum_for_estimate_id_foreign` foreign key (`addendum_for_estimate_id`) references `estimates` (`id`) on delete set null;

-- ---------------------------------------------------------------------
-- 6. 2026_09_18_150400_add_addendum_for_estimate_id_to_ai_results_table
-- ---------------------------------------------------------------------

alter table `ai_results` add `addendum_for_estimate_id` bigint unsigned null after `project_id`;
alter table `ai_results` add constraint `ai_results_addendum_for_estimate_id_foreign` foreign key (`addendum_for_estimate_id`) references `estimates` (`id`) on delete set null;

-- ---------------------------------------------------------------------
-- 7. 2026_09_18_160000_create_job_journeyman_hours_table
-- ---------------------------------------------------------------------

create table `job_journeyman_hours` (`id` bigint unsigned not null auto_increment primary key, `job_id` bigint unsigned not null, `user_id` bigint unsigned not null, `hours` decimal(8, 2) not null, `updated_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `job_journeyman_hours` add constraint `job_journeyman_hours_job_id_foreign` foreign key (`job_id`) references `work_jobs` (`id`) on delete cascade;
alter table `job_journeyman_hours` add constraint `job_journeyman_hours_user_id_foreign` foreign key (`user_id`) references `users` (`id`) on delete cascade;
alter table `job_journeyman_hours` add constraint `job_journeyman_hours_updated_by_foreign` foreign key (`updated_by`) references `users` (`id`) on delete set null;
alter table `job_journeyman_hours` add unique `job_journeyman_hours_job_id_user_id_unique`(`job_id`, `user_id`);

-- ---------------------------------------------------------------------
-- 8. 2026_09_18_170000_add_source_category_to_invoice_items_table
-- ---------------------------------------------------------------------

alter table `invoice_items` add `source_category` varchar(255) null after `description`;

-- ---------------------------------------------------------------------
-- 9. 2026_09_18_173012_create_job_apprentice_assignments_table
-- ---------------------------------------------------------------------

create table `job_apprentice_assignments` (`id` bigint unsigned not null auto_increment primary key, `job_id` bigint unsigned not null, `journeyman_id` bigint unsigned not null, `apprentice_id` bigint unsigned not null, `assigned_by` bigint unsigned null, `created_at` timestamp null, `updated_at` timestamp null) default character set utf8mb4 collate 'utf8mb4_unicode_ci';
alter table `job_apprentice_assignments` add constraint `job_apprentice_assignments_job_id_foreign` foreign key (`job_id`) references `work_jobs` (`id`) on delete cascade;
alter table `job_apprentice_assignments` add constraint `job_apprentice_assignments_journeyman_id_foreign` foreign key (`journeyman_id`) references `foremen` (`id`) on delete cascade;
alter table `job_apprentice_assignments` add constraint `job_apprentice_assignments_apprentice_id_foreign` foreign key (`apprentice_id`) references `foremen` (`id`) on delete cascade;
alter table `job_apprentice_assignments` add constraint `job_apprentice_assignments_assigned_by_foreign` foreign key (`assigned_by`) references `users` (`id`) on delete set null;
alter table `job_apprentice_assignments` add unique `job_apprentice_assignments_job_id_apprentice_id_unique`(`job_id`, `apprentice_id`);
alter table `job_apprentice_assignments` add index `job_apprentice_assignments_job_id_journeyman_id_index`(`job_id`, `journeyman_id`);

-- =====================================================================
-- Bookkeeping — tells Laravel these 9 are already applied, so a later
-- `php artisan migrate` on this server does not try to re-run them.
-- Run this LAST, only after every statement above has succeeded.
-- =====================================================================

set @next_batch = (select coalesce(max(batch), 0) + 1 from `migrations`);

insert into `migrations` (`migration`, `batch`) values
    ('2026_09_18_115931_migrate_crew_roles_to_three_tier', @next_batch),
    ('2026_09_18_150000_add_addendum_fields_to_estimates_table', @next_batch),
    ('2026_09_18_150100_create_estimate_merge_sources_table', @next_batch),
    ('2026_09_18_150200_add_source_estimate_item_id_to_estimate_items_table', @next_batch),
    ('2026_09_18_150300_add_addendum_for_estimate_id_to_uploads_table', @next_batch),
    ('2026_09_18_150400_add_addendum_for_estimate_id_to_ai_results_table', @next_batch),
    ('2026_09_18_160000_create_job_journeyman_hours_table', @next_batch),
    ('2026_09_18_170000_add_source_category_to_invoice_items_table', @next_batch),
    ('2026_09_18_173012_create_job_apprentice_assignments_table', @next_batch);
