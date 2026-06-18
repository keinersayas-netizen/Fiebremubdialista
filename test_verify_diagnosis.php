<?php
/**
 * Script de diagnóstico para verificar el sistema de puntos
 * Ejecutar: php test_verify_diagnosis.php
 */

define('DB_HOST',    'localhost');
define('DB_USER',    'admin');
define('DB_PASS',    '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4');
define('DB_NAME',    'matchday_db');
define('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die("❌ Error de conexión BD: " . $e->getMessage() . "\n");
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  DIAGNÓSTICO DE VERIFICACIÓN DE PRONÓSTICOS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// 1. Verificar estructura de tablas
echo "1️⃣  VERIFICANDO ESTRUCTURA DE TABLAS...\n";
$tables = ['users', 'predictions', 'matches_cache', 'sessions'];
foreach ($tables as $table) {
    $stmt = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=? AND table_name=?");
    $stmt->execute([DB_NAME, $table]);
    if ($stmt->fetch()) {
        echo "   ✓ Tabla '$table' existe\n";
    } else {
        echo "   ❌ Tabla '$table' NO existe\n";
    }
}
echo "\n";

// 2. Contar registros
echo "2️⃣  CONTEO DE REGISTROS...\n";
$counts = [];
foreach (['users', 'predictions', 'matches_cache'] as $table) {
    $result = $pdo->query("SELECT COUNT(*) as cnt FROM $table")->fetch();
    $counts[$table] = $result['cnt'];
    echo "   • $table: {$result['cnt']} registros\n";
}
echo "\n";

// 3. Pronósticos pendientes de verificación
echo "3️⃣  PRONÓSTICOS PENDIENTES DE VERIFICACIÓN...\n";
$stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt FROM predictions p
    INNER JOIN matches_cache m ON m.api_id = p.match_api_id
    WHERE m.status IN ('finished','ft','full_time')
    AND m.home_score IS NOT NULL
    AND m.away_score IS NOT NULL
    AND p.result_checked = 0
");
$stmt->execute();
$pending = $stmt->fetch();
echo "   • Pronósticos sin verificar: {$pending['cnt']}\n";

if ($pending['cnt'] > 0) {
    echo "\n   Detalles:\n";
    $stmt = $pdo->prepare("
        SELECT
            p.id, p.user_id, p.home_pred, p.away_pred,
            m.home_team, m.away_team, m.home_score, m.away_score,
            u.display_name
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        INNER JOIN users u ON u.id = p.user_id
        WHERE m.status IN ('finished','ft','full_time')
        AND m.home_score IS NOT NULL
        AND m.away_score IS NOT NULL
        AND p.result_checked = 0
        LIMIT 5
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $row) {
        echo sprintf("   - %s: %s %d-%d %s (predijo %d-%d)\n",
            $row['display_name'],
            $row['home_team'],
            $row['home_score'],
            $row['away_score'],
            $row['away_team'],
            $row['home_pred'],
            $row['away_pred']
        );
    }
}
echo "\n";

// 4. Pronósticos con puntos ya calculados
echo "4️⃣  PRONÓSTICOS VERIFICADOS...\n";
$stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt FROM predictions WHERE result_checked = 1
");
$stmt->execute();
$verified = $stmt->fetch();
echo "   • Pronósticos verificados: {$verified['cnt']}\n";

// Distribución de puntos
$stmt = $pdo->prepare("
    SELECT
        CASE
            WHEN points_total = 0 THEN '0 pts'
            WHEN points_total = 2 THEN '2 pts'
            WHEN points_total = 5 THEN '5 pts'
            WHEN points_total = 7 THEN '7 pts'
            WHEN points_total = 12 THEN '12 pts'
            ELSE 'Otro'
        END as points,
        COUNT(*) as count
    FROM predictions
    WHERE result_checked = 1
    GROUP BY CASE
        WHEN points_total = 0 THEN '0 pts'
        WHEN points_total = 2 THEN '2 pts'
        WHEN points_total = 5 THEN '5 pts'
        WHEN points_total = 7 THEN '7 pts'
        WHEN points_total = 12 THEN '12 pts'
        ELSE 'Otro'
    END
    ORDER BY MAX(points_total) DESC
");
$stmt->execute();
echo "\n   Distribución de puntos:\n";
foreach ($stmt->fetchAll() as $row) {
    echo "   • {$row['points']}: {$row['count']} predicciones\n";
}
echo "\n";

// 5. Partidos finalizados sin resultados
echo "5️⃣  ANÁLISIS DE PARTIDOS EN CACHÉ...\n";
$stmt = $pdo->prepare("
    SELECT COUNT(*) as cnt FROM matches_cache
    WHERE status IN ('finished','ft','full_time')
    AND (home_score IS NULL OR away_score IS NULL)
");
$stmt->execute();
$incomplete = $stmt->fetch();
echo "   • Partidos finalizados sin scores: {$incomplete['cnt']}\n";

// Verificar estatus de los partidos
$stmt = $pdo->prepare("
    SELECT DISTINCT status, COUNT(*) as cnt
    FROM matches_cache
    GROUP BY status
");
$stmt->execute();
echo "\n   Estados de partidos en caché:\n";
foreach ($stmt->fetchAll() as $row) {
    echo "   • {$row['status']}: {$row['cnt']} partidos\n";
}

// Verificar si hay partidos que debería tener resultados
$stmt = $pdo->prepare("
    SELECT
        m.api_id, m.home_team, m.away_team, m.status,
        m.home_score, m.away_score,
        COUNT(p.id) as num_predictions
    FROM matches_cache m
    LEFT JOIN predictions p ON p.match_api_id = m.api_id
    WHERE p.id IS NOT NULL
    GROUP BY m.api_id
    ORDER BY m.event_date DESC
    LIMIT 10
");
$stmt->execute();
echo "\n   Partidos con pronósticos (últimos 10):\n";
foreach ($stmt->fetchAll() as $row) {
    $score = ($row['home_score'] !== null && $row['away_score'] !== null)
        ? "{$row['home_score']}-{$row['away_score']}"
        : "sin resultado";
    echo sprintf("   • %s vs %s [%s] %s - %d pronósticos\n",
        $row['home_team'],
        $row['away_team'],
        $row['status'],
        $score,
        $row['num_predictions']
    );
}
echo "\n";

// 6. Estadísticas de usuarios
echo "6️⃣  TOP 5 USUARIOS POR PUNTOS...\n";
$stmt = $pdo->prepare("
    SELECT
        u.id, u.display_name,
        COALESCE(SUM(p.points_total), 0) as total_points,
        COUNT(p.id) as total_predictions,
        COUNT(CASE WHEN p.result_checked = 1 THEN 1 END) as verified_predictions
    FROM users u
    LEFT JOIN predictions p ON u.id = p.user_id
    GROUP BY u.id
    ORDER BY total_points DESC
    LIMIT 5
");
$stmt->execute();
foreach ($stmt->fetchAll() as $row) {
    $accuracy = $row['verified_predictions'] > 0 ? round(100 * $row['verified_predictions'] / $row['total_predictions'], 1) : 0;
    echo sprintf("   • %s: %d pts (%d/%d verificados, %d%% exactitud)\n",
        $row['display_name'],
        $row['total_points'],
        $row['verified_predictions'],
        $row['total_predictions'],
        $accuracy
    );
}
echo "\n";

// 7. Partidos en caché
echo "7️⃣  ESTADO DEL CACHÉ DE PARTIDOS...\n";
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        COUNT(CASE WHEN status IN ('finished','ft','full_time') THEN 1 END) as finished,
        COUNT(CASE WHEN status NOT IN ('finished','ft','full_time') THEN 1 END) as pending
    FROM matches_cache
");
$stmt->execute();
$cache = $stmt->fetch();
echo "   • Partidos en caché: {$cache['total']}\n";
echo "   • Finalizados: {$cache['finished']}\n";
echo "   • Pendientes: {$cache['pending']}\n";
echo "\n";

// 8. Información de edad del caché
echo "8️⃣  EDAD DEL CACHÉ...\n";
$stmt = $pdo->prepare("
    SELECT
        MIN(cached_at) as oldest,
        MAX(cached_at) as newest,
        AVG(UNIX_TIMESTAMP(NOW()) - UNIX_TIMESTAMP(cached_at)) as avg_age_seconds
    FROM matches_cache
    WHERE cached_at IS NOT NULL
");
$stmt->execute();
$age = $stmt->fetch();
if ($age['oldest']) {
    echo "   • Caché más antiguo: {$age['oldest']}\n";
    echo "   • Caché más reciente: {$age['newest']}\n";
    echo "   • Edad promedio: " . round($age['avg_age_seconds']) . " segundos\n";
}
echo "\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "✓ Diagnóstico completado\n";
echo "═══════════════════════════════════════════════════════════════\n";
?>
