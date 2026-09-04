<?php

/**
 * Stub of the conv2pdf API for tests/functional.php, served by PHP's built-in server:
 *
 *   php -S 127.0.0.1:18765 tests/stub/router.php
 *
 * It converts nothing. Every response is fixed, and the `echo` tool sends back what the
 * server RECEIVED (headers, multipart parts, fields), so the test can assert the request
 * as the API would see it. Error responses copy the shapes documented in the API's error
 * table: https://conv2pdf.com/api/documentation/
 */

declare(strict_types=1);

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function json(int $status, array $body, array $headers = []): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    foreach ($headers as $h) {
        header($h);
    }
    echo json_encode($body);
}

/** Flattens $_FILES into [field, name, size] entries, whatever the field naming. */
function receivedFiles(): array
{
    $out = [];
    foreach ($_FILES as $field => $f) {
        if (is_array($f['name'])) {
            foreach ($f['name'] as $i => $name) {
                $out[] = ['field' => "{$field}[{$i}]", 'name' => $name, 'size' => $f['size'][$i]];
            }
        } else {
            $out[] = ['field' => $field, 'name' => $f['name'], 'size' => $f['size']];
        }
    }
    return $out;
}

function requestHeader(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$key])) {
        return (string) $_SERVER[$key];
    }
    foreach (function_exists('getallheaders') ? getallheaders() : [] as $k => $v) {
        if (strcasecmp($k, $name) === 0) {
            return (string) $v;
        }
    }
    return '';
}

$seen = [
    'authorization' => requestHeader('Authorization'),
    'user_agent' => requestHeader('User-Agent'),
    'expect' => requestHeader('Expect'),
    'content_type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
];

if ($path === '/v1/tools' && $method === 'GET') {
    json(200, [
        'tools' => [['id' => 'pdf-to-word', 'min_files' => 1, 'max_files' => 1, 'accepted_exts' => ['.pdf']]],
        'seen' => $seen,
    ]);
    return;
}

if (preg_match('#^/v1/convert/([a-z0-9-]+)$#', $path, $m) && $method === 'POST') {
    switch ($m[1]) {
        case 'echo':
            json(200, [
                'job_id' => 'job_echo',
                'status' => 'success',
                'download_url' => '/v1/download/ok',
                'size_bytes' => 15,
                'received' => $seen + ['files' => receivedFiles(), 'fields' => $_POST],
            ]);
            return;
        case 'slow':
            // A conversion that takes a while: bytes stop moving in both directions.
            sleep(3);
            json(200, ['job_id' => 'job_slow', 'status' => 'success', 'download_url' => '/v1/download/ok', 'size_bytes' => 15]);
            return;
        case 'stalled':
            // Long enough for curl's five-second speed window to empty, then some.
            sleep(10);
            json(200, ['job_id' => 'job_slow', 'status' => 'success', 'download_url' => '/v1/download/ok', 'size_bytes' => 15]);
            return;
        case 'rate-limited':
            json(429, ['error' => 'rate_limited', 'retry_after' => 7, 'limit' => 20], ['Retry-After: 7']);
            return;
        case 'quota':
            json(429, [
                'error' => 'quota_exceeded', 'plan' => 'dev', 'quota' => 300, 'used' => 330, 'soft_cap_limit' => 330,
                'period_end' => 1790000000000, 'quota_period_end' => 1790000000000,
            ]);
            return;
        case 'too-large':
            json(413, [
                'error' => 'file_too_large', 'max_bytes' => 10485760, 'file' => 'big.pdf', 'plan' => 'dev',
                'upgrade' => ['plan' => 'starter', 'price_eur_month' => 9, 'max_bytes' => 209715200, 'url' => 'https://conv2pdf.com/tarifs/'],
            ]);
            return;
        case 'busy':
            json(503, ['error' => 'server_busy', 'plan' => 'dev'], ['Retry-After: 5']);
            return;
        case 'html':
            // What a front proxy answers when the API is down: not JSON at all.
            http_response_code(502);
            header('Content-Type: text/html');
            echo '<html><body><h1>502 Bad Gateway</h1></body></html>';
            return;
    }
    json(404, ['error' => 'tool_not_found', 'tool' => $m[1]]);
    return;
}

if (preg_match('#^/v1/download/([a-z]+)$#', $path, $m) && $method === 'GET') {
    switch ($m[1]) {
        case 'ok':
            http_response_code(200);
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="report.pdf"');
            header('Cache-Control: no-store');
            echo "%PDF-1.4\n%%EOF\n";
            return;
        case 'gone':
            json(410, ['error' => 'job_deleted']);
            return;
        case 'limited':
            json(429, ['error' => 'rate_limited', 'retry_after' => 11, 'limit' => 300], ['Retry-After: 11']);
            return;
    }
    json(404, ['error' => 'job_not_found']);
    return;
}

if (preg_match('#^/v1/job/([a-z]+)$#', $path, $m)) {
    if ($method === 'GET') {
        json(200, [
            'job_id' => $m[1], 'status' => 'success', 'tool' => 'pdf-to-word', 'size_bytes' => 15,
            'completed_at' => 1, 'expires_at' => 2, 'download_url' => '/v1/download/ok', 'can_share' => false,
        ]);
        return;
    }
    if ($method === 'DELETE') {
        json(200, ['status' => 'deleted', 'seen' => $seen]);
        return;
    }
}

json(404, ['error' => 'not_found', 'path' => $path, 'method' => $method]);
