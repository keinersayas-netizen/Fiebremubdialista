<?php
/**
 * MatchDay — Backend API v3.0
 * ─────────────────────────────────────────────────────────────
 * SISTEMA DE PUNTOS:
 *   12 pts → Marcador exacto (ej: predijo 2-1, resultado 2-1)
 *    7 pts → Resultado general (acertaste ganador y la cantidad de goles de un equipo)
 *    5 pts → Resultado parcial (acertaste ganador/empate pero no los goles)
 *    2 pts → Goles de un solo equipo acertados
 *    0 pts → Sin aciertos
 *
 * VALIDACIÓN AUTOMÁTICA:
 *   - Se ejecuta al guardar pronósticos, cargar partidos y en cron
 *   - Recalcula si el resultado fue corregido en la API externa
 *   - Nunca valida un partido no finalizado
 * ─────────────────────────────────────────────────────────────
 */

// ─── Zona Horaria Colombia ───────────────────────────────────
date_default_timezone_set('America/Bogota');  // UTC-5

// ─── Configuración ───────────────────────────────────────────
define('DB_HOST',    'localhost');
define('DB_USER',    'admin');
define('DB_PASS',    '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4');
define('DB_CHARSET', 'utf8mb4');

// ─── Mapeo de dominios a bases de datos ───────────────────────
$domainDbMap = [
    'coreschool.colegiolaconcepcion.com.co' => 'matchday_db',
    'coreschool.colegioalteralteris.edu.co' => 'matchday_dbalter',
    ''            => '',  // Base de datos por defecto para otros dominios
];

// Detectar dominio actual y seleccionar BD
$currentHost = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost';
$currentHost = strtolower(trim(explode(':', $currentHost)[0])); // Remover puerto si existe
$DB_NAME = $domainDbMap[$currentHost] ?? $domainDbMap['default'];
define('DB_NAME', $DB_NAME);

define('EXT_API_BASE',  'https://sports.bzzoiro.com/api');
define('EXT_API_TOKEN', '5d5b7145bcf005bd2b0e6a26e43a956c3a130d5f');

define('CACHE_MATCHES_TTL', 120);  // segundos antes de refrescar caché
define('SESSION_TTL',       86400); // 24 horas

// Estados que indica partido en curso o ya jugado
define('LIVE_STATUSES',     ['inprogress','1st_half','halftime','2nd_half',
                              'extra_time','penalties','live']);
define('FINISHED_STATUSES', ['finished','ft','full_time']);
define('STARTED_STATUSES',  array_merge(LIVE_STATUSES, FINISHED_STATUSES));

// ─── CORS / Headers ──────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
}

// ─── Router ──────────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    $uri    = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $parts  = explode('/', $uri);
    $action = $parts[count($parts) - 1] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
}

// Conexión PDO global
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
    respond(500, ['error' => 'DB connection failed: ' . $e->getMessage()]);
}

if (php_sapi_name() !== 'cli') {
    // Enrutar
    match (true) {
        $action === 'register'     && $method === 'POST'   => register($pdo),
        $action === 'login'        && $method === 'POST'   => login($pdo),
        $action === 'logout'       && $method === 'POST'   => logout($pdo),
    $action === 'me'           && $method === 'GET'    => me($pdo),
    $action === 'change-password' && $method === 'POST' => changePassword($pdo),
    $action === 'predictions'  && $method === 'GET'    => getPredictions($pdo),
    $action === 'predictions'  && $method === 'POST'   => savePrediction($pdo),
    $action === 'predictions'  && $method === 'DELETE' => deletePrediction($pdo),
    $action === 'predictions-all' && $method === 'GET' => getPredictionsAll($pdo),
    $action === 'verify-async' && $method === 'POST'   => verifyAsync($pdo),
    $action === 'leaderboard'  && $method === 'GET'    => leaderboard($pdo),
    $action === 'matches'      && $method === 'GET'    => getMatches($pdo),
    $action === 'verify'       && $method === 'POST'   => verifyEndpoint($pdo),
    $action === 'cron'         && $method === 'GET'    => cronVerify($pdo),
    $action === 'upload-avatar' && $method === 'POST' => uploadAvatar($pdo),
    default                                            => respond(404, ['error' => 'Not found'])
    };
}

// ═══════════════════════════════════════════════════════════════
// AUTH
// ═══════════════════════════════════════════════════════════════

