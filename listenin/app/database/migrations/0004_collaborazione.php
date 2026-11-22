<?php
/**
 * 0.4.0 — collaborazione e affidabilità: stato "contestata", check-in (ascolti), punti, badge,
 * Trust Score e QTH predefinito degli utenti, origine "checkin" delle revisioni.
 */
declare(strict_types=1);

use ListenIn\Core\Migrator;

return static function (\PDO $pdo): void {
    $x = static fn(string $sql) => $pdo->exec($sql);

    // stato operativo: aggiunge "contestata"
    $x("ALTER TABLE listenin_frequencies MODIFY status ENUM('attivo','inattivo','sperimentale','da_verificare','contestata') NOT NULL DEFAULT 'da_verificare'");
    // origine delle revisioni: aggiunge "checkin"
    $x("ALTER TABLE listenin_revisions MODIFY origin ENUM('user','moderator','import','report','checkin') NOT NULL DEFAULT 'user'");

    // utenti: punteggi e QTH predefinito
    Migrator::addColumn('listenin_users', 'trust_score', 'INT NOT NULL DEFAULT 0');
    Migrator::addColumn('listenin_users', 'points', 'INT NOT NULL DEFAULT 0');
    Migrator::addColumn('listenin_users', 'default_locator', 'VARCHAR(8) NULL');

    // conferme di ascolto (check-in) e logbook SWL
    $x('CREATE TABLE IF NOT EXISTS listenin_checkins (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        frequency_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NULL,
        heard_on DATE NOT NULL,
        rst VARCHAR(5) NULL,
        equipment VARCHAR(80) NULL,
        recovered TINYINT(1) NOT NULL DEFAULT 0,
        recovery_reason VARCHAR(20) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_checkin_day (user_id, frequency_id, heard_on),
        KEY ix_checkin_freq (frequency_id, heard_on),
        KEY ix_checkin_user (user_id, created_at),
        KEY ix_checkin_recovered (recovered, created_at),
        CONSTRAINT listenin_fk_checkin_frequency FOREIGN KEY (frequency_id) REFERENCES listenin_frequencies (id) ON DELETE CASCADE,
        CONSTRAINT listenin_fk_checkin_user FOREIGN KEY (user_id) REFERENCES listenin_users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // registro dei punti reputazione
    $x('CREATE TABLE IF NOT EXISTS listenin_points (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NULL,
        points INT NOT NULL,
        reason VARCHAR(20) NOT NULL,
        ref_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_points_user (user_id, created_at),
        KEY ix_points_reason (reason, created_at),
        CONSTRAINT listenin_fk_points_user FOREIGN KEY (user_id) REFERENCES listenin_users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // badge
    $x('CREATE TABLE IF NOT EXISTS listenin_badges (
        code VARCHAR(30) NOT NULL,
        name VARCHAR(60) NOT NULL,
        description VARCHAR(200) NOT NULL,
        sort_order SMALLINT NOT NULL DEFAULT 0,
        PRIMARY KEY (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $x('CREATE TABLE IF NOT EXISTS listenin_user_badges (
        user_id INT UNSIGNED NOT NULL,
        badge_code VARCHAR(30) NOT NULL,
        awarded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id, badge_code),
        CONSTRAINT listenin_fk_ub_user FOREIGN KEY (user_id) REFERENCES listenin_users (id) ON DELETE CASCADE,
        CONSTRAINT listenin_fk_ub_badge FOREIGN KEY (badge_code) REFERENCES listenin_badges (code) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    $x("INSERT IGNORE INTO listenin_badges (code, name, description, sort_order) VALUES
        ('primo-ascolto', 'Primo ascolto', 'Hai confermato il tuo primo ascolto.', 10),
        ('sentinella-spettro', 'Sentinella dello Spettro', 'Hai confermato 50 ascolti.', 20),
        ('cacciatore-ponti', 'Cacciatore di Ponti', 'Hai confermato l''ascolto di 10 ponti ripetitori diversi.', 30),
        ('archeologo-rf', 'Archeologo RF', 'Hai recuperato 10 frequenze mai verificate o ferme da oltre un anno.', 40),
        ('collaboratore', 'Collaboratore', 'Hai ottenuto l''approvazione di 5 proposte.', 50)");
};
