<?php

declare(strict_types=1);

namespace Conv2pdf;

/**
 * PHP client for the conv2pdf API — PDF conversion and manipulation, hosted in France.
 *
 * Example:
 *   $c = new Conv2pdf('cpdf_live_...');
 *   $job = $c->convert('pdf-to-word', 'report.pdf');
 *   $c->download($job['download_url'], 'report.docx');
 *
 * Zero dependencies (ext-curl only). REST contract: https://conv2pdf.com/openapi.json
 */
final class Conv2pdf
{
    /** SDK version. Sent on every request as `User-Agent: conv2pdf-php/<version> (PHP <version>)`. */
    public const VERSION = '1.2.0';

    /** Seconds allowed to establish the connection (TCP + TLS). */
    private const CONNECT_TIMEOUT = 10;

    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private int $stallTimeout;

    /**
     * @param string $apiKey       API key in the cpdf_live_... format (from the conv2pdf dashboard).
     * @param string $baseUrl      API root (defaults to production).
     * @param int    $timeout      Hard cap on a whole request in seconds, upload and download
     *                             included; 0 = none. Stalls are caught separately (see
     *                             $stallTimeout), so this only has to cover a large transfer on
     *                             a slow link: 200 MB take five minutes at 5 Mbit/s.
     * @param int    $stallTimeout Seconds without a single byte moving before the request is
     *                             aborted as dead (give or take the five seconds curl averages
     *                             the speed over); 0 = never. A conversion is synchronous and
     *                             the API answers within 90 s, so 120 s tells a slow conversion
     *                             from a lost connection.
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.conv2pdf.com/v1',
        int $timeout = 600,
        int $stallTimeout = 120
    ) {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key required.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = max(0, $timeout);
        $this->stallTimeout = max(0, $stallTimeout);
    }

    /**
     * The User-Agent sent on every request. It names the SDK and its version, so that
     * SDK traffic can be told apart in the API's logs when you contact support.
     */
    public static function userAgent(): string
    {
        return sprintf('conv2pdf-php/%s (PHP %s)', self::VERSION, PHP_VERSION);
    }

    /**
     * Lists the available tools, their file bounds and accepted extensions.
     *
     * @return array{tools: array<int, array<string, mixed>>}
     * @throws Conv2pdfException
     */
    public function tools(): array
    {
        return $this->requestJson('GET', '/tools');
    }

    /**
     * Converts or manipulates one or several files through POST /convert/{tool}.
     *
     * The server identifies each file by its CONTENT, not by its name. The name only settles
     * what the bytes cannot: .doc/.xls/.ppt share one container, and .txt/.csv are the same
     * bytes rendered as a different document. So when a path has no telling extension — a
     * temp file, a stream dumped to disk — send it under a name that has one.
     *
     * @param string $tool Tool identifier (e.g. 'pdf-to-word', 'merge-pdf', 'compress-pdf').
     * @param string|array<mixed>|\CURLFile|\CURLStringFile $files
     *     One file, or a list of files (merge-pdf). A file is:
     *       - a path: 'report.pdf'
     *       - a path sent under another name: ['path' => '/tmp/php3Fx2', 'name' => 'export.csv']
     *       - a \CURLFile (its post name is sent as is)
     *       - a \CURLStringFile, for in-memory content (PHP 8.1+)
     * @param array<string, string|int> $fields Extra fields, depending on the tool, e.g. ['ranges' => '1-5'],
     *                                          ['quality' => 'high'], ['password' => '...'], ['rotation' => 90].
     * @return array{job_id: string, status: string, download_url: string, size_bytes: int, quota?: array<string, mixed>}
     * @throws Conv2pdfException On an API error (4xx/5xx) or a network error.
     * @throws \InvalidArgumentException If no file is given, or a path does not exist.
     */
    public function convert(string $tool, $files, array $fields = []): array
    {
        // A list is an array without a 'path' key: ['a.pdf', 'b.pdf'] or [['path' => …], …].
        $entries = is_array($files) && !array_key_exists('path', $files) ? array_values($files) : [$files];
        if ($entries === []) {
            throw new \InvalidArgumentException('At least one file is required.');
        }

        $post = [];
        foreach ($entries as $i => $entry) {
            // The server collects every file part, whatever the field name.
            // A single file goes to "file"; several (merge) go to "file[0]", "file[1]"…
            $key = count($entries) === 1 ? 'file' : "file[$i]";
            $post[$key] = self::toCurlFile($entry);
        }
        foreach ($fields as $name => $value) {
            $post[$name] = (string) $value;
        }

        return $this->requestJson('POST', '/convert/' . rawurlencode($tool), $post);
    }