function processAvatarUpload(array $file, string $email): ?string
{
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    // Validar tipo MIME
    if (!in_array($file['type'], $allowedTypes)) return null;
    
    // Validar tamaño (5MB)
    if ($file['size'] > 5 * 1024 * 1024) return null;
    
    // Validar que haya contenido
    if ($file['size'] <= 0) return null;

    $uploadDir = __DIR__ . '/uploads/';
    
    // Crear directorio si no existe
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true)) {
            return null;
        }
    }
    
    // Verificar permisos de escritura
    if (!is_writable($uploadDir)) {
        @chmod($uploadDir, 0755);
        if (!is_writable($uploadDir)) return null;
    }

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    // Validar extensión
    if (!in_array($ext, $allowedExts)) return null;
    
    // Normalizar nombre
    $newName = preg_replace('/[^a-z0-9._-]/i', '', $email) . '.' . $ext;
    $path = $uploadDir . $newName;

    // Limpiar archivo anterior si existe
    if (file_exists($path)) {
        @unlink($path);
    }

    if (move_uploaded_file($file['tmp_name'], $path)) {
        // Asegurar permisos de lectura
        @chmod($path, 0644);
        return 'uploads/' . $newName;
    }
    return null;
}

function register(PDO $pdo): void
{
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = $_POST['password']      ?? '';

    if (strlen($username) < 3)                          respond(400, ['error' => 'Username muy corto (mín. 3 caracteres)']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     respond(400, ['error' => 'Email inválido']);
    if (strlen($password) < 6)                          respond(400, ['error' => 'Contraseña muy corta (mín. 6 caracteres)']);

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $pdo->prepare("INSERT INTO users (username, email, password_hash, display_name) VALUES (?,?,?,?)")
            ->execute([$username, $email, $hash, $username]);
        $userId = (int)$pdo->lastInsertId();

        // Procesar avatar si se subió
        if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
            $avatarUrl = processAvatarUpload($_FILES['avatar'], $email);
            if ($avatarUrl) {
                $pdo->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")->execute([$avatarUrl, $userId]);
            }
        }

        respond(201, ['ok' => true, 'user_id' => $userId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') respond(409, ['error' => 'Usuario o email ya existe']);
        throw $e;
    }
}

function login(PDO $pdo): void
{
    $body     = jsonBody();
    $login    = trim($body['login']    ?? '');
    $password = $body['password']      ?? '';

    if (!$login || !$password) respond(400, ['error' => 'Credenciales requeridas']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE (username=? OR email=?) AND is_active=1 LIMIT 1");
    $stmt->execute([$login, $login]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        respond(401, ['error' => 'Credenciales incorrectas']);
    }

    // Limpiar sesiones expiradas del usuario
    $pdo->prepare("DELETE FROM sessions WHERE user_id=? AND expires_at < NOW()")->execute([$user['id']]);

    $token = bin2hex(random_bytes(32));
    $exp   = date('Y-m-d H:i:s', time() + SESSION_TTL);
    $pdo->prepare("INSERT INTO sessions (id, user_id, expires_at, ip_address, user_agent) VALUES (?,?,?,?,?)")
        ->execute([$token, $user['id'], $exp, $_SERVER['REMOTE_ADDR'] ?? null,
                   substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);

    $pdo->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);

    unset($user['password_hash']);
    respond(200, ['token' => $token, 'user' => $user]);
}

function logout(PDO $pdo): void
{
    $token = getBearerToken();
    if ($token) $pdo->prepare("DELETE FROM sessions WHERE id=?")->execute([$token]);
    respond(200, ['ok' => true]);
}

function changePassword(PDO $pdo): void
{
    $user = requireAuth($pdo);
    $body = jsonBody();

    $currentPassword = $body['current_password'] ?? '';
    $newPassword = $body['new_password'] ?? '';

    if (!$currentPassword || !$newPassword) {
        respond(400, ['error' => 'Se requieren current_password y new_password']);
    }

    if (strlen($newPassword) < 6) {
        respond(400, ['error' => 'La nueva contraseña debe tener mínimo 6 caracteres']);
    }

    // Obtener contraseña hash actual del usuario
    $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
    $stmt->execute([$user['id']]);
    $result = $stmt->fetch();

    if (!$result) {
        respond(404, ['error' => 'Usuario no encontrado']);
    }

    // Verificar contraseña actual
    if (!password_verify($currentPassword, $result['password_hash'])) {
        respond(401, ['error' => 'Contraseña actual incorrecta']);
    }

    // Hash y guardar nueva contraseña
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$newHash, $user['id']]);

    respond(200, ['ok' => true, 'message' => 'Contraseña actualizada exitosamente']);
}

function me(PDO $pdo): void
{
    $user  = requireAuth($pdo);
    $user['avatar_url'] = $user['avatar_url'] ?: getAvatarByEmail($user['email']);
    $stats = $pdo->prepare("SELECT * FROM user_stats WHERE user_id=?");
    $stats->execute([$user['id']]);
    respond(200, ['user' => $user, 'stats' => $stats->fetch() ?: null]);
}

