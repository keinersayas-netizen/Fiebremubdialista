<?php
/**
 * Validador directo de pronosticos.
 *
 * Uso CLI:
 *   php validate_predictions_now.php --secret=CLAVE
 *
 * Uso web:
 *   https://TU-DOMINIO/validate_predictions_now.php?secret=CLAVE
 */

date_default_timezone_set('America/Bogota');

define('DB_HOST',    'localhost');
define('DB_USER',    'admin');
define('DB_PASS',    '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4');
define('DB_CHARSET', 'utf8mb4');

define('EXT_API_BASE',  'https://sports.bzzoiro.com/api');
define('EXT_API_TOKEN', '5d5b7145bcf005bd2b0e6a26e43a956c3a130d5f');

define('FINISHED_STATUSES', [
    'finished',
    'ft',
    'full_time',
    'completed',
    'complete',
    'ended',
    'final',
    'after_extra_time',
    'aet',
    'after_penalties',
    'penalties_finished',
]);

$databases = ['matchday_db', 'matchday_dbalter'];

if (php_sapi_name() !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

$secret = getRequestOption('secret') ?? getCliOption('secret') ?? ($_SERVER['argv'][1] ?? '');
$secret = strpos($secret, '--secret=') === 0 ? substr($secret, 9) : $secret;
$expectedSecret = getExpectedCronSecret();

if (trim((string)$secret) === '') {
    fail("Se requiere parametro --secret o ?secret=CLAVE");
}

if ($expectedSecret !== null && !hash_equals($expectedSecret, trim((string)$secret))) {
    fail("Secret invalido");
}

$onlyDb = getRequestOption('db') ?? getCliOption('db');
if ($onlyDb !== null && $onlyDb !== '') {
    if (!in_array($onlyDb, $databases, true)) {
        fail("Base de datos no permitida: $onlyDb");
    }
    $databases = [$onlyDb];
}

$onlyMatchId = (int)(getRequestOption('match_id') ?? getCliOption('match_id') ?? 0);
$finishedStatusSql = "'" . implode("','", FINISHED_STATUSES) . "'";

line("============================================================");
line("VALIDACION DIRECTA DE PRONOSTICOS " . date('Y-m-d H:i:s'));
line("============================================================");
if ($expectedSecret === null) {
    line("ADVERTENCIA: no existe .cron_secret ni MATCHDAY_CRON_SECRET; se acepta cualquier secret no vacio.");
}
if ($onlyMatchId > 0) {
    line("Filtro partido API ID: $onlyMatchId");
}
line("");

$global = [
    'matches_found' => 0,
    'matches_updated' => 0,
    'matches_finished' => 0,
    'predictions_verified' => 0,
    'api_failures' => 0,
    'db_failures' => 0,
];

foreach ($databases as $dbName) {
    line("BD: $dbName");
    line(str_repeat('-', 60));

    try {
        $pdo = connectDatabase($dbName);
        ensurePredictionAuditColumns($pdo);
    } catch (Throwable $e) {
        line("ERROR conexion/preparacion: " . $e->getMessage());
        line("");
        $global['db_failures']++;
        continue;
    }

    $matches = getPredictedMatches($pdo, $onlyMatchId);
    line("Partidos con pronosticos encontrados: " . count($matches));
    $global['matches_found'] += count($matches);

    foreach ($matches as $row) {
        $matchId = (int)$row['match_api_id'];
        $label = trim(($row['home_team'] ?? '') . ' vs ' . ($row['away_team'] ?? ''));
        if ($label === 'vs') {
            $label = "Partido $matchId";
        }

        $apiMatch = fetchMatchFromApi($matchId);
        if (!$apiMatch) {
            $global['api_failures']++;
            line("  - $matchId $label: no se pudo consultar API, se usa cache local");
            $apiMatch = [
                'id' => $matchId,
                'league' => [
                    'id' => $row['league_id'] ?? null,
                    'name' => $row['league_name'] ?? null,
                    'country' => $row['league_country'] ?? null,
                ],
                'season' => [
                    'id' => $row['season_id'] ?? null,
                    'name' => $row['season_name'] ?? null,
                ],
                'home_team' => $row['home_team'],
                'away_team' => $row['away_team'],
                'event_date' => $row['event_date'],
                'status' => $row['status'],
                'home_score' => $row['home_score'],
                'away_score' => $row['away_score'],
                'home_score_ht' => $row['home_score_ht'] ?? null,
                'away_score_ht' => $row['away_score_ht'] ?? null,
                'current_minute' => $row['current_minute'] ?? null,
                'period' => $row['period'] ?? null,
                'round_number' => $row['round_number'] ?? null,
            ];
        } else {
            saveMatchCache($pdo, $apiMatch);
            $global['matches_updated']++;
        }

        $status = normalizeStatus((string)($apiMatch['status'] ?? ''));
        $homeScore = $apiMatch['home_score'] ?? null;
        $awayScore = $apiMatch['away_score'] ?? null;

        if (isFinishedStatus($status) && $homeScore !== null && $awayScore !== null) {
            $verified = verifyMatchPredictions($pdo, $matchId, $finishedStatusSql);
            $global['matches_finished']++;
            $global['predictions_verified'] += $verified;
            line("  - $matchId {$apiMatch['home_team']} {$homeScore}-{$awayScore} {$apiMatch['away_team']} [$status]: $verified pronosticos verificados/recalculados");
        } else {
            line("  - $matchId $label [$status]: no finalizado o sin marcador");
        }

        usleep(100000);
    }

    line("");
}

line("============================================================");
line("RESUMEN");
line("============================================================");
line("Partidos encontrados: {$global['matches_found']}");
line("Partidos actualizados desde API: {$global['matches_updated']}");
line("Partidos finalizados procesados: {$global['matches_finished']}");
line("Pronosticos verificados/recalculados: {$global['predictions_verified']}");
line("Fallos API: {$global['api_failures']}");
line("Fallos BD: {$global['db_failures']}");
line("Terminado: " . date('Y-m-d H:i:s'));

function connectDatabase(string $dbName): PDO
{
    return new PDO(
        "mysql:host=" . DB_HOST . ";dbname=$dbName;charset=" . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
}

function ensurePredictionAuditColumns(PDO $pdo): void
{
    foreach (['last_real_home', 'last_real_away'] as $column) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM predictions LIKE ?");
        $stmt->execute([$column]);
        if ($stmt->fetch()) {
            continue;
        }

        $after = $column === 'last_real_home' ? 'result_checked' : 'last_real_home';
        $pdo->exec("ALTER TABLE predictions ADD COLUMN $column TINYINT UNSIGNED DEFAULT NULL AFTER $after");
        line("  Columna creada en predictions: $column");
    }
}

function getPredictedMatches(PDO $pdo, int $onlyMatchId = 0): array
{
    $sql = "
        SELECT
            p.match_api_id,
            COUNT(*) AS prediction_count,
            SUM(CASE WHEN p.result_checked = 0 THEN 1 ELSE 0 END) AS pending_count,
            m.api_id,
            m.league_id,
            m.league_name,
            m.league_country,
            m.season_id,
            m.season_name,
            m.home_team,
            m.away_team,
            m.event_date,
            m.status,
            m.home_score,
            m.away_score,
            m.home_score_ht,
            m.away_score_ht,
            m.current_minute,
            m.period,
            m.round_number
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE 1=1
    ";
    $params = [];
    if ($onlyMatchId > 0) {
        $sql .= " AND p.match_api_id = ?";
        $params[] = $onlyMatchId;
    }

    $sql .= "
        GROUP BY
            p.match_api_id,
            m.api_id,
            m.league_id,
            m.league_name,
            m.league_country,
            m.season_id,
            m.season_name,
            m.home_team,
            m.away_team,
            m.event_date,
            m.status,
            m.home_score,
            m.away_score,
            m.home_score_ht,
            m.away_score_ht,
            m.current_minute,
            m.period,
            m.round_number
        ORDER BY m.event_date DESC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function fetchMatchFromApi(int $matchId): ?array
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
        'timeout' => 8,
    ]]);

    $res = @file_get_contents(EXT_API_BASE . "/events/{$matchId}/", false, $ctx);
    if (!$res) {
        return null;
    }

    $decoded = json_decode($res, true);
    return is_array($decoded) && !empty($decoded['id']) ? $decoded : null;
}

