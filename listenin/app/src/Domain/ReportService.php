<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Auth;
use ListenIn\Core\Config;
use ListenIn\Core\Database;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Security;
use ListenIn\Core\Session;
use ListenIn\Core\Validator;

/** Segnalazioni rapide: errore nei dati, richiesta di aggiornamento, richiesta di rimozione. */
final class ReportService
{
    /** Campi aggiornabili con la richiesta rapida (cambio tono, stato, QTH, ...). */
    public const UPDATE_FIELDS = ['ctcss_hz', 'dcs_code', 'status', 'locator', 'municipality', 'province',
        'callsign', 'entity', 'label', 'mode', 'color_code', 'offset_hz', 'last_heard_at', 'notes'];

    public const REMOVAL_REASONS = [
        'duplicato' => 'Record duplicato',
        'dismessa' => 'Frequenza dismessa',
        'non_pertinente' => 'Dato non pertinente',
        'altro' => 'Altro motivo',
    ];

    public static function create(int $frequencyId, array $in, ?array $user): array
    {
        $row = FrequencyRepository::findOrFail($frequencyId);
        $v = new Validator($in);

        // Honeypot anti-bot: il campo è nascosto agli utenti reali
        if (!empty($in['website'])) {
            throw new ApiException(400, 'rejected', 'Segnalazione non accettata.');
        }
        if ($user === null) {
            if (!Config::get('reports.allow_anonymous', true)) {
                throw ApiException::unauthorized('Accedi per inviare segnalazioni.');
            }
            RateLimiter::hit('report_anon', Security::clientIp());
            if (Session::get('disclaimer_ack') !== Config::get('legal.disclaimer_version')) {
                $v->accepted('disclaimer_ack', 'Conferma di aver preso visione del disclaimer.');
            }
        } else {
            RateLimiter::hit('report_user', 'u' . $user['id']);
            if (!Auth::hasCurrentDisclaimer($user)) {
                $v->accepted('disclaimer_ack', 'Conferma di aver preso visione del disclaimer.');
            }
        }

        $type = $v->enum('type', ['errore', 'aggiornamento', 'rimozione']);
        $field = null;
        $suggested = null;
        $reason = null;
        $duplicateOf = null;
        $message = $v->string('message', 3, 1000, false, true);

        if ($type === 'errore') {
            $field = $v->enum('field', FrequencyFields::names());
            $suggested = $v->string('suggested_value', 1, 255);
        } elseif ($type === 'aggiornamento') {
            $field = $v->enum('field', self::UPDATE_FIELDS);
            $suggested = $v->string('suggested_value', 1, 255);
        } elseif ($type === 'rimozione') {
            $reason = $v->enum('removal_reason', array_keys(self::REMOVAL_REASONS));
            if ($reason === 'duplicato') {
                $duplicateOf = $v->int('duplicate_of_id', 1, PHP_INT_MAX >> 1);
                if ($duplicateOf !== null && ($duplicateOf === $frequencyId || !FrequencyRepository::find($duplicateOf))) {
                    $v->addError('duplicate_of_id', 'Indica l\'id di un record esistente diverso da questo.');
                }
            }
            if ($reason === 'altro' && $message === null) {
                $v->addError('message', 'Descrivi il motivo della rimozione.');
            }
        }
        $v->throwIfErrors();

        $ipHash = Security::ipHash();
        $dup = Database::value(
            "SELECT id FROM listenin_reports WHERE frequency_id = :f AND type = :t AND status IN ('aperta','in_revisione')
               AND (field <=> :fi) AND " . ($user ? 'reporter_id = :who' : 'reporter_ip_hash = :who'),
            ['f' => $frequencyId, 't' => $type, 'fi' => $field, 'who' => $user ? (int) $user['id'] : $ipHash]
        );
        if ($dup) {
            throw new ApiException(409, 'duplicate_report', 'Hai già una segnalazione aperta di questo tipo su questa frequenza.');
        }

        $current = $field !== null ? FrequencyFields::toText(FrequencyRepository::logical($row)[$field] ?? null) : null;
        $id = Database::insert(
            'INSERT INTO listenin_reports (frequency_id, type, field, current_value, suggested_value, removal_reason,
                    duplicate_of_id, message, reporter_id, reporter_ip_hash)
             VALUES (:f, :t, :fi, :cv, :sv, :rr, :d, :m, :u, :ip)',
            ['f' => $frequencyId, 't' => $type, 'fi' => $field,
                'cv' => $current !== null ? mb_substr($current, 0, 255) : null,
                'sv' => $suggested, 'rr' => $reason, 'd' => $duplicateOf, 'm' => $message,
                'u' => $user ? (int) $user['id'] : null, 'ip' => $ipHash]
        );