// ═══════════════════════════════════════════════════════════════
// PREDICTIONS
// ═══════════════════════════════════════════════════════════════

function getPredictions(PDO $pdo): void
{
    $user = requireAuth($pdo);
    
    // Paginación: por defecto 50 pronósticos por página
    $limit  = min(100, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));

    // CRÍTICA: No llamar verifyPredictions() en cada carga
    // Eso ocurre asincronamente desde el frontend
    // verifyPredictions($pdo, $user['id']);

    $stmt = $pdo->prepare("
        SELECT
            p.id,
            p.match_api_id,
            p.home_pred,
            p.away_pred,
            p.points_exact,
            p.points_winner,
            p.points_total,
            p.result_checked,
            p.last_real_home,
            p.last_real_away,
            p.created_at,
            p.updated_at,
            m.home_team,
            m.away_team,
            m.event_date,
            m.status,
            m.home_score  AS real_home,
            m.away_score  AS real_away,
            m.league_name,
            m.is_mundial
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE p.user_id = ?
        ORDER BY m.event_date DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$user['id'], $limit, $offset]);
    $predictions = $stmt->fetchAll();

    // Obtener total de pronósticos del usuario (para paginación)
    $countStmt = $pdo->prepare("SELECT COUNT(*) as total FROM predictions WHERE user_id = ?");
    $countStmt->execute([$user['id']]);
    $countRow = $countStmt->fetch();
    $total = (int)($countRow['total'] ?? 0);

    // Enriquecer con tipo de resultado para el frontend
    foreach ($predictions as &$pred) {
        $pred['result_type'] = getResultType($pred);
    }
    unset($pred);

    respond(200, [
        'predictions' => $predictions,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ]
    ]);
}

function getPredictionsAll(PDO $pdo): void
{
    // Limitar a máximo 500 pronósticos para evitar sobrecarga
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 300)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    
    $stmt = $pdo->prepare("
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
            m.home_team,
            m.away_team,
            m.event_date,
            m.status,
            m.home_score  AS real_home,
            m.away_score  AS real_away,
            m.league_name,
            m.is_mundial,
            u.id as user_id,
            u.display_name AS username,
            u.avatar_url
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        INNER JOIN users u ON u.id = p.user_id
        ORDER BY 
            CASE 
                WHEN DATE(m.event_date) = CURDATE() THEN 0
                WHEN DATE(m.event_date) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 1
                WHEN DATE(m.event_date) = DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN 2
                ELSE 3
            END,
            m.event_date DESC,
            u.display_name ASC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$limit, $offset]);
    $predictions = $stmt->fetchAll();

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as total FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
    ");
    $countStmt->execute();
    $countRow = $countStmt->fetch();
    $total = (int)($countRow['total'] ?? 0);

    // Enriquecer con tipo de resultado
    // IMPORTANTE: NO procesar getAvatarByEmail() en backend
    // Eso lo hace el frontend con URL determinística
    foreach ($predictions as &$pred) {
        $pred['result_type'] = getResultType($pred);
        // Generar URL de avatar determinística para lazy-loading
        if (!$pred['avatar_url']) {
            $pred['avatar_url'] = sprintf('https://ui-avatars.com/api/?name=%s&background=1a1b28&color=64748b&size=40&bold=true',
                urlencode(substr($pred['username'], 0, 2)));
        }
    }
    unset($pred);

    respond(200, [
        'predictions' => $predictions,
        'pagination' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
        ]
    ]);
}

