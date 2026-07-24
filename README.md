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

### Merging (2 to 20 files)

```php
$job = $c->convert('merge-pdf', ['a.pdf', 'b.pdf', 'c.pdf']);
$c->download($job['download_url'], 'merged.pdf');
```

### Jobs

```php
$meta = $c->job($job['job_id']);     // metadata for a finished job (tool, size, expiry)
$c->deleteJob($job['job_id']);       // delete now (otherwise purged automatically after 1 hour)
```

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
    $e->getStatus();     // 422
    $e->getErrorCode();  // 'pdf_scanned_needs_ocr'
    $e->getMessage();
}
```

Match on `getErrorCode()` rather than on the message: error codes are part of the API contract, messages are not.

## Privacy

Processing runs on OVH servers in Gravelines, France. No US service, no transfer outside the EU, no Cloud Act. Input and output files are deleted after one hour and no result is ever cached. DPA provided on request.

## Resources

- Documentation: <https://conv2pdf.com/api/documentation/>
- OpenAPI specification: <https://conv2pdf.com/openapi.json>
- Postman collection: <https://conv2pdf.com/conv2pdf.postman_collection.json>

## License

MIT — see [LICENSE](LICENSE).
