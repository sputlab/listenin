<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Config;
use ListenIn\Core\Database;

/**
 * Ogni variazione del dataset passa da qui e lascia una revisione con il
 * dettaglio dei campi modificati, autore, timestamp e stato di approvazione.
 */
final class RevisionService
{
    /**
     * Registra una proposta. Con $apply = true viene approvata subito (moderatori, import).
     * @return array{revision_id:int,status:string,frequency_id:?int,duplicates:array}
     */
    public static function propose(
        ?int $authorId,
        string $action,
        ?int $frequencyId,
        array $logical,
        ?string $comment,
        string $origin = 'user',
        bool $apply = false,
        ?int $reviewerId = null,
        ?int $reportId = null,
        array $importMeta = []
    ): array {
        return Database::transaction(function () use ($authorId, $action, $frequencyId, $logical, $comment, $origin, $apply, $reviewerId, $reportId, $importMeta) {
            $baseVersion = null;
            $changes = [];
            $duplicates = [];

            if ($action === 'create') {
                foreach (FrequencyFields::REQUIRED_ON_CREATE as $req) {
                    if (($logical[$req] ?? null) === null) {
                        throw ApiException::validation([$req => 'Campo obbligatorio.']);
                    }
                }
                foreach ($logical as $field => $value) {
                    if ($value !== null) {
                        $changes[$field] = [null, FrequencyFields::toText($value)];
                    }
                }
                $duplicates = FrequencyRepository::possibleDuplicates((int) $logical['freq_hz'], $logical['region'] ?? null);
            } else {
                $row = Database::one('SELECT id, version, deleted_at FROM listenin_frequencies WHERE id = :id FOR UPDATE', ['id' => $frequencyId])
                    ?? throw ApiException::notFound('Frequenza non trovata.');
                $baseVersion = (int) $row['version'];
                $current = FrequencyRepository::logical(FrequencyRepository::find((int) $frequencyId, true));

                if ($action === 'update') {
                    if ($row['deleted_at'] !== null) {
                        throw new ApiException(409, 'deleted', 'Il record è stato rimosso: non è modificabile.');
                    }
                    foreach ($logical as $field => $value) {
                        if (!FrequencyFields::same($current[$field] ?? null, $value)) {
                            $changes[$field] = [FrequencyFields::toText($current[$field] ?? null), FrequencyFields::toText($value)];
                        }
                    }
                    if (!$changes) {
                        throw new ApiException(422, 'no_changes', 'Nessuna differenza rispetto ai dati attuali.');
                    }
                } elseif ($action === 'delete') {
                    if ($row['deleted_at'] !== null) {
                        throw new ApiException(409, 'already_deleted', 'Il record è già stato rimosso.');
                    }
                    // Snapshot completo: lo storico conserva cosa è stato rimosso
                    foreach ($current as $field => $value) {
                        if ($value !== null) {
                            $changes[$field] = [FrequencyFields::toText($value), null];
                        }
                    }
                } elseif ($action === 'restore') {
                    if ($row['deleted_at'] === null) {
                        throw new ApiException(409, 'not_deleted', 'Il record è già attivo.');
                    }
                } else {
                    throw new \InvalidArgumentException('Azione non valida');
                }

                // Una sola proposta in attesa per autore e record: la precedente viene sostituita
                if ($authorId !== null && !$apply) {
                    Database::run(
                        "UPDATE listenin_revisions SET status = 'superseded', reviewed_at = NOW()
                          WHERE frequency_id = :f AND author_id = :a AND status = 'pending'",
                        ['f' => $frequencyId, 'a' => $authorId]
                    );
                }
            }

            $revisionId = Database::insert(
                'INSERT INTO listenin_revisions (frequency_id, action, status, origin, base_version, author_id, author_comment, report_id)
                 VALUES (:f, :a, \'pending\', :o, :b, :u, :c, :r)',
                ['f' => $frequencyId, 'a' => $action, 'o' => $origin, 'b' => $baseVersion,
                    'u' => $authorId, 'c' => $comment, 'r' => $reportId]
            );
            foreach ($changes as $field => [$old, $new]) {
                Database::run(
                    'INSERT INTO listenin_revision_changes (revision_id, field, old_value, new_value) VALUES (:r, :f, :o, :n)',
                    ['r' => $revisionId, 'f' => $field, 'o' => $old, 'n' => $new]
                );
            }

            $status = 'pending';
            if ($apply) {
                $frequencyId = self::approve($revisionId, $reviewerId, null, false, $importMeta);
                $status = 'approved';
            }
            return ['revision_id' => $revisionId, 'status' => $status, 'frequency_id' => $frequencyId, 'duplicates' => $duplicates];
        });
    }

    /**
     * Approva e applica una revisione al dataset.
     * Se il record è cambiato dopo la proposta, i campi toccati devono avere ancora
     * il valore "prima" registrato; altrimenti conflitto 409 (salvo $force).
     * @return int id della frequenza
     */
    public static function approve(int $revisionId, ?int $reviewerId, ?string $note, bool $force = false, array $importMeta = []): int
    {
        return Database::transaction(function () use ($revisionId, $reviewerId, $note, $force, $importMeta) {
            $rev = Database::one('SELECT * FROM listenin_revisions WHERE id = :id FOR UPDATE', ['id' => $revisionId])
                ?? throw ApiException::notFound('Revisione non trovata.');
            if ($rev['status'] !== 'pending') {
                throw new ApiException(409, 'not_pending', 'La revisione è già stata gestita.');
            }
            $changes = Database::all('SELECT id, field, old_value, new_value FROM listenin_revision_changes WHERE revision_id = :r', ['r' => $revisionId]);
            $frequencyId = $rev['frequency_id'] !== null ? (int) $rev['frequency_id'] : null;

            if ($rev['action'] === 'create') {
                $logical = [];
                foreach ($changes as $c) {
                    $logical[$c['field']] = FrequencyFields::fromText($c['field'], $c['new_value']);
                }
                // Rivalidazione completa: le tassonomie potrebbero essere cambiate nel frattempo
                $logical = FrequencyFields::validate($logical + array_fill_keys(FrequencyFields::names(), null), false);
                $frequencyId = FrequencyRepository::write(
                    null, $logical, $rev['author_id'] !== null ? (int) $rev['author_id'] : null,
                    $importMeta['source'] ?? 'manuale'
                );
                Database::run('UPDATE listenin_revisions SET frequency_id = :f WHERE id = :id', ['f' => $frequencyId, 'id' => $revisionId]);
                // webhook: le importazioni di massa non generano avvisi (salvo webhooks.include_imports)
                if ($rev['origin'] !== 'import' || Config::get('webhooks.include_imports', false)) {
                    WebhookService::dispatch('created', $frequencyId);
                }
            } else {
                $row = Database::one('SELECT version FROM listenin_frequencies WHERE id = :id FOR UPDATE', ['id' => $frequencyId])
                    ?? throw ApiException::notFound('Frequenza non più esistente.');
                $current = FrequencyRepository::logical(FrequencyRepository::find($frequencyId, true));

                if ($rev['action'] === 'update') {
                    $conflicts = [];
                    if ((int) $row['version'] !== (int) $rev['base_version']) {
                        foreach ($changes as $c) {
                            if (FrequencyFields::toText($current[$c['field']] ?? null) !== $c['old_value']) {
                                $conflicts[] = $c['field'];
                            }
                        }
                    }
                    if ($conflicts && !$force) {
                        throw new ApiException(409, 'conflict',
                            'Il record è stato modificato dopo questa proposta. Verifica i campi in conflitto.',
                            ['conflicts' => $conflicts]);
                    }
                    $logical = [];
                    foreach ($changes as $c) {
                        $logical[$c['field']] = FrequencyFields::fromText($c['field'], $c['new_value']);
                    }
                    $logical = FrequencyFields::validate($logical, true, $current);
                    if ($logical) {
                        FrequencyRepository::write($frequencyId, $logical, null);
                    }
                    // Lo storico riflette i valori effettivamente sostituiti
                    foreach ($changes as $c) {
                        Database::run('UPDATE listenin_revision_changes SET old_value = :o WHERE id = :id',
                            ['o' => FrequencyFields::toText($current[$c['field']] ?? null), 'id' => $c['id']]);
                    }
                    // Campi derivati dall'armonizzazione (es. ingresso da offset) entrano nello storico
                    $known = array_column($changes, 'field');
                    foreach ($logical as $field => $value) {
                        if (!in_array($field, $known, true)) {
                            Database::run('INSERT INTO listenin_revision_changes (revision_id, field, old_value, new_value) VALUES (:r, :f, :o, :n)',
                                ['r' => $revisionId, 'f' => $field, 'o' => FrequencyFields::toText($current[$field] ?? null), 'n' => FrequencyFields::toText($value)]);
                        }
                    }
                } elseif ($rev['action'] === 'delete') {
                    FrequencyRepository::setDeleted($frequencyId, true);
                } elseif ($rev['action'] === 'restore') {
                    FrequencyRepository::setDeleted($frequencyId, false);
                }
            }

            Database::run(
                "UPDATE listenin_revisions SET status = 'approved', reviewer_id = :u, review_note = :n, reviewed_at = NOW() WHERE id = :id",
                ['u' => $reviewerId, 'n' => $note, 'id' => $revisionId]
            );
            if ($reviewerId !== null && $rev['origin'] !== 'moderator') {
                ModerationLog::add($reviewerId, 'revision.approve', 'revision', $revisionId, $note);
            }
            // reputazione dell'autore: Trust Score e punti per le proposte e le segnalazioni accolte
            $author = $rev['author_id'] !== null ? (int) $rev['author_id'] : null;
            TrustService::onApproved($author, (string) $rev['origin']);
            if ($author !== null && $rev['origin'] === 'user') {
                PointsService::award($author, 'proposta', $revisionId);
                PointsService::evaluateBadges($author);
            }
            return $frequencyId;
        });
    }

    public static function reject(int $revisionId, int $reviewerId, ?string $note): void
    {
        Database::transaction(function () use ($revisionId, $reviewerId, $note) {
            $rev = Database::one('SELECT status FROM listenin_revisions WHERE id = :id FOR UPDATE', ['id' => $revisionId])
                ?? throw ApiException::notFound('Revisione non trovata.');
            if ($rev['status'] !== 'pending') {
                throw new ApiException(409, 'not_pending', 'La revisione è già stata gestita.');
            }
            Database::run(
                "UPDATE listenin_revisions SET status = 'rejected', reviewer_id = :u, review_note = :n, reviewed_at = NOW() WHERE id = :id",
                ['u' => $reviewerId, 'n' => $note, 'id' => $revisionId]
            );
            ModerationLog::add($reviewerId, 'revision.reject', 'revision', $revisionId, $note);
            $authorId = Database::value('SELECT author_id FROM listenin_revisions WHERE id = :id', ['id' => $revisionId]);
            TrustService::onRejected($authorId !== null ? (int) $authorId : null);
        });
    }

    /** Dettaglio di una revisione con valori leggibili e stato attuale dei campi. */
    public static function detail(int $revisionId): array
    {
        $rev = Database::one(
            'SELECT r.*, a.nickname AS author_nickname, v.nickname AS reviewer_nickname
               FROM listenin_revisions r
               LEFT JOIN listenin_users a ON a.id = r.author_id
               LEFT JOIN listenin_users v ON v.id = r.reviewer_id
              WHERE r.id = :id', ['id' => $revisionId]
        ) ?? throw ApiException::notFound('Revisione non trovata.');

        $current = null;
        $version = null;
        if ($rev['frequency_id'] !== null && ($row = FrequencyRepository::find((int) $rev['frequency_id'], true))) {
            $current = FrequencyRepository::logical($row);
            $version = (int) $row['version'];
        }
        $changes = [];
        foreach (Database::all('SELECT field, old_value, new_value FROM listenin_revision_changes WHERE revision_id = :r ORDER BY id', ['r' => $revisionId]) as $c) {
            $now = $current !== null ? FrequencyFields::toText($current[$c['field']] ?? null) : null;
            $changes[] = [
                'field' => $c['field'],
                'label' => FrequencyFields::LABELS[$c['field']] ?? $c['field'],
                'old' => FrequencyFields::display($c['field'], $c['old_value']),
                'new' => FrequencyFields::display($c['field'], $c['new_value']),
                'current' => FrequencyFields::display($c['field'], $now),
                'stale' => $rev['status'] === 'pending' && $current !== null && $rev['action'] === 'update' && $now !== $c['old_value'],
            ];
        }
        return [
            'id' => (int) $rev['id'],
            'frequency_id' => $rev['frequency_id'] !== null ? (int) $rev['frequency_id'] : null,
            'action' => $rev['action'],
            'status' => $rev['status'],
            'origin' => $rev['origin'],
            'author' => self::authorName($rev['author_id'], $rev['author_nickname'], $rev['origin']),
            'author_comment' => $rev['author_comment'],
            'reviewer' => $rev['reviewer_nickname'],
            'review_note' => $rev['review_note'],
            'created_at' => $rev['created_at'],
            'reviewed_at' => $rev['reviewed_at'],
            'outdated' => $version !== null && $rev['base_version'] !== null && $version !== (int) $rev['base_version'],
            'report_id' => $rev['report_id'] !== null ? (int) $rev['report_id'] : null,
            'duplicates' => $rev['action'] === 'create' && ($hz = self::changeValue($revisionId, 'freq_hz'))
                ? FrequencyRepository::possibleDuplicates((int) $hz, self::changeValue($revisionId, 'region')) : [],
            'changes' => $changes,
        ];
    }

    private static function changeValue(int $revisionId, string $field): ?string
    {
        $v = Database::value('SELECT new_value FROM listenin_revision_changes WHERE revision_id = :r AND field = :f', ['r' => $revisionId, 'f' => $field]);
        return $v !== null ? (string) $v : null;
    }

    public static function authorName(mixed $authorId, ?string $nickname, string $origin): string
    {
        if ($nickname !== null) {
            return $nickname;
        }
        return match (true) {
            $origin === 'import' => 'Importazione',
            $origin === 'report' && $authorId === null => 'Segnalazione anonima',
            $authorId === null => 'Utente eliminato',
            default => 'Sistema',
        };
    }

    /** Storico pubblico (revisioni approvate) di una frequenza. */
    public static function history(int $frequencyId): array
    {
        $revs = Database::all(
            "SELECT r.id, r.action, r.origin, r.author_id, r.created_at, r.reviewed_at, a.nickname
               FROM listenin_revisions r LEFT JOIN listenin_users a ON a.id = r.author_id
              WHERE r.frequency_id = :f AND r.status = 'approved'
              ORDER BY r.reviewed_at DESC, r.id DESC LIMIT 100", ['f' => $frequencyId]
        );
        if (!$revs) {
            return [];
        }
        $ids = implode(',', array_map(static fn($r) => (int) $r['id'], $revs));
        $changes = [];
        foreach (Database::all("SELECT revision_id, field, old_value, new_value FROM listenin_revision_changes WHERE revision_id IN ($ids) ORDER BY id") as $c) {
            $changes[(int) $c['revision_id']][] = [
                'field' => $c['field'],
                'label' => FrequencyFields::LABELS[$c['field']] ?? $c['field'],
                'old' => FrequencyFields::display($c['field'], $c['old_value']),
                'new' => FrequencyFields::display($c['field'], $c['new_value']),
            ];
        }
        return array_map(static fn($r) => [
            'id' => (int) $r['id'],
            'action' => $r['action'],
            'author' => self::authorName($r['author_id'], $r['nickname'], $r['origin']),
            'date' => $r['reviewed_at'] ?? $r['created_at'],
            'changes' => $r['action'] === 'delete' ? [] : ($changes[(int) $r['id']] ?? []),
        ], $revs);
    }
}
