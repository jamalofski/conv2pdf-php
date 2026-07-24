<?php

/**
 * Smoke test : exécute tout le code du SDK et échoue si PHP émet le moindre
 * diagnostic (Deprecated, Warning, Notice). Ce n'est pas un test fonctionnel —
 * aucune clé API, aucun appel à conv2pdf.com.
 *
 * Le client pointe sur un port local fermé : curl échoue immédiatement, mais
 * curl_init/setopt/exec/getinfo, l'encodage multipart CURLFile et les trois
 * formes d'URL de téléchargement ont bien été exécutés.
 *
 * Usage : php -d error_reporting=E_ALL tests/smoke.php
 */

declare(strict_types=1);

$diagnostics = [];

// Installé AVANT les require : certaines dépréciations sont émises à la
// compilation du fichier, pas à l'exécution.
set_error_handler(function (int $no, string $msg, string $file, int $line) use (&$diagnostics): bool {
    $diagnostics[] = sprintf('%s:%d — [%d] %s', basename($file), $line, $no, $msg);
    return true;
});

require __DIR__ . '/../src/Conv2pdfException.php';
require __DIR__ . '/../src/Conv2pdf.php';

use Conv2pdf\Conv2pdf;
use Conv2pdf\Conv2pdfException;

$failures = [];

/** Exécute $fn et vérifie qu'elle lève bien $expected. */
$expect = function (string $label, string $expected, callable $fn) use (&$failures): void {
    try {
        $fn();
        $failures[] = "$label : aucune exception, attendu $expected";
    } catch (Throwable $e) {
        if (!($e instanceof $expected)) {
            $failures[] = "$label : " . get_class($e) . ' au lieu de ' . $expected . ' (' . $e->getMessage() . ')';
        }
    }
};

$pdf = tempnam(sys_get_temp_dir(), 'smoke') ?: '';
file_put_contents($pdf, "%PDF-1.4\n%%EOF\n");
$out = tempnam(sys_get_temp_dir(), 'smoke') ?: '';

$c = new Conv2pdf('cpdf_live_smoke', 'http://127.0.0.1:9/v1', 2);

// Endpoints JSON : GET, POST multipart (1 fichier puis N), DELETE.
$expect('tools()', Conv2pdfException::class, function () use ($c): void { $c->tools(); });
$expect('convert() 1 fichier', Conv2pdfException::class, function () use ($c, $pdf): void { $c->convert('pdf-to-word', $pdf); });
$expect('convert() N fichiers', Conv2pdfException::class, function () use ($c, $pdf): void { $c->convert('merge-pdf', [$pdf, $pdf]); });
$expect('convert() + champs', Conv2pdfException::class, function () use ($c, $pdf): void { $c->convert('rotate-pdf', $pdf, ['rotation' => 90]); });
$expect('job()', Conv2pdfException::class, function () use ($c): void { $c->job('abc'); });
$expect('deleteJob()', Conv2pdfException::class, function () use ($c): void { $c->deleteJob('abc'); });

// download() : URL absolue, chemin depuis la racine, job_id nu.
foreach (['http://127.0.0.1:9/v1/download/abc', '/v1/download/abc', 'abc'] as $target) {
    $expect("download($target)", Conv2pdfException::class, function () use ($c, $target, $out): void { $c->download($target, $out); });
}

// Base URL non parsable : exerce la branche parse_url() en échec.
$broken = new Conv2pdf('cpdf_live_smoke', '///:pas-une-url', 2);
$expect('download() base cassée', Conv2pdfException::class, function () use ($broken, $out): void { $broken->download('/v1/download/abc', $out); });

// Validation des arguments.
$expect('convert() sans fichier', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', []); });
$expect('convert() fichier absent', InvalidArgumentException::class, function () use ($c): void { $c->convert('pdf-to-word', '/introuvable.pdf'); });
$expect('constructeur sans clé', InvalidArgumentException::class, function (): void { new Conv2pdf(''); });

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
    echo 'ÉCHEC       ', $f, "\n";
}

$total = count($diagnostics) + count($failures);
if ($total > 0) {
    echo "\nsmoke KO : ", count($diagnostics), " diagnostic(s), ", count($failures), " échec(s)\n";
    exit(1);
}

echo "smoke OK : aucun diagnostic, tous les chemins exercés\n";
