<?php
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
    die("❌ BD: " . $e->getMessage());
}

$matchId = 8287;
$url = EXT_API_BASE . "/events/{$matchId}/";

echo "🔄 Actualizando partido México vs South Africa\n";
echo "═════════════════════════════════════════════\n\n";
echo "Consultando: $url\n\n";

$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
    'timeout' => 15,
]]);

$res = @file_get_contents($url, false, $ctx);
if (!$res) {
    die("❌ No responde API\n");
}

$m = json_decode($res, true);
if (!isset($m['id'])) {
    echo "❌ Error:\n";
    print_r($m);
    exit;
}

echo "✓ Respuesta obtenida\n";
echo "  • Status: {$m['status']}\n";
echo "  • Score: {$m['home_score']}-{$m['away_score']}\n";
echo "  • Fecha: {$m['event_date']}\n";
echo "  • Minuto: {$m['current_minute']}\n\n";

if ($m['status'] === 'notstarted') {
    echo "⏳ Partido aún no comienza (falta " . round((strtotime($m['event_date']) - time()) / 60) . " minutos)\n";
    exit;
}

if (!in_array($m['status'], ['finished', 'ft', 'full_time'])) {
    echo "🔴 EN VIVO - Status: {$m['status']}\n";
    exit;
}

echo "✓ PARTIDO FINALIZADO\n\n";

// Actualizar caché
echo "📝 Actualizando caché...\n";
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
    $m['home_score'],
    $m['away_score'],
    $m['home_score_ht'] ?? null,
    $m['away_score_ht'] ?? null,
    $m['status'],
    $m['current_minute'] ?? null,
    $m['period'] ?? null,
    json_encode($m),
    $matchId
]);

echo "✓ Caché actualizado\n\n";

// Verificar pronósticos
echo "✓ Verificando pronósticos...\n\n";

$stmt = $pdo->prepare("
    SELECT 
        p.id, p.user_id, p.home_pred, p.away_pred, p.result_checked,
        u.display_name
    FROM predictions p
    INNER JOIN users u ON u.id = p.user_id
    WHERE p.match_api_id = ? AND p.result_checked = 0
");
$stmt->execute([$matchId]);
$preds = $stmt->fetchAll();

if (empty($preds)) {
    echo "  (No hay pronósticos sin verificar)\n";
} else {
    $updateStmt = $pdo->prepare("
        UPDATE predictions
        SET points_exact = ?, points_winner = ?, points_total = ?,
            result_checked = 1, last_real_home = ?, last_real_away = ?
        WHERE id = ?
    ");

    foreach ($preds as $p) {
        [$exact, $winner, $total] = calcPoints($p['home_pred'], $p['away_pred'], $m['home_score'], $m['away_score']);
        
        $updateStmt->execute([
            $exact, $winner, $total,
            $m['home_score'], $m['away_score'],
            $p['id']
        ]);
        
        $pts = $total > 0 ? "✓ $total pts" : "✗ 0 pts";
        echo sprintf("  • %s: %d-%d vs %d-%d = %s\n",
            $p['display_name'],
            $p['home_pred'], $p['away_pred'],
            $m['home_score'], $m['away_score'],
            $pts
        );
    }
}

echo "\n═════════════════════════════════════════════\n";
echo "✓ Actualización completada\n";

function calcPoints($hP, $aP, $hR, $aR) {
    if ($hP === $hR && $aP === $aR) return [12, 0, 12];
    $pS = $hP <=> $aP;
    $rS = $hR <=> $aR;
    if ($pS === $rS) {
        if ($hP === $hR || $aP === $aR) return [0, 7, 7];
        return [0, 5, 5];
    }
    if ($hP === $hR || $aP === $aR) return [0, 2, 2];
    return [0, 0, 0];
}
?>