function saveMatchCache(PDO $pdo, array $m): void
{
    $pdo->prepare("
        INSERT INTO matches_cache
            (api_id, league_id, league_name, league_country, season_id, season_name,
             home_team, away_team, event_date, status,
             home_score, away_score, home_score_ht, away_score_ht,
             current_minute, period, round_number, is_mundial, raw_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            league_id       = VALUES(league_id),
            league_name     = VALUES(league_name),
            league_country  = VALUES(league_country),
            season_id       = VALUES(season_id),
            season_name     = VALUES(season_name),
            home_team       = VALUES(home_team),
            away_team       = VALUES(away_team),
            event_date      = VALUES(event_date),
            status          = VALUES(status),
            home_score      = VALUES(home_score),
            away_score      = VALUES(away_score),
            home_score_ht   = VALUES(home_score_ht),
            away_score_ht   = VALUES(away_score_ht),
            current_minute  = VALUES(current_minute),
            period          = VALUES(period),
            round_number    = VALUES(round_number),
            is_mundial      = VALUES(is_mundial),
            raw_json        = VALUES(raw_json),
            cached_at       = NOW()
    ")->execute([
        $m['id'],
        $m['league']['id']      ?? null,
        $m['league']['name']    ?? null,
        $m['league']['country'] ?? null,
        $m['season']['id']      ?? null,
        $m['season']['name']    ?? null,
        $m['home_team'],
        $m['away_team'],
        $m['event_date'],
        $m['status'] ?? 'notstarted',
        $m['home_score']        ?? null,
        $m['away_score']        ?? null,
        $m['home_score_ht']     ?? null,
        $m['away_score_ht']     ?? null,
        $m['current_minute']    ?? null,
        $m['period']            ?? null,
        $m['round_number']      ?? null,
        (isset($m['league']['id']) && (int)$m['league']['id'] === 27) ? 1 : 0,
        json_encode($m),
    ]);
}

