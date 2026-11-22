<?php
/**
 * ListenIn — shell dell'applicazione (frontend vanilla JS a moduli ES).
 * Nessuno script o stile inline: compatibile con la CSP restrittiva.
 */
declare(strict_types=1);

require (require __DIR__ . '/app-path.php') . '/src/bootstrap.php';

use ListenIn\Core\Config;
use ListenIn\Core\Security;
use ListenIn\Core\Session;

Security::sendHeaders();
Session::start();
Security::csrfToken();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');

$e = [Security::class, 'e'];
$name = Config::get('app.name', 'ListenIn');
$v = LISTENIN_VERSION;
// Percorso base dell'applicazione (es. /webapp/listenin/): serve per gli URL parlanti, dove la pagina può trovarsi
// più in profondità (/frequenze/lombardia/milano/dmr) e quindi gli indirizzi relativi non basterebbero.
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/') . '/';
$reqPath = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');
$rel = str_starts_with($reqPath, $base) ? substr($reqPath, strlen($base)) : '';
$title = $name . ' · ' . Config::get('app.tagline');
if (preg_match('#^frequenze/([a-z0-9-]+)(?:/([a-z0-9-]+))?(?:/([a-z0-9-]+))?/?$#', $rel, $m)) {
    $parts = array_filter(array_slice($m, 1), static fn($x) => $x !== '' && !in_array($x, ['italia', 'tutte'], true));
    if ($parts) {
        $title = 'Frequenze ' . implode(' · ', array_map(static fn($x) => ucwords(str_replace('-', ' ', $x)), $parts)) . ' · ' . $name;
    }
}
?><!doctype html>
<html lang="it">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?></title>
<meta name="rrf-base" content="<?= $e($base) ?>">
<meta name="theme-color" content="#16324F">
<link rel="manifest" href="<?= $e($base) ?>manifest.webmanifest">
<link rel="apple-touch-icon" href="<?= $e($base) ?>assets/icons/apple-touch-icon.png">
<meta name="description" content="Consultazione, censimento e aggiornamento collaborativo delle frequenze radio: ponti ripetitori, emergenza, aeronautico, marittimo, utility.">
<meta name="color-scheme" content="light dark">
<script src="<?= $e($base) ?>assets/js/theme-init.js?v=<?= $e($v) ?>"></script>
<link rel="icon" href="<?= $e($base) ?>assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= $e($base) ?>assets/css/app.css?v=<?= $e($v) ?>">
<script type="module" src="<?= $e($base) ?>assets/js/app.js?v=<?= $e($v) ?>"></script>
</head>
<body>
<a class="skip" href="#main">Vai ai risultati</a>
<header class="masthead">
  <a class="brand" href="#/" aria-label="<?= $e($name) ?>, pagina iniziale">
    <svg class="brand-mark" viewBox="0 0 32 32" aria-hidden="true"><path d="M2 22 L8 22 L10 12 L13 27 L16 6 L19 25 L22 15 L24 22 L30 22" /></svg>
    <span class="brand-name"><?= $e($name) ?></span>
  </a>
  <button type="button" class="burger" id="burger" aria-expanded="false" aria-controls="usernav mainnav" aria-label="Apri il menu">
    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
  </button>
  <form class="search" role="search" id="search-form">
    <label for="q" class="visually-hidden">Cerca per frequenza, nominativo, ente o località</label>
    <input id="q" name="q" type="search" autocomplete="off" spellcheck="false"
           placeholder="Frequenza, nominativo, ente o località">
  </form>
  <nav class="usernav" id="usernav" aria-label="Account"></nav>
</header>

<nav class="subnav" id="mainnav" aria-label="Sezioni">
  <a href="#/" aria-current="page">Frequenze</a>
  <a href="#/missioni">Missioni di recupero</a>
  <a href="#/postazioni">Postazioni</a>
  <a href="#/notizie">Notizie</a>
  <a href="#/statistiche">Statistiche</a>
  <a href="#/opendata">Open data</a>
  <a href="#/offline">Offline</a>
</nav>

<section class="spectrum" aria-label="Distribuzione dei risultati nello listenin">
  <div class="spectrum-track" id="spectrum"></div>
</section>

<div class="layout">
  <aside class="filters" id="filters" aria-label="Filtri">
    <div class="filters-head">
      <h2>Filtri</h2>
      <button type="button" class="link" id="filters-reset">Azzera</button>
      <button type="button" class="icon-btn filters-close" id="filters-close" aria-label="Chiudi filtri">×</button>
    </div>
    <form id="filter-form" class="filter-form"></form>
    <div class="filter-tools" id="filter-tools"></div>
  </aside>
  <main id="main" class="results" tabindex="-1">
    <div id="view"></div>
  </main>
</div>

<footer class="sitefoot">
  <p><?= $e($name) ?> <span class="ver">versione <?= $e($v) ?></span></p>
  <div class="theme-switch" id="theme-switch"></div>
  <p class="foot-note">Dati aperti, curati dalla comunità di radioascolto.</p>
  <nav aria-label="Note legali">
    <a href="#/opendata">Open data</a>
    <a href="#/notizie">Notizie</a>
    <a href="<?= $e($base) ?>pagina.php?doc=disclaimer" data-doc="disclaimer">Disclaimer</a>
    <a href="<?= $e($base) ?>pagina.php?doc=termini" data-doc="termini">Termini</a>
    <a href="<?= $e($base) ?>pagina.php?doc=privacy" data-doc="privacy">Privacy</a>
  </nav>
</footer>

<dialog id="modal" class="modal" aria-labelledby="modal-title">
  <div class="modal-inner" id="modal-body"></div>
</dialog>
<div class="toasts" id="toasts" role="status" aria-live="polite"></div>
<noscript><p class="noscript">Questa applicazione richiede JavaScript. Il disclaimer è consultabile a <a href="<?= $e($base) ?>pagina.php?doc=disclaimer">questa pagina</a>.</p></noscript>
</body>
</html>
