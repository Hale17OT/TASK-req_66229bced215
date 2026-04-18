<?php
namespace Tests\unit;

use PHPUnit\Framework\TestCase;

class IdempotencyKeyShapeTest extends TestCase
{
    public function testKeyValidationPattern(): void
    {
        // \A ... \z anchors so PCRE rejects embedded newlines/tails (default
        // $ matches end-of-line, not end-of-string).
        $pattern = '/\A[A-Za-z0-9_\-:.]+\z/';
        $valid = ['abc-123', 'A.b_C-1:2.3', '0123456789abcdef'];
        $invalid = ['has space', "tab\there", 'too$money', "newline\n"];
        foreach ($valid as $k) {
            $this->assertMatchesRegularExpression($pattern, $k);
        }
        foreach ($invalid as $k) {
            $this->assertDoesNotMatchRegularExpression($pattern, $k);
        }
    }
}
