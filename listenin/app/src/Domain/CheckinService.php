<?php
declare(strict_types=1);

namespace ListenIn\Domain;

use ListenIn\Core\ApiException;
use ListenIn\Core\Config;
use ListenIn\Core\Database;
use ListenIn\Core\RateLimiter;
use ListenIn\Core\Validator;

/**
 * Conferma di ascolto (check-in) e logbook SWL.
 *
 * Un check-in aggiorna la data dell'ultimo ascolto (e quindi l'indice di affidabilità) tramite una revisione di
 * origine "checkin", così resta nello storico. Se la frequenza era contestata, da verificare, mai verificata o
 * ferma da oltre un anno, il check-in è un "Recupero" e vale più punti.
 */
final class CheckinService
{
    /** @return array{recovered:bool,reason:?string,points:int,badges:array,reliability:string,status:string,last_heard_at:string} */
    public static function create(array $user, int $frequencyId, array $in): array
    {
        $userId = (int) $user['id'];
        RateLimiter::hit('checkin', 'u' . $userId);
        $v = new Validator($in);
        $rst = $v->string('rst', 2, 3, false);
        if ($rst !== null && !preg_match('/^[1-5][1-9]{1,2}$/', $rst)) {
            $v->addError('rst', 'Rapporto non valido (es. 59 oppure 599).');
        }
        $equipment = $v->string('equipment', 2, 80, false);
        $heardOn = $v->string('heard_on', 10, 10, false) ?? date('Y-m-d');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $heardOn);
        if (!$dt || $dt->format('Y-m-d') !== $heardOn || $dt > new \DateTimeImmutable('today') || $dt < new \DateTimeImmutable('-14 days')) {
            $v->addError('heard_on', 'La data deve essere compresa tra oggi e i 14 giorni precedenti.');
        }
        $v->throwIfErrors();

        return Database::transaction(function () use ($userId, $frequencyId, $rst, $equipment, $heardOn) {
            $row = Database::one('SELECT id, status, last_heard_at, version FROM listenin_frequencies WHERE id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => $frequencyId])
                ?? throw ApiException::notFound('Frequenza non trovata.');
            $reason = Reliability::recoveryReason($row['last_heard_at'], (string) $row['status']);
            // Contro l'abuso: oltre una certa quota giornaliera i check-in restano validi ma non contano come recuperi
            if ($reason !== null) {
                $today = (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE user_id = :u AND recovered = 1 AND created_at >= CURDATE()', ['u' => $userId]);
                if ($today >= (int) Config::get('checkin.max_recoveries_per_day', 40)) {
                    $reason = null;
                }
            }
            try {
                $checkinId = Database::insert(
                    'INSERT INTO listenin_checkins (frequency_id, user_id, heard_on, rst, equipment, recovered, recovery_reason)
                     VALUES (:f, :u, :d, :r, :e, :rec, :why)',
                    ['f' => $frequencyId, 'u' => $userId, 'd' => $heardOn, 'r' => $rst, 'e' => $equipment, 'rec' => $reason !== null ? 1 : 0, 'why' => $reason]
                );
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) {
                    throw new ApiException(409, 'already_checked_in', 'Hai già confermato l\'ascolto di questa frequenza per quella data.');
                }
                throw $e;
            }

            // aggiornamento del record tramite revisione (visibile nello storico)
            $changes = [];
            if ($row['last_heard_at'] === null || $heardOn > (string) $row['last_heard_at']) {
                $changes['last_heard_at'] = $heardOn;
            }
            if ($row['status'] === 'da_verificare') {
                $changes['status'] = 'attivo';
            }
            if ($changes) {
                RevisionService::propose($userId, 'update', $frequencyId, $changes, 'Conferma di ascolto', 'checkin', true);
            }
            // frequenza inattiva o contestata ascoltata: la riattivazione la decide un moderatore
            if (in_array($row['status'], ['inattivo', 'contestata'], true)) {
                try {
                    RevisionService::propose($userId, 'update', $frequencyId, ['status' => 'attivo'], 'Conferma di ascolto: la frequenza risulta attiva', 'checkin', false);
                } catch (ApiException $e) {
                    if ($e->errorCode !== 'no_changes') {
                        throw $e;
                    }
                }
            }

            if ($reason !== null) {
                WebhookService::dispatch('recovered', $frequencyId);
            }
            $points = PointsService::award($userId, 'checkin', $checkinId);
            if ($reason !== null) {
                $points += PointsService::award($userId, 'recupero', $checkinId);
            }
            $badges = PointsService::evaluateBadges($userId);
            $fresh = Database::one('SELECT status, last_heard_at FROM listenin_frequencies WHERE id = :id', ['id' => $frequencyId]);
            return [
                'recovered' => $reason !== null, 'reason' => $reason, 'points' => $points, 'badges' => $badges,
                'reliability' => Reliability::level($fresh['last_heard_at'], (string) $fresh['status']),
                'status' => $fresh['status'], 'last_heard_at' => $fresh['last_heard_at'],
            ];
        });
    }

