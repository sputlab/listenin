<?php
/**
 * Pagine legali renderizzate lato server (consultabili anche senza JavaScript).
 */
declare(strict_types=1);

require (require __DIR__ . '/app-path.php') . '/src/bootstrap.php';

use ListenIn\Core\Config;
use ListenIn\Core\Security;

Security::sendHeaders();
header('Content-Type: text/html; charset=utf-8');

$docs = ['disclaimer' => 'Disclaimer e avvertenze', 'termini' => 'Termini di utilizzo', 'privacy' => 'Informativa privacy'];
$doc = is_string($_GET['doc'] ?? null) && isset($docs[$_GET['doc']]) ? $_GET['doc'] : 'disclaimer';
$e = [Security::class, 'e'];
$name = Config::get('app.name', 'ListenIn');
// Contenuto statico di fiducia, versionato con il codice (non contiene input utente)
$html = (string) file_get_contents(LISTENIN_ROOT . '/src/Content/' . $doc . '.html');
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($docs[$doc]) ?> · <?= $e($name) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= $e(LISTENIN_VERSION) ?>">
</head>
<body class="page-doc">
<main class="doc">
  <p><a href="./">← Torna a <?= $e($name) ?></a></p>
  <h1><?= $e($docs[$doc]) ?></h1>
  <?php if ($doc === 'disclaimer'): ?>
  <p class="doc-meta">Versione <?= $e(Config::get('legal.disclaimer_version')) ?></p>
  <?php endif; ?>
  <div class="prose"><?= $html ?></div>
</main>
</body>
</html>
