<?php
namespace Tests\api;

use PHPUnit\Framework\TestCase;
use think\App;
use think\facade\Db;
use think\Response;

/**
 * Base class for real-HTTP API tests.
 *
 * Each test dispatches through a FRESH \think\App via `$app->http->run($request)`
 * — this is the exact pipeline FPM uses in production: SessionInit → global
 * middleware → route matching → route middleware → controller → response.
 * No mocking, no stubbing, no transport interception. The only substitution
 * is that the request object is constructed programmatically instead of being
 * auto-populated from $_SERVER/$_POST during an actual FPM invocation.
 *
 * Database: the suite points at `studio_console_test` (separate from the dev
 * database). Mutable tables are truncated and demo users re-seeded with a
 * known password before each test class.
 */
abstract class ApiTestCase extends TestCase
{
    /** Stable demo password applied to every seeded user before each class. */
    protected const DEMO_PASSWORD = 'Admin!Pass#2026';

    /** Cookies carried across successive request() calls — emulates a browser jar. */
    protected array $cookies = [];

    /** CSRF token extracted from the last response's `studio_csrf` cookie. */
    protected ?string $csrfToken = null;

    /** Tables we wipe before each test class. Seeded catalogs (users/roles/
     * permissions/locations/departments/scheduled_jobs) are left intact. */
    protected const TRANSIENT_TABLES = [
        'idempotency_keys',
        'draft_recovery',
        'login_attempts',
        'user_sessions',
        'password_history',
        'fund_commitments',
        'budget_overrides',
        'reimbursement_attachments',
        'duplicate_document_registry',
        'approval_workflow_steps',
        'approval_workflow_instances',
        'approval_comments',
        'settlement_line_items',
        'refund_records',
        'reconciliation_exceptions',
        'reconciliation_runs',
        'ledger_entries',
        'settlement_records',
        'reimbursements',
        'budget_allocations',
        'budget_periods',
        'budget_categories',
        'schedule_adjustment_requests',
        'schedule_entries',
        'attendance_correction_requests',
        'attendance_records',
        'audit_logs',
        'job_runs',
        'export_jobs',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::ensureSeededState();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->cookies = [];
        $this->csrfToken = null;
        // Between tests: release any locked users, forget failed-login
        // counters, and drop accumulated user_sessions so SessionService's
        // max-concurrent enforcement doesn't revoke the new test's session as
        // a side-effect of a previous test's logins.
        Db::execute('TRUNCATE TABLE login_attempts');
        Db::execute('TRUNCATE TABLE user_sessions');
        Db::execute(
            "UPDATE users SET status = 'active', locked_until = NULL, failed_login_count = 0
             WHERE username IN ('admin','frontdesk','coach','finance','operations')"
        );
    }

    /**
     * Truncate mutable tables and re-hash demo users to DEMO_PASSWORD with
     * status='active'. Idempotent and fast.
     */
    protected static function ensureSeededState(): void
    {
        Db::execute('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::TRANSIENT_TABLES as $t) {
            Db::execute("TRUNCATE TABLE `{$t}`");
        }
        Db::execute('SET FOREIGN_KEY_CHECKS=1');

        $hash = password_hash(self::DEMO_PASSWORD, PASSWORD_ARGON2ID);
        Db::execute(
            "UPDATE users SET password_hash = ?, status = 'active', must_change_password = 0,
             failed_login_count = 0, locked_until = NULL, password_changed_at = NOW()
             WHERE username IN ('admin','frontdesk','coach','finance','operations')",
            [$hash]
        );
    }

    /**
     * Dispatch a real HTTP request through the ThinkPHP pipeline and return the
     * Response. Session + CSRF cookies are carried across successive calls.
     *
     * @param string $method HTTP method
     * @param string $path   Full path starting with `/` — may include `?query`
     * @param array|string|null $body  Request body; arrays are JSON-encoded by default
     * @param array  $headers Extra request headers (case-insensitive)
     * @param array  $files  Upload descriptors in $_FILES format
     */
    protected function request(
        string $method,
        string $path,
        array|string|null $body = null,
        array $headers = [],
        array $files = []
    ): Response {
        $app = new App();
        $app->initialize();

        /** @var \app\Request $request */
        $request = $app->make('request', [], true);

        $method = strtoupper($method);
        $parts = parse_url($path);
        $pathOnly = $parts['path'] ?? '/';
        $queryStr = $parts['query'] ?? '';
        $get = [];
        if ($queryStr !== '') parse_str($queryStr, $get);

        $request->setMethod($method);
        $request->setUrl($path);
        $request->setPathinfo(ltrim($pathOnly, '/'));
        $request->withGet($get);
        $request->withCookie($this->cookies);

        // Headers (case-insensitive, lowercased keys — TP's withHeader normalizes)
        $hdrs = [];
        foreach ($headers as $k => $v) {
            $hdrs[strtolower($k)] = (string)$v;
        }

        // Default content type
        if ($body !== null && !isset($hdrs['content-type']) && !$files) {
            $hdrs['content-type'] = 'application/json';
        }
        // CSRF token auto-forward for unsafe methods
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            if ($this->csrfToken && !isset($hdrs['x-csrf-token'])) {
                $hdrs['x-csrf-token'] = $this->csrfToken;
            }
            // Idempotency-Key auto-generated if caller didn't provide one
            if (!isset($hdrs['idempotency-key'])) {
                $hdrs['idempotency-key'] = 'test-' . bin2hex(random_bytes(8));
            }
        }
        $request->withHeader($hdrs);