    /**
     * Metadata for a finished job (GET /job/{jobId}): tool, size, dates, download_url.
     * Conversions are synchronous, so convert() throws straight away on failure. This
     * answers 200 only for a job that succeeded and still has its file: 409 job_not_ready
     * (the body carries `status`) otherwise, 410 job_deleted after a deleteJob(), and
     * 410 file_expired past the one-hour retention.
     *
     * @return array<string, mixed>
     * @throws Conv2pdfException
     */
    public function job(string $jobId): array
    {
        return $this->requestJson('GET', '/job/' . rawurlencode($jobId));
    }

    /**
     * Deletes a job and its file now, instead of at the one-hour purge (DELETE /job/{jobId}).
     * Idempotent: deleting twice answers 200 twice.
     *
     * @return array<string, mixed>
     * @throws Conv2pdfException
     */
    public function deleteJob(string $jobId): array
    {
        return $this->requestJson('DELETE', '/job/' . rawurlencode($jobId));
    }

    /**
     * Downloads the result of a conversion to a local file (GET /download/{jobId}).
     * Available for one hour after the conversion.
     *
     * @param string $jobIdOrUrl The download_url returned by convert(), or a bare job_id.
     * @param string $destPath   Local destination path.
     * @throws Conv2pdfException On an API or network error (the partial file is removed).
     * @throws \RuntimeException If the destination file cannot be opened for writing.
     */
    public function download(string $jobIdOrUrl, string $destPath): void
    {
        $url = $this->resolveDownloadUrl($jobIdOrUrl);
        $fh = fopen($destPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Cannot open destination file for writing: $destPath");
        }

        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, $this->curlOptions([
            CURLOPT_FILE => $fh,
            CURLOPT_HTTPHEADER => $this->requestHeaders(),
            CURLOPT_HEADERFUNCTION => self::headerCollector($headers),
        ]));
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        // curl_close() has been a no-op since PHP 8.0 and is deprecated in 8.5, so the
        // handle is released with unset() — before fclose(), as it still holds the stream.
        unset($ch);
        fclose($fh);

