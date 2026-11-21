<?php
declare(strict_types=1);

namespace ListenIn\Domain;

/**
 * Indice di affidabilità dinamico, calcolato al momento dalla data dell'ultimo ascolto e dallo stato operativo:
 *   alta           ultimo ascolto entro 90 giorni
 *   media          da 91 a 365 giorni
 *   da_verificare  oltre 365 giorni
 *   mai            nessun ascolto registrato
 * Una frequenza "contestata" non supera mai "da verificare", qualunque sia la data.
 */
final class Reliability
{
    public const LEVELS = ['alta' => 'Alta', 'media' => 'Media', 'da_verificare' => 'Da verificare', 'mai' => 'Mai verificata'];

    /** Espressione SQL equivalente a level() (per filtri, statistiche, ordinamenti). */
    public static function sql(string $alias = 'f'): string
    {
        return "CASE WHEN {$alias}.last_heard_at IS NULL THEN 'mai'
                     WHEN {$alias}.status = 'contestata' THEN 'da_verificare'
                     WHEN DATEDIFF(CURDATE(), {$alias}.last_heard_at) <= 90 THEN 'alta'
                     WHEN DATEDIFF(CURDATE(), {$alias}.last_heard_at) <= 365 THEN 'media'
                     ELSE 'da_verificare' END";
    }

    public static function days(?string $lastHeard, ?\DateTimeImmutable $today = null): ?int
    {
        if ($lastHeard === null || $lastHeard === '') {
            return null;
        }
        $today ??= new \DateTimeImmutable('today');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($lastHeard, 0, 10));
        return $d ? (int) $d->diff($today)->format('%r%a') : null;
    }

    public static function level(?string $lastHeard, string $status, ?\DateTimeImmutable $today = null): string
    {
        $days = self::days($lastHeard, $today);
        if ($days === null) {
            return 'mai';
        }
        if ($status === 'contestata') {
            return 'da_verificare';
        }
        return $days <= 90 ? 'alta' : ($days <= 365 ? 'media' : 'da_verificare');
    }

    /**
     * Una frequenza è "da recuperare" se è contestata, da verificare (come stato), mai verificata,
     * o ferma da oltre un anno / inattiva da oltre un anno. @return ?string motivo oppure null
     */
    public static function recoveryReason(?string $lastHeard, string $status): ?string
    {
        if ($status === 'contestata') {
            return 'contestata';
        }
        $days = self::days($lastHeard);
        if ($days === null) {
            return 'mai';
        }
        if ($status === 'inattivo' && $days > 365) {
            return 'inattiva';
        }
        if ($days > 365) {
            return 'scaduta';
        }
        if ($status === 'da_verificare') {
            return 'stato';
        }
        return null;
    }

    /** Condizione SQL dei record "da recuperare" (missioni). */
    public static function missionSql(string $alias = 'f'): string
    {
        return "({$alias}.last_heard_at IS NULL OR {$alias}.status IN ('contestata','da_verificare')
                 OR DATEDIFF(CURDATE(), {$alias}.last_heard_at) > 365)";
    }
}