        // Build $_SERVER-style snapshot. ThinkPHP reads several fields directly.
        $server = [
            'REMOTE_ADDR'     => '127.0.0.1',
            'HTTP_HOST'       => 'localhost',
            'SERVER_NAME'     => 'localhost',
            'REQUEST_METHOD'  => $method,
            'REQUEST_URI'     => $path,
            'PATH_INFO'       => $pathOnly,
            'SCRIPT_NAME'     => '/index.php',
            'HTTP_USER_AGENT' => 'phpunit/9.6',
        ];
        foreach ($hdrs as $k => $v) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $k))] = $v;
        }
        $request->withServer($server);

        // Body
        if (is_array($body)) {
            $ct = $hdrs['content-type'] ?? 'application/json';
            if (str_contains($ct, 'json')) {
                $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $request->withInput($raw);
                // Also stash in POST for PUT/DELETE bodies that controllers read via $request->put()/post()
                $request->withPost($body);
            } elseif (str_contains($ct, 'form-urlencoded')) {
                $request->withInput(http_build_query($body));
                $request->withPost($body);
            } else {
                $request->withPost($body);
            }
        } elseif (is_string($body)) {
            $request->withInput($body);
        }

        if ($files) {
            $request->withFiles($files);
        }

        // Run the full pipeline
        $response = $app->http->run($request);

        // Fire middleware `end()` callbacks so session data is persisted to
        // disk (ThinkPHP flushes via SessionInit::end()). Without this, session
        // writes made by a login handler never reach the filesystem and
        // subsequent requests can't rehydrate the session.
        $app->http->end($response);

        if (getenv('API_TEST_DEBUG')) {
            fwrite(STDERR, sprintf(
                "\n[TEST] %s %s -> %d\n  req-cookie-in=%s\n  app-cookie-out=%s\n  jar-in=%s\n",
                $method, $path, $response->getCode(),
                json_encode(array_keys($this->cookies)),
                json_encode(array_keys($app->cookie->getCookie())),
                json_encode(array_keys($this->cookies))
            ));
        }

        // Capture cookies that the response instructed the browser to set.
        // The Response object carries these via ->cookie() calls; we read them
        // from the Response's cookie slot AND from the Cookie service which
        // middleware wrote into during the pipeline.
        /** @var \think\Cookie $cookieSvc */
        $cookieSvc = $app->cookie;
        foreach ($cookieSvc->getCookie() as $name => $payload) {
            // [$value, $expireAt, $options]
            $this->cookies[$name] = is_array($payload) ? (string)$payload[0] : (string)$payload;
        }
        if (method_exists($response, 'getCookie')) {
            foreach ((array)$response->getCookie() as $name => $payload) {
                if (!isset($this->cookies[$name])) {
                    $this->cookies[$name] = is_array($payload) ? (string)($payload[0] ?? '') : (string)$payload;
                }
            }
        }
        if (isset($this->cookies['studio_csrf']) && $this->cookies['studio_csrf'] !== '') {
            $this->csrfToken = $this->cookies['studio_csrf'];
        }

        // Close the PDO connection owned by this request's App so we don't
        // exhaust MySQL's max_connections pool across a test run. Each call to
        // $app->db->close() (or the connection's own ->close()) releases the
        // underlying PDO handle immediately instead of waiting for GC.
        try {
            /** @var \think\DbManager $db */
            $db = $app->db;
            foreach ($db->getInstance() as $conn) {
                if (method_exists($conn, 'close')) $conn->close();
            }
        } catch (\Throwable $e) { /* best-effort */ }

        return $response;
    }

    // ------------------------------------------------------------------
    // Assertions and helpers
    // ------------------------------------------------------------------

    protected function json(Response $r): array
    {
        $decoded = json_decode((string)$r->getContent(), true);
        return is_array($decoded) ? $decoded : [];
    }

    protected function assertStatus(int $expected, Response $r, string $msg = ''): void
    {
        self::assertSame(
            $expected,
            $r->getCode(),
            ($msg ? $msg . ' — ' : '') . 'body=' . substr((string)$r->getContent(), 0, 400)
        );
    }

    protected function assertEnvelopeCode(int $expected, Response $r, string $msg = ''): void
    {
        $body = $this->json($r);
        self::assertSame(
            $expected,
            $body['code'] ?? null,
            ($msg ? $msg . ' — ' : '') . 'envelope=' . substr((string)$r->getContent(), 0, 400)
        );
    }

    protected function assertDataHas(Response $r, string $key): array
    {
        $body = $this->json($r);
        self::assertArrayHasKey('data', $body, 'no `data` in: ' . substr((string)$r->getContent(), 0, 300));
        $data = $body['data'] ?? [];
        if (is_array($data)) {
            self::assertArrayHasKey($key, $data, "missing data.{$key}: " . substr((string)$r->getContent(), 0, 300));
        } else {
            self::fail("data is not an array: {$r->getContent()}");
        }
        return $body;
    }

    /** Log in as a demo user; subsequent request() calls carry session + CSRF. */
    protected function loginAs(string $username, string $password = self::DEMO_PASSWORD): Response
    {
        $res = $this->request('POST', '/api/v1/auth/login', [
            'username' => $username,
            'password' => $password,
        ]);
        $this->assertStatus(200, $res, "login failed for {$username}");
        $this->assertEnvelopeCode(0, $res);
        return $res;
    }

    /** Clear the in-test cookie jar (simulates logging out on the client side). */
    protected function forgetSession(): void
    {
        $this->cookies = [];
        $this->csrfToken = null;
    }
}
