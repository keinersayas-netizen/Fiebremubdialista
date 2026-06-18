<?php
/**
 * Script de prueba: Verificar conectividad con API externa
 * 
 * Uso: php test_external_api.php
 */

define('EXT_API_BASE',  'https://sports.bzzoiro.com/api');
define('EXT_API_TOKEN', '5d5b7145bcf005bd2b0e6a26e43a956c3a130d5f');

echo "═══════════════════════════════════════════════════════════════\n";
echo "  TEST: Conectividad con API Externa\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// Test 1: Endpoint genérico
echo "1️⃣  Probando acceso a /events?limit=5...\n";
$url = EXT_API_BASE . '/events?limit=5&status=finished';
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
    'timeout' => 10,
]]);

$start = microtime(true);
$res = @file_get_contents($url, false, $ctx);
$elapsed = microtime(true) - $start;

if ($res === false) {
    echo "   ❌ NO RESPONDE (timeout: {$elapsed}s)\n";
    echo "   → Posible causa: Servidor caído, token inválido, o firewall bloqueando\n\n";
} else {
    $data = json_decode($res, true);
    echo "   ✓ Respuesta en {$elapsed}s\n";
    if (isset($data['results'])) {
        echo "   • Resultados retornados: " . count($data['results']) . "\n";
        foreach (array_slice($data['results'], 0, 3) as $match) {
            echo sprintf("   • %s vs %s [%s]\n",
                $match['home_team'] ?? 'N/A',
                $match['away_team'] ?? 'N/A',
                $match['status'] ?? 'unknown'
            );
        }
    } else {
        echo "   ⚠ Respuesta no contiene 'results'\n";
        echo "   → Respuesta: " . substr(json_encode($data), 0, 200) . "\n";
    }
}

echo "\n";

// Test 2: Partido específico
echo "2️⃣  Probando partido específico...\n";
$testMatchId = 123456; // ID de prueba
$url = EXT_API_BASE . "/events/{$testMatchId}/";
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
    'timeout' => 10,
]]);

$start = microtime(true);
$res = @file_get_contents($url, false, $ctx);
$elapsed = microtime(true) - $start;

if ($res === false) {
    echo "   ⚠ No responde (puede ser ID inválido)\n";
} else {
    $match = json_decode($res, true);
    if (isset($match['id'])) {
        echo "   ✓ Partido encontrado: {$match['home_team']} vs {$match['away_team']}\n";
        echo "   • Status: {$match['status']}\n";
        echo "   • Score: {$match['home_score']}-{$match['away_score']}\n";
    } else if (isset($match['detail'])) {
        echo "   ⚠ Partido no encontrado (ID inválido): {$match['detail']}\n";
    } else {
        echo "   ⚠ Respuesta inesperada: " . substr(json_encode($match), 0, 100) . "\n";
    }
}

echo "\n";

// Test 3: Ligas disponibles
echo "3️⃣  Obteniendo ligas disponibles...\n";
$url = EXT_API_BASE . '/leagues?limit=20';
$ctx = stream_context_create(['http' => [
    'method'  => 'GET',
    'header'  => "Authorization: Token " . EXT_API_TOKEN . "\r\n",
    'timeout' => 10,
]]);

$res = @file_get_contents($url, false, $ctx);
if ($res === false) {
    echo "   ⚠ No responde\n";
} else {
    $data = json_decode($res, true);
    if (isset($data['results'])) {
        echo "   • Ligas disponibles: " . count($data['results']) . "\n";
        foreach (array_slice($data['results'], 0, 5) as $league) {
            echo "   • {$league['id']}: {$league['name']} ({$league['country']})\n";
        }
        
        // Buscar mundial (típicamente id = 27)
        $mundial = array_filter($data['results'], fn($l) => $l['id'] == 27);
        if (!empty($mundial)) {
            echo "   ✓ Liga Mundial encontrada (id=27)\n";
        }
    }
}

echo "\n";

// Test 4: DNS y conectividad
echo "4️⃣  Verificando DNS y conectividad básica...\n";
$host = parse_url(EXT_API_BASE, PHP_URL_HOST);
echo "   • Host: $host\n";

if (function_exists('gethostbyname')) {
    $ip = @gethostbyname($host);
    if ($ip !== $host) {
        echo "   ✓ DNS resuelve a: $ip\n";
    } else {
        echo "   ❌ DNS no resuelve\n";
    }
}

if (function_exists('fsockopen')) {
    $port = 443;
    @$sock = fsockopen($host, $port, $errno, $errstr, 5);
    if ($sock) {
        echo "   ✓ Conectividad TCP al puerto 443: OK\n";
        fclose($sock);
    } else {
        echo "   ❌ No se puede conectar al puerto 443: $errstr\n";
    }
}

echo "\n";

// Test 5: Configuración PHP
echo "5️⃣  Configuración de PHP...\n";
echo "   • allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
echo "   • default_socket_timeout: " . ini_get('default_socket_timeout') . "s\n";
echo "   • OpenSSL: " . (extension_loaded('openssl') ? 'ON' : 'OFF') . "\n";
echo "   • cURL: " . (extension_loaded('curl') ? 'ON' : 'OFF') . "\n";

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "✓ Test completado\n";
echo "═══════════════════════════════════════════════════════════════\n";
?>