        // Presa visione registrata al primo invio
        if ($user && !Auth::hasCurrentDisclaimer($user)) {
            UserService::acceptDisclaimer((int) $user['id']);
        } elseif (!$user) {
            Session::set('disclaimer_ack', Config::get('legal.disclaimer_version'));
        }
        return ['id' => $id, 'status' => 'aperta'];
    }

    public static function list(?string $status, int $page, int $perPage = 25): array
    {
        $where = $status ? 'WHERE r.status = :s' : "WHERE r.status IN ('aperta','in_revisione')";
        $params = $status ? ['s' => $status] : [];
        $total = (int) Database::value("SELECT COUNT(*) FROM listenin_reports r $where", $params);
        $rows = Database::all(
            "SELECT r.*, f.freq_hz, f.label AS freq_label, f.deleted_at AS freq_deleted,
                    u.nickname AS reporter_nickname, h.nickname AS handler_nickname
               FROM listenin_reports r
               JOIN listenin_frequencies f ON f.id = r.frequency_id
               LEFT JOIN listenin_users u ON u.id = r.reporter_id
               LEFT JOIN listenin_users h ON h.id = r.handled_by
               $where ORDER BY r.created_at ASC LIMIT " . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage),
            $params
        );
        return ['items' => array_map([self::class, 'present'], $rows), 'total' => $total, 'page' => $page,
            'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'frequency_id' => (int) $r['frequency_id'],
            'freq_mhz' => isset($r['freq_hz']) ? Normalizer::formatMHz((int) $r['freq_hz']) : null,
            'freq_label' => $r['freq_label'] ?? null,
            'freq_deleted' => !empty($r['freq_deleted']),
            'type' => $r['type'],
            'field' => $r['field'],
            'field_label' => $r['field'] ? (FrequencyFields::LABELS[$r['field']] ?? $r['field']) : null,
            'current_value' => $r['field'] ? FrequencyFields::display($r['field'], $r['current_value']) : null,
            'suggested_value' => $r['suggested_value'],
            'removal_reason' => $r['removal_reason'],
            'removal_reason_label' => $r['removal_reason'] ? self::REMOVAL_REASONS[$r['removal_reason']] : null,
            'duplicate_of_id' => $r['duplicate_of_id'] !== null ? (int) $r['duplicate_of_id'] : null,
            'message' => $r['message'],
            'reporter' => ($r['origin'] ?? 'utente') === 'import' ? 'Importazione (controllo duplicati)'
                : ($r['reporter_nickname'] ?? ($r['reporter_id'] === null ? 'Anonimo' : null)),
            'origin' => $r['origin'] ?? 'utente',
            'status' => $r['status'],
            'handler' => $r['handler_nickname'] ?? null,
            'resolution_note' => $r['resolution_note'],
            'revision_id' => $r['revision_id'] !== null ? (int) $r['revision_id'] : null,
            'created_at' => $r['created_at'],
            'handled_at' => $r['handled_at'],
        ];
    }

    /** Presa in carico da parte di un moderatore. */
    public static function claim(int $reportId, int $moderatorId): void
    {
        $n = Database::run(
            "UPDATE listenin_reports SET status = 'in_revisione', handled_by = :u WHERE id = :id AND status = 'aperta'",
            ['u' => $moderatorId, 'id' => $reportId]
        )->rowCount();
        if (!$n) {
            throw new ApiException(409, 'not_open', 'La segnalazione non è più aperta.');
        }
        ModerationLog::add($moderatorId, 'report.claim', 'report', $reportId);
    }

    /**
     * Risolve la segnalazione. Con $apply la correzione suggerita (o $value modificato dal moderatore)
     * diventa una revisione approvata, attribuita al segnalatore.
     */
    public static function resolve(int $reportId, int $moderatorId, ?string $note, bool $apply, ?string $value): array
    {
        return Database::transaction(function () use ($reportId, $moderatorId, $note, $apply, $value) {
            $r = Database::one('SELECT * FROM listenin_reports WHERE id = :id FOR UPDATE', ['id' => $reportId])
                ?? throw ApiException::notFound('Segnalazione non trovata.');
            if (!in_array($r['status'], ['aperta', 'in_revisione'], true)) {
                throw new ApiException(409, 'closed', 'La segnalazione è già chiusa.');
            }
            $revisionId = null;
            if ($apply) {
                $authorId = $r['reporter_id'] !== null ? (int) $r['reporter_id'] : null;
                if ($r['type'] === 'rimozione') {
                    // Duplicato confermato: le righe della fonte passano al record conservato,
                    // così una nuova importazione non ricrea il doppione
                    if ($r['removal_reason'] === 'duplicato' && $r['duplicate_of_id'] !== null) {
                        Database::run('UPDATE listenin_frequency_sources SET frequency_id = :keep, merged = 1 WHERE frequency_id = :del',
                            ['keep' => (int) $r['duplicate_of_id'], 'del' => (int) $r['frequency_id']]);
                    }
                    $res = RevisionService::propose($authorId, 'delete', (int) $r['frequency_id'], [],
                        'Rimozione da segnalazione #' . $reportId . ': ' . self::REMOVAL_REASONS[$r['removal_reason']],
                        'report', true, $moderatorId, $reportId);
                } else {
                    $row = FrequencyRepository::findOrFail((int) $r['frequency_id']);
                    $current = FrequencyRepository::logical($row);
                    $newValue = $value ?? $r['suggested_value'];
                    $input = [$r['field'] => $newValue];
                    if ($r['field'] === 'freq_hz') {
                        $input = ['freq' => $newValue];
                    }
                    $logical = FrequencyFields::validate($input, true, $current);
                    if (!$logical) {
                        throw new ApiException(422, 'no_changes', 'Il valore suggerito coincide con quello attuale.');
                    }
                    $res = RevisionService::propose($authorId, 'update', (int) $r['frequency_id'], $logical,
                        'Correzione da segnalazione #' . $reportId, 'report', true, $moderatorId, $reportId);
                }
                $revisionId = $res['revision_id'];
            }
            Database::run(
                "UPDATE listenin_reports SET status = 'risolta', handled_by = :u, resolution_note = :n,
                        revision_id = :rev, handled_at = NOW() WHERE id = :id",
                ['u' => $moderatorId, 'n' => $note, 'rev' => $revisionId, 'id' => $reportId]
            );
            ModerationLog::add($moderatorId, 'report.resolve', 'report', $reportId, $note);
            if ($apply && $r['reporter_id'] !== null && $r['origin'] !== 'import') {
                PointsService::award((int) $r['reporter_id'], 'segnalazione', $reportId);
                TrustService::onApproved((int) $r['reporter_id'], 'report');
            }
            return ['status' => 'risolta', 'revision_id' => $revisionId];
        });
    }

    public static function archive(int $reportId, int $moderatorId, ?string $note): void
    {
        $n = Database::run(
            "UPDATE listenin_reports SET status = 'archiviata', handled_by = :u, resolution_note = :n, handled_at = NOW()
              WHERE id = :id AND status IN ('aperta','in_revisione')",
            ['u' => $moderatorId, 'n' => $note, 'id' => $reportId]
        )->rowCount();
        if (!$n) {
            throw new ApiException(409, 'closed', 'La segnalazione è già chiusa.');
        }
        ModerationLog::add($moderatorId, 'report.archive', 'report', $reportId, $note);
    }

    /** Azzeramento degli hash IP oltre il periodo di conservazione. */
    public static function purgeIpHashes(): int
    {
        $days = (int) Config::get('reports.ip_hash_retention_days', 30);
        return Database::run(
            'UPDATE listenin_reports SET reporter_ip_hash = NULL WHERE reporter_ip_hash IS NOT NULL AND created_at < (NOW() - INTERVAL :d DAY)',
            ['d' => $days]
        )->rowCount();
    }
}
