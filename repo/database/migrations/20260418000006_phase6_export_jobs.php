<?php
use think\migration\Migrator;

class Phase6ExportJobs extends Migrator
{
    public function change(): void
    {
        $this->execute(<<<'SQL'
-- =========================================================================
-- Phase 6 — Export jobs, scheduled jobs registry, job runs
-- =========================================================================

CREATE TABLE export_jobs (
  id                       BIGINT AUTO_INCREMENT PRIMARY KEY,
  requested_by_user_id     BIGINT NOT NULL,
  kind                     VARCHAR(32) NOT NULL,    -- 'audit','reimbursements','settlements','budget_utilization'
  filters_json             JSON NOT NULL,
  status                   ENUM('queued','running','completed','failed','expired') NOT NULL DEFAULT 'queued',
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  started_at               DATETIME NULL,
  completed_at             DATETIME NULL,
  expires_at               DATETIME NOT NULL,
  file_path                VARCHAR(512) NULL,
  row_count                BIGINT NULL,
  sha256                   CHAR(64) NULL,
  error                    VARCHAR(2048) NULL,
  KEY idx_export_user_time (requested_by_user_id, created_at),
  KEY idx_export_status (status, created_at),
  CONSTRAINT fk_export_user FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE scheduled_jobs (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_key             VARCHAR(64) NOT NULL,
  enabled             TINYINT(1) NOT NULL DEFAULT 1,
  interval_seconds    INT NOT NULL DEFAULT 300,
  last_run_at         DATETIME NULL,
  last_status         ENUM('ok','failed','skipped') NULL,
  last_error          VARCHAR(2048) NULL,
  next_run_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  lock_owner          VARCHAR(64) NULL,
  lock_acquired_at    DATETIME NULL,
  UNIQUE KEY uq_sched_job_key (job_key),
  KEY idx_sched_due (enabled, next_run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_runs (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_key         VARCHAR(64) NOT NULL,
  started_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at     DATETIME NULL,
  status          ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  error           VARCHAR(2048) NULL,
  metrics_json    JSON NULL,
  KEY idx_jobruns_job_time (job_key, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO scheduled_jobs (job_key, enabled, interval_seconds) VALUES
  ('lockout_expiry',           1,  60),
  ('password_expiry_marker',   1, 3600),
  ('export_file_expiry',       1, 3600),
  ('attachment_orphan_cleanup',1, 86400),
  ('audit_retention_archival', 1, 86400),
  ('session_cleanup',          1, 300),
  ('idempotency_cleanup',      1, 600),
  ('draft_recovery_cleanup',   1, 86400);
SQL
);
    }
}
