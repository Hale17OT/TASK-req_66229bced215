<?php
namespace app\job;

use RuntimeException;

class Registry
{
    public static function resolve(string $key): JobInterface
    {
        $map = [
            'lockout_expiry'             => LockoutExpiryJob::class,
            'password_expiry_marker'     => PasswordExpiryMarkerJob::class,
            'export_file_expiry'         => ExportFileExpiryJob::class,
            'attachment_orphan_cleanup'  => AttachmentOrphanCleanupJob::class,
            'audit_retention_archival'   => AuditRetentionArchivalJob::class,
            'session_cleanup'            => SessionCleanupJob::class,
            'idempotency_cleanup'        => IdempotencyCleanupJob::class,
            'draft_recovery_cleanup'     => DraftRecoveryCleanupJob::class,
        ];
        if (!isset($map[$key])) throw new RuntimeException("unknown job: {$key}");
        return new $map[$key];
    }
}
