<?php
/**
 * 0.6.0 — comunità: notizie e aggiornamenti, webhook con coda di consegna, badge aggiuntivi.
 */
declare(strict_types=1);

use ListenIn\Core\Database;

return static function (\PDO $pdo): void {
    $x = static fn(string $sql) => $pdo->exec($sql);

    $x("ALTER TABLE listenin_moderation_log MODIFY target_type ENUM('revision','report','user','frequency','site','news','webhook') NOT NULL");

    // notizie e aggiornamenti, gestite dagli amministratori
    $x("CREATE TABLE IF NOT EXISTS listenin_news (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(120) NOT NULL,
        title VARCHAR(160) NOT NULL,
        body TEXT NOT NULL,
        category ENUM('comunicazione','bandplan','importazione') NOT NULL DEFAULT 'comunicazione',
        status ENUM('draft','published') NOT NULL DEFAULT 'draft',
        published_at DATETIME NULL,
        author_id INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_news_slug (slug),
        KEY ix_news_pub (status, published_at),
        CONSTRAINT fk_news_author FOREIGN KEY (author_id) REFERENCES listenin_users (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // webhook configurati dagli utenti (il segreto di firma è conservato cifrato)
    $x("CREATE TABLE IF NOT EXISTS listenin_webhooks (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(80) NOT NULL,
        url VARCHAR(500) NOT NULL,
        secret_sealed VARCHAR(255) NOT NULL,
        events SET('created','recovered') NOT NULL DEFAULT 'created,recovered',
        region_id SMALLINT UNSIGNED NULL,
        province_id SMALLINT UNSIGNED NULL,
        itu_band_id TINYINT UNSIGNED NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        failures INT UNSIGNED NOT NULL DEFAULT 0,
        last_status SMALLINT NULL,
        last_attempt_at DATETIME NULL,
        disabled_reason VARCHAR(120) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY ix_webhook_user (user_id),
        KEY ix_webhook_active (active, region_id, province_id, itu_band_id),
        CONSTRAINT fk_webhook_user FOREIGN KEY (user_id) REFERENCES listenin_users (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $x("CREATE TABLE IF NOT EXISTS listenin_webhook_deliveries (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        webhook_id INT UNSIGNED NOT NULL,
        event VARCHAR(20) NOT NULL,
        frequency_id INT UNSIGNED NULL,
        payload TEXT NOT NULL,
        status ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        http_status SMALLINT NULL,
        last_error VARCHAR(160) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        delivered_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY ix_delivery_due (status, next_attempt_at),
        KEY ix_delivery_hook (webhook_id, id),
        CONSTRAINT fk_delivery_webhook FOREIGN KEY (webhook_id) REFERENCES listenin_webhooks (id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // badge aggiuntivi
    $x("INSERT IGNORE INTO listenin_badges (code, name, description, sort_order) VALUES
        ('esploratore', 'Esploratore', 'Hai confermato ascolti in 5 regioni diverse.', 60),
        ('maratoneta', 'Maratoneta', 'Hai confermato ascolti per 7 giorni consecutivi.', 70),
        ('veterano', 'Veterano', 'Hai confermato 250 ascolti.', 80),
        ('recuperatore', 'Recuperatore seriale', 'Hai recuperato 50 frequenze.', 90),
        ('redattore', 'Redattore', 'Hai ottenuto l''approvazione di 25 proposte.', 100),
        ('campione-del-mese', 'Campione del mese', 'Sei stato il primo in classifica recuperi di un mese.', 110)");
};
