<?php
define('DB_HOST',    'localhost');
define('DB_USER',    'admin');
define('DB_PASS',    '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4');
define('DB_NAME',    'matchday_db');
define('DB_CHARSET', 'utf8mb4');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("❌ BD Error: " . $e->getMessage() . "\n");
}

echo "📊 ANÁLISIS DETALLADO DE PRONÓSTICOS\n";
echo "═════════════════════════════════════════════════════════════\n\n";

// Partidos con pronósticos: cuáles finalizaron
$sql = "
    SELECT 
        m.api_id, m.home_team, m.away_team, m.status,
        m.home_score, m.away_score,
        DATE(m.event_date) as fecha,
        COUNT(p.id) as num_predicciones,
        COUNT(CASE WHEN p.result_checked = 1 THEN 1 END) as verificados
    FROM matches_cache m
    INNER JOIN predictions p ON p.match_api_id = m.api_id
    GROUP BY m.api_id
    ORDER BY m.event_date DESC
";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$matches = $stmt->fetchAll();

echo "📋 PRONÓSTICOS POR PARTIDO (total: " . count($matches) . ")\n\n";

$finalizados = 0;
$sin_finalizar = 0;

foreach ($matches as $m) {
    $estado = in_array($m['status'], ['finished', 'ft', 'full_time']) ? '✓' : '○';
    $score = ($m['home_score'] !== null) ? "{$m['home_score']}-{$m['away_score']}" : "SIN RESULTADO";
    
    echo sprintf("%s %-20s %-20s [%-12s] %s | %d predicciones (%d verificadas)\n",
        $estado,
        substr($m['home_team'], 0, 20),
        substr($m['away_team'], 0, 20),
        $m['status'] ?? 'unknown',
        $score,
        $m['num_predicciones'],
        $m['verificados']
    );
    
    if (in_array($m['status'], ['finished', 'ft', 'full_time'])) {
        $finalizados++;
    } else {
        $sin_finalizar++;
    }
}

echo "\n";
echo "✓ Partidos finalizados: $finalizados\n";
echo "○ Partidos sin finalizar: $sin_finalizar\n";
echo "\n";

// Detalle de pronósticos sin verificar de partidos finalizados
echo "📌 PRONÓSTICOS SIN VERIFICAR DE PARTIDOS FINALIZADOS:\n";
$sql = "
    SELECT 
        p.id, p.user_id, p.home_pred, p.away_pred,
        m.home_team, m.away_team, m.home_score, m.away_score,
        u.display_name
    FROM predictions p
    INNER JOIN matches_cache m ON m.api_id = p.match_api_id
    INNER JOIN users u ON u.id = p.user_id
    WHERE m.status IN ('finished', 'ft', 'full_time')
    AND m.home_score IS NOT NULL
    AND p.result_checked = 0
    LIMIT 10
";

$stmt = $pdo->prepare($sql);
$stmt->execute();
$pending = $stmt->fetchAll();

if (empty($pending)) {
    echo "   → No hay pronósticos pendientes en partidos finalizados\n";
} else {
    foreach ($pending as $p) {
        echo sprintf("   • %s: %s (%d-%d) vs %s (%d-%d)\n",
            $p['display_name'],
            $p['home_team'],
            $p['home_pred'],
            $p['away_pred'],
            $p['away_team'],
            $p['home_score'],
            $p['away_score']
        );
    }
}

echo "\n═════════════════════════════════════════════════════════════\n";
?>
