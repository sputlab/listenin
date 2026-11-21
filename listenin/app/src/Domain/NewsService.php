<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Database;
use ListenIn\Core\Validator;

/** Notizie e aggiornamenti: comunicazioni, novità sui piani di banda, resoconti delle importazioni. */
final class NewsService
{
    public const CATEGORIES = ['comunicazione' => 'Comunicazione', 'bandplan' => 'Piano di banda', 'importazione' => 'Importazione'];

    private static function slugFor(string $title): string
    {
        $base = substr(trim(str_replace(' ', '-', Normalizer::key($title)), '-'), 0, 90) ?: 'notizia';
        $try = $base . '-' . date('Ymd');
        $n = 1;
        while (Database::value('SELECT 1 FROM listenin_news WHERE slug = :s', ['s' => $try])) {
            $try = $base . '-' . date('Ymd') . '-' . (++$n);
        }
        return $try;
    }

    private static function present(array $r, bool $full): array
    {
        $o = ['id' => (int) $r['id'], 'slug' => $r['slug'], 'title' => $r['title'], 'category' => $r['category'], 'category_label' => self::CATEGORIES[$r['category']] ?? $r['category'],
            'status' => $r['status'], 'published_at' => $r['published_at'], 'author' => $r['author'] ?? null,
            'excerpt' => mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $r['body'])), 0, 220, '…')];
        if ($full) {
            $o['body'] = $r['body'];
            $o['updated_at'] = $r['updated_at'];
        }
        return $o;
    }

    private const SELECT = 'SELECT n.*, u.nickname AS author FROM listenin_news n LEFT JOIN listenin_users u ON u.id = n.author_id';

    public static function list(int $page, int $perPage = 10, bool $all = false, ?string $category = null): array
    {
        $where = $all ? '1 = 1' : "n.status = 'published'";
        $params = [];
        if ($category !== null && isset(self::CATEGORIES[$category])) {
            $where .= ' AND n.category = :c';
            $params['c'] = $category;
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM listenin_news n WHERE $where", $params);
        $rows = Database::all(self::SELECT . " WHERE $where ORDER BY COALESCE(n.published_at, n.created_at) DESC, n.id DESC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), $params);
        return ['items' => array_map(static fn($r) => self::present($r, false), $rows), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function show(string $slug, bool $all = false): array
    {
        $r = Database::one(self::SELECT . ' WHERE n.slug = :s' . ($all ? '' : " AND n.status = 'published'"), ['s' => $slug]) ?? throw ApiException::notFound('Notizia non trovata.');
        return self::present($r, true);
    }

    private static function validate(array $in, ?array $current = null): array
    {
        $v = new Validator($in);
        $title = $v->string('title', 3, 160, $current === null) ?? $current['title'];
        $body = $v->string('body', 3, 8000, $current === null, true) ?? $current['body'];
        $category = $v->enum('category', array_keys(self::CATEGORIES), false) ?? ($current['category'] ?? 'comunicazione');
        $status = $v->enum('status', ['draft', 'published'], false) ?? ($current['status'] ?? 'draft');
        $v->throwIfErrors();
        return [$title, $body, $category, $status];
    }

    public static function create(int $authorId, array $in): array
    {
        [$title, $body, $category, $status] = self::validate($in);
        $id = Database::insert('INSERT INTO listenin_news (slug, title, body, category, status, published_at, author_id) VALUES (:s, :t, :b, :c, :st, :p, :a)',
            ['s' => self::slugFor($title), 't' => $title, 'b' => $body, 'c' => $category, 'st' => $status, 'p' => $status === 'published' ? date('Y-m-d H:i:s') : null, 'a' => $authorId]);
        ModerationLog::add($authorId, 'news.create', 'news', $id, $title);
        return self::show((string) Database::value('SELECT slug FROM listenin_news WHERE id = :id', ['id' => $id]), true);
    }

    public static function update(int $id, int $actorId, array $in): array
    {
        $cur = Database::one('SELECT * FROM listenin_news WHERE id = :id', ['id' => $id]) ?? throw ApiException::notFound('Notizia non trovata.');
        [$title, $body, $category, $status] = self::validate($in, $cur);
        Database::run('UPDATE listenin_news SET title = :t, body = :b, category = :c, status = :st, published_at = :p WHERE id = :id',
            ['t' => $title, 'b' => $body, 'c' => $category, 'st' => $status, 'id' => $id,
                'p' => $status === 'published' ? ($cur['published_at'] ?? date('Y-m-d H:i:s')) : $cur['published_at']]);
        ModerationLog::add($actorId, 'news.update', 'news', $id, $title);
        return self::show($cur['slug'], true);
    }

    public static function delete(int $id, int $actorId): void
    {
        $cur = Database::one('SELECT title FROM listenin_news WHERE id = :id', ['id' => $id]) ?? throw ApiException::notFound('Notizia non trovata.');
        Database::run('DELETE FROM listenin_news WHERE id = :id', ['id' => $id]);
        ModerationLog::add($actorId, 'news.delete', 'news', $id, $cur['title']);
    }

    /** Bozza di resoconto a partire dal risultato di un'importazione. */
    public static function draftFromImport(int $authorId, array $job): array
    {
        $s = (array) ($job['stats'] ?? []);
        $labels = ['righe_lette' => 'Righe lette', 'righe_unificate' => 'Righe unificate (duplicati certi)', 'record_risultanti' => 'Record armonizzati', 'nuovi' => 'Nuovi record',
            'aggiornati' => 'Record aggiornati', 'invariati' => 'Record invariati', 'collegati_a_esistenti' => 'Collegati a record esistenti', 'segnalati' => 'Possibili duplicati segnalati ai moderatori'];
        $lines = [];
        foreach ($labels as $k => $label) {
            if (isset($s[$k]) && is_numeric($s[$k])) {
                $lines[] = $label . ': ' . number_format((float) $s[$k], 0, ',', '.');
            }
        }
        $src = (string) ($job['source'] ?? 'altra fonte');
        $body = "Importazione dei dati dalla fonte «{$src}» completata" . (!empty($job['dry_run']) ? ' (simulazione)' : '') . ".\n\n" . ($lines ? implode("\n", $lines) : 'Nessun dato di riepilogo disponibile.')
            . "\n\nI dati sono stati armonizzati e privati dei duplicati; le frequenze importate partono come «da verificare» finché la comunità non ne conferma l'ascolto.";
        return self::create($authorId, ['title' => 'Importazione dati: ' . $src, 'body' => $body, 'category' => 'importazione', 'status' => 'draft']);
    }
}
