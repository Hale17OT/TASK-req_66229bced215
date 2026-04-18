<?php
// Application-wide helper functions, autoloaded via composer.json `files`.

if (!function_exists('json_response')) {
    /**
     * Standard JSON envelope per spec §12.1.
     */
    function json_response(int $code = 0, string $message = 'ok', $data = null, array $errors = [], int $httpStatus = 200): \think\Response
    {
        $payload = [
            'code'    => $code,
            'message' => $message,
            'data'    => $data,
            'meta'    => [
                'request_id' => request()->header('x-request-id', request()->header('idempotency-key', '')),
                'ts'         => gmdate('c'),
            ],
        ];
        if (!empty($errors)) {
            $payload['errors'] = $errors;
        }
        return json($payload, $httpStatus);
    }
}

if (!function_exists('ulid')) {
    function ulid(): string
    {
        // Crockford-base32 ULID, ramsey/uuid provides UuidV7 which is sortable
        return strtolower(\Ramsey\Uuid\Uuid::uuid7()->toString());
    }
}

if (!function_exists('canonical_json')) {
    function canonical_json($value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
