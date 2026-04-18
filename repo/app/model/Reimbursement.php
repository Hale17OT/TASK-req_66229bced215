<?php
namespace app\model;

use app\service\security\FieldCipher;
use think\Model;

/**
 * Reimbursement model.
 *
 * Encryption: `receipt_no` is stored as `enc:v1:<base64>` ciphertext at rest.
 * The accessor decrypts on read so application code keeps using the plaintext
 * value. A separate `receipt_no_hash` HMAC column carries the equality
 * lookup that used to be done against plaintext.
 *
 * Sensitive serialization: callers that emit the model into an API response
 * MUST pass it through `app\service\security\FieldMasker::reimbursement()`
 * unless the requester holds `sensitive.unmask`.
 */
class Reimbursement extends Model
{
    protected $table = 'reimbursements';
    protected $autoWriteTimestamp = false;

    /** Mutator: encrypt receipt_no on write + maintain blind-index column. */
    public function setReceiptNoAttr(?string $value): ?string
    {
        if ($value === null) return null;
        // Side-effect: keep the HMAC lookup column in sync with the plaintext.
        $this->setAttr('receipt_no_hash', self::cipher()->blindIndex((string)$value));
        return self::cipher()->encrypt((string)$value);
    }

    /** Accessor: decrypt receipt_no on read. */
    public function getReceiptNoAttr(?string $value): ?string
    {
        if ($value === null) return null;
        return self::cipher()->decrypt((string)$value);
    }

    private static ?FieldCipher $cipher = null;
    public static function cipher(): FieldCipher
    {
        if (!self::$cipher) self::$cipher = FieldCipher::fromEnv();
        return self::$cipher;
    }
}
