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
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    /**
     * @param string $apiKey  API key in the cpdf_live_... format (from the conv2pdf dashboard).
     * @param string $baseUrl API root (defaults to production).
     * @param int    $timeout Request timeout, in seconds.
     */
    public function __construct(string $apiKey, string $baseUrl = 'https://api.conv2pdf.com/v1', int $timeout = 120)
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('API key required.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Lists the available tools, their file size limits and accepted extensions.
     *
     * @return array{tools: array<int, array<string, mixed>>}
     */
    public function tools(): array
    {
        return $this->requestJson('GET', '/tools');
    }

    /**
     * Converts or manipulates a file through POST /convert/{tool}.
     *
     * @param string          $tool   Tool identifier (e.g. 'pdf-to-word', 'merge-pdf', 'compress-pdf').
     * @param string|string[] $files  A file path, or an array of paths (merge-pdf: 2 to 20 files).
     * @param array<string, string|int> $fields  Extra fields, depending on the tool, e.g. ['ranges' => '1-5'],
     *                                            ['quality' => 'high'], ['password' => '...'], ['rotation' => 90].
     * @return array{job_id: string, status: string, download_url: string, size_bytes: int, quota?: array<string, mixed>}
     * @throws Conv2pdfException On an API error (4xx/5xx) or a network error.
     * @throws \InvalidArgumentException If no file is given, or a path does not exist.
     */
    public function convert(string $tool, $files, array $fields = []): array
    {
        $paths = is_array($files) ? array_values($files) : [$files];
        if ($paths === []) {
            throw new \InvalidArgumentException('At least one file is required.');
        }

        $post = [];
        foreach ($paths as $i => $path) {
            if (!is_string($path) || !is_file($path)) {
                throw new \InvalidArgumentException('File not found: ' . (is_string($path) ? $path : gettype($path)));
            }
            // The server collects every file part, whatever the field name.
            // A single file goes to "file"; several (merge) go to "file[0]", "file[1]"…
            $key = count($paths) === 1 ? 'file' : "file[$i]";
            $post[$key] = new \CURLFile($path);
        }
        foreach ($fields as $name => $value) {
            $post[$name] = (string) $value;
        }

        return $this->requestJson('POST', '/convert/' . rawurlencode($tool), $post);
    }

    /**
     * Metadata for a finished job (GET /job/{jobId}): tool, size, dates, download_url.
     * Conversions are synchronous, so convert() throws straight away on failure and an
     * unfinished job returns 409. Mostly useful to re-check expiry before downloading.
     *
     * @return array<string, mixed>
     * @throws Conv2pdfException
     */
    public function job(string $jobId): array
    {
        return $this->requestJson('GET', '/job/' . rawurlencode($jobId));
    }

    /**
     * Deletes a job and its file (DELETE /job/{jobId}).
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

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fh,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey],
        ]);
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
            // On error the JSON body ({error:...}) has been written to the file, so the
            // error code is read back before the partial file is removed.
            $code = null;
            $errBody = @file_get_contents($destPath);
            if ($errBody !== false) {
                $d = json_decode($errBody, true);
                if (is_array($d) && isset($d['error'])) {
                    $code = (string) $d['error'];
                }
            }
            @unlink($destPath);
            throw new Conv2pdfException(
                "Download failed (HTTP $status)" . ($code !== null ? ": $code" : '') . '.',
                $status,
                $code
            );
        }
    }

    /**
     * @param array<string, mixed>|null $post Multipart fields (with CURLFile) for a POST, null otherwise.
     * @return array<string, mixed>
     * @throws Conv2pdfException
     */
    private function requestJson(string $method, string $path, ?array $post = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
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
            $code = is_array($data) && isset($data['error']) ? (string) $data['error'] : null;
            throw new Conv2pdfException(
                'Request failed (HTTP ' . $status . ')' . ($code !== null ? ": $code" : '') . '.',
                $status,
                $code
            );
        }
        if (!is_array($data)) {
            throw new Conv2pdfException("Invalid JSON response (HTTP $status).", $status, null);
        }
        return $data;
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