function savePrediction(PDO $pdo): void
{
    $user  = requireAuth($pdo);
    $body  = jsonBody();

    $matchId  = (int)($body['match_api_id'] ?? 0);
    $homePred = max(0, min(99, (int)($body['home_pred'] ?? 0)));
    $awayPred = max(0, min(99, (int)($body['away_pred'] ?? 0)));

    if ($matchId <= 0) respond(400, ['error' => 'match_api_id inválido']);

    // Asegurar que el partido esté en caché local
    ensureMatchCache($pdo, $matchId);

    // Verificar que el partido no haya iniciado
    $matchStmt = $pdo->prepare("SELECT status, home_team, away_team, event_date FROM matches_cache WHERE api_id=?");
    $matchStmt->execute([$matchId]);
    $match = $matchStmt->fetch();

    if (!$match) respond(404, ['error' => 'Partido no encontrado']);

    if (isMatchStarted($match['status'])) {
        respond(403, [
            'error'  => 'No puedes pronosticar un partido ya iniciado o finalizado',
            'status' => $match['status'],
        ]);
    }

    // Verificar que no esté dentro de los 40 minutos antes del partido
    if (isWithin40MinutesBefore($match['event_date'])) {
        respond(403, [
            'error' => 'No puedes editar tu pronóstico menos de 40 minutos antes del partido',
            'event_date' => $match['event_date'],
        ]);
    }

    // Upsert: un pronóstico por usuario × partido
    $pdo->prepare("
        INSERT INTO predictions (user_id, match_api_id, home_pred, away_pred)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            home_pred       = VALUES(home_pred),
            away_pred       = VALUES(away_pred),
            result_checked  = 0,
            points_exact    = 0,
            points_winner   = 0,
            points_total    = 0,
            last_real_home  = NULL,
            last_real_away  = NULL,
            updated_at      = NOW()
    ")->execute([$user['id'], $matchId, $homePred, $awayPred]);

    respond(200, ['ok' => true, 'message' => "Pronóstico guardado: {$match['home_team']} {$homePred}-{$awayPred} {$match['away_team']}"]);
}

function deletePrediction(PDO $pdo): void
{
    $user    = requireAuth($pdo);
    $matchId = (int)($_GET['match_api_id'] ?? 0);
    if ($matchId <= 0) respond(400, ['error' => 'match_api_id requerido']);

    // No permitir borrar pronóstico de partido ya iniciado
    $stmt = $pdo->prepare("SELECT status, event_date FROM matches_cache WHERE api_id=?");
    $stmt->execute([$matchId]);
    $match = $stmt->fetch();
    if ($match && isMatchStarted($match['status'])) {
        respond(403, ['error' => 'No puedes eliminar el pronóstico de un partido ya iniciado']);
    }

    // Verificar que no esté dentro de los 40 minutos antes del partido
    if ($match && isWithin40MinutesBefore($match['event_date'])) {
        respond(403, [
            'error' => 'No puedes editar tu pronóstico menos de 40 minutos antes del partido',
            'event_date' => $match['event_date'],
        ]);
    }

    $pdo->prepare("DELETE FROM predictions WHERE user_id=? AND match_api_id=?")->execute([$user['id'], $matchId]);
    respond(200, ['ok' => true]);
}

// ═══════════════════════════════════════════════════════════════
// LEADERBOARD
// ═══════════════════════════════════════════════════════════════

function leaderboard(PDO $pdo): void
{
    $limit = min(500, (int)($_GET['limit'] ?? 150));
    $stmt  = $pdo->prepare("
        SELECT u.id, u.username, u.display_name, u.avatar_url, u.email,
               COALESCE(SUM(p.points_total), 0) as total_points,
               COUNT(CASE WHEN p.points_total > 0 THEN 1 END) as correct_predictions,
               COUNT(CASE WHEN p.points_exact > 0 THEN 1 END) as exact_scores,
               COUNT(p.id) as total_predictions,
               ROUND(COUNT(CASE WHEN p.points_total > 0 THEN 1 END) / GREATEST(COUNT(p.id), 1) * 100) as accuracy_pct
        FROM users u
        LEFT JOIN predictions p ON u.id = p.user_id AND p.result_checked = 1
        GROUP BY u.id
        ORDER BY total_points DESC, u.id ASC
        LIMIT ?
    ");
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();

    // Agregar posición y verificar avatares
    foreach ($rows as $i => &$row) {
        $row['position'] = $i + 1;
        $row['avatar_url'] = $row['avatar_url'] ?: getAvatarByEmail($row['email']);
        unset($row['email']);
    }
    unset($row);

    respond(200, ['leaderboard' => $rows]);
}

// ═══════════════════════════════════════════════════════════════
// MATCHES (proxy + caché + validación automática)
// ═══════════════════════════════════════════════════════════════

function getMatches(PDO $pdo): void
{
    $league   = (int)($_GET['league']    ?? 0);
    $dateFrom = $_GET['date_from']       ?? null;
    $dateTo   = $_GET['date_to']         ?? null;
    $status   = $_GET['status']          ?? null;
    $mundial  = isset($_GET['mundial']) && $_GET['mundial'] === '1';

    // ── Consultar caché local ──
    $where  = ['1=1'];
    $params = [];
    if ($mundial)  { $where[] = 'm.is_mundial=1'; }
    if ($league)   { $where[] = 'm.league_id=?';              $params[] = $league; }
    if ($dateFrom) { $where[] = 'DATE(m.event_date)>=?';      $params[] = $dateFrom; }
    if ($dateTo)   { $where[] = 'DATE(m.event_date)<=?';      $params[] = $dateTo; }
    if ($status)   { $where[] = 'm.status=?';                 $params[] = $status; }

    $sql  = "SELECT * FROM matches_cache m WHERE " . implode(' AND ', $where)
          . " ORDER BY m.event_date DESC LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cached = $stmt->fetchAll();

    // Si la caché es fresca, devolver directo y verificar pronósticos en background
    $staleRows = array_filter($cached, fn($r) =>
        (time() - strtotime($r['cached_at'])) > CACHE_MATCHES_TTL);

    if ($cached && !$staleRows) {
        verifyPredictions($pdo); // verificar todos sin filtrar por usuario
        respond(200, ['results' => $cached, 'source' => 'cache']);
    }

    // ── Refrescar desde API externa ──
    $urlParams = http_build_query(array_filter([
        'league'    => $league    ?: null,
        'date_from' => $dateFrom,
        'date_to'   => $dateTo,
        'status'    => $status,
        'limit'     => 200,
    ]));
    $raw = externalGet(EXT_API_BASE . '/events/?' . $urlParams);

    if (!$raw || empty($raw['results'])) {
        verifyPredictions($pdo);
        respond(200, ['results' => $cached, 'source' => 'cache_fallback']);
    }

    // ── Actualizar caché con resultados de la API ──
    $upsertSql = "
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
    ";
    $upsert    = $pdo->prepare($upsertSql);
    $hasPredSt = $pdo->prepare("SELECT 1 FROM predictions WHERE match_api_id=? LIMIT 1");

    foreach ($raw['results'] as $m) {
        $hasPredSt->execute([$m['id']]);
        // Solo cachear si hay pronósticos sobre ese partido (o si es Mundial)
        $isM = $mundial || ($m['league']['id'] ?? 0) == 27;
        if ($hasPredSt->fetch() || $isM) {
            $upsert->execute([
                $m['id'],
                $m['league']['id']      ?? null,
                $m['league']['name']    ?? null,
                $m['league']['country'] ?? null,
                $m['season']['id']      ?? null,
                $m['season']['name']    ?? null,
                $m['home_team'],
                $m['away_team'],
                $m['event_date'],
                $m['status'],
                $m['home_score']        ?? null,
                $m['away_score']        ?? null,
                $m['home_score_ht']     ?? null,
                $m['away_score_ht']     ?? null,
                $m['current_minute']    ?? null,
                $m['period']            ?? null,
                $m['round_number']      ?? null,
                $isM ? 1 : 0,
                json_encode($m),
            ]);
        }
    }

    // ── Verificar pronósticos tras actualizar el caché ──
    verifyPredictions($pdo);

    respond(200, ['results' => $raw['results'], 'source' => 'api']);
}

// ═══════════════════════════════════════════════════════════════
// VERIFY ENDPOINT  (trigger manual o cron)
// ═══════════════════════════════════════════════════════════════

/**
 * Endpoint para verificación asincrónica de pronósticos
 * Útil para no bloquear la carga de la página
 * Se puede llamar desde el frontend con fetch() en background
 */
function verifyAsync(PDO $pdo): void
{
    $user = requireAuth($pdo);
    
    // Llamar versión lite que no refresca desde API externa
    $result = verifyPredictionsLite($pdo, $user['id']);
    
    respond(200, [
        'ok' => true,
        'verified_count' => $result['total'],
        'timestamp' => date('c')
    ]);
}

/**
 * Verifyendpoint  (trigger manual o cron)
 * ═════════════════════════════════════════════════════════════════
 */
function verifyEndpoint(PDO $pdo): void
{
    requireAuth($pdo);
    $result = verifyPredictions($pdo);
    respond(200, ['ok' => true, 'verified' => $result]);
}

/**
 * Endpoint público para cron job (GET /api.php/cron?secret=TU_CLAVE)
 * Agregar en crontab:  * * * * * curl "https://tudominio.com/api.php/cron?secret=CLAVE"
 */
function cronVerify(PDO $pdo): void
{
    $secret = $_GET['secret'] ?? '';
    if ($secret !== 'CLAVE_SECRETA_AQUI') respond(403, ['error' => 'Forbidden']);
    $result = verifyPredictions($pdo);
    respond(200, ['ok' => true, 'timestamp' => date('c'), 'verified' => $result]);
}

// ═══════════════════════════════════════════════════════════════
// VERIFICACIÓN DE PRONÓSTICOS — Núcleo del sistema de puntos
// ═══════════════════════════════════════════════════════════════

/**
 * Calcula y asigna puntos a pronósticos de partidos finalizados.
 *
 * Sistema de puntos:
 *   12 pts → marcador exacto        (ej: predijo 2-1, resultado 2-1)
 *    7 pts → resultado general (acertaste ganador y la cantidad de goles de un equipo)
 *    5 pts → resultado parcial (acertaste ganador/empate pero no los goles)
 *    2 pts → goles de un solo equipo acertados
 *    0 pts → fallo total
 *
 * Se recalcula si el resultado de la API fue corregido después.
 *
 * @param PDO      $pdo
 * @param int|null $userId  Filtrar por usuario (null = todos)
 * @return array   Resumen de pronósticos verificados
 */
function verifyPredictions(PDO $pdo, ?int $userId = null): array
{
    refreshPredictedMatches($pdo, $userId);

    // Seleccionar pronósticos pendientes de partidos finalizados
    // O pronósticos ya verificados con resultado distinto (corrección de árbitro/VAR)
    $sql = "
        SELECT
            p.id            AS pred_id,
            p.user_id,
            p.home_pred,
            p.away_pred,
            p.result_checked,
            p.last_real_home,
            p.last_real_away,
            m.api_id        AS match_id,
            m.home_score    AS real_home,
            m.away_score    AS real_away,
            m.status
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE
            m.status     IN ('finished','ft','full_time')
            AND m.home_score IS NOT NULL
            AND m.away_score IS NOT NULL
            AND (
                p.result_checked = 0
                OR p.last_real_home IS NULL
                OR p.last_real_away IS NULL
                OR p.last_real_home <> m.home_score
                OR p.last_real_away <> m.away_score
            )
    ";
    $params = [];
    if ($userId !== null) {
        $sql    .= " AND p.user_id = ?";
        $params[] = $userId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (empty($rows)) return ['total' => 0, 'updated' => []];

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

    $updatedPreds  = [];

    foreach ($rows as $row) {
        [$exact, $winner, $total] = calculatePoints(
            (int)$row['home_pred'], (int)$row['away_pred'],
            (int)$row['real_home'], (int)$row['real_away']
        );

        $updateStmt->execute([
            $exact, $winner, $total,
            $row['real_home'], $row['real_away'],
            $row['pred_id'],
        ]);

        $updatedPreds[] = [
            'pred_id'      => $row['pred_id'],
            'user_id'      => $row['user_id'],
            'match_id'     => $row['match_id'],
            'home_pred'    => $row['home_pred'],
            'away_pred'    => $row['away_pred'],
            'real_home'    => $row['real_home'],
            'real_away'    => $row['real_away'],
            'points_exact' => $exact,
            'points_winner'=> $winner,
            'points_total' => $total,
        ];
    }

    return ['total' => count($updatedPreds), 'updated' => $updatedPreds];
}

/**
 * VERSIÓN OPTIMIZADA: Verifica pronósticos SIN llamar a refreshPredictedMatches()
 * ─────────────────────────────────────────────────────────────────────────────────
 * Esto se usa para carga asincrónica desde el frontend.
 * Evita las llamadas HTTP a la API externa que ralentizan la carga.
 * Asume que los datos de matches_cache ya están relativamente frescos.
 *
 * @param PDO      $pdo
 * @param int|null $userId  Filtrar por usuario (null = todos)
 * @return array   Resumen de pronósticos verificados
 */
function verifyPredictionsLite(PDO $pdo, ?int $userId = null): array
{
    // NO llamar refreshPredictedMatches() - eso es lento y hace llamadas HTTP
    // Solo verificar pronósticos con datos ya cachados

    // Seleccionar pronósticos pendientes de partidos finalizados
    $sql = "
        SELECT
            p.id            AS pred_id,
            p.user_id,
            p.home_pred,
            p.away_pred,
            p.result_checked,
            p.last_real_home,
            p.last_real_away,
            m.api_id        AS match_id,
            m.home_score    AS real_home,
            m.away_score    AS real_away,
            m.status
        FROM predictions p
        INNER JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE
            m.status     IN ('finished','ft','full_time')
            AND m.home_score IS NOT NULL
            AND m.away_score IS NOT NULL
            AND (
                p.result_checked = 0
                OR p.last_real_home IS NULL
                OR p.last_real_away IS NULL
                OR p.last_real_home <> m.home_score
                OR p.last_real_away <> m.away_score
            )
    ";
    $params = [];
    if ($userId !== null) {
        $sql    .= " AND p.user_id = ?";
        $params[] = $userId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (empty($rows)) return ['total' => 0, 'updated' => []];

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

    $affectedUsers = [];
    $updatedPreds  = [];
    $batchSize     = 0;
    $maxBatchSize  = 100; // Actualizar points cada 100 predicciones para optimizar

    foreach ($rows as $row) {
        [$exact, $winner, $total] = calculatePoints(
            (int)$row['home_pred'], (int)$row['away_pred'],
            (int)$row['real_home'], (int)$row['real_away']
        );

        $updateStmt->execute([
            $exact, $winner, $total,
            $row['real_home'], $row['real_away'],
            $row['pred_id'],
        ]);

        $updatedPreds[] = [
            'pred_id'      => $row['pred_id'],
            'user_id'      => $row['user_id'],
            'match_id'     => $row['match_id'],
            'points_total' => $total,
        ];
    }

    return ['total' => count($updatedPreds), 'updated' => $updatedPreds];
}

/**
 * Calcula puntos dado un pronóstico y el resultado real.
 *
 * @return array [points_exact, points_winner, points_total]
 */
function calculatePoints(int $homePred, int $awayPred, int $realHome, int $realAway): array
{
    // Nuevo sistema de puntuación:
    // - Exacto: 12 pts
    // - Resultado general (ganador + goles de un equipo): 7 pts
    // - Resultado parcial (ganador correcto, falla goles): 5 pts
    // - Goles de un equipo acertados (sin acertar ganador): 2 pts
    // - Ningún acierto: 0 pts

    // Marcador exacto → 12 pts
    if ($homePred === $realHome && $awayPred === $realAway) {
        return [12, 0, 12];
    }

    $predSign = $homePred <=> $awayPred;  // -1, 0, +1
    $realSign = $realHome <=> $realAway;

    // Si el signo (ganador/empate) es correcto
    if ($predSign === $realSign) {
        // Si además acertaste la cantidad de goles de alguno de los equipos → 7 pts
        if ($homePred === $realHome || $awayPred === $realAway) {
            return [0, 7, 7];
        }
        // Ganador/empate correcto pero fallaste ambos marcadores → 5 pts
        return [0, 5, 5];
    }

    // Si no acertaste ganador pero sí la cantidad de goles de uno de los equipos → 2 pts
    if ($homePred === $realHome || $awayPred === $realAway) {
        return [0, 2, 2];
    }

    // Ningún acierto → 0 pts
    return [0, 0, 0];
}

// ═══════════════════════════════════════════════════════════════
// UPLOAD AVATAR
// ═══════════════════════════════════════════════════════════════

function uploadAvatar(PDO $pdo): void
{
    $user = requireAuth($pdo);

    if (!isset($_FILES['avatar'])) {
        respond(400, ['error' => 'No se envió archivo']);
    }

    $file = $_FILES['avatar'];
    
    // Validar errores de upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors = [
            UPLOAD_ERR_INI_SIZE   => 'Archivo muy grande (límite del servidor)',
            UPLOAD_ERR_FORM_SIZE  => 'Archivo muy grande (límite del formulario)',
            UPLOAD_ERR_PARTIAL    => 'Carga incompleta',
            UPLOAD_ERR_NO_FILE    => 'No se seleccionó archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'No hay directorio temporal',
            UPLOAD_ERR_CANT_WRITE => 'No se puede escribir el archivo',
            UPLOAD_ERR_EXTENSION  => 'Extensión no permitida',
        ];
        $errorMsg = $errors[$file['error']] ?? 'Error desconocido';
        respond(400, ['error' => $errorMsg]);
    }

    // Validar que sea una imagen
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeType, $allowedMimes)) {
        respond(400, ['error' => 'Solo se permiten imágenes (JPG, PNG, GIF, WebP)']);
    }

    $avatarUrl = processAvatarUpload($file, $user['email']);
    if (!$avatarUrl) {
        respond(400, ['error' => 'Error al procesar la imagen. Verifica el tamaño y formato']);
    }

    $pdo->prepare("UPDATE users SET avatar_url = ? WHERE id = ?")->execute([$avatarUrl, $user['id']]);

    respond(200, ['ok' => true, 'avatar_url' => $avatarUrl]);
}

