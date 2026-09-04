<?php

/**
 * Functional test: the SDK against a stub of the API served by PHP's built-in server
 * (tests/stub/router.php). No conv2pdf server, no API key, no network beyond localhost.
 *
 * It checks what the smoke test cannot: what the server actually RECEIVES (multipart
 * file names, fields, User-Agent, Authorization, HTTP method) and what the SDK makes
 * of the responses (error code, payload, Retry-After, download to file, stall
 * detection). Like the smoke test, it fails on any PHP diagnostic.
 *
 * Usage: php -d error_reporting=E_ALL tests/functional.php
 * CONV2PDF_STUB=http://127.0.0.1:18765/v1 runs against a stub already started by hand.
 */

declare(strict_types=1);

$diagnostics = [];
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$diagnostics): bool {
    $diagnostics[] = sprintf('%s:%d — [%d] %s', basename($file), $line, $no, $msg);
    return true;
});

require __DIR__ . '/../src/Conv2pdfException.php';
require __DIR__ . '/../src/Conv2pdf.php';

use Conv2pdf\Conv2pdf;
use Conv2pdf\Conv2pdfException;

$failures = [];
$check = function (string $label, bool $ok, $got = null) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label . ($got !== null ? ' — got ' . json_encode($got) : '');
    }
};
/** Runs $fn, which must raise a Conv2pdfException; returns it (null when it did not). */
$refused = function (string $label, callable $fn) use (&$failures): ?Conv2pdfException {
    try {
        $fn();
    } catch (Conv2pdfException $e) {
        return $e;
    }
    $failures[] = "$label: no exception";
    return null;
};

// --- The stub: started here unless CONV2PDF_STUB points at one.
$baseUrl = (string) getenv('CONV2PDF_STUB');
$stub = null;
if ($baseUrl === '') {
    $port = 18765;
    $baseUrl = "http://127.0.0.1:$port/v1";
    $log = sys_get_temp_dir() . '/conv2pdf-php-stub.log';
    $stub = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/stub/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($stub)) {
        echo "functional KO: cannot start the stub server\n";
        exit(1);
    }
    fclose($pipes[0]);
    $up = false;
    for ($i = 0; $i < 50 && !$up; $i++) {
        usleep(100000);
        $ch = curl_init("$baseUrl/tools");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 1]);
        $up = curl_exec($ch) !== false;
        unset($ch);
    }
    if (!$up) {
        proc_terminate($stub);
        echo "functional KO: the stub server did not come up (see $log)\n";
        exit(1);
    }
}

$pdf = tempnam(sys_get_temp_dir(), 'c2p') ?: '';
file_put_contents($pdf, "%PDF-1.4\n%%EOF\n"); // 15 bytes
$out = tempnam(sys_get_temp_dir(), 'c2p') ?: '';

$c = new Conv2pdf('cpdf_live_functional', $baseUrl);

// 1. What the server receives on a conversion.
$r = $c->convert('echo', $pdf, ['quality' => 'high', 'rotation' => 90]);
$rx = $r['received'];
$check('Authorization header', $rx['authorization'] === 'Bearer cpdf_live_functional', $rx['authorization']);
$check('User-Agent header', $rx['user_agent'] === Conv2pdf::userAgent(), $rx['user_agent']);
$check('User-Agent names the SDK and its version', strpos($rx['user_agent'], 'conv2pdf-php/' . Conv2pdf::VERSION . ' ') === 0, $rx['user_agent']);
$check('no Expect: 100-continue', $rx['expect'] === '', $rx['expect']);
$check('multipart body', strpos($rx['content_type'], 'multipart/form-data') === 0, $rx['content_type']);
$check('one file, under "file", with its basename', $rx['files'] === [['field' => 'file', 'name' => basename($pdf), 'size' => 15]], $rx['files']);
$check('fields sent as strings', $rx['fields'] === ['quality' => 'high', 'rotation' => '90'], $rx['fields']);
$check('response passed through', $r['job_id'] === 'job_echo' && $r['download_url'] === '/v1/download/ok', $r);

