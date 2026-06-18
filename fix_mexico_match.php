<?php
// Script para buscar y actualizar pronósticos del partido México vs South Africa

define('DB_HOST',    'localhost');
define('DB_USER',    'admin');
define('DB_PASS',    '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4');
define('DB_NAME',    'matchday_db');
define('DB_CHARSET', 'utf8mb4');

define('EXT_API_BASE',  'https://sports.bzzoiro.com/api');
define('EXT_API_TOKEN', '5d5b7145bcf005bd2b0e6a26e43a956c3a130d5f');

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=".DB_CHARSET,
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    die("❌ BD Error: " . $e->getMessage() . "\n");
}

echo "🔍 BÚSQUEDA: México vs South Africa\n";
echo "═════════════════════════════════════════════════════════════\n\n";

// Buscar el partido
$stmt = $pdo->prepare("
    SELECT 
        m.api_id, m.home_team, m.away_team, m.status,
        m.home_score, m.away_score, m.event_date, m.cached_at
    FROM matches_cache m
    WHERE (m.home_team LIKE '%Mexico%' OR m.home_team LIKE '%México%')
    AND (m.away_team LIKE '%South Africa%' OR m.away_team LIKE '%Africa del Sur%')
    LIMIT 1
");
$stmt->execute();
$match = $stmt->fetch();

if (!$match) {
    echo "❌ Partido no encontrado en caché\n";
    echo "\nBuscando partidos de México...\n";
    $stmt = $pdo->prepare("SELECT api_id, home_team, away_team FROM matches_cache WHERE home_team LIKE '%Mexico%' OR away_team LIKE '%Mexico%' LIMIT 5");
    $stmt->execute();
    foreach ($stmt->fetchAll() as $m) {
        echo "   • {$m['home_team']} vs {$m['away_team']}\n";
    }
    exit;
}

echo "✓ PARTIDO ENCONTRADO\n";
echo "   ID: {$match['api_id']}\n";
echo "   {$match['home_team']} vs {$match['away_team']}\n";
echo "   Status: {$match['status']}\n";
echo "   Score: " . ($match['home_score'] !== null ? "{$match['home_score']}-{$match['away_score']}" : "SIN RESULTADO") . "\n";
echo "   Cached: {$match['cached_at']}\n";
echo "   Event: {$match['event_date']}\n\n";

// Buscar pronósticos de este partido
$stmt = $pdo->prepare("
    SELECT 
        p.id, p.user_id, p.home_pred, p.away_pred,
        p.points_total, p.result_checked,
        u.display_name, u.email
    FROM predictions p
    INNER JOIN users u ON u.id = p.user_id
    WHERE p.match_api_id = ?
    ORDER BY u.display_name
");
$stmt->execute([$match['api_id']]);
$predictions = $stmt->fetchAll();

echo "📋 PRONÓSTICOS (" . count($predictions) . ")\n";
foreach ($predictions as $p) {
    $estado = $p['result_checked'] ? "✓ Verificado" : "⏳ Pendiente";
    echo sprintf("   • %s: %d-%d (puntos: %s) [%s]\n",
        $p['display_name'],
        $p['home_pred'],
        $p['away_pred'],
        $p['points_total'] ?? '—',
        $estado
    );
}
echo "\n";

// Si está finalizados pero sin resultado
if (in_array($match['status'], ['finished', 'ft', 'full_time']) && $match['home_score'] === null) {
    echo "⚠️  ALERTA: Partido finalizó pero NO tiene score en caché\n";
    echo "   Actualizando desde API...\n\n";
    
    $url = EXT_API_BASE . "/events/{$match['api_id']}/";
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
        'timeout' => 10,
    ]]);
    
    $res = @file_get_contents($url, false, $ctx);
    $api_match = $res ? json_decode($res, true) : null;
    
    if (!$api_match) {
        echo "❌ No se pudo obtener datos de la API\n";
        exit;
    }
    
    echo "✓ Datos de la API obtenidos\n";
    echo "   Score: {$api_match['home_score']}-{$api_match['away_score']}\n";
    echo "   Status: {$api_match['status']}\n\n";
    
    // Actualizar caché
    $pdo->prepare("
        UPDATE matches_cache SET
            home_score = ?,
            away_score = ?,
            home_score_ht = ?,
            away_score_ht = ?,
            status = ?,
            current_minute = ?,
            period = ?,
            raw_json = ?,
            cached_at = NOW()
        WHERE api_id = ?
    ")->execute([
        $api_match['home_score'],
        $api_match['away_score'],
        $api_match['home_score_ht'] ?? null,
        $api_match['away_score_ht'] ?? null,
        $api_match['status'],
        $api_match['current_minute'] ?? null,
        $api_match['period'] ?? null,
        json_encode($api_match),
        $match['api_id']
    ]);
    
    $match = [
        'home_score' => $api_match['home_score'],
        'away_score' => $api_match['away_score'],
        'status' => $api_match['status']
    ];
    
    echo "✓ Caché actualizado\n\n";
}

// Si está finalizado y tiene score, verificar pronósticos
if (in_array($match['status'], ['finished', 'ft', 'full_time']) && $match['home_score'] !== null) {
    echo "🔄 VERIFICANDO PRONÓSTICOS...\n\n";
    
    $updateStmt = $pdo->prepare("
        UPDATE predictions
        SET
            points_exact = ?,
            points_winner = ?,
            points_total = ?,
            result_checked = 1,
            last_real_home = ?,
            last_real_away = ?
        WHERE id = ?
    ");
    
    foreach ($predictions as $p) {
        [$exact, $winner, $total] = calculatePoints(
            (int)$p['home_pred'],
            (int)$p['away_pred'],
            (int)$match['home_score'],
            (int)$match['away_score']
        );
        
        $updateStmt->execute([
            $exact, $winner, $total,
            $match['home_score'],
            $match['away_score'],
            $p['id']
        ]);
        
        $points_text = $total > 0 ? "✓ $total pts" : "✗ 0 pts";
        echo sprintf("   • %s: %d-%d vs %d-%d = %s",
            $p['display_name'],
            $p['home_pred'],
            $p['away_pred'],
            $match['home_score'],
            $match['away_score'],
            $points_text
        );
        
        if ($exact > 0) echo " (EXACTO!)";
        echo "\n";
    }
    
    echo "\n✓ Pronósticos verificados\n";
} else if (!in_array($match['status'], ['finished', 'ft', 'full_time'])) {
    echo "⏳ Partido aún no finaliza (estado: {$match['status']})\n";
    echo "   Fecha prevista: {$match['event_date']}\n";
}

echo "\n═════════════════════════════════════════════════════════════\n";

function calculatePoints(int $homePred, int $awayPred, int $realHome, int $realAway): array
{
    if ($homePred === $realHome && $awayPred === $realAway) {
        return [12, 0, 12];
    }

    $predSign = $homePred <=> $awayPred;
    $realSign = $realHome <=> $realAway;

    if ($predSign === $realSign) {
        if ($homePred === $realHome || $awayPred === $realAway) {
            return [0, 7, 7];
        }
        return [0, 5, 5];
    }

    if ($homePred === $realHome || $awayPred === $realAway) {
        return [0, 2, 2];
    }

    return [0, 0, 0];
}
?>