        if ($ok === false) {
            @unlink($destPath);
            throw new Conv2pdfException("Network error during download: $err", 0, null);
        }
        if ($status < 200 || $status >= 300) {
            // On error the JSON body ({error:…}) has been written to the file, so it is
            // read back before the partial file is removed.
            $payload = [];
            $errBody = @file_get_contents($destPath);
            if ($errBody !== false) {
                $decoded = json_decode($errBody, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                }
            }
            @unlink($destPath);
            throw self::apiError('Download', $status, $payload, $headers);
        }
    }

    /**
     * @param array<string, mixed>|null $post Multipart fields (with CURLFile) for a POST, null otherwise.
     * @return array<string, mixed>
     * @throws Conv2pdfException
     */
    private function requestJson(string $method, string $path, ?array $post = null): array
    {
        $headers = [];
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, $this->curlOptions([
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->requestHeaders(['Accept: application/json']),
            CURLOPT_HEADERFUNCTION => self::headerCollector($headers),
        ]));
        if ($post !== null) {
            // An array holding a CURLFile makes curl encode the body as multipart/form-data.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            throw new Conv2pdfException("Network error: $err", 0, null);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // No curl_close(): a no-op since PHP 8.0, deprecated in 8.5. The CurlHandle is
        // released when the function returns.

        $data = json_decode(is_string($body) ? $body : '', true);
        if ($status < 200 || $status >= 300) {
            throw self::apiError('Request', $status, is_array($data) ? $data : [], $headers);
        }
        if (!is_array($data)) {
            throw new Conv2pdfException("Invalid JSON response (HTTP $status).", $status, null);
        }
        return $data;
    }

    /**
     * Builds the exception for a non-2xx response: the stable code and the payload from the
     * JSON body when there is one, Retry-After from the headers when the server set it.
     *
     * @param array<string, mixed>  $payload
     * @param array<string, string> $headers Lower-cased names.
     */
    private static function apiError(string $what, int $status, array $payload, array $headers): Conv2pdfException
    {
        $code = isset($payload['error']) && is_scalar($payload['error']) ? (string) $payload['error'] : null;
        $retryAfter = isset($headers['retry-after']) && ctype_digit($headers['retry-after'])
            ? (int) $headers['retry-after']
            : null;
        return new Conv2pdfException(
            "$what failed (HTTP $status)" . ($code !== null ? ": $code" : '') . '.',
            $status,
            $code,
            $payload,
            $retryAfter
        );
    }

    /**
     * Normalises a file entry of convert() into what curl can post.
     *
     * @param mixed $entry
     * @return \CURLFile|\CURLStringFile
     */
    private static function toCurlFile($entry): object
    {
        // \CURLStringFile only exists from PHP 8.1: on 8.0 the instanceof is simply false.
        if ($entry instanceof \CURLFile || $entry instanceof \CURLStringFile) {
            return $entry;
        }
        $name = null;
        if (is_array($entry)) {
            $name = $entry['name'] ?? null;
            $entry = $entry['path'] ?? null;
        }
        if (!is_string($entry) || !is_file($entry)) {
            throw new \InvalidArgumentException('File not found: ' . (is_string($entry) ? $entry : gettype($entry)));
        }
        $file = new \CURLFile($entry);
        if (is_string($name) && $name !== '') {
            $file->setPostFilename($name);
        }
        return $file;
    }

    /**
     * Timeouts shared by every request, under the options given.
     *
     * @param array<int, mixed> $options
     * @return array<int, mixed>
     */
    private function curlOptions(array $options): array
    {
        $defaults = [
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $this->timeout,
        ];
        if ($this->stallTimeout > 0) {
            // Abort when fewer than one byte per second moved, in either direction, for
            // $stallTimeout seconds. curl measures that speed over a five-second window, so
            // the abort comes about five seconds later than the figure says. The clock also
            // runs while the server is converting, hence a value above the API's own 90 s
            // processing budget.
            $defaults[CURLOPT_LOW_SPEED_LIMIT] = 1;
            $defaults[CURLOPT_LOW_SPEED_TIME] = $this->stallTimeout;
        }
        return $options + $defaults;
    }

    /**
     * @param string[] $extra
     * @return string[]
     */
    private function requestHeaders(array $extra = []): array
    {
        return array_merge([
            'Authorization: Bearer ' . $this->apiKey,
            'User-Agent: ' . self::userAgent(),
            // curl would otherwise send `Expect: 100-continue` on a multipart POST and wait
            // up to a second for a reply the API's front proxy answers itself. Nothing is
            // gained: the request body is read in full before the API sees the request.
            'Expect:',
        ], $extra);
    }

    /**
     * Collects response headers as they arrive, lower-cased. Redirects are not followed,
     * so the headers are those of the final answer.
     *
     * @param array<string, string> $headers
     */
    private static function headerCollector(array &$headers): callable
    {
        return static function ($ch, string $line) use (&$headers): int {
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
            return strlen($line);
        };
    }

    /**
     * Resolves the download URL from a download_url (path or absolute) or a bare job_id.
     */
    private function resolveDownloadUrl(string $jobIdOrUrl): string
    {
        if (strpos($jobIdOrUrl, '://') !== false) {
            return $jobIdOrUrl;
        }
        if ($jobIdOrUrl !== '' && $jobIdOrUrl[0] === '/') {
            // The download_url returned by the API is a path from the host root, e.g. "/v1/download/abc".
            $p = parse_url($this->baseUrl);
            $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')
                . (isset($p['port']) ? ':' . $p['port'] : '');
            return $origin . $jobIdOrUrl;
        }
        return $this->baseUrl . '/download/' . rawurlencode($jobIdOrUrl);
    }
}
