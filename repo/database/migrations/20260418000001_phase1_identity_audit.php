<?php
use think\migration\Migrator;

class Phase1IdentityAudit extends Migrator
{
    public function change(): void
    {
        $this->execute(<<<'SQL'
-- =========================================================================
-- Phase 1 — Identity, RBAC, scope, sessions, password history, audit core
-- =========================================================================

CREATE TABLE locations (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(32) NOT NULL,
  name            VARCHAR(128) NOT NULL,
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_locations_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  code            VARCHAR(32) NOT NULL,
  name            VARCHAR(128) NOT NULL,
  status          ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_departments_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                      BIGINT AUTO_INCREMENT PRIMARY KEY,
  username                VARCHAR(64) NOT NULL,
  password_hash           VARCHAR(255) NOT NULL,
  display_name            VARCHAR(128) NOT NULL,
  status                  ENUM('active','locked','disabled','password_expired') NOT NULL DEFAULT 'password_expired',
  failed_login_count      INT NOT NULL DEFAULT 0,
  locked_until            DATETIME NULL,
  last_login_at           DATETIME NULL,
  last_login_ip           VARCHAR(45) NULL,
  password_changed_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  must_change_password    TINYINT(1) NOT NULL DEFAULT 1,
  default_location_id     BIGINT NULL,
  default_department_id   BIGINT NULL,
  created_by              BIGINT NULL,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version                 INT NOT NULL DEFAULT 1,
  UNIQUE KEY uq_users_username (username),
  KEY idx_users_status (status),
  CONSTRAINT fk_users_default_location FOREIGN KEY (default_location_id) REFERENCES locations(id) ON DELETE SET NULL,
  CONSTRAINT fk_users_default_department FOREIGN KEY (default_department_id) REFERENCES departments(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE roles (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  `key`         VARCHAR(64) NOT NULL,
  name          VARCHAR(128) NOT NULL,
  description   VARCHAR(512) NULL,
  is_system     TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  `key`         VARCHAR(128) NOT NULL,
  description   VARCHAR(255) NULL,
  category      VARCHAR(64) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_permissions_key (`key`),
  KEY idx_permissions_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_roles (
  user_id       BIGINT NOT NULL,
  role_id       BIGINT NOT NULL,
  assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by   BIGINT NULL,
  PRIMARY KEY (user_id, role_id),
  KEY idx_user_roles_role (role_id),
  CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id        BIGINT NOT NULL,
  permission_id  BIGINT NOT NULL,
  granted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  granted_by     BIGINT NULL,
  PRIMARY KEY (role_id, permission_id),
  KEY idx_role_perms_perm (permission_id),
  CONSTRAINT fk_role_perms_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_perms_perm FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_scope_assignments (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT NOT NULL,
  location_id     BIGINT NULL,
  department_id   BIGINT NULL,
  is_global       TINYINT(1) NOT NULL DEFAULT 0,
  assigned_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_by     BIGINT NULL,
  KEY idx_scope_user (user_id),
  CONSTRAINT fk_scope_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_scope_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE CASCADE,
  CONSTRAINT fk_scope_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_sessions (
  id                  BIGINT AUTO_INCREMENT PRIMARY KEY,
  session_id          VARCHAR(128) NOT NULL,
  user_id             BIGINT NOT NULL,
  ip                  VARCHAR(45) NULL,
  user_agent          VARCHAR(255) NULL,
  device_fingerprint  VARCHAR(128) NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_activity_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at          DATETIME NOT NULL,
  revoked_at          DATETIME NULL,
  revoked_by          BIGINT NULL,
  revoke_reason       VARCHAR(128) NULL,
  UNIQUE KEY uq_sessions_session_id (session_id),
  KEY idx_sessions_user_active (user_id, revoked_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE password_history (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pwhist_user (user_id, created_at DESC),
  CONSTRAINT fk_pwhist_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  username        VARCHAR(64) NOT NULL,
  ip              VARCHAR(45) NULL,
  succeeded       TINYINT(1) NOT NULL DEFAULT 0,
  reason          VARCHAR(64) NULL,
  attempted_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_login_attempts_user (username, attempted_at),
  KEY idx_login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  occurred_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  actor_user_id     BIGINT NULL,
  actor_username    VARCHAR(64) NULL,
  action            VARCHAR(128) NOT NULL,
  target_entity     VARCHAR(64) NOT NULL,
  target_entity_id  VARCHAR(64) NOT NULL,
  outcome           ENUM('success','failure') NOT NULL DEFAULT 'success',
  ip                VARCHAR(45) NULL,
  request_id        VARCHAR(64) NULL,
  correlation_id    VARCHAR(64) NULL,
  before_json       JSON NULL,
  after_json        JSON NULL,
  metadata_json     JSON NULL,
  prev_hash         CHAR(64) NULL,
  row_hash          CHAR(64) NOT NULL,
  KEY idx_audit_actor_time (actor_user_id, occurred_at),
  KEY idx_audit_target (target_entity, target_entity_id),
  KEY idx_audit_action_time (action, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hard-disable UPDATE/DELETE on audit rows (spec §14.4 / §11)
DROP TRIGGER IF EXISTS trg_audit_no_update;
DROP TRIGGER IF EXISTS trg_audit_no_delete;
SQL
);

        // Triggers must each be a single statement separated from the multi-statement block above
        $this->execute(<<<'SQL'
CREATE TRIGGER trg_audit_no_update BEFORE UPDATE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
SQL
);
        $this->execute(<<<'SQL'
CREATE TRIGGER trg_audit_no_delete BEFORE DELETE ON audit_logs
FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only'
SQL
);

        $this->execute(<<<'SQL'
CREATE TABLE idempotency_keys (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT NULL,
  `key`           VARCHAR(128) NOT NULL,
  request_method  VARCHAR(8) NOT NULL,
  request_path    VARCHAR(255) NOT NULL,
  request_hash    CHAR(64) NOT NULL,
  response_status INT NULL,
  response_body   LONGTEXT NULL,
  state           ENUM('in_flight','completed') NOT NULL DEFAULT 'in_flight',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  expires_at      DATETIME NOT NULL,
  UNIQUE KEY uq_idem_user_key (user_id, `key`),
  KEY idx_idem_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE draft_recovery (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT NOT NULL,
  draft_token   VARCHAR(64) NOT NULL,
  payload_json  JSON NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  UNIQUE KEY uq_draft_user_token (user_id, draft_token),
  KEY idx_draft_expires (expires_at),
  CONSTRAINT fk_draft_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
);
    }
}
