-- =====================================================================
--  ListenIn — database collaborativo delle frequenze radio
--  schema.sql  ·  versione 0.3.2
--  Compatibile: MySQL 8.0+ / MariaDB 10.6+
--  Charset utf8mb4, engine InnoDB, prefisso tabelle: listenin_
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS listenin_import_runs;
DROP TABLE IF EXISTS listenin_moderation_log;
DROP TABLE IF EXISTS listenin_reports;
DROP TABLE IF EXISTS listenin_revision_changes;
DROP TABLE IF EXISTS listenin_revisions;
DROP TABLE IF EXISTS listenin_frequency_sources;
DROP TABLE IF EXISTS listenin_frequency_provinces;
DROP TABLE IF EXISTS listenin_frequencies;
DROP TABLE IF EXISTS listenin_entities;
DROP TABLE IF EXISTS listenin_municipalities;
DROP TABLE IF EXISTS listenin_provinces;
DROP TABLE IF EXISTS listenin_regions;
DROP TABLE IF EXISTS listenin_countries;
DROP TABLE IF EXISTS listenin_band_plans;
DROP TABLE IF EXISTS listenin_itu_bands;
DROP TABLE IF EXISTS listenin_modes;
DROP TABLE IF EXISTS listenin_services;
DROP TABLE IF EXISTS listenin_rate_limits;
DROP TABLE IF EXISTS listenin_user_tokens;
DROP TABLE IF EXISTS listenin_users;

SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------
--  UTENTI E AUTENTICAZIONE
-- ---------------------------------------------------------------------
CREATE TABLE listenin_users (
  id                     INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  email                  VARCHAR(190)     NOT NULL,
  email_verified_at      DATETIME         NULL,
  nickname               VARCHAR(32)      NOT NULL,
  password_hash          VARCHAR(255)     NOT NULL,
  role                   ENUM('user','moderator','admin') NOT NULL DEFAULT 'user',
  status                 ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  terms_accepted_at      DATETIME         NOT NULL,
  disclaimer_version     VARCHAR(16)      NULL,
  disclaimer_accepted_at DATETIME         NULL,
  password_changed_at    DATETIME         NULL,     -- invalida le sessioni aperte prima del cambio
  failed_logins          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  locked_until           DATETIME         NULL,
  last_login_at          DATETIME         NULL,
  created_at             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at             DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  UNIQUE KEY uq_users_nickname (nickname),
  KEY ix_users_role (role),
  KEY ix_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Token monouso: verifica email, cambio email, reset password.
-- Si salva solo l'hash SHA-256 del token, mai il token in chiaro.
CREATE TABLE listenin_user_tokens (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  purpose      ENUM('verify_email','change_email','reset_password') NOT NULL,
  token_hash   CHAR(64)     NOT NULL,
  payload      VARCHAR(190) NULL,            -- nuova email per change_email
  expires_at   DATETIME     NOT NULL,
  used_at      DATETIME     NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY ix_tokens_user_purpose (user_id, purpose),
  KEY ix_tokens_expires (expires_at),
  CONSTRAINT listenin_fk_tokens_user FOREIGN KEY (user_id)
    REFERENCES listenin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Rate limiting a finestra fissa. bucket = HMAC(azione|ip|utente)
CREATE TABLE listenin_rate_limits (
  bucket        CHAR(64)          NOT NULL,
  window_start  INT UNSIGNED      NOT NULL,
  hits          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at    INT UNSIGNED      NOT NULL,
  PRIMARY KEY (bucket, window_start),
  KEY ix_rate_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  TASSONOMIE (asse 1: ambito e servizio · classificazione tecnica)
-- ---------------------------------------------------------------------
CREATE TABLE listenin_services (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id   SMALLINT UNSIGNED NULL,
  slug        VARCHAR(48)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  color       CHAR(7)      NULL,
  sort_order  SMALLINT     NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_slug (slug),
  KEY ix_services_parent (parent_id, sort_order),
  CONSTRAINT listenin_fk_services_parent FOREIGN KEY (parent_id)
    REFERENCES listenin_services (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listenin_modes (
  id                    TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                  VARCHAR(12)  NOT NULL,
  name                  VARCHAR(60)  NOT NULL,
  family                ENUM('analogico','digitale','dati') NOT NULL,
  default_bandwidth_hz  INT UNSIGNED NULL,
  access_code           ENUM('none','color_code','ran','nac') NOT NULL DEFAULT 'none',
  sort_order            SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_modes_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listenin_itu_bands (
  id      TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code    VARCHAR(4)       NOT NULL,
  name    VARCHAR(60)      NOT NULL,
  min_hz  BIGINT UNSIGNED  NOT NULL,
  max_hz  BIGINT UNSIGNED  NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_itu_code (code),
  KEY ix_itu_range (min_hz, max_hz),
  CONSTRAINT listenin_ck_itu_range CHECK (max_hz > min_hz)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Segmenti del piano delle frequenze (IARU R1, nautico, aeronautico, ...)
CREATE TABLE listenin_band_plans (
  id          SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(48)  NOT NULL,
  name        VARCHAR(80)  NOT NULL,
  min_hz      BIGINT UNSIGNED NOT NULL,
  max_hz      BIGINT UNSIGNED NOT NULL,
  service_id  SMALLINT UNSIGNED NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_plans_slug (slug),
  KEY ix_plans_range (min_hz, max_hz),
  CONSTRAINT listenin_ck_plans_range CHECK (max_hz > min_hz),
  CONSTRAINT listenin_fk_plans_service FOREIGN KEY (service_id)
    REFERENCES listenin_services (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  GEOGRAFIA STRUTTURATA (asse 2)
-- ---------------------------------------------------------------------
CREATE TABLE listenin_countries (
  iso2  CHAR(2)     NOT NULL,
  name  VARCHAR(80) NOT NULL,
  PRIMARY KEY (iso2)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listenin_regions (
  id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  country_iso2  CHAR(2)     NOT NULL,
  istat_code    CHAR(2)     NULL,
  slug          VARCHAR(48) NOT NULL,
  name          VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_regions_slug (slug),
  KEY ix_regions_country (country_iso2),
  CONSTRAINT listenin_fk_regions_country FOREIGN KEY (country_iso2)
    REFERENCES listenin_countries (iso2) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listenin_provinces (
  id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  region_id  SMALLINT UNSIGNED NOT NULL,
  code       CHAR(2)     NOT NULL,           -- sigla automobilistica
  name       VARCHAR(80) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_provinces_code (code),
  KEY ix_provinces_region (region_id),
  CONSTRAINT listenin_fk_provinces_region FOREIGN KEY (region_id)
    REFERENCES listenin_regions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listenin_municipalities (
  id           INT UNSIGNED      NOT NULL AUTO_INCREMENT,
  province_id  SMALLINT UNSIGNED NOT NULL,
  istat_code   CHAR(6)      NULL,
  name         VARCHAR(100) NOT NULL,
  name_key     VARCHAR(100) NOT NULL,         -- nome normalizzato per il matching
  latitude     DECIMAL(9,6)  NULL,
  longitude    DECIMAL(10,6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_municipalities_prov_name (province_id, name_key),
  UNIQUE KEY uq_municipalities_istat (istat_code),
  KEY ix_municipalities_name_key (name_key),
  CONSTRAINT listenin_fk_municipalities_province FOREIGN KEY (province_id)
    REFERENCES listenin_provinces (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Enti e gestori (asse 3)
CREATE TABLE listenin_entities (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name       VARCHAR(120) NOT NULL,
  name_key   VARCHAR(120) NOT NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_entities_key (name_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  FREQUENZE (dataset ufficiale)
-- ---------------------------------------------------------------------
CREATE TABLE listenin_frequencies (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- a) Parametri RF — la frequenza è memorizzata in Hz interi;
  --    kHz e MHz sono colonne generate con precisione uniforme.
  freq_hz          BIGINT UNSIGNED NOT NULL,
  freq_khz         DECIMAL(15,3) GENERATED ALWAYS AS (freq_hz / 1000) STORED,
  freq_mhz         DECIMAL(13,6) GENERATED ALWAYS AS (freq_hz / 1000000) STORED,
  offset_hz        BIGINT       NULL,
  input_freq_hz    BIGINT UNSIGNED NULL,
  tone_type        ENUM('none','ctcss','dcs') NOT NULL DEFAULT 'none',
  ctcss_hz         DECIMAL(5,1) NULL,
  dcs_code         VARCHAR(4)   NULL,          -- es. 023N, 754I
  color_code       TINYINT UNSIGNED NULL,      -- DMR 0..15
  dmr_timeslot     TINYINT UNSIGNED NULL,      -- 1..2
  talkgroup        VARCHAR(32)  NULL,
  ran              TINYINT UNSIGNED NULL,      -- NXDN 0..63
  nac              CHAR(3)      NULL,          -- P25 esadecimale 000..FFF
  -- b) Classificazione tecnica
  mode_id          TINYINT UNSIGNED NOT NULL,
  bandwidth_hz     INT UNSIGNED NULL,
  itu_band_id      TINYINT UNSIGNED NULL,
  band_plan_id     SMALLINT UNSIGNED NULL,
  -- c1) Ambito e servizio
  service_id       SMALLINT UNSIGNED NOT NULL,
  label            VARCHAR(160) NOT NULL,      -- descrizione d'uso, es. "VVF ch 22 provinciale"
  channel          VARCHAR(20)  NULL,          -- es. R7a, RU5, CH16
  -- c2) Geografia strutturata
  country_iso2     CHAR(2)      NULL,
  region_id        SMALLINT UNSIGNED NULL,
  province_id      SMALLINT UNSIGNED NULL,     -- provincia principale
  municipality_id  INT UNSIGNED NULL,
  area_note        VARCHAR(120) NULL,          -- area non riconducibile a un'unità amministrativa
  locator          VARCHAR(8)   NULL,          -- Maidenhead 4/6/8 caratteri
  latitude         DECIMAL(9,6)  NULL,
  longitude        DECIMAL(10,6) NULL,
  coverage_km      SMALLINT UNSIGNED NULL,
  -- c3) Identificativo e stato
  callsign         VARCHAR(20)  NULL,
  entity_id        INT UNSIGNED NULL,
  status           ENUM('attivo','inattivo','sperimentale','da_verificare') NOT NULL DEFAULT 'da_verificare',
  last_heard_at    DATE         NULL,
  notes            TEXT         NULL,
  -- Provenienza, versioning, ricerca
  source           ENUM('manuale','inascolto','import') NOT NULL DEFAULT 'manuale',
  search_text      TEXT         NOT NULL,      -- testo denormalizzato per FULLTEXT
  version          INT UNSIGNED NOT NULL DEFAULT 1,
  created_by       INT UNSIGNED NULL,
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at       DATETIME     NULL,          -- rimozione logica: lo storico resta integro
  PRIMARY KEY (id),
  KEY ix_freq_source (source),
  KEY ix_freq_hz (deleted_at, freq_hz),
  KEY ix_freq_service_status (deleted_at, service_id, status),
  KEY ix_freq_region_province (deleted_at, region_id, province_id),
  KEY ix_freq_mode (deleted_at, mode_id),
  KEY ix_freq_band (deleted_at, itu_band_id, band_plan_id),
  KEY ix_freq_status_heard (deleted_at, status, last_heard_at),
  KEY ix_freq_locator (locator),
  KEY ix_freq_callsign (callsign),
  KEY ix_freq_updated (updated_at),
  FULLTEXT KEY ft_freq_search (search_text),
  CONSTRAINT listenin_ck_freq_hz        CHECK (freq_hz BETWEEN 1000 AND 300000000000),
  CONSTRAINT listenin_ck_freq_cc        CHECK (color_code IS NULL OR color_code <= 15),
  CONSTRAINT listenin_ck_freq_ts        CHECK (dmr_timeslot IS NULL OR dmr_timeslot IN (1,2)),
  CONSTRAINT listenin_ck_freq_ran       CHECK (ran IS NULL OR ran <= 63),
  CONSTRAINT listenin_ck_freq_lat       CHECK (latitude IS NULL OR latitude BETWEEN -90 AND 90),
  CONSTRAINT listenin_ck_freq_lon       CHECK (longitude IS NULL OR longitude BETWEEN -180 AND 180),
  CONSTRAINT listenin_ck_freq_tone      CHECK (
      (tone_type = 'none'  AND ctcss_hz IS NULL AND dcs_code IS NULL) OR
      (tone_type = 'ctcss' AND ctcss_hz IS NOT NULL AND dcs_code IS NULL) OR
      (tone_type = 'dcs'   AND dcs_code IS NOT NULL AND ctcss_hz IS NULL)),
  CONSTRAINT listenin_fk_freq_mode        FOREIGN KEY (mode_id)         REFERENCES listenin_modes (id)          ON DELETE RESTRICT,
  CONSTRAINT listenin_fk_freq_itu         FOREIGN KEY (itu_band_id)     REFERENCES listenin_itu_bands (id)      ON DELETE SET NULL,
  CONSTRAINT listenin_fk_freq_plan        FOREIGN KEY (band_plan_id)    REFERENCES listenin_band_plans (id)     ON DELETE SET NULL,
  CONSTRAINT listenin_fk_freq_service     FOREIGN KEY (service_id)      REFERENCES listenin_services (id)       ON DELETE RESTRICT,
  CONSTRAINT listenin_fk_freq_country     FOREIGN KEY (country_iso2)    REFERENCES listenin_countries (iso2)    ON DELETE SET NULL,
  CONSTRAINT listenin_fk_freq_region      FOREIGN KEY (region_id)       REFERENCES listenin_regions (id)        ON DELETE SET NULL,
  CONSTRAINT listenin_fk_freq_province    FOREIGN KEY (province_id)     REFERENCES listenin_provinces (id)      ON DELETE SET NULL,
  CONSTRAINT listenin_fk_freq_municipality FOREIGN KEY (municipality_id) REFERENCES listenin_municipalities (id) ON DELETE SET NULL,
  CONSTRAINT listenin_fk_freq_entity      FOREIGN KEY (entity_id)       REFERENCES listenin_entities (id)       ON DELETE SET NULL,
  CONSTRAINT listenin_fk_freq_creator     FOREIGN KEY (created_by)      REFERENCES listenin_users (id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Copertura multi-provincia (es. "AL AT VC NO VB BI")
CREATE TABLE listenin_frequency_provinces (
  frequency_id  INT UNSIGNED      NOT NULL,
  province_id   SMALLINT UNSIGNED NOT NULL,
  PRIMARY KEY (frequency_id, province_id),
  KEY ix_fp_province (province_id, frequency_id),
  CONSTRAINT listenin_fk_fp_frequency FOREIGN KEY (frequency_id) REFERENCES listenin_frequencies (id) ON DELETE CASCADE,
  CONSTRAINT listenin_fk_fp_province  FOREIGN KEY (province_id)  REFERENCES listenin_provinces (id)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tracciabilità delle fonti: ogni record della fonte (es. id della fonte esterna) punta al record
-- armonizzato. Più record sorgente duplicati confluiscono nello stesso record (deduplicazione).
CREATE TABLE listenin_frequency_sources (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  frequency_id   INT UNSIGNED NOT NULL,
  source         VARCHAR(32)  NOT NULL,
  source_ref     VARCHAR(64)  NOT NULL,
  raw_hash       CHAR(40)     NOT NULL,      -- impronta della riga originale: rileva variazioni alla fonte
  raw_label      VARCHAR(255) NULL,          -- descrizione originale, per verifica
  merged         TINYINT(1)   NOT NULL DEFAULT 0, -- 1 = confluito come duplicato in un record esistente
  first_seen_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_fs_ref (source, source_ref),
  KEY ix_fs_frequency (frequency_id),
  CONSTRAINT listenin_fk_fs_frequency FOREIGN KEY (frequency_id) REFERENCES listenin_frequencies (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  STORICO E REVISIONI
--  Ogni variazione (proposta utente, modifica moderatore, import)
--  è una revisione; il dettaglio campo per campo sta in revision_changes.
-- ---------------------------------------------------------------------
CREATE TABLE listenin_revisions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  frequency_id   INT UNSIGNED NULL,           -- NULL per proposte di nuovi record non ancora approvate
  action         ENUM('create','update','delete','restore') NOT NULL,
  status         ENUM('pending','approved','rejected','superseded') NOT NULL DEFAULT 'pending',
  origin         ENUM('user','moderator','import','report') NOT NULL DEFAULT 'user',
  base_version   INT UNSIGNED NULL,           -- versione del record al momento della proposta
  author_id      INT UNSIGNED NULL,           -- NULL = sistema o account eliminato
  author_comment VARCHAR(500) NULL,
  reviewer_id    INT UNSIGNED NULL,
  review_note    VARCHAR(500) NULL,
  report_id      INT UNSIGNED NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_rev_status_created (status, created_at),
  KEY ix_rev_frequency (frequency_id, status, id),
  KEY ix_rev_author (author_id, created_at),
  KEY ix_rev_report (report_id),
  CONSTRAINT listenin_fk_rev_frequency FOREIGN KEY (frequency_id) REFERENCES listenin_frequencies (id) ON DELETE CASCADE,
  CONSTRAINT listenin_fk_rev_author    FOREIGN KEY (author_id)    REFERENCES listenin_users (id)       ON DELETE SET NULL,
  CONSTRAINT listenin_fk_rev_reviewer  FOREIGN KEY (reviewer_id)  REFERENCES listenin_users (id)       ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listenin_revision_changes (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  revision_id  INT UNSIGNED NOT NULL,
  field        VARCHAR(40)  NOT NULL,
  old_value    TEXT         NULL,
  new_value    TEXT         NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_change_field (revision_id, field),
  CONSTRAINT listenin_fk_change_revision FOREIGN KEY (revision_id) REFERENCES listenin_revisions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  SEGNALAZIONI
-- ---------------------------------------------------------------------
CREATE TABLE listenin_reports (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  frequency_id     INT UNSIGNED NOT NULL,
  type             ENUM('errore','aggiornamento','rimozione') NOT NULL,
  origin           ENUM('utente','import') NOT NULL DEFAULT 'utente', -- 'import': possibile duplicato rilevato in importazione
  field            VARCHAR(40)  NULL,          -- campo errato / da aggiornare
  current_value    VARCHAR(255) NULL,          -- valore presente al momento della segnalazione
  suggested_value  VARCHAR(255) NULL,
  removal_reason   ENUM('duplicato','dismessa','non_pertinente','altro') NULL,
  duplicate_of_id  INT UNSIGNED NULL,
  message          VARCHAR(1000) NULL,
  reporter_id      INT UNSIGNED NULL,          -- NULL = anonimo o account eliminato
  reporter_ip_hash CHAR(64)     NULL,          -- HMAC dell'IP, azzerato dopo la retention
  status           ENUM('aperta','in_revisione','risolta','archiviata') NOT NULL DEFAULT 'aperta',
  handled_by       INT UNSIGNED NULL,
  resolution_note  VARCHAR(500) NULL,
  revision_id      INT UNSIGNED NULL,          -- revisione generata dalla risoluzione
  created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  handled_at       DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_reports_status_created (status, created_at),
  KEY ix_reports_frequency (frequency_id, status),
  KEY ix_reports_reporter (reporter_id, created_at),
  CONSTRAINT listenin_ck_reports_removal CHECK (type <> 'rimozione' OR removal_reason IS NOT NULL),
  CONSTRAINT listenin_fk_reports_frequency FOREIGN KEY (frequency_id)    REFERENCES listenin_frequencies (id) ON DELETE CASCADE,
  CONSTRAINT listenin_fk_reports_duplicate FOREIGN KEY (duplicate_of_id) REFERENCES listenin_frequencies (id) ON DELETE SET NULL,
  CONSTRAINT listenin_fk_reports_reporter  FOREIGN KEY (reporter_id)     REFERENCES listenin_users (id)       ON DELETE SET NULL,
  CONSTRAINT listenin_fk_reports_handler   FOREIGN KEY (handled_by)      REFERENCES listenin_users (id)       ON DELETE SET NULL,
  CONSTRAINT listenin_fk_reports_revision  FOREIGN KEY (revision_id)     REFERENCES listenin_revisions (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE listenin_revisions
  ADD CONSTRAINT listenin_fk_rev_report FOREIGN KEY (report_id) REFERENCES listenin_reports (id) ON DELETE SET NULL;

-- ---------------------------------------------------------------------
--  AUDIT E IMPORT
-- ---------------------------------------------------------------------
CREATE TABLE listenin_moderation_log (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id     INT UNSIGNED NULL,
  action       VARCHAR(40)  NOT NULL,
  target_type  ENUM('revision','report','user','frequency') NOT NULL,
  target_id    INT UNSIGNED NOT NULL,
  note         VARCHAR(500) NULL,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_modlog_target (target_type, target_id),
  KEY ix_modlog_actor (actor_id, created_at),
  CONSTRAINT listenin_fk_modlog_actor FOREIGN KEY (actor_id) REFERENCES listenin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE listenin_import_runs (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  source       VARCHAR(32)  NOT NULL,
  input_name   VARCHAR(255) NOT NULL,
  dry_run      TINYINT(1)   NOT NULL DEFAULT 0,
  rows_read    INT UNSIGNED NOT NULL DEFAULT 0,
  inserted     INT UNSIGNED NOT NULL DEFAULT 0,
  updated      INT UNSIGNED NOT NULL DEFAULT 0,
  unchanged    INT UNSIGNED NOT NULL DEFAULT 0,
  skipped      INT UNSIGNED NOT NULL DEFAULT 0,
  report_json  MEDIUMTEXT   NULL,
  started_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at  DATETIME     NULL,
  PRIMARY KEY (id),
  KEY ix_import_started (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
