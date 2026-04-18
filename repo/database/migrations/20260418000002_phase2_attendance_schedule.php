<?php
use think\migration\Migrator;

class Phase2AttendanceSchedule extends Migrator
{
    public function change(): void
    {
        $this->execute(<<<'SQL'
-- =========================================================================
-- Phase 2 — Attendance & schedule entries + correction/adjustment requests
-- =========================================================================

CREATE TABLE attendance_records (
  id                       BIGINT AUTO_INCREMENT PRIMARY KEY,
  location_id              BIGINT NOT NULL,
  recorded_by_user_id      BIGINT NOT NULL,
  member_reference         VARCHAR(128) NULL,
  member_name              VARCHAR(128) NULL,
  occurred_at              DATETIME NOT NULL,
  attendance_type          VARCHAR(32) NULL,
  notes                    VARCHAR(512) NULL,
  source_correction_id     BIGINT NULL,
  superseded_by_id         BIGINT NULL,
  status                   ENUM('active','superseded') NOT NULL DEFAULT 'active',
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_att_location_time (location_id, occurred_at),
  KEY idx_att_member (member_reference),
  KEY idx_att_status (status, occurred_at),
  CONSTRAINT fk_att_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_recorder FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_att_superseded_by FOREIGN KEY (superseded_by_id) REFERENCES attendance_records(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE attendance_correction_requests (
  id                       BIGINT AUTO_INCREMENT PRIMARY KEY,
  target_attendance_id     BIGINT NOT NULL,
  requested_by_user_id     BIGINT NOT NULL,
  location_id              BIGINT NOT NULL,
  proposed_payload_json    JSON NOT NULL,
  reason                   VARCHAR(2048) NOT NULL,
  status                   ENUM('draft','submitted','approved','rejected','withdrawn','applied') NOT NULL DEFAULT 'draft',
  reviewer_user_id         BIGINT NULL,
  reviewed_at              DATETIME NULL,
  review_comment           VARCHAR(2048) NULL,
  applied_record_id        BIGINT NULL,
  applied_at               DATETIME NULL,
  created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version                  INT NOT NULL DEFAULT 1,
  KEY idx_acr_status (status, created_at),
  KEY idx_acr_requester (requested_by_user_id, status),
  KEY idx_acr_target (target_attendance_id),
  CONSTRAINT fk_acr_target  FOREIGN KEY (target_attendance_id) REFERENCES attendance_records(id) ON DELETE RESTRICT,
  CONSTRAINT fk_acr_requester FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_acr_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_acr_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_acr_applied FOREIGN KEY (applied_record_id) REFERENCES attendance_records(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedule_entries (
  id                BIGINT AUTO_INCREMENT PRIMARY KEY,
  coach_user_id     BIGINT NOT NULL,
  location_id       BIGINT NOT NULL,
  department_id     BIGINT NULL,
  starts_at         DATETIME NOT NULL,
  ends_at           DATETIME NOT NULL,
  title             VARCHAR(255) NOT NULL,
  notes             VARCHAR(2048) NULL,
  status            ENUM('active','superseded','cancelled') NOT NULL DEFAULT 'active',
  superseded_by_id  BIGINT NULL,
  created_by        BIGINT NOT NULL,
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version           INT NOT NULL DEFAULT 1,
  KEY idx_sched_coach_time (coach_user_id, starts_at),
  KEY idx_sched_location (location_id, starts_at),
  KEY idx_sched_status (status, starts_at),
  CONSTRAINT fk_sched_coach FOREIGN KEY (coach_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sched_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sched_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  CONSTRAINT fk_sched_superseded_by FOREIGN KEY (superseded_by_id) REFERENCES schedule_entries(id) ON DELETE SET NULL,
  CONSTRAINT fk_sched_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schedule_adjustment_requests (
  id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
  target_entry_id       BIGINT NOT NULL,
  requested_by_user_id  BIGINT NOT NULL,
  proposed_changes_json JSON NOT NULL,
  reason                VARCHAR(2048) NOT NULL,
  status                ENUM('draft','submitted','approved','rejected','withdrawn','applied') NOT NULL DEFAULT 'draft',
  reviewer_user_id      BIGINT NULL,
  reviewed_at           DATETIME NULL,
  review_comment        VARCHAR(2048) NULL,
  applied_entry_id      BIGINT NULL,
  applied_at            DATETIME NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  version               INT NOT NULL DEFAULT 1,
  KEY idx_sar_status (status, created_at),
  KEY idx_sar_requester (requested_by_user_id, status),
  CONSTRAINT fk_sar_target FOREIGN KEY (target_entry_id) REFERENCES schedule_entries(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sar_requester FOREIGN KEY (requested_by_user_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sar_reviewer FOREIGN KEY (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sar_applied FOREIGN KEY (applied_entry_id) REFERENCES schedule_entries(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL
);
    }
}
