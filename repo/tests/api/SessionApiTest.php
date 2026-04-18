<?php
namespace Tests\api;

use app\model\UserSession;

/**
 * Covers /api/v1/sessions  and /api/v1/sessions/{id}
 */
class SessionApiTest extends ApiTestCase
{
    public function test_get_sessions_requires_auth(): void
    {
        $res = $this->request('GET', '/api/v1/sessions');
        $this->assertStatus(401, $res);
    }

    public function test_get_sessions_returns_current_session_list(): void
    {
        $this->loginAs('admin');
        $res = $this->request('GET', '/api/v1/sessions');
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
        $body = $this->json($res);
        self::assertIsArray($body['data']);
        self::assertGreaterThanOrEqual(1, count($body['data']));
        self::assertArrayNotHasKey('session_id', $body['data'][0], 'raw session_id must never be exposed');
        self::assertArrayHasKey('ip', $body['data'][0]);
    }

    public function test_delete_session_for_foreign_user_is_rejected(): void
    {
        // Log in as admin (user_id=1), then fabricate a session row for a
        // different real user (user_id=2 = frontdesk). Attempting to revoke it
        // must be rejected because admin's own session-mgmt is self-scoped.
        $this->loginAs('admin');
        $row = UserSession::create([
            'session_id'         => hash('sha256', 'fake-for-test-' . uniqid()),
            'user_id'            => 2,
            'ip'                 => '127.0.0.2',
            'user_agent'         => 'other',
            'device_fingerprint' => 'aa',
            'expires_at'         => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $res = $this->request('DELETE', '/api/v1/sessions/' . $row->id);
        $this->assertStatus(403, $res);
    }

    public function test_delete_session_revokes_own_session(): void
    {
        $this->loginAs('admin');
        $list = $this->json($this->request('GET', '/api/v1/sessions'));
        $mineId = (int)$list['data'][0]['id'];
        $res = $this->request('DELETE', '/api/v1/sessions/' . $mineId);
        $this->assertStatus(200, $res);
        $this->assertEnvelopeCode(0, $res);
    }
}