// 2. File names: the server judges content; the name settles .doc/.xls/.ppt and .txt/.csv.
$r = $c->convert('echo', ['path' => $pdf, 'name' => 'export.csv']);
$check('path sent under another name', $r['received']['files'][0]['name'] === 'export.csv', $r['received']['files']);

$cf = new CURLFile($pdf);
$cf->setPostFilename('facture.pdf');
$r = $c->convert('echo', $cf);
$check('CURLFile post name kept', $r['received']['files'][0]['name'] === 'facture.pdf', $r['received']['files']);

if (class_exists(CURLStringFile::class)) {
    $r = $c->convert('echo', new CURLStringFile("a;b\n1;2\n", 'inline.csv'));
    $check('CURLStringFile: in-memory content', $r['received']['files'] === [['field' => 'file', 'name' => 'inline.csv', 'size' => 8]], $r['received']['files']);
}

$r = $c->convert('echo', [$pdf, ['path' => $pdf, 'name' => 'second.pdf'], $cf]);
$seen = array_map(function (array $f): array { return [$f['field'], $f['name']]; }, $r['received']['files']);
$check('merge: file[0], file[1], file[2], each under its name', $seen === [['file[0]', basename($pdf)], ['file[1]', 'second.pdf'], ['file[2]', 'facture.pdf']], $seen);

// 3. Errors: status, code, payload, Retry-After.
$e = $refused('429 rate_limited', function () use ($c, $pdf): void { $c->convert('rate-limited', $pdf); });
if ($e !== null) {
    $check('rate_limited: status', $e->getStatus() === 429 && $e->getCode() === 429, $e->getStatus());
    $check('rate_limited: code', $e->getErrorCode() === 'rate_limited', $e->getErrorCode());
    $check('rate_limited: payload', $e->getPayload() === ['error' => 'rate_limited', 'retry_after' => 7, 'limit' => 20], $e->getPayload());
    $check('rate_limited: Retry-After', $e->getRetryAfter() === 7, $e->getRetryAfter());
    $check('rate_limited: transient', $e->isTransient());
    $check('rate_limited: message', $e->getMessage() === 'Request failed (HTTP 429): rate_limited.', $e->getMessage());
}

$e = $refused('429 quota_exceeded', function () use ($c, $pdf): void { $c->convert('quota', $pdf); });
if ($e !== null) {
    $check('quota_exceeded: code', $e->getErrorCode() === 'quota_exceeded', $e->getErrorCode());
    $check('quota_exceeded: not transient', !$e->isTransient() && $e->getRetryAfter() === null, $e->getRetryAfter());
    $check('quota_exceeded: quota_period_end in the payload', ($e->getPayload()['quota_period_end'] ?? null) === 1790000000000, $e->getPayload());
}

$e = $refused('413 file_too_large', function () use ($c, $pdf): void { $c->convert('too-large', $pdf); });
if ($e !== null) {
    $check('file_too_large: status', $e->getStatus() === 413, $e->getStatus());
    $check('file_too_large: upgrade offer in the payload', ($e->getPayload()['upgrade']['plan'] ?? null) === 'starter' && ($e->getPayload()['upgrade']['price_eur_month'] ?? null) === 9, $e->getPayload());
    $check('file_too_large: not transient', !$e->isTransient());
}

$e = $refused('503 server_busy', function () use ($c, $pdf): void { $c->convert('busy', $pdf); });
if ($e !== null) {
    $check('server_busy: status', $e->getStatus() === 503, $e->getStatus());
    $check('server_busy: code', $e->getErrorCode() === 'server_busy', $e->getErrorCode());
    $check('server_busy: Retry-After', $e->getRetryAfter() === 5 && $e->isTransient(), $e->getRetryAfter());
}

$e = $refused('502 without a JSON body', function () use ($c, $pdf): void { $c->convert('html', $pdf); });
if ($e !== null) {
    $check('non-JSON: status', $e->getStatus() === 502, $e->getStatus());
    $check('non-JSON: no code', $e->getErrorCode() === null, $e->getErrorCode());
    $check('non-JSON: empty payload', $e->getPayload() === [], $e->getPayload());
    $check('non-JSON: message', $e->getMessage() === 'Request failed (HTTP 502).', $e->getMessage());
    $check('non-JSON: not transient', !$e->isTransient());
}

