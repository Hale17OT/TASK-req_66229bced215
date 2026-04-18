<?php
namespace app\model;

use app\service\security\FieldCipher;
use think\Model;

/**
 * Settlement record. The three method-specific reference fields
 * (check_number, terminal_batch_ref, cash_receipt_ref) are stored encrypted
 * at rest and decrypted on read by the accessors below. Callers that emit
 * a settlement into an API response MUST pass it through
 * `FieldMasker::settlement()` unless the requester holds `sensitive.unmask`.
 */
class SettlementRecord extends Model
{
    protected $table = 'settlement_records';
    protected $autoWriteTimestamp = false;

    public function setCheckNumberAttr(?string $v): ?string         { return $v === null ? null : self::cipher()->encrypt((string)$v); }
    public function getCheckNumberAttr(?string $v): ?string         { return $v === null ? null : self::cipher()->decrypt((string)$v); }
    public function setTerminalBatchRefAttr(?string $v): ?string    { return $v === null ? null : self::cipher()->encrypt((string)$v); }
    public function getTerminalBatchRefAttr(?string $v): ?string    { return $v === null ? null : self::cipher()->decrypt((string)$v); }
    public function setCashReceiptRefAttr(?string $v): ?string      { return $v === null ? null : self::cipher()->encrypt((string)$v); }
    public function getCashReceiptRefAttr(?string $v): ?string      { return $v === null ? null : self::cipher()->decrypt((string)$v); }

    private static ?FieldCipher $cipher = null;
    public static function cipher(): FieldCipher
    {
        if (!self::$cipher) self::$cipher = FieldCipher::fromEnv();
        return self::$cipher;
    }
}
