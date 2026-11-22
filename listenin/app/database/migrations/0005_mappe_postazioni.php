<?php
/**
 * 0.5.0 — mappe e postazioni: posizione calcolata di ogni record (per prossimità e distanza), postazioni/monti
 * con altitudine, altitudine dei record, coordinate delle province e nuove province sarde (riforma 2026).
 */
declare(strict_types=1);

use ListenIn\Core\Database;
use ListenIn\Core\Migrator;
use ListenIn\Domain\GeoService;

return static function (\PDO $pdo): void {
    $x = static fn(string $sql) => $pdo->exec($sql);

    // province: coordinate del baricentro (posizione approssimata) e nuove province sarde
    Migrator::addColumn('listenin_provinces', 'latitude', 'DECIMAL(9,6) NULL');
    Migrator::addColumn('listenin_provinces', 'longitude', 'DECIMAL(10,6) NULL');
    $sardinia = (int) Database::value("SELECT id FROM listenin_regions WHERE slug = 'sardegna'");
    if ($sardinia) {
        foreach ([['CI', 'Sulcis Iglesiente'], ['OG', 'Ogliastra'], ['OT', 'Gallura Nord-Est Sardegna'], ['VS', 'Medio Campidano']] as [$code, $name]) {
            Database::run('INSERT IGNORE INTO listenin_provinces (region_id, code, name) VALUES (:r, :c, :n)', ['r' => $sardinia, 'c' => $code, 'n' => $name]);
        }
    }
    $file = LISTENIN_ROOT . '/data/geo_province.json';
    $centroids = is_file($file) ? (array) json_decode((string) file_get_contents($file), true) : [];
    $byCode = [];
    foreach ($centroids as $c) {
        $byCode[$c['code']] = $c;
        Database::run('UPDATE listenin_provinces SET latitude = :la, longitude = :lo WHERE code = :c', ['la' => $c['lat'], 'lo' => $c['lon'], 'c' => $c['code']]);
    }
    // "Sud Sardegna" (SU) non esiste più: baricentro approssimato tra le province che ne hanno preso il posto
    if (isset($byCode['CI'], $byCode['VS'])) {
        Database::run("UPDATE listenin_provinces SET latitude = :la, longitude = :lo WHERE code = 'SU'",
            ['la' => ($byCode['CI']['lat'] + $byCode['VS']['lat']) / 2, 'lo' => ($byCode['CI']['lon'] + $byCode['VS']['lon']) / 2]);
    }

    // registro di moderazione: le postazioni sono un nuovo tipo di oggetto
    $x("ALTER TABLE listenin_moderation_log MODIFY target_type ENUM('revision','report','user','frequency','site') NOT NULL");

    // postazioni / monti
    $x('CREATE TABLE IF NOT EXISTS listenin_sites (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        slug VARCHAR(120) NOT NULL,
        name VARCHAR(100) NOT NULL,
        name_key VARCHAR(100) NOT NULL,
        province_id SMALLINT UNSIGNED NULL,
        municipality_id INT UNSIGNED NULL,
        latitude DECIMAL(9,6) NULL,
        longitude DECIMAL(10,6) NULL,
        altitude_m SMALLINT NULL,
        locator VARCHAR(8) NULL,
        notes VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_site_slug (slug),
        KEY ix_site_key (name_key),
        KEY ix_site_province (province_id),
        CONSTRAINT listenin_fk_site_province FOREIGN KEY (province_id) REFERENCES listenin_provinces (id) ON DELETE SET NULL,
        CONSTRAINT listenin_fk_site_municipality FOREIGN KEY (municipality_id) REFERENCES listenin_municipalities (id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

    // frequenze: altitudine, postazione e posizione calcolata
    Migrator::addColumn('listenin_frequencies', 'altitude_m', 'SMALLINT NULL');
    Migrator::addColumn('listenin_frequencies', 'site_id', 'INT UNSIGNED NULL');
    Migrator::addColumn('listenin_frequencies', 'geo_lat', 'DECIMAL(9,6) NULL');
    Migrator::addColumn('listenin_frequencies', 'geo_lon', 'DECIMAL(10,6) NULL');
    Migrator::addColumn('listenin_frequencies', 'geo_source', "ENUM('coordinate','sito','locatore','comune','provincia') NULL");
    Migrator::addIndex('listenin_frequencies', 'ix_freq_geo', 'KEY ix_freq_geo (deleted_at, geo_lat, geo_lon)');
    Migrator::addIndex('listenin_frequencies', 'ix_freq_site', 'KEY ix_freq_site (site_id)');
    if (!Database::value("SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'listenin_frequencies' AND constraint_name = 'fk_freq_site'")) {
        $x('ALTER TABLE listenin_frequencies ADD CONSTRAINT listenin_fk_freq_site FOREIGN KEY (site_id) REFERENCES listenin_sites (id) ON DELETE SET NULL');
    }

    // posizione dei record già presenti: coordinate proprie, poi locatore, poi centro provincia
    $x("UPDATE listenin_frequencies SET geo_lat = latitude, geo_lon = longitude, geo_source = 'coordinate'
         WHERE latitude IS NOT NULL AND longitude IS NOT NULL");
    $after = 0;
    do {
        $rows = Database::all("SELECT id FROM listenin_frequencies WHERE id > :a AND locator IS NOT NULL AND geo_source IS NULL ORDER BY id LIMIT 500", ['a' => $after]);
        foreach ($rows as $r) {
            GeoService::refresh((int) $r['id']);
            $after = (int) $r['id'];
        }
    } while ($rows);
    $x("UPDATE listenin_frequencies f JOIN listenin_provinces p ON p.id = f.province_id
           SET f.geo_lat = p.latitude, f.geo_lon = p.longitude, f.geo_source = 'provincia'
         WHERE f.geo_source IS NULL AND p.latitude IS NOT NULL");
};
