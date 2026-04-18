<?php
namespace Tests\unit;

use app\service\reimbursement\DuplicateRegistry;
use PHPUnit\Framework\TestCase;

class DuplicateRegistryNormalizationTest extends TestCase
{
    public function testMerchantNormalization(): void
    {
        $this->assertSame('acme inc', DuplicateRegistry::normalizeMerchant('  ACME   Inc  '));
        $this->assertSame('the corner cafe', DuplicateRegistry::normalizeMerchant("The   Corner Cafe"));
    }

    public function testReceiptNoNormalization(): void
    {
        $this->assertSame('inv2026123', DuplicateRegistry::normalizeReceiptNo('INV-2026 123'));
        $this->assertSame('inv2026123', DuplicateRegistry::normalizeReceiptNo('inv_2026.123'));
        $this->assertSame('a1b2c3',     DuplicateRegistry::normalizeReceiptNo('a1b2c3'));
    }
}
