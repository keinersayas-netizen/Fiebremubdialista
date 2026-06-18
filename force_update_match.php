<?php
date_default_timezone_set('America/Bogota');

$dbName = $argv[1] ?? 'matchday_dbalter';
$matchId = $argv[2] ?? '3876741';  // Mexico vs South Africa

echo "═══════════════════════════════════════════════════════════════\n";
echo "  🔄 FORZAR ACTUALIZACIÓN: Partido $matchId en $dbName\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Conectar BD
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

// 1. Consultar estado actual en BD
echo "1️⃣  Estado ACTUAL en BD:\n";
$stmt = $pdo->prepare("
    SELECT
        api_id, home_team, away_team, event_date, status,
        home_score, away_score, current_minute
    FROM matches_cache
    WHERE api_id = ?
");
$stmt->execute([$matchId]);
$before = $stmt->fetch();

if ($before) {
    echo "   • Partido: {$before['home_team']} vs {$before['away_team']}\n";
    echo "   • Fecha: {$before['event_date']}\n";
    echo "   • Estado: {$before['status']}\n";
    echo "   • Resultado: {$before['home_score']}-{$before['away_score']}\n";
    echo "   • Minuto: {$before['current_minute']}\n\n";
} else {
    echo "   ⚠️  No encontrado en caché\n\n";
}

// 2. Consultar API externa
echo "2️⃣  Consultando API EXTERNA:\n";

$url = "https://sports.bzzoiro.com/api/events/{$matchId}/";
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "Authorization: Token 5d5b7145bcf005bd2b0e6a26e43a956c3a130d5f\r\n",
    'timeout' => 8,
]]);

$res = @file_get_contents($url, false, $ctx);
if (!$res) {
    die("   ❌ Error consultando API\n");
}

$match = json_decode($res, true);
if (!$match) {
    die("   ❌ Error decodificando JSON\n");
}

echo "   • Partido: {$match['home_team']} vs {$match['away_team']}\n";
echo "   • Fecha: {$match['event_date']}\n";
echo "   • Estado: {$match['status']}\n";
echo "   • Resultado: {$match['home_score']}-{$match['away_score']}\n";
echo "   • Minuto: {$match['current_minute']}\n\n";

// 3. Actualizar caché
echo "3️⃣  Actualizando caché en BD:\n";

$pdo->prepare("
    UPDATE matches_cache
    SET
        status          = ?,
        home_score      = ?,
        away_score      = ?,
        home_score_ht   = ?,
        away_score_ht   = ?,
        current_minute  = ?,
        period          = ?,
        raw_json        = ?,
        cached_at       = NOW()
    WHERE api_id = ?
")->execute([
    $match['status'] ?? 'notstarted',
    $match['home_score']    ?? null,
    $match['away_score']    ?? null,
    $match['home_score_ht'] ?? null,
    $match['away_score_ht'] ?? null,
    $match['current_minute'] ?? null,
    $match['period']        ?? null,
    json_encode($match),
    $matchId,
]);

// 4. Verificar cambios
echo "4️⃣  Estado DESPUÉS de actualización:\n";
$stmt = $pdo->prepare("SELECT status, home_score, away_score FROM matches_cache WHERE api_id = ?");
$stmt->execute([$matchId]);
$after = $stmt->fetch();

if ($after) {
    echo "   • Estado: {$after['status']}\n";
    echo "   • Resultado: {$after['home_score']}-{$after['away_score']}\n";
    
    if ($after['status'] !== $before['status'] ?? null) {
        echo "   ✅ Estado ACTUALIZADO\n";
    }
}

// 5. Verificar pronósticos
echo "\n5️⃣  Verificando pronósticos de este partido:\n";

$stmt = $pdo->prepare("
    SELECT
        COUNT(*) as total,
        COUNT(CASE WHEN result_checked = 1 THEN 1 END) as verificados,
        COUNT(CASE WHEN result_checked = 0 THEN 1 END) as pendientes
    FROM predictions
    WHERE match_api_id = ?
");
$stmt->execute([$matchId]);
$preds = $stmt->fetch();

echo "   • Total: {$preds['total']}\n";
echo "   • Verificados: {$preds['verificados']}\n";
echo "   • Pendientes: {$preds['pendientes']}\n";

// Si está finalizado y hay pendientes, verificar
if (in_array($match['status'], ['finished', 'ft', 'full_time']) && $preds['pendientes'] > 0) {
    echo "\n6️⃣  🔴 Partido finalizado pero hay pronósticos sin verificar!\n";
    echo "   Ejecutando verificación...\n";
    
    $stmt = $pdo->prepare("
        SELECT
            p.id            AS pred_id,
            p.home_pred,
            p.away_pred,
            m.home_score    AS real_home,
            m.away_score    AS real_away
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE
            m.api_id        = ?
            AND m.status    IN ('finished','ft','full_time')
            AND m.home_score IS NOT NULL
            AND p.result_checked = 0
    ");
    $stmt->execute([$matchId]);
    $toVerify = $stmt->fetchAll();
    
    if (!empty($toVerify)) {
        $updateStmt = $pdo->prepare("
            UPDATE predictions
            SET
                points_exact   = ?,
                points_winner  = ?,
                points_total   = ?,
                result_checked = 1,
                last_real_home = ?,
                last_real_away = ?
            WHERE id = ?
        ");
        
        $verified = 0;
        foreach ($toVerify as $p) {
            [$exact, $winner, $total] = calculatePoints(
                (int)$p['home_pred'], (int)$p['away_pred'],
                (int)$p['real_home'], (int)$p['real_away']
            );
            
            $updateStmt->execute([
                $exact, $winner, $total,
                $p['real_home'], $p['real_away'],
                $p['pred_id'],
            ]);
            
            $verified++;
        }
        
        echo "   ✅ $verified pronósticos verificados\n";
    }
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "✓ Actualización completada\n";
echo "═══════════════════════════════════════════════════════════════\n";

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
