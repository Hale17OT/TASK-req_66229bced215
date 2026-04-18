<?php
namespace app\service\idempotency;

use app\exception\IdempotencyConflictException;
use app\model\IdempotencyKey;
use think\facade\Db;

/**
 * Idempotency key cache for unsafe write endpoints (spec §15.3, ASSUMPTIONS §E.20).
 *
 * Flow per request:
 *   1. lookup(key, user, method, path, body)
 *      - hit + completed + same hash → return cached response
 *      - hit + completed + different hash → throw IdempotencyConflictException
 *      - hit + in_flight                 → throw 409 (concurrent retry)
 *      - miss → reserve(key, ...)
 *   2. controller does its work
 *   3. store(key, status, body)
 */
class IdempotencyService
{
    public function lookup(string $key, ?int $userId, string $method, string $path, string $body): array
    {
        $hash = hash('sha256', $body);
        $row = IdempotencyKey::where('user_id', $userId)->where('key', $key)->find();
        if (!$row) {
            return ['state' => 'miss', 'hash' => $hash];
        }
        if ($row->state === 'completed' && $row->request_hash === $hash) {
            return [
                'state'    => 'replay',
                'status'   => (int)$row->response_status,
                'body'     => (string)$row->response_body,
            ];
        }
        if ($row->state === 'completed' && $row->request_hash !== $hash) {
            throw new IdempotencyConflictException($key);
        }
        // in_flight — concurrent retry
        return ['state' => 'in_flight'];
    }

    public function reserve(string $key, ?int $userId, string $method, string $path, string $body): IdempotencyKey
    {
        $ttl = (int)config('app.studio.idempotency.ttl_seconds');
        return IdempotencyKey::create([
            'user_id'        => $userId,
            'key'            => $key,
            'request_method' => strtoupper($method),
            'request_path'   => substr($path, 0, 255),
            'request_hash'   => hash('sha256', $body),
            'state'          => 'in_flight',
            'expires_at'     => date('Y-m-d H:i:s', time() + $ttl),
        ]);
    }

    public function complete(string $key, ?int $userId, int $status, string $body): void
    {
        Db::table('idempotency_keys')
            ->where('user_id', $userId)
            ->where('key', $key)
            ->update([
                'state'           => 'completed',
                'response_status' => $status,
                'response_body'   => $body,
                'completed_at'    => date('Y-m-d H:i:s'),
            ]);
    }

    public function purgeExpired(): int
    {
        return Db::table('idempotency_keys')->where('expires_at', '<', date('Y-m-d H:i:s'))->delete();
    }
}
