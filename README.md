# conv2pdf/php

SDK PHP officiel de l'[API conv2pdf](https://conv2pdf.com/api/) : conversion et manipulation de PDF (14 outils), **hébergée en France**, RGPD, DPA fourni. Wrapper mince, **zéro dépendance** (uniquement `ext-curl`).

## Installation

```bash
composer require conv2pdf/php
```

Prérequis : PHP 8.0 à 8.5 (chaque version est testée en CI), extension `curl`. [Obtenez une clé API gratuite](https://conv2pdf.com/api/) (plan Dev : 300 conversions/mois, sans carte).

## Démarrage rapide

```php
use Conv2pdf\Conv2pdf;

$c = new Conv2pdf('cpdf_live_...');

$job = $c->convert('pdf-to-word', 'rapport.pdf');
$c->download($job['download_url'], 'rapport.docx');
```

## Utilisation

### Conversions avec options

```php
$c->convert('compress-pdf', 'gros.pdf', ['quality' => 'high']);      // low | medium | high
$c->convert('split-pdf',    'doc.pdf',  ['ranges' => '1-5,7,10-12']);
$c->convert('rotate-pdf',   'doc.pdf',  ['rotation' => 90]);          // 90 | 180 | 270
$c->convert('watermark-pdf','doc.pdf',  ['text' => 'CONFIDENTIEL']);
$c->convert('protect-pdf',  'doc.pdf',  ['password' => 'secret', 'prevent_print' => 'on']);
```

### Fusion (2 à 20 fichiers)

```php
$job = $c->convert('merge-pdf', ['a.pdf', 'b.pdf', 'c.pdf']);
$c->download($job['download_url'], 'fusion.pdf');
```

### Jobs

```php
$meta = $c->job($job['job_id']);     // métadonnées d'un job terminé (tool, taille, expiration)
$c->deleteJob($job['job_id']);       // suppression immédiate (sinon purge auto à 1 h)
```

### Liste des outils

```php
foreach ($c->tools()['tools'] as $tool) {
    echo $tool['id'], ' ', implode(',', $tool['accepted_exts']), "\n";
}
```

### Gestion des erreurs

Toute erreur API (4xx/5xx) ou réseau lève une `Conv2pdf\Conv2pdfException`.

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

## Confidentialité

Traitement sur des serveurs OVH en France, aucun service hors UE, pas de Cloud Act. Fichiers d'entrée et de sortie supprimés au bout d'une heure, aucun résultat mis en cache. DPA fourni sur demande.

## Ressources

- Documentation : <https://conv2pdf.com/api/documentation/>
- Spécification OpenAPI : <https://conv2pdf.com/openapi.json>
- Collection Postman : <https://conv2pdf.com/conv2pdf.postman_collection.json>

## Licence

MIT — voir [LICENSE](LICENSE).