function verifyMatchPredictions(PDO $pdo, int $matchId, string $finishedStatusSql): int
{
    $stmt = $pdo->prepare("
        SELECT
            p.id AS pred_id,
            p.home_pred,
            p.away_pred,
            m.home_score AS real_home,
            m.away_score AS real_away
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE p.match_api_id = ?
          AND LOWER(m.status) IN ($finishedStatusSql)
          AND m.home_score IS NOT NULL
          AND m.away_score IS NOT NULL
          AND (
              p.result_checked = 0
              OR p.last_real_home IS NULL
              OR p.last_real_away IS NULL
              OR p.last_real_home <> m.home_score
              OR p.last_real_away <> m.away_score
          )
    ");
    $stmt->execute([$matchId]);
    $predictions = $stmt->fetchAll();

    if (empty($predictions)) {
        return 0;
    }

    $update = $pdo->prepare("
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

    $verified = 0;
    foreach ($predictions as $p) {
        [$exact, $winner, $total] = calculatePoints(
            (int)$p['home_pred'],
            (int)$p['away_pred'],
            (int)$p['real_home'],
            (int)$p['real_away']
        );

        $update->execute([
            $exact,
            $winner,
            $total,
            $p['real_home'],
            $p['real_away'],
            $p['pred_id'],
        ]);
        $verified++;
    }

    return $verified;
}

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

function normalizeStatus(string $status): string
{
    return strtolower(trim($status));
}

function isFinishedStatus(string $status): bool
{
    return in_array(normalizeStatus($status), FINISHED_STATUSES, true);
}

function getRequestOption(string $name): ?string
{
    if (php_sapi_name() === 'cli') {
        return null;
    }

    return isset($_GET[$name]) ? trim((string)$_GET[$name]) : null;
}

function getCliOption(string $name): ?string
{
    foreach ($_SERVER['argv'] ?? [] as $arg) {
        if (strpos($arg, "--$name=") === 0) {
            return trim(substr($arg, strlen($name) + 3));
        }
    }

    return null;
}

function getExpectedCronSecret(): ?string
{
    $envSecret = getenv('MATCHDAY_CRON_SECRET');
    if (is_string($envSecret) && trim($envSecret) !== '') {
        return trim($envSecret);
    }

    $secretFile = __DIR__ . '/.cron_secret';
    if (is_readable($secretFile)) {
        $fileSecret = trim((string)file_get_contents($secretFile));
        if ($fileSecret !== '') {
            return $fileSecret;
        }
    }

    return null;
}

function line(string $message): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    line("ERROR: $message");
    exit(1);
}