// ═══════════════════════════════════════════════════════════════
// HELPERS — AVATARES
// ═══════════════════════════════════════════════════════════════

/**
 * Busca un archivo de avatar en /uploads/ basado en el email del usuario.
 * Verifica las extensiones comunes: .jpg, .jpeg, .png, .gif, .webp
 *
 * @param string $email El email del usuario
 * @return string|null La ruta relativa del avatar si existe, null en caso contrario
 */
/* archivos que acepta */
function getAvatarByEmail(string $email): ?string
{
    $uploadDir = __DIR__ . '/uploads/';
    $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    // Normalizar nombre igual que en processAvatarUpload (sin caracteres especiales)
    $cleanEmail = preg_replace('/[^a-z0-9._-]/i', '', $email);
    
    foreach ($extensions as $ext) {
        $filename = $cleanEmail . '.' . $ext;
        $path = $uploadDir . $filename;
        if (file_exists($path)) {
            return 'uploads/' . $filename;
        }
    }
    
    return null;
}

// ═══════════════════════════════════════════════════════════════
// HELPERS — PARTIDOS
// ═══════════════════════════════════════════════════════════════

function isMatchStarted(string $status): bool
{
    return in_array(strtolower(trim($status)), STARTED_STATUSES, true);
}

function isMatchFinished(string $status): bool
{
    return in_array(strtolower(trim($status)), FINISHED_STATUSES, true);
}

