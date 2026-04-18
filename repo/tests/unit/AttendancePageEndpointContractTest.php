<?php
namespace Tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * audit-4 #1 (Blocker) — regression guard for the Front Desk attendance page
 * hitting an admin-only location endpoint.
 *
 * Before the fix, `public/static/js/pages/attendance.js` called
 * `/api/v1/admin/locations`, which requires `auth.manage_users` and 403s for
 * Front Desk, breaking location-dropdown load on the core attendance flow.
 *
 * This is a static-source contract test: we read the page JS off disk and
 * assert that the URL it queries is the scope-aware reference endpoint and
 * not the admin enumeration endpoint. Cheap to run, catches the exact
 * regression without needing a browser harness.
 */
class AttendancePageEndpointContractTest extends TestCase
{
    private function pageSource(): string
    {
        $path = dirname(__DIR__, 2) . '/public/static/js/pages/attendance.js';
        self::assertFileExists($path, 'attendance page JS missing');
        return (string)file_get_contents($path);
    }

    public function test_page_calls_scope_aware_locations_endpoint(): void
    {
        $src = $this->pageSource();
        self::assertMatchesRegularExpression(
            '#StudioApi\.get\(\s*[\'"]/api/v1/locations[\'"]#',
            $src,
            'attendance page must query the scope-aware /api/v1/locations endpoint'
        );
    }

    public function test_page_does_not_call_admin_locations_endpoint(): void
    {
        $src = $this->pageSource();
        self::assertDoesNotMatchRegularExpression(
            '#/api/v1/admin/locations#',
            $src,
            'attendance page must not hit the admin-only location enumeration endpoint'
        );
    }
}
