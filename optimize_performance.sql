-- ============================================================
--  OPTIMIZACIONES DE RENDIMIENTO - Módulo "Mis Pronósticos"
--  Auditoría de Optimización v1.0
--  Fecha: 2026-06-02
-- ============================================================

USE matchday_db;

-- ============================================================
-- 1. ÍNDICES COMPUESTOS CRÍTICOS
-- ============================================================

-- Búsqueda de pronósticos del usuario filtrando por estado de verificación
ALTER TABLE predictions
    ADD INDEX IF NOT EXISTS idx_user_checked_match (user_id, result_checked, match_api_id);

-- Búsqueda de pronósticos pendientes de usuario
ALTER TABLE predictions
    ADD INDEX IF NOT EXISTS idx_user_pending (user_id, result_checked, updated_at);

-- Búsqueda de todos los pronósticos pendientes (para verificación masiva)
ALTER TABLE predictions
    ADD INDEX IF NOT EXISTS idx_pending_matches (result_checked, match_api_id);

-- Búsqueda eficiente de matches finalizados con scores
ALTER TABLE matches_cache
    ADD INDEX IF NOT EXISTS idx_finished_scores (status, home_score, away_score);

-- Búsqueda de partidos en vivo por fecha
ALTER TABLE matches_cache
    ADD INDEX IF NOT EXISTS idx_status_date (status, event_date DESC);

-- Búsqueda de pronósticos para leaderboard
ALTER TABLE predictions
    ADD INDEX IF NOT EXISTS idx_user_verified_points (user_id, result_checked, points_total);

-- ============================================================
-- 2. ANÁLISIS Y LÍMITES RECOMENDADOS
-- ============================================================

-- Contar pronósticos por usuario (para determinar si usar paginación)
-- SELECT user_id, COUNT(*) as total_preds FROM predictions GROUP BY user_id ORDER BY total_preds DESC;

-- ============================================================
-- 3. STORED PROCEDURE OPTIMIZADO - Verificación de pronósticos
-- ============================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS sp_verify_predictions_optimized$$

