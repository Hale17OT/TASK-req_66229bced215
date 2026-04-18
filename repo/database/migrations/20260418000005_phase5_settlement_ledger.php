<?php
use think\migration\Migrator;

class Phase5SettlementLedger extends Migrator
{
    public function change(): void
    {
        $this->execute(<<<'SQL'
-- =========================================================================
-- Phase 5 — Settlements, line items, refunds, ledger, reconciliation
-- =========================================================================

CREATE TABLE settlement_records (
  id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id     BIGINT NOT NULL,
  settlement_no        VARCHAR(32) NOT NULL,
  method               ENUM('cash','check','terminal_batch_entry') NOT NULL,
  gross_amount         DECIMAL(18,2) NOT NULL,
  check_number         VARCHAR(64) NULL,
  terminal_batch_ref   VARCHAR(64) NULL,
  cash_receipt_ref     VARCHAR(64) NULL,
  status               ENUM('unpaid','recorded_not_confirmed','confirmed','partially_refunded','refunded','exception') NOT NULL DEFAULT 'unpaid',
  recorded_by_user_id  BIGINT NOT NULL,
  recorded_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  confirmed_at         DATETIME NULL,
  confirmed_by_user_id BIGINT NULL,
  exception_reason     VARCHAR(2048) NULL,
  notes                VARCHAR(2048) NULL,
  version              INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_settle_no (settlement_no),
  KEY idx_settle_reimb (reimbursement_id),
  KEY idx_settle_status (status, recorded_at),
  CONSTRAINT fk_settle_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE RESTRICT,
  CONSTRAINT fk_settle_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_settle_amount_positive CHECK (gross_amount > 0),
  CONSTRAINT chk_settle_method_refs CHECK (
    (method = 'cash')
      OR (method = 'check' AND check_number IS NOT NULL)
      OR (method = 'terminal_batch_entry' AND terminal_batch_ref IS NOT NULL)
  )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settlement_line_items (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  settlement_id     BIGINT NOT NULL,
  description       VARCHAR(255) NOT NULL,
  amount            DECIMAL(18,2) NOT NULL,
  account_code      VARCHAR(64) NULL,
  KEY idx_sli_settle (settlement_id),
  CONSTRAINT fk_sli_settle FOREIGN KEY (settlement_id) REFERENCES settlement_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refund_records (
  id                   BIGINT AUTO_INCREMENT PRIMARY KEY,
  settlement_id        BIGINT NOT NULL,
  amount               DECIMAL(18,2) NOT NULL,
  reason               VARCHAR(2048) NOT NULL,
  refunded_by_user_id  BIGINT NOT NULL,
  refunded_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status               ENUM('recorded','reversed') NOT NULL DEFAULT 'recorded',
  KEY idx_refund_settle (settlement_id, status),
  CONSTRAINT fk_refund_settle FOREIGN KEY (settlement_id) REFERENCES settlement_records(id) ON DELETE CASCADE,
  CONSTRAINT fk_refund_user FOREIGN KEY (refunded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_refund_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ledger_entries (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  posted_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  ref_entity_type     VARCHAR(64) NOT NULL,
  ref_entity_id       BIGINT NOT NULL,
  account_code        VARCHAR(64) NOT NULL,
  debit               DECIMAL(18,2) NOT NULL DEFAULT 0,
  credit              DECIMAL(18,2) NOT NULL DEFAULT 0,
  memo                VARCHAR(512) NULL,
  posted_by_user_id   BIGINT NOT NULL,
  KEY idx_ledger_ref (ref_entity_type, ref_entity_id),
  KEY idx_ledger_account_time (account_code, posted_at),
  CONSTRAINT fk_ledger_user FOREIGN KEY (posted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_ledger_dr_cr CHECK (debit >= 0 AND credit >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reconciliation_runs (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at        DATETIME NULL,
  started_by_user_id  BIGINT NOT NULL,
  status              ENUM('running','completed','failed') NOT NULL DEFAULT 'running',
  period_start        DATE NOT NULL,
  period_end          DATE NOT NULL,
  summary_json        JSON NULL,
  KEY idx_recon_status (status, started_at),
  CONSTRAINT fk_recon_user FOREIGN KEY (started_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reconciliation_exceptions (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  run_id              BIGINT NOT NULL,
  settlement_id       BIGINT NULL,
  reimbursement_id    BIGINT NULL,
  exception_type      VARCHAR(64) NOT NULL,
  detail_json         JSON NULL,
  status              ENUM('open','resolved') NOT NULL DEFAULT 'open',
  resolved_at         DATETIME NULL,
  resolved_by         BIGINT NULL,
  resolution_notes    VARCHAR(2048) NULL,
  KEY idx_excp_run (run_id, status),
  CONSTRAINT fk_excp_run FOREIGN KEY (run_id) REFERENCES reconciliation_runs(id) ON DELETE CASCADE,
  CONSTRAINT fk_excp_settle FOREIGN KEY (settlement_id) REFERENCES settlement_records(id) ON DELETE SET NULL,
  CONSTRAINT fk_excp_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
);
    }
}
