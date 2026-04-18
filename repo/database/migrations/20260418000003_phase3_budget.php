<?php
use think\migration\Migrator;

class Phase3Budget extends Migrator
{
    public function change(): void
    {
        $this->execute(<<<'SQL'
-- =========================================================================
-- Phase 3 — Budget categories, periods, allocations, commitments, overrides
-- =========================================================================

CREATE TABLE budget_categories (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(128) NOT NULL,
  code          VARCHAR(64) NULL,
  description   VARCHAR(512) NULL,
  status        ENUM('active','archived') NOT NULL DEFAULT 'active',
  created_by    BIGINT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_budget_cat_name (name),
  UNIQUE KEY uq_budget_cat_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_periods (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  label         VARCHAR(64) NOT NULL,
  period_start  DATE NOT NULL,
  period_end    DATE NOT NULL,
  status        ENUM('open','closed') NOT NULL DEFAULT 'open',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_budget_period_range (period_start, period_end),
  KEY idx_period_label (label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_allocations (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  category_id     BIGINT NOT NULL,
  period_id       BIGINT NOT NULL,
  scope_type      ENUM('org','location','department') NOT NULL,
  location_id     BIGINT NULL,
  department_id   BIGINT NULL,
  cap_amount      DECIMAL(18,2) NOT NULL,
  notes           VARCHAR(512) NULL,
  status          ENUM('active','superseded','archived') NOT NULL DEFAULT 'active',
  superseded_by_id BIGINT NULL,
  created_by      BIGINT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version         INT NOT NULL DEFAULT 1,
  KEY idx_alloc_lookup (category_id, period_id, scope_type, location_id, department_id, status),
  KEY idx_alloc_status (status),
  CONSTRAINT fk_alloc_category FOREIGN KEY (category_id) REFERENCES budget_categories(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alloc_period FOREIGN KEY (period_id) REFERENCES budget_periods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alloc_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alloc_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE RESTRICT,
  CONSTRAINT fk_alloc_superseded_by FOREIGN KEY (superseded_by_id) REFERENCES budget_allocations(id) ON DELETE SET NULL,
  CONSTRAINT chk_alloc_cap_positive CHECK (cap_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fund_commitments (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  allocation_id       BIGINT NOT NULL,
  reimbursement_id    BIGINT NOT NULL,
  amount              DECIMAL(18,2) NOT NULL,
  status              ENUM('pending','active','released','consumed','cancelled') NOT NULL DEFAULT 'pending',
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  status_changed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  notes               VARCHAR(512) NULL,
  KEY idx_commit_alloc_status (allocation_id, status),
  KEY idx_commit_reimbursement (reimbursement_id),
  CONSTRAINT fk_commit_alloc FOREIGN KEY (allocation_id) REFERENCES budget_allocations(id) ON DELETE RESTRICT,
  CONSTRAINT chk_commit_amount_positive CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budget_overrides (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  reimbursement_id    BIGINT NOT NULL,
  allocation_id       BIGINT NOT NULL,
  requested_amount    DECIMAL(18,2) NOT NULL,
  available_before    DECIMAL(18,2) NOT NULL,
  available_after     DECIMAL(18,2) NOT NULL,
  reason              VARCHAR(2048) NOT NULL,
  approved_by_user_id BIGINT NOT NULL,
  approved_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_override_reimb (reimbursement_id),
  KEY idx_override_alloc (allocation_id),
  CONSTRAINT fk_override_alloc FOREIGN KEY (allocation_id) REFERENCES budget_allocations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_override_user FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
);
    }
}
