<?php

/**
 * Smoke test: runs every code path in the SDK and fails if PHP emits any diagnostic
 * (Deprecated, Warning, Notice). This is not a functional test — no API key is used
 * and conv2pdf.com is never contacted.
 *
 * The client points at a closed local port, so curl fails immediately, but
 * curl_init/setopt/exec/getinfo, the CURLFile multipart encoding and the three
 * download URL forms have all been exercised by then.
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

// download(): absolute URL, path from the host root, bare job_id.
foreach (['http://127.0.0.1:9/v1/download/abc', '/v1/download/abc', 'abc'] as $target) {
    $expect("download($target)", Conv2pdfException::class, function () use ($c, $target, $out): void { $c->download($target, $out); });
}

// Unparsable base URL: exercises the branch where parse_url() fails.
$broken = new Conv2pdf('cpdf_live_smoke', '///:not-a-url', 2);
$expect('download() broken base URL', Conv2pdfException::class, function () use ($broken, $out): void { $broken->download('/v1/download/abc', $out); });

// Argument validation.
$expect('convert() without a file', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', []); });
$expect('convert() missing file', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', '/does-not-exist.pdf'); });
$expect('constructor without a key', InvalidArgumentException::class, function (): void { new Conv2pdf(''); });

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
