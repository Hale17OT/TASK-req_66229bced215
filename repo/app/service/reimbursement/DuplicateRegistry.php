<?php
namespace app\service\reimbursement;

use app\exception\BusinessException;
use app\model\DuplicateDocument;
use app\model\Reimbursement;
use app\service\security\FieldCipher;
use think\facade\Db;

/**
 * Spec §9.7 / §13.3:
 *
 * Default: block duplicate (merchant + receipt_no) org-wide.
 * Safe enhancement: also block (merchant + amount + service_period_start +
 *   service_period_end + receipt_no).
 *
 * "Reserved" identities are released only by Admin voiding the marker (§9.7).
 *
 * Encryption note: `normalized_merchant` and `normalized_receipt_no` are
 * stored as HMAC-SHA-256 blind indexes (FieldCipher::blindIndex), NOT the
 * raw normalized strings — the registry must be useless to a DB-only
 * adversary. Equality lookup still works because HMAC is deterministic.
 */
class DuplicateRegistry
{
    public function __construct(private FieldCipher $cipher)
    {
    }

    /** Trim + lowercase + collapse whitespace. Result fed into HMAC. */
    public static function normalizeMerchant(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }

    /** Trim + lowercase + strip separators. Result fed into HMAC. */
    public static function normalizeReceiptNo(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[\s_\-\.]+/', '', $s);
        return $s;
    }

    public function merchantBlindIndex(string $merchant): string
    {
        return (string)$this->cipher->blindIndex(self::normalizeMerchant($merchant));
    }

    public function receiptNoBlindIndex(string $receiptNo): string
    {
        return (string)$this->cipher->blindIndex(self::normalizeReceiptNo($receiptNo));
    }

    public function assertNoDuplicate(int $excludeReimbursementId, string $merchant, string $receiptNo, string $amount, string $serviceStart, string $serviceEnd): void
    {
        $res = $this->check($excludeReimbursementId, $merchant, $receiptNo, $amount, $serviceStart, $serviceEnd);
        if ($res['ok']) return;
        $code = $res['reason'] === 'receipt_reserved' ? 40030 : 40031;
        throw new BusinessException((string)$res['message'], $code, 422, [
            'receipt_no' => [(string)$res['message']],
        ]);
    }

    /**
     * Non-throwing variant used by the pre-submit UI probe. Returns:
     *   ['ok' => bool, 'reason' => null|'receipt_reserved'|'near_duplicate',
     *    'conflict_reimbursement_id' => int|null, 'message' => string]
     *
     * Keep in lock-step with `assertNoDuplicate()` so the UI probe and the
     * server-side gate agree on what counts as a duplicate.
     */
    public function check(int $excludeReimbursementId, string $merchant, string $receiptNo, string $amount, string $serviceStart, string $serviceEnd): array
    {
        $nm = $this->merchantBlindIndex($merchant);
        $nr = $this->receiptNoBlindIndex($receiptNo);

        // Default: org-wide on (merchant, receipt_no) — uses blind indexes.
        $clash = DuplicateDocument::where('normalized_merchant', $nm)
            ->where('normalized_receipt_no', $nr)
            ->where('state', 'reserved')
            ->where('reimbursement_id', '<>', $excludeReimbursementId)
            ->find();
        if ($clash) {
            return [
                'ok'     => false,
                'reason' => 'receipt_reserved',
                'conflict_reimbursement_id' => (int)$clash->reimbursement_id,
                'message' => 'Already used by reimbursement #' . $clash->reimbursement_id,
            ];
        }

        // Safe-enhancement secondary check uses the receipt_no_hash blind
        // index (added by the security_hardening migration) instead of the
        // encrypted plaintext column.
        $similar = Db::table('reimbursements')
            ->where('id', '<>', $excludeReimbursementId)
            ->where('receipt_no_hash', $nr)
            ->where('amount', $amount)
            ->where('service_period_start', $serviceStart)
            ->where('service_period_end', $serviceEnd)
            ->whereNotIn('status', ['cancelled', 'withdrawn'])
            ->find();
        if ($similar) {
            return [
                'ok'     => false,
                'reason' => 'near_duplicate',
                'conflict_reimbursement_id' => (int)$similar['id'],
                'message' => 'Same merchant/amount/period/receipt found on #' . $similar['id'],
            ];
        }
        return ['ok' => true, 'reason' => null, 'conflict_reimbursement_id' => null, 'message' => 'no duplicate'];
    }

    public function reserve(Reimbursement $r): DuplicateDocument
    {
        // Check first; throws on conflict
        $this->assertNoDuplicate((int)$r->id, (string)$r->merchant, (string)$r->receipt_no,
            (string)$r->amount, (string)$r->service_period_start, (string)$r->service_period_end);
        $existing = DuplicateDocument::where('reimbursement_id', $r->id)->where('state', 'reserved')->find();
        if ($existing) return $existing;
        return DuplicateDocument::create([
            'reimbursement_id'      => (int)$r->id,
            'normalized_merchant'   => $this->merchantBlindIndex((string)$r->merchant),
            'normalized_receipt_no' => $this->receiptNoBlindIndex((string)$r->receipt_no),
            'amount'                => (string)$r->amount,
            'service_period_start'  => (string)$r->service_period_start,
            'service_period_end'    => (string)$r->service_period_end,
            'state'                 => 'reserved',
        ]);
    }

    public function adminVoid(int $reimbursementId, int $adminUserId, string $reason): int
    {
        return Db::table('duplicate_document_registry')
            ->where('reimbursement_id', $reimbursementId)
            ->where('state', 'reserved')
            ->update([
                'state' => 'voided',
                'voided_at' => date('Y-m-d H:i:s'),
                'voided_by' => $adminUserId,
                'void_reason' => substr($reason, 0, 512),
            ]);
    }
}
