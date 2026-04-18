<?php
use think\migration\Migrator;

class SecurityHardening extends Migrator
{
    public function change(): void
    {
        $this->execute(<<<'SQL'
-- ============================================================================
-- Security hardening migration
--
-- Widens columns whose plaintext we now wrap in `enc:v1:<base64>` envelopes,
-- adds blind-index columns for the equality lookups that used to scan
-- plaintext, and migrates the duplicate-document registry to HMAC-only
-- normalized values.
--
-- Forward migration is non-destructive: existing plaintext rows continue to
-- read fine via the FieldCipher fail-open path. New writes are encrypted.
-- ============================================================================

ALTER TABLE reimbursements
  MODIFY COLUMN receipt_no VARCHAR(255) NOT NULL,
  ADD COLUMN  receipt_no_hash CHAR(64) NULL AFTER receipt_no,
  ADD INDEX   idx_reimb_receipt_hash (receipt_no_hash);

ALTER TABLE settlement_records
  MODIFY COLUMN check_number       VARCHAR(255) NULL,
  MODIFY COLUMN terminal_batch_ref VARCHAR(255) NULL,
  MODIFY COLUMN cash_receipt_ref   VARCHAR(255) NULL;

-- Drop the legacy unique on (merchant, receipt_no) — we replace it with a
-- unique on the HMAC-only blind-indexed pair so the plaintext receipt is
-- never used as an equality probe again.
ALTER TABLE duplicate_document_registry
  DROP INDEX uq_dup_active,
  MODIFY COLUMN normalized_merchant   CHAR(64) NOT NULL,
  MODIFY COLUMN normalized_receipt_no CHAR(64) NOT NULL,
  ADD UNIQUE KEY uq_dup_active (normalized_merchant, normalized_receipt_no, state);
SQL
);
    }
}
