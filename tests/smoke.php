<?php

/**
 * Smoke test: runs every code path in the SDK and fails if PHP emits any diagnostic
 * (Deprecated, Warning, Notice). This is not a functional test — no API key is used
 * and conv2pdf.com is never contacted. tests/functional.php checks the exchanges
 * themselves, against a local stub.
 *
 * The client points at a closed local port, so curl fails immediately, but
 * curl_init/setopt/exec/getinfo, the CURLFile multipart encoding, the file entry
 * forms and the three download URL forms have all been exercised by then.
 *
 * Usage: php -d error_reporting=E_ALL tests/smoke.php
 */

declare(strict_types=1);

$diagnostics = [];

// Registered BEFORE the requires: some deprecations are emitted when the file is
// compiled, not when it runs.
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$diagnostics): bool {
    $diagnostics[] = sprintf('%s:%d — [%d] %s', basename($file), $line, $no, $msg);
    return true;
});

require __DIR__ . '/../src/Conv2pdfException.php';
require __DIR__ . '/../src/Conv2pdf.php';

use Conv2pdf\Conv2pdf;
use Conv2pdf\Conv2pdfException;

$failures = [];

/** Runs $fn and checks that it raises $expected. */
$expect = function (string $label, string $expected, callable $fn) use (&$failures): void {
    try {
        $fn();
        $failures[] = "$label: no exception, expected $expected";
    } catch (Throwable $e) {
        if (!($e instanceof $expected)) {
            $failures[] = "$label: " . get_class($e) . ' instead of ' . $expected . ' (' . $e->getMessage() . ')';
        }
    }
};
$check = function (string $label, bool $ok) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};

$pdf = tempnam(sys_get_temp_dir(), 'smoke') ?: '';
file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
$out = tempnam(sys_get_temp_dir(), 'smoke') ?: '';

$c = new Conv2pdf('cpdf_live_smoke', 'http://127.0.0.1:9/v1', 2);

// JSON endpoints: GET, multipart POST (one file, then several), DELETE.
$expect('tools()', Conv2pdfException::class, function () use ($c): void { $c->tools(); });
$expect('convert() one file', Conv2pdfException::class, function () use ($c, $pdf): void { $c->convert('pdf-to-word', $pdf); });
$expect('convert() several files', Conv2pdfException::class, function () use ($c, $pdf): void { $c->convert('merge-pdf', [$pdf, $pdf]); });
$expect('convert() with fields', Conv2pdfException::class, function () use ($c, $pdf): void { $c->convert('rotate-pdf', $pdf, ['rotation' => 90]); });
$expect('job()', Conv2pdfException::class, function () use ($c): void { $c->job('abc'); });
$expect('deleteJob()', Conv2pdfException::class, function () use ($c): void { $c->deleteJob('abc'); });

// File entries: a path under another name, a CURLFile, in-memory content (PHP 8.1+), a mixed list.
$curlFile = new CURLFile($pdf);
$curlFile->setPostFilename('facture.pdf');
$expect('convert() path under another name', Conv2pdfException::class, function () use ($c, $pdf): void { $c->convert('office-to-pdf', ['path' => $pdf, 'name' => 'export.csv']); });
$expect('convert() CURLFile', Conv2pdfException::class, function () use ($c, $curlFile): void { $c->convert('pdf-to-word', $curlFile); });
if (class_exists(CURLStringFile::class)) {
    $expect('convert() CURLStringFile', Conv2pdfException::class, function () use ($c): void { $c->convert('office-to-pdf', new CURLStringFile("a;b\n", 'inline.csv')); });
}
$expect('convert() mixed list', Conv2pdfException::class, function () use ($c, $pdf, $curlFile): void { $c->convert('merge-pdf', [$pdf, ['path' => $pdf, 'name' => 'b.pdf'], $curlFile]); });

// download(): absolute URL, path from the host root, bare job_id.
foreach (['http://127.0.0.1:9/v1/download/abc', '/v1/download/abc', 'abc'] as $target) {
    $expect("download($target)", Conv2pdfException::class, function () use ($c, $target, $out): void { $c->download($target, $out); });
}

// Unparsable base URL: exercises the branch where parse_url() fails.
$broken = new Conv2pdf('cpdf_live_smoke', '///:not-a-url', 2);
$expect('download() broken base URL', Conv2pdfException::class, function () use ($broken, $out): void { $broken->download('/v1/download/abc', $out); });

// Timeouts switched off (0 = no cap, no stall detection): the curl options branch without them.
$noTimeouts = new Conv2pdf('cpdf_live_smoke', 'http://127.0.0.1:9/v1', 0, 0);
$expect('convert() without timeouts', Conv2pdfException::class, function () use ($noTimeouts, $pdf): void { $noTimeouts->convert('pdf-to-word', $pdf); });

// Argument validation.
$expect('convert() without a file', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', []); });
$expect('convert() missing file', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', '/does-not-exist.pdf'); });
$expect('convert() entry with a missing path', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', ['path' => '/does-not-exist.pdf', 'name' => 'x.pdf']); });
$expect('convert() entry of a wrong type', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', [42]); });
$expect('convert() object of a wrong type', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', new stdClass()); });
$expect('constructor without a key', InvalidArgumentException::class, function (): void { new Conv2pdf(''); });

// The exception carries the whole body and the Retry-After delay; both default to "none".
$e = new Conv2pdfException('m', 429, 'rate_limited', ['error' => 'rate_limited', 'retry_after' => 3, 'limit' => 20], 3);
$check('exception: payload', $e->getPayload() === ['error' => 'rate_limited', 'retry_after' => 3, 'limit' => 20]);
$check('exception: retry-after', $e->getRetryAfter() === 3 && $e->isTransient());
$check('exception: status and code', $e->getStatus() === 429 && $e->getCode() === 429 && $e->getErrorCode() === 'rate_limited');
$e = new Conv2pdfException('network');
$check('exception: defaults', $e->getStatus() === 0 && $e->getErrorCode() === null && $e->getPayload() === [] && $e->getRetryAfter() === null && !$e->isTransient());

// The User-Agent names the SDK and its version.
$check('userAgent()', preg_match('~^conv2pdf-php/\d+\.\d+\.\d+ \(PHP \d~', Conv2pdf::userAgent()) === 1);

foreach ([$pdf, $out] as $tmp) {
    if ($tmp !== '' && file_exists($tmp)) {
        unlink($tmp);
    }
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
    echo "\nsmoke KO: ", count($diagnostics), " diagnostic(s), ", count($failures), " failure(s)\n";
    exit(1);
}

echo "smoke OK: no diagnostic, every path exercised\n";
