-- ============================================================
--  MATCHDAY — Base de Datos MySQL
--  Liga & Mundial 2026 — Sistema de Pronósticos
--  Versión: 2.0
-- ============================================================

CREATE DATABASE IF NOT EXISTS matchday_db
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE matchday_db;

-- ============================================================
-- TABLA: users
-- ============================================================
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    email         VARCHAR(120) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    avatar_url    VARCHAR(255) DEFAULT NULL,
    display_name  VARCHAR(80)  DEFAULT NULL,
    total_points  INT          NOT NULL DEFAULT 0,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login    DATETIME     DEFAULT NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    INDEX idx_username  (username),
    INDEX idx_email     (email),
    INDEX idx_points    (total_points DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: sessions  (manejo de sesiones server-side)
-- ============================================================
CREATE TABLE IF NOT EXISTS sessions (
    id         VARCHAR(128) PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME     NOT NULL,
    ip_address VARCHAR(45)  DEFAULT NULL,
    user_agent TEXT         DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id   (user_id),
    INDEX idx_expires   (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: matches_cache
-- Caché local de partidos traídos de la API externa
-- ============================================================
CREATE TABLE IF NOT EXISTS matches_cache (
    api_id          INT          NOT NULL PRIMARY KEY,   -- ID del partido en la API externa
    league_id       INT          DEFAULT NULL,
    league_name     VARCHAR(120) DEFAULT NULL,
    league_country  VARCHAR(80)  DEFAULT NULL,
    season_id       INT          DEFAULT NULL,
    season_name     VARCHAR(80)  DEFAULT NULL,
    home_team       VARCHAR(100) NOT NULL,
    away_team       VARCHAR(100) NOT NULL,
    event_date      DATETIME     NOT NULL,
    status          VARCHAR(30)  NOT NULL DEFAULT 'notstarted',
    home_score      TINYINT UNSIGNED DEFAULT NULL,
    away_score      TINYINT UNSIGNED DEFAULT NULL,
    home_score_ht   TINYINT UNSIGNED DEFAULT NULL,
    away_score_ht   TINYINT UNSIGNED DEFAULT NULL,
    current_minute  SMALLINT UNSIGNED DEFAULT NULL,
    period          VARCHAR(10)  DEFAULT NULL,
    round_number    SMALLINT     DEFAULT NULL,
    is_mundial      TINYINT(1)   NOT NULL DEFAULT 0,
    raw_json        JSON         DEFAULT NULL,           -- payload completo de la API
    cached_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_league    (league_id),
    INDEX idx_date      (event_date),
    INDEX idx_status    (status),
    INDEX idx_mundial   (is_mundial)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: predictions
-- Un pronóstico por usuario × partido
-- ============================================================
CREATE TABLE IF NOT EXISTS predictions (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    match_api_id    INT          NOT NULL,               -- referencia a matches_cache.api_id
    home_pred       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    away_pred       TINYINT UNSIGNED NOT NULL DEFAULT 0,
    -- Campos calculados al verificar el resultado
    points_winner   TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 3 pts si acertó el ganador/empate
    points_exact    TINYINT UNSIGNED NOT NULL DEFAULT 0, -- 2 pts bonus marcador exacto
    points_total    TINYINT UNSIGNED NOT NULL DEFAULT 0, -- suma
    result_checked  TINYINT(1)   NOT NULL DEFAULT 0,     -- 1 = ya se calcularon puntos
    -- Cuándo se guardó / modificó
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_match (user_id, match_api_id),   -- un pronóstico por partido
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (match_api_id) REFERENCES matches_cache(api_id) ON DELETE CASCADE,
    INDEX idx_user      (user_id),
    INDEX idx_match     (match_api_id),
    INDEX idx_checked   (result_checked)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLA: leaderboard_snapshots
-- Foto semanal del ranking para histórico
-- ============================================================
CREATE TABLE IF NOT EXISTS leaderboard_snapshots (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    week_start  DATE         NOT NULL,
    position    SMALLINT UNSIGNED NOT NULL,
    points      INT          NOT NULL DEFAULT 0,
    correct     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    total_preds SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_user_week (user_id, week_start),
    INDEX idx_week (week_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- VISTA: user_stats
-- Estadísticas en tiempo real de cada usuario
-- ============================================================
CREATE OR REPLACE VIEW user_stats AS
SELECT
    u.id                                               AS user_id,
    u.username,
    u.display_name,
    u.avatar_url,
    COUNT(p.id)                                        AS total_predictions,
    SUM(p.points_total)                                AS total_points,
    SUM(CASE WHEN p.points_total  > 0 THEN 1 ELSE 0 END)  AS correct_predictions,
    SUM(CASE WHEN p.points_exact  > 0 THEN 1 ELSE 0 END)  AS exact_scores,
    SUM(CASE WHEN p.result_checked = 0 THEN 1 ELSE 0 END) AS pending_predictions,
    CASE
        WHEN COUNT(p.id) > 0
        THEN ROUND(SUM(CASE WHEN p.points_total > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(p.id), 1)
        ELSE 0
    END                                                AS accuracy_pct
FROM users u
LEFT JOIN predictions p ON p.user_id = u.id AND p.result_checked = 1
WHERE u.is_active = 1
GROUP BY u.id, u.username, u.display_name, u.avatar_url;

-- ============================================================
-- VISTA: global_leaderboard
-- Ranking global por puntos
-- ============================================================
CREATE OR REPLACE VIEW global_leaderboard AS
SELECT
    ROW_NUMBER() OVER (ORDER BY SUM(p.points_total) DESC, COUNT(p.id) ASC) AS position,
    u.id      AS user_id,
    u.username,
    u.display_name,
    u.avatar_url,
    COUNT(p.id)              AS total_predictions,
    SUM(p.points_total)      AS total_points,
    SUM(CASE WHEN p.points_total > 0 THEN 1 ELSE 0 END) AS correct_predictions,
    CASE
        WHEN COUNT(p.id) > 0
        THEN ROUND(SUM(CASE WHEN p.points_total > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(p.id), 1)
        ELSE 0
    END AS accuracy_pct
FROM users u
LEFT JOIN predictions p ON p.user_id = u.id AND p.result_checked = 1
WHERE u.is_active = 1
GROUP BY u.id, u.username, u.display_name, u.avatar_url
ORDER BY total_points DESC;

-- ============================================================
-- STORED PROCEDURE: sp_verify_predictions
-- Verifica y asigna puntos a todos los pronósticos pendientes
-- de partidos ya finalizados.
-- Sistema de puntos:
--   3 pts → acertó ganador / empate
--   2 pts → marcador exacto (bonus adicional, total = 5)
--   0 pts → falló
-- ============================================================
DELIMITER $$

CREATE PROCEDURE sp_verify_predictions()
BEGIN
    UPDATE predictions p
    JOIN matches_cache m ON m.api_id = p.match_api_id
    SET
        p.points_winner = CASE
            WHEN (m.home_score > m.away_score AND p.home_pred > p.away_pred) THEN 3
            WHEN (m.home_score < m.away_score AND p.home_pred < p.away_pred) THEN 3
            WHEN (m.home_score = m.away_score AND p.home_pred = p.away_pred) THEN 3
            ELSE 0
        END,
        p.points_exact = CASE
            WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 2
            ELSE 0
        END,
        p.points_total = CASE
            WHEN (m.home_score > m.away_score AND p.home_pred > p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 5 ELSE 3 END
            WHEN (m.home_score < m.away_score AND p.home_pred < p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 5 ELSE 3 END
            WHEN (m.home_score = m.away_score AND p.home_pred = p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 5 ELSE 3 END
            ELSE 0
        END,
        p.result_checked = 1
    WHERE
        p.result_checked = 0
        AND m.status      = 'finished'
        AND m.home_score  IS NOT NULL
        AND m.away_score  IS NOT NULL;

    -- Actualizar el total acumulado en users
    UPDATE users u
    SET u.total_points = (
        SELECT COALESCE(SUM(p2.points_total), 0)
        FROM predictions p2
        WHERE p2.user_id = u.id AND p2.result_checked = 1
    );
END$$

DELIMITER ;

-- ============================================================
-- DATOS DE PRUEBA (opcional — comentar en producción)
-- ============================================================

-- Usuario demo
INSERT IGNORE INTO users (username, email, password_hash, display_name)
VALUES ('demo', 'demo@matchday.app', '$2y$12$DEMO_HASH_REPLACE_IN_PROD', 'Demo User');

-- ============================================================
-- ÍNDICES adicionales de rendimiento
-- ============================================================
ALTER TABLE matches_cache
    ADD INDEX IF NOT EXISTS idx_event_date_status (event_date, status);

ALTER TABLE predictions
    ADD INDEX IF NOT EXISTS idx_user_checked (user_id, result_checked);

-- ============================================================
-- FIN DEL SCRIPT
-- ============================================================
