<?php
date_default_timezone_set('America/Bogota');

$dbName = isset($argv[1]) ? $argv[1] : 'matchday_dbalter';

try {
    $pdo = new PDO(
        "mysql:host=localhost;dbname=$dbName;charset=utf8mb4",
        'admin',
        '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("❌ Error BD: " . $e->getMessage() . "\n");
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  📊 DIAGNÓSTICO: $dbName\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Zona Horaria: " . date_default_timezone_get() . "\n";
echo "  Hora Actual:  " . date('Y-m-d H:i:s') . "\n\n";

// Pronósticos pendientes (no verificados)
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.user_id,
        p.home_pred,
        p.away_pred,
        p.result_checked,
        p.points_total,
        m.api_id,
        m.home_team,
        m.away_team,
        m.event_date,
        m.status,
        m.home_score,
        m.away_score,
        TIMESTAMPDIFF(MINUTE, NOW(), m.event_date) as minutos_hasta_inicio
    FROM predictions p
    INNER JOIN matches_cache m ON m.api_id = p.match_api_id
    WHERE p.result_checked = 0
    ORDER BY m.event_date ASC
    LIMIT 30
");
$stmt->execute();
$pendientes = $stmt->fetchAll();

echo "📌 PRONÓSTICOS PENDIENTES (SIN VERIFICAR)\n";
echo "─────────────────────────────────────────────────────────────\n";

if (empty($pendientes)) {
    echo "   ✓ No hay pronósticos pendientes\n\n";
} else {
    foreach ($pendientes as $p) {
        $minutos = $p['minutos_hasta_inicio'];
        $estado = $minutos > 0 ? "🟡 Próximo en $minutos min" : "🔴 Ya empezó";
        
        if ($minutos <= 40) {
            $estado .= " (⏰ BLOQUEADO para editar)";
        }
        
        echo "   [{$p['home_team']} vs {$p['away_team']}]\n";
        echo "   • Tu pronóstico: {$p['home_pred']}-{$p['away_pred']}\n";
        echo "   • Hora partido:  {$p['event_date']} $estado\n";
        echo "   • Estado API:    {$p['status']}\n";
        echo "   • Resultado:     {$p['home_score']}-{$p['away_score']}\n\n";
    }
}

// Pronósticos verificados
$stmt = $pdo->prepare("
    SELECT
        p.id,
        p.user_id,
        p.home_pred,
        p.away_pred,
        p.points_total,
        m.home_team,
        m.away_team,
        m.event_date,
        m.status,
        m.home_score,
        m.away_score,
        u.display_name
    FROM predictions p
    INNER JOIN matches_cache m ON m.api_id = p.match_api_id
    INNER JOIN users u ON u.id = p.user_id
    WHERE p.result_checked = 1
    ORDER BY m.event_date DESC
    LIMIT 15
");
$stmt->execute();
$verificados = $stmt->fetchAll();

echo "✅ PRONÓSTICOS VERIFICADOS\n";
echo "─────────────────────────────────────────────────────────────\n";

if (empty($verificados)) {
    echo "   ⚠️  No hay pronósticos verificados aún\n\n";
} else {
    foreach ($verificados as $p) {
        $pts = $p['points_total'];
        $marca = $pts > 0 ? "✓" : "✗";
        echo "   $marca {$p['display_name']}: {$p['home_pred']}-{$p['away_pred']} → {$p['home_score']}-{$p['away_score']} ({$pts} pts)\n";
    }
    echo "\n";
}

// Estadísticas
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total_pred,
        COUNT(CASE WHEN result_checked = 1 THEN 1 END) as verificados,
        COUNT(CASE WHEN result_checked = 0 THEN 1 END) as pendientes,
        COALESCE(SUM(points_total), 0) as total_puntos
    FROM predictions
");
$stmt->execute();
$stats = $stmt->fetch();

echo "📊 ESTADÍSTICAS GENERALES\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "   • Total pronósticos: {$stats['total_pred']}\n";
echo "   • Verificados: {$stats['verificados']}\n";
echo "   • Pendientes: {$stats['pendientes']}\n";
echo "   • Puntos totales: {$stats['total_puntos']}\n\n";

// Diagnóstico de partidos con scores finales sin verificación
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as cantidad,
        GROUP_CONCAT(CONCAT(home_team, ' vs ', away_team) SEPARATOR ', ') as partidos
    FROM matches_cache
    WHERE status IN ('finished', 'ft', 'full_time')
    AND home_score IS NOT NULL
    AND away_score IS NOT NULL
");
$stmt->execute();
$finished = $stmt->fetch();

echo "🔍 PARTIDOS FINALIZADOS CON SCORES\n";
echo "─────────────────────────────────────────────────────────────\n";
echo "   • Cantidad: {$finished['cantidad']}\n";
echo "   • Partidos: {$finished['partidos']}\n\n";

echo "═══════════════════════════════════════════════════════════════\n";
echo "✓ Diagnóstico completado\n";
echo "═══════════════════════════════════════════════════════════════\n";
?>