/**
 * Verifica si falta 40 minutos o menos para que comience el partido.
 * Retorna true si quedan 40 minutos o menos (y el partido aún no ha empezado).
 * Los usuarios no pueden editar pronósticos dentro de este período.
 */
function isWithin40MinutesBefore(string $eventDate): bool
{
    $eventTime = strtotime($eventDate);
    $now = time();
    $diffSeconds = $eventTime - $now;
    // Si quedan 40 minutos o menos (2400 segundos) pero aún no comienza
    return $diffSeconds <= 2400 && $diffSeconds > 0;
}

/**
 * Alias para compatibilidad con código antiguo
 */
function isWithin30MinutesBefore(string $eventDate): bool
{
    return isWithin40MinutesBefore($eventDate);
}

/**
 * Asegura que el partido exista en matches_cache;
 * si no, lo trae de la API externa.
 */
function ensureMatchCache(PDO $pdo, int $matchId): void
{
    $stmt = $pdo->prepare("SELECT api_id FROM matches_cache WHERE api_id=?");
    $stmt->execute([$matchId]);
    if ($stmt->fetch()) return;

    $m = externalGet(EXT_API_BASE . "/events/{$matchId}/");
    if (!$m || empty($m['id'])) {
        respond(404, ['error' => 'Partido no encontrado en la API externa']);
    }

    saveMatchCache($pdo, $m);
}