CREATE PROCEDURE sp_verify_predictions_optimized(IN p_user_id INT UNSIGNED)
BEGIN
    DECLARE v_rows_affected INT DEFAULT 0;
    
    -- Verificar únicamente pronósticos pendientes de partidos finalizados
    -- Sin llamar a refreshPredictedMatches() para evitar llamadas HTTP
    UPDATE predictions p
    INNER JOIN matches_cache m ON m.api_id = p.match_api_id
    SET
        p.points_exact = CASE
            WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 12
            ELSE 0
        END,
        p.points_winner = CASE
            WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 0
            WHEN (m.home_score > m.away_score AND p.home_pred > p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred OR m.away_score = p.away_pred) THEN 7 ELSE 5 END
            WHEN (m.home_score < m.away_score AND p.home_pred < p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred OR m.away_score = p.away_pred) THEN 7 ELSE 5 END
            WHEN (m.home_score = m.away_score AND p.home_pred = p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 0 ELSE 5 END
            WHEN (m.home_score = p.home_pred OR m.away_score = p.away_pred) THEN 2
            ELSE 0
        END,
        p.points_total = p.points_exact + (CASE
            WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 0
            WHEN (m.home_score > m.away_score AND p.home_pred > p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred OR m.away_score = p.away_pred) THEN 7 ELSE 5 END
            WHEN (m.home_score < m.away_score AND p.home_pred < p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred OR m.away_score = p.away_pred) THEN 7 ELSE 5 END
            WHEN (m.home_score = m.away_score AND p.home_pred = p.away_pred) THEN
                CASE WHEN (m.home_score = p.home_pred AND m.away_score = p.away_pred) THEN 0 ELSE 5 END
            WHEN (m.home_score = p.home_pred OR m.away_score = p.away_pred) THEN 2
            ELSE 0
        END),
        p.result_checked = 1,
        p.last_real_home = m.home_score,
        p.last_real_away = m.away_score
    WHERE
        p.user_id = p_user_id
        AND p.result_checked = 0
        AND m.status IN ('finished','ft','full_time')
        AND m.home_score IS NOT NULL
        AND m.away_score IS NOT NULL;
    
    GET DIAGNOSTICS v_rows_affected = ROW_COUNT;
    
    -- Actualizar total_points del usuario
    UPDATE users u
    SET u.total_points = (
        SELECT COALESCE(SUM(p2.points_total), 0)
        FROM predictions p2
        WHERE p2.user_id = p_user_id AND p2.result_checked = 1
    )
    WHERE u.id = p_user_id;
    
    SELECT v_rows_affected AS rows_verified;
END$$

DELIMITER ;

-- ============================================================
-- 4. VISTA OPTIMIZADA - Predicciones del usuario
-- ============================================================

DROP VIEW IF EXISTS user_predictions_optimized;

CREATE VIEW user_predictions_optimized AS
SELECT
    p.id,
    p.match_api_id,
    p.home_pred,
    p.away_pred,
    p.points_exact,
    p.points_winner,
    p.points_total,
    p.result_checked,
    p.created_at,
    p.updated_at,
    m.home_team,
    m.away_team,
    m.event_date,
    m.status,
    m.home_score  AS real_home,
    m.away_score  AS real_away,
    m.league_name,
    m.is_mundial,
    -- Tipo de resultado para frontend
    CASE
        WHEN p.result_checked = 0 THEN 'pending'
        WHEN p.points_total = 0 THEN 'loss'
        WHEN p.points_exact > 0 THEN 'exact'
        ELSE 'winner'
    END AS result_type
FROM predictions p
INNER JOIN matches_cache m ON m.api_id = p.match_api_id
ORDER BY m.event_date DESC;

-- ============================================================
-- 5. VISTA PARA PRONÓSTICOS DE HOY/MAÑANA (Todos los usuarios)
-- ============================================================

DROP VIEW IF EXISTS predictions_today_tomorrow;

CREATE VIEW predictions_today_tomorrow AS
SELECT
    p.id,
    p.user_id,
    p.match_api_id,
    p.home_pred,
    p.away_pred,
    p.points_total,
    p.result_checked,
    m.home_team,
    m.away_team,
    m.event_date,
    m.status,
    m.home_score  AS real_home,
    m.away_score  AS real_away,
    u.username,
    u.avatar_url,
    u.id AS user_id_col
FROM predictions p
INNER JOIN matches_cache m ON m.api_id = p.match_api_id
INNER JOIN users u ON u.id = p.user_id
WHERE DATE(m.event_date) IN (CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 DAY))
ORDER BY m.event_date ASC, u.username ASC;

-- ============================================================
-- 6. ESTADÍSTICAS Y DIAGNÓSTICO
-- ============================================================

-- Ver cantidad de pronósticos por usuario
-- SELECT user_id, COUNT(*) as total, SUM(points_total) as points FROM predictions GROUP BY user_id ORDER BY total DESC;

-- Ver partidos sin cachear
-- SELECT COUNT(*) as uncached_matches FROM predictions WHERE match_api_id NOT IN (SELECT api_id FROM matches_cache);

-- Ver eficiencia de índices
-- ANALYZE TABLE predictions;
-- ANALYZE TABLE matches_cache;
-- ANALYZE TABLE users;

-- ============================================================
-- 7. TABLA DE CACHÉ DE VERIFICACIONES (Opcional)
-- ============================================================

CREATE TABLE IF NOT EXISTS verification_cache (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    verified_count  INT UNSIGNED NOT NULL DEFAULT 0,
    total_points    INT UNSIGNED NOT NULL DEFAULT 0,
    last_verified   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user (user_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_last_verified (last_verified)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- FIN DE OPTIMIZACIONES
-- ============================================================
