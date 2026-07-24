<?php

declare(strict_types=1);

namespace Conv2pdf;

/**
 * Client PHP de l'API conv2pdf — conversion et manipulation de PDF, hébergée en France.
 *
 * Exemple :
 *   $c = new Conv2pdf('cpdf_live_...');
 *   $job = $c->convert('pdf-to-word', 'rapport.pdf');
 *   $c->download($job['download_url'], 'sortie.docx');
 *
 * Zéro dépendance (ext-curl uniquement). Contrat REST : https://conv2pdf.com/openapi.json
 */
final class Conv2pdf
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    /**
     * @param string $apiKey  Clé API au format cpdf_live_... (tableau de bord conv2pdf).
     * @param string $baseUrl Racine de l'API (par défaut la production).
     * @param int    $timeout Timeout des requêtes, en secondes.
     */
    public function __construct(string $apiKey, string $baseUrl = 'https://api.conv2pdf.com/v1', int $timeout = 120)
    {
        if ($apiKey === '') {
            throw new \InvalidArgumentException('Clé API requise.');
        }
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeout = $timeout;
    }

    /**
     * Liste des outils disponibles, leurs bornes de fichiers et extensions acceptées.
     *
     * @return array{tools: array<int, array<string, mixed>>}
     */
    public function tools(): array
    {
        return $this->requestJson('GET', '/tools');
    }

    /**
     * Convertit ou manipule un fichier via POST /convert/{tool}.
     *
     * @param string          $tool   Identifiant de l'outil (ex. 'pdf-to-word', 'merge-pdf', 'compress-pdf').
     * @param string|string[] $files  Chemin du fichier, ou tableau de chemins (merge-pdf : 2 à 20 fichiers).
     * @param array<string, string|int> $fields  Champs additionnels selon l'outil, ex. ['ranges' => '1-5'],
     *                                            ['quality' => 'high'], ['password' => '...'], ['rotation' => 90].
     * @return array{job_id: string, status: string, download_url: string, size_bytes: int, quota?: array<string, mixed>}
     * @throws Conv2pdfException En cas d'erreur API (4xx/5xx) ou réseau.
     * @throws \InvalidArgumentException Si aucun fichier n'est fourni ou qu'un chemin est introuvable.
     */
    public function convert(string $tool, $files, array $fields = []): array
    {
        $paths = is_array($files) ? array_values($files) : [$files];
        if ($paths === []) {
            throw new \InvalidArgumentException('Au moins un fichier est requis.');
        }

        $post = [];
        foreach ($paths as $i => $path) {
            if (!is_string($path) || !is_file($path)) {
                throw new \InvalidArgumentException('Fichier introuvable : ' . (is_string($path) ? $path : gettype($path)));
            }
            // Le serveur collecte toutes les parts « fichier » quel que soit leur nom de champ.
            // Un seul fichier → champ « file » ; plusieurs (merge) → « file[0] », « file[1] »…
            $key = count($paths) === 1 ? 'file' : "file[$i]";
            $post[$key] = new \CURLFile($path);
        }
        foreach ($fields as $name => $value) {
            $post[$name] = (string) $value;
        }

        return $this->requestJson('POST', '/convert/' . rawurlencode($tool), $post);
    }

    /**
     * Métadonnées d'un job terminé (GET /job/{jobId}) : tool, taille, dates, download_url.
     * Les conversions étant synchrones, convert() lève directement en cas d'échec ; un job
     * non abouti renvoie 409. Utile surtout pour re-vérifier l'expiration avant téléchargement.
     *
     * @return array<string, mixed>
     * @throws Conv2pdfException
     */
    public function job(string $jobId): array
    {
        return $this->requestJson('GET', '/job/' . rawurlencode($jobId));
    }

    /**
     * Supprime un job et son fichier (DELETE /job/{jobId}).
     *
     * @return array<string, mixed>
     * @throws Conv2pdfException
     */
    public function deleteJob(string $jobId): array
    {
        return $this->requestJson('DELETE', '/job/' . rawurlencode($jobId));
    }

    /**
     * Télécharge le résultat d'une conversion vers un fichier local (GET /download/{jobId}).
     * Disponible une heure après la conversion.
     *
     * @param string $jobIdOrUrl Le download_url renvoyé par convert(), ou un job_id nu.
     * @param string $destPath   Chemin de destination local.
     * @throws Conv2pdfException En cas d'erreur API/réseau (le fichier partiel est supprimé).
     * @throws \RuntimeException Si le fichier de destination ne peut pas être ouvert en écriture.
     */
    public function download(string $jobIdOrUrl, string $destPath): void
    {
        $url = $this->resolveDownloadUrl($jobIdOrUrl);
        $fh = fopen($destPath, 'wb');
        if ($fh === false) {
            throw new \RuntimeException("Impossible d'écrire le fichier de destination : $destPath");
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
        curl_close($ch);
        fclose($fh);

        if ($ok === false) {
            @unlink($destPath);
            throw new Conv2pdfException("Erreur réseau au téléchargement : $err", 0, null);
        }
        if ($status < 200 || $status >= 300) {
            // Sur erreur, le corps JSON ({error:...}) a été écrit dans le fichier : on
            // en extrait le code d'erreur avant de supprimer le fichier partiel.
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
                "Téléchargement échoué (HTTP $status)" . ($code !== null ? " : $code" : '') . '.',
                $status,
                $code
            );
        }
    }

    /**
     * @param array<string, mixed>|null $post Champs multipart (avec CURLFile) pour un POST, null sinon.
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
            // Tableau contenant un CURLFile → curl encode en multipart/form-data automatiquement.
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Conv2pdfException("Erreur réseau : $err", 0, null);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $data = json_decode(is_string($body) ? $body : '', true);
        if ($status < 200 || $status >= 300) {
            $code = is_array($data) && isset($data['error']) ? (string) $data['error'] : null;
            throw new Conv2pdfException(
                'Requête échouée (HTTP ' . $status . ')' . ($code !== null ? " : $code" : '') . '.',
                $status,
                $code
            );
        }
        if (!is_array($data)) {
            throw new Conv2pdfException("Réponse JSON invalide (HTTP $status).", $status, null);
        }
        return $data;
    }

    /**
     * Résout l'URL de téléchargement à partir d'un download_url (chemin ou absolu) ou d'un job_id nu.
     */
    private function resolveDownloadUrl(string $jobIdOrUrl): string
    {
        if (strpos($jobIdOrUrl, '://') !== false) {
            return $jobIdOrUrl;
        }
        if ($jobIdOrUrl !== '' && $jobIdOrUrl[0] === '/') {
            // download_url renvoyé par l'API = chemin depuis la racine de l'hôte, ex. « /v1/download/abc ».
            $p = parse_url($this->baseUrl);
            $origin = ($p['scheme'] ?? 'https') . '://' . ($p['host'] ?? '')
                . (isset($p['port']) ? ':' . $p['port'] : '');
            return $origin . $jobIdOrUrl;
        }
        return $this->baseUrl . '/download/' . rawurlencode($jobIdOrUrl);
    }
}