function refreshMatchCache(PDO $pdo, int $matchId): void
{
    $m = externalGet(EXT_API_BASE . "/events/{$matchId}/");
    if (!$m || empty($m['id'])) {
        return;
    }

    saveMatchCache($pdo, $m);
}

function refreshPredictedMatches(PDO $pdo, ?int $userId = null): void
{
    $sql = "
        SELECT DISTINCT p.match_api_id, m.status, m.cached_at, m.home_score, m.away_score
        FROM predictions p
        LEFT JOIN matches_cache m ON m.api_id = p.match_api_id
        WHERE 1=1
    ";
    $params = [];
    if ($userId !== null) {
        $sql .= " AND p.user_id = ?";
        $params[] = $userId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $status   = strtolower(trim($row['status'] ?? ''));
        $isLive   = in_array($status, LIVE_STATUSES, true);
        $cachedAt = $row['cached_at'] ? strtotime($row['cached_at']) : 0;
        $age      = time() - $cachedAt;
        $isStale  = $cachedAt === 0 || $age > CACHE_MATCHES_TTL;
        $needsSync = $row['home_score'] === null
                  || $row['away_score'] === null
                  || $isStale
                  || ($isLive && $age > 15);

        if ($needsSync) {
            refreshMatchCache($pdo, (int)$row['match_api_id']);
        }
    }
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
        (isset($m['league']['id']) && $m['league']['id'] == 27) ? 1 : 0,
        json_encode($m),
    ]);
}

