# conv2pdf/php

Official PHP SDK for the [conv2pdf API](https://conv2pdf.com/api/): PDF conversion and manipulation (14 tools), **hosted in France**, GDPR-compliant, DPA provided. A thin wrapper with **zero dependencies** (`ext-curl` only).

## Installation

```bash
composer require conv2pdf/php
```

Requires PHP 8.0 to 8.5 (every version is covered by CI) and the `curl` extension. [Get a free API key](https://conv2pdf.com/api/) (Dev plan: 300 conversions per month, no card).

## Quick start

```php
use Conv2pdf\Conv2pdf;

$c = new Conv2pdf('cpdf_live_...');

$job = $c->convert('pdf-to-word', 'report.pdf');
$c->download($job['download_url'], 'report.docx');
```

## Usage

### Conversions with options

```php
$c->convert('compress-pdf', 'large.pdf', ['quality' => 'high']);      // low | medium | high
$c->convert('split-pdf',    'doc.pdf',   ['ranges' => '1-5,7,10-12']);
$c->convert('rotate-pdf',   'doc.pdf',   ['rotation' => 90]);          // 90 | 180 | 270
$c->convert('watermark-pdf','doc.pdf',   ['text' => 'CONFIDENTIAL']);
$c->convert('protect-pdf',  'doc.pdf',   ['password' => 'secret', 'prevent_print' => 'on']);
```

### Merging

```php
$job = $c->convert('merge-pdf', ['a.pdf', 'b.pdf', 'c.pdf']);
$c->download($job['download_url'], 'merged.pdf');
```

### Plan limits

The API bounds a request by plan, not by tool:

| Plan | Per file | Files per merge | Conversions per month |
|---|---|---|---|
| Dev (free, no card) | 10 MB | 2 | 300 |
| Starter, Growth, Business | 200 MB | up to 20 | 1,000 / 5,000 / 20,000 |

Beyond them the API answers `413 file_too_large` or `402 plan_limit_files`; the 413 names the plan that would accept the file (see [Error handling](#error-handling)). Each tool's own bounds come from `tools()`.

### File names and in-memory content

The API identifies a file by its **content**, never by the extension of its name. The name only settles what the bytes cannot: `.doc`/`.xls`/`.ppt` share one container, and `.txt`/`.csv` are the same bytes rendered as a different document. So a temp file with no extension should be sent under a name that has one:

```php
$c->convert('office-to-pdf', ['path' => $tmpPath, 'name' => 'export.csv']);

// A \CURLFile is sent as is, post name included. On PHP 8.1+, a \CURLStringFile
// sends in-memory content without touching the disk.
$c->convert('office-to-pdf', new \CURLStringFile($csv, 'export.csv'));

// Lists mix every form (merge-pdf).
$c->convert('merge-pdf', ['cover.pdf', ['path' => $tmpPath, 'name' => 'annex.pdf']]);
```

### Jobs

```php
$meta = $c->job($job['job_id']);     // metadata for a finished job (tool, size, expiry)
$c->deleteJob($job['job_id']);       // delete now (otherwise purged automatically after 1 hour)
```

A job that succeeded and still has its file answers 200. Otherwise `job()` and `download()` raise `409 job_not_ready` (the payload carries `status`: `pending`, `failed` or `rejected`), `410 job_deleted` after a `deleteJob()`, or `410 file_expired` past the one-hour retention. A 410 is final: do not retry.

### Listing the tools

```php
foreach ($c->tools()['tools'] as $tool) {
    echo $tool['id'], ' ', implode(',', $tool['accepted_exts']), "\n";
}
```

### Error handling

Every API error (4xx/5xx) and network error raises a `Conv2pdf\Conv2pdfException`.

```php
use Conv2pdf\Conv2pdfException;

try {
    $c->convert('pdf-to-word', 'scan.pdf');
} catch (Conv2pdfException $e) {
    $e->getStatus();      // 422
    $e->getErrorCode();   // 'pdf_scanned_needs_ocr'
    $e->getPayload();     // the whole error body: ['error' => 'pdf_scanned_needs_ocr', 'plan' => 'dev']
    $e->getRetryAfter();  // seconds to wait when the server said so (429 rate_limited, 503 server_busy), else null
    $e->isTransient();    // true when retrying later makes sense, i.e. getRetryAfter() is set
}
```

Match on `getErrorCode()` rather than on the message: error codes are part of the API contract, messages are not. The rest of the body is in `getPayload()`; the API puts there what a client can act on:

| Status | Code | In the payload |
|---|---|---|
| 402 | `plan_limit_files` | `max_allowed` |
| 409 | `job_not_ready` | `status`: `pending`, `failed` or `rejected` |
| 410 | `file_expired`, `job_deleted` | Gone for good, do not retry |
| 413 | `file_too_large` | `max_bytes`, `plan`, and `upgrade` (`plan`, `price_eur_month`, `max_bytes`, `url`) when a higher plan accepts the file |
| 415 | `unsupported_content` | `detected_type`: what the file really is |
| 415 | `unsupported_media_type` | `received`, `accepted`: the request was not `multipart/form-data` |
| 422 | `empty_file`, `password_protected`, `pdf_scanned_needs_ocr`, `pdf_too_many_pages`, … | A verdict on the file itself |
| 429 | `rate_limited` | `retry_after`, `limit`: 20 conversions per minute per key; `getRetryAfter()` is set |
| 429 | `quota_exceeded` | `quota`, `used`, `quota_period_end` (next reset, millisecond timestamp); not transient |
| 503 | `server_busy` | Queue saturated; `getRetryAfter()` is set |

The full table is in the [API documentation](https://conv2pdf.com/api/documentation/).

```php
try {
    $job = $c->convert('compress-pdf', 'big.pdf');
} catch (Conv2pdfException $e) {
    if ($e->isTransient()) {
        sleep($e->getRetryAfter());
        $job = $c->convert('compress-pdf', 'big.pdf');
    } elseif ($e->getErrorCode() === 'file_too_large' && isset($e->getPayload()['upgrade'])) {
        $offer = $e->getPayload()['upgrade'];   // ['plan' => 'starter', 'price_eur_month' => 9, 'max_bytes' => ..., 'url' => ...]
    } elseif ($e->getErrorCode() === 'quota_exceeded') {
        $resetAt = intdiv((int) $e->getPayload()['quota_period_end'], 1000);   // Unix timestamp of the next reset
    } else {
        throw $e;
    }
}
```

### Timeouts

```php
new Conv2pdf($key, 'https://api.conv2pdf.com/v1', $timeout = 600, $stallTimeout = 120);
```

A conversion is synchronous: the response comes back when the file is ready, within 90 seconds. Two clocks apply to every request:

- `$stallTimeout` (default 120 s) aborts a request when no byte has moved in either direction for that long. It also runs while the server is converting, hence a value above the API's 90 s. 0 disables it.
- `$timeout` (default 600 s) caps the whole request, upload and download included. It only has to cover a large transfer on a slow link: 200 MB take five minutes at 5 Mbit/s. 0 disables it.

Connecting is capped at 10 seconds. Every abort raises a `Conv2pdfException` with status 0.

### User-Agent

Every request carries `User-Agent: conv2pdf-php/<version> (PHP <version>)`. Mention it when you contact support: it tells your calls apart in the API's logs.

## Privacy

Processing runs on OVH servers in Gravelines, France. No US service, no transfer outside the EU, no Cloud Act. Input and output files are deleted after one hour and no result is ever cached. DPA provided on request.

## Resources

- Documentation: <https://conv2pdf.com/api/documentation/>
- OpenAPI specification: <https://conv2pdf.com/openapi.json>
- Postman collection: <https://conv2pdf.com/conv2pdf.postman_collection.json>

## License

MIT — see [LICENSE](LICENSE).