$e = $refused('404 tool_not_found', function () use ($c, $pdf): void { $c->convert('nope', $pdf); });
if ($e !== null) {
    $check('tool_not_found: code and payload', $e->getErrorCode() === 'tool_not_found' && ($e->getPayload()['tool'] ?? null) === 'nope', $e->getPayload());
}

// 4. Download: to a file, by path / bare id / absolute URL, then the error paths
//    (JSON body read back from the partial file, file removed).
$c->download('/v1/download/ok', $out);
$check('download by path writes the body', file_get_contents($out) === "%PDF-1.4\n%%EOF\n");
$c->download('ok', $out);
$check('download by bare job id', file_get_contents($out) === "%PDF-1.4\n%%EOF\n");
$c->download("$baseUrl/download/ok", $out);
$check('download by absolute URL', file_get_contents($out) === "%PDF-1.4\n%%EOF\n");

$e = $refused('download 410', function () use ($c, $out): void { $c->download('/v1/download/gone', $out); });
if ($e !== null) {
    $check('download 410: status and code', $e->getStatus() === 410 && $e->getErrorCode() === 'job_deleted', $e->getPayload());
    $check('download 410: message', $e->getMessage() === 'Download failed (HTTP 410): job_deleted.', $e->getMessage());
    $check('download 410: partial file removed', !file_exists($out));
}
$e = $refused('download 429', function () use ($c, $out): void { $c->download('limited', $out); });
if ($e !== null) {
    $check('download 429: code and Retry-After', $e->getErrorCode() === 'rate_limited' && $e->getRetryAfter() === 11 && $e->isTransient(), $e->getPayload());
    $check('download 429: partial file removed', !file_exists($out));
}

// 5. Jobs and tools.
$check('job()', $c->job('abc')['status'] === 'success');
$d = $c->deleteJob('abc');
$check('deleteJob() sends DELETE with the same headers', $d['status'] === 'deleted' && $d['seen']['user_agent'] === Conv2pdf::userAgent() && $d['seen']['authorization'] === 'Bearer cpdf_live_functional', $d);
$check('tools()', $c->tools()['tools'][0]['id'] === 'pdf-to-word');

// 6. Stall detection. curl averages the speed over a five-second window, so a client
//    that tolerates 1 s of silence gives up about 6 s after its last byte — before a
//    server that stays silent for 10 s answers. The default (120 s) sits through a 3 s
//    conversion.
$impatient = new Conv2pdf('cpdf_live_functional', $baseUrl, 600, 1);
$started = microtime(true);
$e = $refused('stall detected', function () use ($impatient, $pdf): void { $impatient->convert('stalled', $pdf); });
$elapsed = microtime(true) - $started;
if ($e !== null) {
    $check('stall: network error, status 0', $e->getStatus() === 0 && strpos($e->getMessage(), 'Network error') === 0, $e->getMessage());
    $check('stall: aborted before the server answered', $elapsed < 9.5, $elapsed);
}
$r = $c->convert('slow', $pdf);
$check('slow conversion tolerated by default', $r['status'] === 'success', $r);

foreach ([$pdf, $out] as $tmp) {
    if ($tmp !== '' && file_exists($tmp)) {
        unlink($tmp);
    }
}
if ($stub !== null) {
    proc_terminate($stub);
    proc_close($stub);
}

restore_error_handler();

echo 'PHP ', PHP_VERSION, "\n";
foreach ($diagnostics as $d) {
    echo 'DIAGNOSTIC  ', $d, "\n";
}
foreach ($failures as $f) {
    echo 'FAILURE     ', $f, "\n";
}

$total = count($diagnostics) + count($failures);
if ($total > 0) {
    echo "\nfunctional KO: ", count($diagnostics), " diagnostic(s), ", count($failures), " failure(s)\n";
    exit(1);
}

echo "functional OK: no diagnostic, every exchange checked against the stub\n";
