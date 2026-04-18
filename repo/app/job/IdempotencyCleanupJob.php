<?php
namespace app\job;

class IdempotencyCleanupJob implements JobInterface
{
    public function run(): array
    {
        $svc = new \app\service\idempotency\IdempotencyService();
        $n = $svc->purgeExpired();
        return ['deleted' => $n];
    }
}