/**
 * Determina el tipo de resultado de un pronóstico para el frontend.
 */
function getResultType(array $pred): string
{
    if (!$pred['result_checked']) return 'pending';
    if ($pred['points_total'] == 0) return 'loss';
    if ($pred['points_exact'] > 0)  return 'exact';   // marcador exacto (12 pts)
    return 'winner';                                   // acierto parcial/ganador (>=2 pts)
}

// ═══════════════════════════════════════════════════════════════
// HELPERS — HTTP / AUTH
// ═══════════════════════════════════════════════════════════════

function requireAuth(PDO $pdo): array
{
    $token = getBearerToken();
    if (!$token) respond(401, ['error' => 'No autenticado']);

    $stmt = $pdo->prepare("
        SELECT u.* FROM sessions s
        JOIN users u ON u.id = s.user_id
        WHERE s.id=? AND s.expires_at > NOW() AND u.is_active=1
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) respond(401, ['error' => 'Sesión inválida o expirada']);
    unset($user['password_hash']);
    return $user;
}

function getBearerToken(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (!$h && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $h = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    }

    if (!$h) $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';

    if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return $m[1];
    return isset($_GET['token']) ? $_GET['token'] : null;
}

function externalGet(string $url): ?array
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
        'timeout' => 8,
    ]]);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) return null;
    $decoded = json_decode($res, true);
    return is_array($decoded) ? $decoded : null;
}

function jsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function respond(int $code, array $data): never
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
