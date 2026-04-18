<?php
namespace app\service\security;

use app\service\auth\PermissionResolver;

/**
 * Applies the spec §11.3 masking policy to outbound API payloads.
 *
 *   - Receipt / check / terminal-batch / cash-receipt refs : last-4 only.
 *   - File names                                            : last-8 of stem only.
 *   - IPs in audit                                          : last octet → `xxx`.
 *
 * `sensitive.unmask` permission bypasses every rule.
 */
final class FieldMasker
{
    public function __construct(
        private FieldCipher $cipher,
        private PermissionResolver $perms,
    ) {}

    public function canUnmask(?int $userId): bool
    {
        if ($userId === null || $userId <= 0) return false;
        return $this->perms->has($userId, 'sensitive.unmask');
    }

    public function reimbursement(array $row, ?int $viewerId): array
    {
        if ($this->canUnmask($viewerId)) return $row;
        if (array_key_exists('receipt_no', $row)) {
            $row['receipt_no_masked'] = $this->cipher->mask((string)($row['receipt_no'] ?? ''));
            $row['receipt_no'] = $row['receipt_no_masked'];
        }
        return $row;
    }

    public function settlement(array $row, ?int $viewerId): array
    {
        if ($this->canUnmask($viewerId)) return $row;
        foreach (['check_number', 'terminal_batch_ref', 'cash_receipt_ref'] as $f) {
            if (array_key_exists($f, $row)) {
                $row[$f] = $this->cipher->mask((string)($row[$f] ?? ''));
            }
        }
        return $row;
    }

    public function attachment(array $row, ?int $viewerId): array
    {
        // `storage_path` is internal infrastructure data — strip
        // unconditionally regardless of the caller's masking permission.
        unset($row['storage_path']);
        if ($this->canUnmask($viewerId)) return $row;
        if (isset($row['file_name'])) {
            $row['file_name'] = $this->maskFilename((string)$row['file_name']);
        }
        return $row;
    }

    public function maskFilename(string $name): string
    {
        $dot = strrpos($name, '.');
        $ext = $dot !== false ? substr($name, $dot) : '';
        $stem = $dot !== false ? substr($name, 0, $dot) : $name;
        $n = mb_strlen($stem);
        if ($n <= 8) return str_repeat('*', $n) . $ext;
        return str_repeat('*', $n - 8) . mb_substr($stem, -8) . $ext;
    }

    public function auditRow(array $row, ?int $viewerId): array
    {
        if ($this->canUnmask($viewerId)) return $row;
        if (isset($row['ip'])) {
            $row['ip'] = preg_replace('/\.\d+$/', '.xxx', (string)$row['ip']);
        }
        return $row;
    }

    /** Masks a list of rows in place using a per-resource callback. */
    public function maskList(array $rows, ?int $viewerId, string $kind): array
    {
        $fn = match ($kind) {
            'reimbursement' => fn ($r) => $this->reimbursement($r, $viewerId),
            'settlement'    => fn ($r) => $this->settlement($r, $viewerId),
            'attachment'    => fn ($r) => $this->attachment($r, $viewerId),
            'audit'         => fn ($r) => $this->auditRow($r, $viewerId),
            default         => fn ($r) => $r,
        };
        return array_map($fn, $rows);
    }

    /**
     * Strip plaintext sensitive values from a payload before it lands in the
     * append-only audit log. The audit row keeps the field names so reviewers
     * can see WHAT changed, but the values are masked. Used by services that
     * call AuditService::record() with model `toArray()` snapshots.
     */
    public function sanitizeForAudit(array $payload): array
    {
        $sensitive = ['receipt_no', 'check_number', 'terminal_batch_ref', 'cash_receipt_ref'];
        foreach ($sensitive as $f) {
            if (array_key_exists($f, $payload) && $payload[$f] !== null && $payload[$f] !== '') {
                $payload[$f] = $this->cipher->mask((string)$payload[$f]);
            }
        }
        if (isset($payload['file_name'])) {
            $payload['file_name'] = $this->maskFilename((string)$payload['file_name']);
        }
        if (isset($payload['storage_path'])) {
            unset($payload['storage_path']);
        }
        return $payload;
    }
}
