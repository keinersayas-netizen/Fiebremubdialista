<?php
// Script de mantenimiento para verificación automática de pronósticos - MULTI-BD
// 
// Uso:
//   php cron_verify_matches.php --secret=CLAVE_SECRETA
// 
// Procesa automáticamente:
//   - matchday_db (coreschool.colegiolaconcepcion.com.co)
//   - matchday_dbalter (coreschool.colegioalteralteris.edu.co)

// Zona horaria Colombia (UTC-5)
date_default_timezone_set('America/Bogota');

define('DB_HOST',    'localhost');
define('DB_USER',    'admin');
define('DB_PASS',    '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4');
define('DB_CHARSET', 'utf8mb4');

define('EXT_API_BASE',  'https://sports.bzzoiro.com/api');
define('EXT_API_TOKEN', '5d5b7145bcf005bd2b0e6a26e43a956c3a130d5f');
define('CACHE_MATCHES_TTL', 120);

// Bases de datos a procesar
$databases = ['matchday_db', 'matchday_dbalter'];

// Validar secreto
$secret = $_GET['secret'] ?? $_SERVER['argv'][1] ?? '';
if (strpos($secret, '--secret=') === 0) {
    $secret = substr($secret, 9);
}

if (!$secret) {
    die("❌ Se requiere parámetro --secret\n");
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  🔄 CRON: Verificación Multi-BD " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$totalStats = ['updated' => 0, 'verified' => 0, 'failed' => 0];

// Procesar cada BD
foreach ($databases as $dbName) {
    echo "📦 Procesando: $dbName\n";
    echo "   ─────────────────────────────────\n";
    
    try {
        $pdo = new PDO(
            "mysql:host=".DB_HOST.";dbname=$dbName;charset=".DB_CHARSET,
            DB_USER, DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        echo "   ❌ Error conexión: " . $e->getMessage() . "\n\n";
        $totalStats['failed']++;
        continue;
    }

    // 1. Obtener matches mundialistas que tengan pronósticos
    echo "   • Buscando partidos con pronósticos...\n";
    $stmt = $pdo->prepare("
        SELECT p.match_api_id
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE m.is_mundial = 1
        GROUP BY p.match_api_id
        ORDER BY 
            CASE 
                WHEN m.status IN ('inprogress','1st_half','halftime','2nd_half','extra_time','penalties','live') THEN 1
                WHEN m.status IN ('finished','ft','full_time') THEN 2
                WHEN DATE(m.event_date) = CURDATE() THEN 3
                ELSE 4
            END,
            m.event_date ASC
        LIMIT 20
    ");
    $stmt->execute();
    $matchIds = array_column($stmt->fetchAll(), 'match_api_id');
    echo "   • Encontrados: " . count($matchIds) . " partidos\n";

    // 2. Actualizar cada partido desde la API
    echo "   • Actualizando datos de la API...\n";
    $updated = 0;
    $failed = 0;
    $finished = 0;

    foreach ($matchIds as $matchId) {
        $url = EXT_API_BASE . "/events/{$matchId}/";
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
            'timeout' => 5,
        ]]);
        
        $res = @file_get_contents($url, false, $ctx);
        $match = $res ? json_decode($res, true) : null;
        
        if (!$match || empty($match['id'])) {
            $failed++;
            continue;
        }
        
        // Actualizar caché
        $pdo->prepare("
            INSERT INTO matches_cache
                (api_id, league_id, league_name, league_country, season_id, season_name,
                 home_team, away_team, event_date, status,
                 home_score, away_score, home_score_ht, away_score_ht,
                 current_minute, period, round_number, is_mundial, raw_json)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                status          = VALUES(status),
                home_score      = VALUES(home_score),
                away_score      = VALUES(away_score),
                home_score_ht   = VALUES(home_score_ht),
                away_score_ht   = VALUES(away_score_ht),
                current_minute  = VALUES(current_minute),
                period          = VALUES(period),
                raw_json        = VALUES(raw_json),
                cached_at       = NOW()
        ")->execute([
            $match['id'],
            $match['league']['id']      ?? null,
            $match['league']['name']    ?? null,
            $match['league']['country'] ?? null,
            $match['season']['id']      ?? null,
            $match['season']['name']    ?? null,
            $match['home_team'],
            $match['away_team'],
            $match['event_date'],
            $match['status'] ?? 'notstarted',
            $match['home_score']        ?? null,
            $match['away_score']        ?? null,
            $match['home_score_ht']     ?? null,
            $match['away_score_ht']     ?? null,
            $match['current_minute']    ?? null,
            $match['period']            ?? null,
            $match['round_number']      ?? null,
            1,
            json_encode($match),
        ]);
        
        if (in_array($match['status'], ['finished', 'ft', 'full_time'])) {
            $finished++;
        }
        $updated++;
        
        // Pequeña pausa para no sobrecargar
        usleep(100000);
    }

    echo "   • Actualizados: $updated\n";
    echo "   • Finalizados: $finished\n";
    echo "   • Fallos: $failed\n";

    // 3. Verificar pronósticos de partidos finalizados
    echo "   • Verificando pronósticos...\n";

    $stmt = $pdo->prepare("
        SELECT
            p.id            AS pred_id,
            p.user_id,
            p.home_pred,
            p.away_pred,
            m.api_id        AS match_id,
            m.home_score    AS real_home,
            m.away_score    AS real_away,
            m.home_team,
            m.away_team
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE
            m.status     IN ('finished','ft','full_time')
            AND m.home_score IS NOT NULL
            AND m.away_score IS NOT NULL
            AND (
                p.result_checked = 0
                OR p.last_real_home <> m.home_score
                OR p.last_real_away <> m.away_score
            )
    ");
    $stmt->execute();
    $predictions = $stmt->fetchAll();

    $verified = 0;
    if (!empty($predictions)) {
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
        
        foreach ($predictions as $p) {
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
    }

    echo "   • Pronósticos verificados: $verified\n";
    echo "\n";
    
    $totalStats['updated'] += $updated;
    $totalStats['verified'] += $verified;
}

// 4. Resumen final
echo "═══════════════════════════════════════════════════════════════\n";
echo "  ✓ RESUMEN FINAL\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "• Bases de datos procesadas: " . count($databases) . "\n";
echo "• Partidos actualizados: {$totalStats['updated']}\n";
echo "• Pronósticos verificados: {$totalStats['verified']}\n";
echo "• Errores: {$totalStats['failed']}\n";
echo "\n✓ Cron completado a " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════════\n";

/**
 * Calcula puntos
 */
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
