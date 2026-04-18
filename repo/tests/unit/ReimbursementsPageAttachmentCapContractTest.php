<?php
namespace Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * audit-5 follow-up: the UI attachment cap enforcement must read from the
 * `attachment_count` field that `ReimbursementController::show()` now
 * publishes. Before this fix the JS read `r.data.attachments.length`, which
 * was always `undefined` because the show response never carried an
 * attachments array — so the cap-reached alert never fired on the client.
 *
 * This is a static-source contract test: we read the page JS off disk and
 * assert the upload handler looks at `attachment_count` and does NOT fall
 * back to the non-existent `attachments` array. Catches the exact regression
 * without a browser harness.
 */
class ReimbursementsPageAttachmentCapContractTest extends TestCase
{
    private function pageSource(): string
    {
        $path = dirname(__DIR__, 2) . '/public/static/js/pages/reimbursements.js';
        self::assertFileExists($path, 'reimbursements page JS missing');
        return (string)file_get_contents($path);
    }

    public function test_upload_handler_reads_attachment_count_field(): void
    {
        $src = $this->pageSource();
        self::assertMatchesRegularExpression(
            '#r\.data\.attachment_count#',
            $src,
            'upload handler must read the server-provided `attachment_count` field'
        );
    }

    public function test_upload_handler_does_not_rely_on_missing_attachments_array(): void
    {
        $src = $this->pageSource();
        self::assertDoesNotMatchRegularExpression(
            '#r\.data\.attachments\.length#',
            $src,
            'upload handler must not use `r.data.attachments.length` — show() does not return an attachments array'
        );
    }
}
