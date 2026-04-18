<?php
namespace Tests\unit;

use app\service\security\FieldCipher;
use PHPUnit\Framework\TestCase;

class FieldCipherTest extends TestCase
{
    private function cipher(string $key = 'test-key'): FieldCipher
    {
        return new FieldCipher($key);
    }

    public function test_round_trip_recovers_plaintext(): void
    {
        $c = $this->cipher();
        $ct = $c->encrypt('INV-PLAINTEXT-12345');
        self::assertStringStartsWith(FieldCipher::PREFIX, $ct);
        self::assertSame('INV-PLAINTEXT-12345', $c->decrypt($ct));
    }

    public function test_encrypt_is_non_deterministic(): void
    {
        $c = $this->cipher();
        $a = $c->encrypt('CHK-9876');
        $b = $c->encrypt('CHK-9876');
        self::assertNotSame($a, $b, 'random nonce → ciphertexts must differ');
        self::assertSame($c->decrypt($a), $c->decrypt($b));
    }

    public function test_blind_index_is_deterministic(): void
    {
        $c = $this->cipher();
        self::assertSame($c->blindIndex('foo'), $c->blindIndex('foo'));
        self::assertNotSame($c->blindIndex('foo'), $c->blindIndex('bar'));
    }

    public function test_decrypt_passes_through_legacy_plaintext(): void
    {
        $c = $this->cipher();
        // Legacy rows written before encryption was enabled should not break.
        self::assertSame('legacy-value', $c->decrypt('legacy-value'));
    }

    public function test_decrypt_with_wrong_key_returns_null(): void
    {
        $a = $this->cipher('keyA');
        $b = $this->cipher('keyB');
        $ct = $a->encrypt('confidential');
        self::assertNull($b->decrypt($ct));
    }

    public function test_mask_shows_last_four_only(): void
    {
        $c = $this->cipher();
        self::assertSame('***************2345', $c->mask('INV-PLAINTEXT-12345'));
        self::assertSame('****', $c->mask('abcd'));
        self::assertSame('***',  $c->mask('abc'));
        self::assertSame('',     $c->mask(''));
    }

    public function test_empty_and_null_inputs_passthrough(): void
    {
        $c = $this->cipher();
        self::assertNull($c->encrypt(null));
        self::assertSame('', $c->encrypt(''));
        self::assertNull($c->decrypt(null));
        self::assertNull($c->blindIndex(null));
    }
}
