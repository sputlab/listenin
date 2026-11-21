<?php
declare(strict_types=1);

namespace ListenIn\Controllers;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Config;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Request;
use ListenIn\Core\Response;
use ListenIn\Core\Security;
use ListenIn\Domain\NewsService;
use ListenIn\Import\ImportEngine;

/** Notizie e aggiornamenti: lettura pubblica, feed e gestione per gli amministratori. */
final class NewsController
{
    public static function index(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        Response::json(NewsService::list($r->queryInt('page', 1, 1, 1000), $r->queryInt('per_page', 10, 1, 30), false, $r->queryString('category', 20)));
    }

    public static function show(Request $r): void
    {
        RateLimiter::hit('api_read', Security::clientIp());
        $slug = (string) $r->queryString('slug', 120);
        if (!preg_match('/^[a-z0-9-]{1,120}$/', $slug)) {
            throw ApiException::notFound('Notizia non trovata.');
        }
        Response::json(NewsService::show($slug));
    }

    public static function feed(Request $r): void
    {
        RateLimiter::hit('feed', Security::clientIp());
        $atom = $r->queryString('format', 4) === 'atom';
        $items = NewsService::list(1, 30)['items'];
        $full = array_map(static fn($i) => NewsService::show($i['slug']), $items);
        $base = rtrim((string) Config::get('app.base_url'), '/');
        $name = (string) Config::get('app.name', 'ListenIn');
        $e = static fn($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('Content-Type: ' . ($atom ? 'application/atom+xml' : 'application/rss+xml') . '; charset=utf-8');
        header('Cache-Control: public, max-age=300');
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $title = $name . ' · notizie e aggiornamenti';
        if ($atom) {
            echo '<feed xmlns="http://www.w3.org/2005/Atom"><title>' . $e($title) . '</title><id>' . $e($base . '/news') . '</id><link href="' . $e($base . '/#/notizie') . '"/><updated>'
                . $e(date('c', strtotime($full[0]['published_at'] ?? 'now'))) . '</updated>';
            foreach ($full as $n) {
                echo '<entry><title>' . $e($n['title']) . '</title><id>' . $e($base . '/#/notizia/' . $n['slug']) . '</id><link href="' . $e($base . '/#/notizia/' . $n['slug']) . '"/><updated>'
                    . $e(date('c', strtotime($n['published_at']))) . '</updated><category term="' . $e($n['category']) . '"/><summary>' . $e($n['excerpt']) . '</summary><content type="text">' . $e($n['body']) . '</content></entry>';
            }
            echo '</feed>';
        } else {
            echo '<rss version="2.0"><channel><title>' . $e($title) . '</title><link>' . $e($base . '/#/notizie') . '</link><description>' . $e((string) Config::get('app.tagline')) . '</description><language>it</language>';
            foreach ($full as $n) {
                echo '<item><title>' . $e($n['title']) . '</title><link>' . $e($base . '/#/notizia/' . $n['slug']) . '</link><guid isPermaLink="false">' . $e($base . '/#/notizia/' . $n['slug']) . '</guid>'
                    . '<pubDate>' . $e(date(DATE_RSS, strtotime($n['published_at']))) . '</pubDate><category>' . $e($n['category_label']) . '</category><description>' . $e($n['body']) . '</description></item>';
            }
            echo '</channel></rss>';
        }
        exit;
    }

    // ---------------- amministratori
    public static function adminList(Request $r): void
    {
        Auth::requireRole('admin');
        Response::json(NewsService::list($r->queryInt('page', 1, 1, 1000), 25, true));
    }

    public static function adminShow(Request $r): void
    {
        Auth::requireRole('admin');
        Response::json(NewsService::show((string) $r->queryString('slug', 120), true));
    }

    public static function create(Request $r): void
    {
        $u = Auth::requireRole('admin');
        Response::json(NewsService::create((int) $u['id'], $r->json()) + ['message' => 'Notizia salvata.'], 201);
    }

    public static function update(Request $r, array $p): void
    {
        $u = Auth::requireRole('admin');
        Response::json(NewsService::update($p['id'], (int) $u['id'], $r->json()) + ['message' => 'Notizia aggiornata.']);
    }

    public static function delete(Request $r, array $p): void
    {
        $u = Auth::requireRole('admin');
        NewsService::delete($p['id'], (int) $u['id']);
        Response::json(['message' => 'Notizia eliminata.']);
    }

    /** Crea una bozza di resoconto a partire dal risultato di un'importazione. */
    public static function fromImport(Request $r): void
    {
        $u = Auth::requireRole('admin');
        $job = ImportEngine::load((string) ($r->json()['job'] ?? ''));
        if (($job['status'] ?? '') !== 'done') {
            throw ApiException::validation(['job' => 'L\'importazione non è ancora completata.']);
        }
        Response::json(NewsService::draftFromImport((int) $u['id'], $job) + ['message' => 'Bozza creata: rivedila e pubblicala dalla scheda Notizie.'], 201);
    }
}
