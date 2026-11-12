-- =====================================================================
-- my_STRV - MariaDB Database Schema
-- Multi-Tenant Strava Analytics Platform
-- Tabellen-Präfix: my_strv_
-- Charset: utf8mb4 | Engine: InnoDB (Cascade Deletes supported)
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- 1. my_strv_users: Hauptbenutzertabelle mit Authentifizierung & API-Keys
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `my_strv_users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `custom_client_id` VARCHAR(50) NULL,
    `custom_client_secret` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `last_login` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. my_strv_strava_tokens: OAuth2-Tokens pro Benutzer
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `my_strv_strava_tokens` (
    `user_id` INT PRIMARY KEY,
    `athlete_id` BIGINT NOT NULL,
    `access_token` VARCHAR(255) NOT NULL,
    `refresh_token` VARCHAR(255) NOT NULL,
    `expires_at` INT NOT NULL,
    `scope` VARCHAR(100) NULL,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_my_strv_tokens_user` FOREIGN KEY (`user_id`)
        REFERENCES `my_strv_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. my_strv_athlete_settings: Benutzerspezifische Leistungsparameter
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `my_strv_athlete_settings` (
    `user_id` INT PRIMARY KEY,
    `ftp` INT DEFAULT 250,
    `weight` FLOAT DEFAULT 75.0,
    `max_hr` INT DEFAULT 190,
    `rest_hr` INT DEFAULT 50,
    CONSTRAINT `fk_my_strv_athlete_settings_user` FOREIGN KEY (`user_id`)
        REFERENCES `my_strv_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. my_strv_activities: Aktivitäten mit Mandantentrennung & Metriken
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `my_strv_activities` (
    `id` BIGINT NOT NULL,
    `user_id` INT NOT NULL,
    `athlete_id` BIGINT NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `sport_type` VARCHAR(50) NOT NULL,
    `start_date` DATETIME NOT NULL,
    `distance` FLOAT NOT NULL DEFAULT 0,
    `moving_time` INT NOT NULL DEFAULT 0,
    `elapsed_time` INT NOT NULL DEFAULT 0,
    `total_elevation_gain` FLOAT NOT NULL DEFAULT 0,
    
    -- Leistungs- & Herzfrequenzwerte
    `average_speed` FLOAT NULL,
    `max_speed` FLOAT NULL,
    `average_cadence` FLOAT NULL,
    `average_heartrate` FLOAT NULL,
    `max_heartrate` FLOAT NULL,
    `average_watts` FLOAT NULL,
    `max_watts` FLOAT NULL,
    `weighted_average_watts` FLOAT NULL,
    `kilojoules` FLOAT NULL,
    `suffer_score` INT NULL,
    
    -- Ruder-spezifische Metriken
    `split_500m_seconds` FLOAT NULL,
    `distance_per_stroke` FLOAT NULL,
    `joules_per_stroke` FLOAT NULL,
    `max_cadence` FLOAT NULL,
    
    -- Rad-spezifische Metriken
    `w_kg` FLOAT NULL,
    `normalized_power` FLOAT NULL,
    `intensity_factor` FLOAT NULL,
    `tss` FLOAT NULL,
    `efficiency_factor` FLOAT NULL,
    `vam` FLOAT NULL,
    
    -- GPS- & Kartendaten
    `summary_polyline` TEXT NULL,
    `start_lat` DOUBLE NULL,
    `start_lng` DOUBLE NULL,
    
    -- Berechnete Felder (Stored Generated Columns mit Null-/Divisionsschutz)
    `elevation_per_km` FLOAT GENERATED ALWAYS AS (
        IF(`distance` > 0, ROUND(`total_elevation_gain` / (`distance` / 1000), 2), 0)
    ) STORED,
    `pause_time` INT GENERATED ALWAYS AS (
        `elapsed_time` - `moving_time`
    ) STORED,
    
    -- Rohdaten als JSON-Backup
    `raw_summary` JSON NULL,
    
    PRIMARY KEY (`id`, `user_id`),
    CONSTRAINT `fk_my_strv_activities_user` FOREIGN KEY (`user_id`)
        REFERENCES `my_strv_users` (`id`) ON DELETE CASCADE,
    INDEX `idx_my_strv_act_user_date` (`user_id`, `start_date`),
    INDEX `idx_my_strv_act_user_sport` (`user_id`, `sport_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. my_strv_gear: Ausrüstung pro Benutzer
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `my_strv_gear` (
    `id` VARCHAR(50) NOT NULL,
    `user_id` INT NOT NULL,
    `name` VARCHAR(100) NULL,
    `brand_name` VARCHAR(100) NULL,
    `model_name` VARCHAR(100) NULL,
    `distance` FLOAT NOT NULL DEFAULT 0,
    `primary_gear` BOOLEAN NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`, `user_id`),
    CONSTRAINT `fk_my_strv_gear_user` FOREIGN KEY (`user_id`)
        REFERENCES `my_strv_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. my_strv_sync_logs: Synchronisations-Historie & Rate-Limits pro Benutzer
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `my_strv_sync_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `sync_type` VARCHAR(50) NOT NULL,
    `status` VARCHAR(50) NOT NULL,
    `items_synced` INT NOT NULL DEFAULT 0,
    `rate_limit_15min_remaining` INT NULL,
    `message` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_my_strv_sync_logs_user` FOREIGN KEY (`user_id`)
        REFERENCES `my_strv_users` (`id`) ON DELETE CASCADE,
    INDEX `idx_my_strv_sync_user` (`user_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. my_strv_goals: Saisonale & periodische Ziele (Woche / Monat / Jahr)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `my_strv_goals` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `title` VARCHAR(150) NOT NULL,
    `sport_type` VARCHAR(50) NULL COMMENT 'NULL = Alle Sportarten',
    `metric_type` ENUM('distance', 'time', 'elevation', 'calories', 'workouts') NOT NULL DEFAULT 'distance',
    `target_value` DOUBLE NOT NULL,
    `period_type` ENUM('weekly', 'monthly', 'yearly') NOT NULL DEFAULT 'yearly',
    `year` INT NOT NULL DEFAULT 2026,
    `seasonality` ENUM('linear', 'summer_peak', 'winter_peak') NOT NULL DEFAULT 'linear',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_my_strv_goals_user` FOREIGN KEY (`user_id`)
        REFERENCES `my_strv_users` (`id`) ON DELETE CASCADE,
    INDEX `idx_my_strv_goals_user` (`user_id`, `year`, `period_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
