<?php
namespace app\middleware;

use Closure;
use think\facade\Log;
use think\Request;
use think\Response;

/**
 * Lightweight access logger. Logs only METHOD + sanitized URL + status +
 * latency + ip — NEVER bodies or sensitive query parameters. Query strings
 * carrying field names that match the SENSITIVE_QUERY_KEYS set are masked
 * to `***` so receipt/check/cash refs never appear in plain text in
 * `runtime/log/*.log`.
 */
class RequestLogger
{
    private const SENSITIVE_QUERY_KEYS = [
        'password', 'new_password', 'current_password',
        'receipt_no', 'check_number', 'terminal_batch_ref', 'cash_receipt_ref',
        'token', 'api_key', 'authorization',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $start = microtime(true);
        /** @var Response $response */
        $response = $next($request);
        $ms = (int)((microtime(true) - $start) * 1000);
        Log::info(sprintf(
            '%s %s %d %dms ip=%s',
            $request->method(),
            $this->sanitizeUrl($request->url()),
            $response->getCode(),
            $ms,
            $request->ip()
        ));
        return $response;
    }

    private function sanitizeUrl(string $url): string
    {
        $qpos = strpos($url, '?');
        if ($qpos === false) return $url;
        $base = substr($url, 0, $qpos);
        parse_str(substr($url, $qpos + 1), $params);
        foreach ($params as $k => $_) {
            if (in_array(strtolower((string)$k), self::SENSITIVE_QUERY_KEYS, true)) {
                $params[$k] = '***';
            }
        }
        return $base . ($params ? '?' . http_build_query($params) : '');
    }
}
