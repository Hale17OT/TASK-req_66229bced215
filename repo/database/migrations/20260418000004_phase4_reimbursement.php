<?php
use think\migration\Migrator;

class Phase4Reimbursement extends Migrator
{
    public function change(): void
    {
        $this->execute(<<<'SQL'
-- =========================================================================
-- Phase 4 — Reimbursements + attachments + duplicate registry + workflow
-- =========================================================================

CREATE TABLE reimbursements (
  id                       BIGINT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_no         VARCHAR(32) NOT NULL,
  submitter_user_id        BIGINT NOT NULL,
  scope_location_id        BIGINT NULL,
  scope_department_id      BIGINT NULL,
  category_id              BIGINT NOT NULL,
  amount                   DECIMAL(18,2) NOT NULL,
  merchant                 VARCHAR(255) NOT NULL,
  service_period_start     DATE NOT NULL,
  service_period_end       DATE NOT NULL,
  receipt_no               VARCHAR(100) NOT NULL,
  description              VARCHAR(2048) NULL,
  status                   ENUM(
    'draft','submitted','pending_override_review','under_review','needs_revision',
    'resubmitted','approved','settlement_pending','settled','partially_refunded',
    'refunded','rejected','withdrawn','cancelled'
  ) NOT NULL DEFAULT 'draft',
  previous_version_id      BIGINT NULL,
  submitted_at             DATETIME NULL,
  decided_at               DATETIME NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version                  INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_reimb_no (reimbursement_no),
  KEY idx_reimb_status_submitted (status, submitted_at),
  KEY idx_reimb_submitter (submitter_user_id, status),
  KEY idx_reimb_category (category_id),
  KEY idx_reimb_merchant (merchant),
  KEY idx_reimb_receipt (receipt_no),
  KEY idx_reimb_service_period (service_period_start, service_period_end),
  CONSTRAINT fk_reimb_submitter FOREIGN KEY (submitter_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reimb_location FOREIGN KEY (scope_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reimb_department FOREIGN KEY (scope_department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reimb_category FOREIGN KEY (category_id) REFERENCES budget_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_reimb_prev_version FOREIGN KEY (previous_version_id) REFERENCES reimbursements(id) ON DELETE SET NULL,
  CONSTRAINT chk_reimb_amount_positive CHECK (amount > 0),
  CONSTRAINT chk_reimb_period CHECK (service_period_end >= service_period_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE fund_commitments
  ADD CONSTRAINT fk_commit_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE;
ALTER TABLE budget_overrides
  ADD CONSTRAINT fk_override_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE;

CREATE TABLE reimbursement_attachments (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id    BIGINT NOT NULL,
  file_name           VARCHAR(255) NOT NULL,
  mime_type           VARCHAR(64) NOT NULL,
  size_bytes          BIGINT NOT NULL,
  storage_path        VARCHAR(512) NOT NULL,
  sha256              CHAR(64) NOT NULL,
  uploaded_by_user_id BIGINT NOT NULL,
  uploaded_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  deleted_at          DATETIME NULL,
  KEY idx_att_reimb (reimbursement_id, deleted_at),
  CONSTRAINT fk_att_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT chk_att_size_positive CHECK (size_bytes > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE duplicate_document_registry (
  id                       BIGINT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id         BIGINT NOT NULL,
  normalized_merchant      VARCHAR(255) NOT NULL,
  normalized_receipt_no    VARCHAR(120) NOT NULL,
  amount                   DECIMAL(18,2) NOT NULL,
  service_period_start     DATE NOT NULL,
  service_period_end       DATE NOT NULL,
  state                    ENUM('reserved','voided') NOT NULL DEFAULT 'reserved',
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  voided_at                DATETIME NULL,
  voided_by                BIGINT NULL,
  void_reason              VARCHAR(512) NULL,
  UNIQUE KEY uq_dup_active (normalized_merchant, normalized_receipt_no, state),
  KEY idx_dup_reimb (reimbursement_id),
  CONSTRAINT fk_dup_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_workflow_instances (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id    BIGINT NOT NULL,
  current_step        VARCHAR(64) NOT NULL,
  state               VARCHAR(32) NOT NULL DEFAULT 'open',
  started_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ended_at            DATETIME NULL,
  KEY idx_awi_reimb (reimbursement_id),
  CONSTRAINT fk_awi_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_workflow_steps (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  instance_id     BIGINT NOT NULL,
  step_name       VARCHAR(64) NOT NULL,
  actor_user_id   BIGINT NOT NULL,
  action          ENUM('submit','resubmit','review','approve','reject','needs_revision','override','withdraw') NOT NULL,
  comment         VARCHAR(2048) NULL,
  before_status   VARCHAR(64) NOT NULL,
  after_status    VARCHAR(64) NOT NULL,
  recorded_at     DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY idx_aws_instance (instance_id, recorded_at),
  KEY idx_aws_actor (actor_user_id, recorded_at),
  CONSTRAINT fk_aws_instance FOREIGN KEY (instance_id) REFERENCES approval_workflow_instances(id) ON DELETE CASCADE,
  CONSTRAINT fk_aws_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE approval_comments (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id    BIGINT NOT NULL,
  user_id             BIGINT NOT NULL,
  body                VARCHAR(2048) NOT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_apc_reimb (reimbursement_id, created_at),
  CONSTRAINT fk_apc_reimb FOREIGN KEY (reimbursement_id) REFERENCES reimbursements(id) ON DELETE CASCADE,
  CONSTRAINT fk_apc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
);
    }
}