    public static function logbook(int $userId, int $page, int $perPage = 25): array
    {
        $total = (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE user_id = :u', ['u' => $userId]);
        $rows = Database::all(
            'SELECT c.id, c.frequency_id, c.heard_on, c.rst, c.equipment, c.recovered, c.recovery_reason, f.freq_hz, f.label
               FROM listenin_checkins c JOIN listenin_frequencies f ON f.id = c.frequency_id
              WHERE c.user_id = :u ORDER BY c.heard_on DESC, c.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage), ['u' => $userId]);
        $recoveries = (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE user_id = :u AND recovered = 1', ['u' => $userId]);
        return [
            'items' => array_map(static fn($r) => [
                'id' => (int) $r['id'], 'frequency_id' => (int) $r['frequency_id'], 'freq_mhz' => Normalizer::formatMHz((int) $r['freq_hz']),
                'label' => $r['label'], 'heard_on' => $r['heard_on'], 'rst' => $r['rst'], 'equipment' => $r['equipment'],
                'recovered' => (bool) $r['recovered'], 'recovery_reason' => $r['recovery_reason'],
            ], $rows),
            'total' => $total, 'recoveries' => $recoveries, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    /** Righe del logbook per l'esportazione CSV (flusso, memoria costante). */
    public static function logbookRows(int $userId): \Generator
    {
        $stmt = Database::run(
            'SELECT c.heard_on, f.freq_hz, f.label, f.callsign, m.code AS mode, c.rst, c.equipment, c.recovered, c.recovery_reason
               FROM listenin_checkins c JOIN listenin_frequencies f ON f.id = c.frequency_id JOIN listenin_modes m ON m.id = f.mode_id
              WHERE c.user_id = :u ORDER BY c.heard_on, c.id', ['u' => $userId]);
        while ($r = $stmt->fetch()) {
            yield ['data' => $r['heard_on'], 'frequenza_mhz' => Normalizer::formatFixed((int) $r['freq_hz'], 6), 'modo' => $r['mode'],
                'descrizione' => $r['label'], 'nominativo' => $r['callsign'], 'rapporto' => $r['rst'], 'apparato_antenna' => $r['equipment'],
                'recupero' => $r['recovered'] ? 'si' : 'no', 'motivo_recupero' => $r['recovery_reason']];
        }
    }

    public static function recent(int $frequencyId, int $limit = 5): array
    {
        $rows = Database::all(
            'SELECT c.heard_on, c.rst, u.nickname FROM listenin_checkins c LEFT JOIN listenin_users u ON u.id = c.user_id
              WHERE c.frequency_id = :f ORDER BY c.heard_on DESC, c.id DESC LIMIT ' . (int) $limit, ['f' => $frequencyId]);
        return [
            'total' => (int) Database::value('SELECT COUNT(*) FROM listenin_checkins WHERE frequency_id = :f', ['f' => $frequencyId]),
            'recent' => array_map(static fn($r) => ['heard_on' => $r['heard_on'], 'rst' => $r['rst'], 'by' => $r['nickname'] ?? 'utente eliminato'], $rows),
        ];
    }
}
