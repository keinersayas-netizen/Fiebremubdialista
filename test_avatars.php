<?php
/**
 * Script de prueba para verificar que los avatares se cargan automáticamente
 * Ejecutar: php test_avatars.php
 */

// Configuración DB
define('DB_HOST',    'localhost');
define('DB_NAME',    'matchday_db');
define('DB_USER',    'admin');
define('DB_PASS',    '2eac4425e56ae74ac753a2f5906649bc1c0d6b19586a5ae4');
define('DB_CHARSET', 'utf8mb4');

// Conectar a la BD
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
    die("Error de conexión: " . $e->getMessage() . "\n");
}

// Función helper (copiada de api.php)
function getAvatarByEmail(string $email): ?string
{
    $uploadDir = __DIR__ . '/uploads/';
    $extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    foreach ($extensions as $ext) {
        $filename = $email . '.' . $ext;
        $path = $uploadDir . $filename;
        if (file_exists($path)) {
            return 'uploads/' . $filename;
        }
    }
    
    return null;
}

echo "=== PRUEBA DE AVATARES ===\n\n";

// Obtener todos los usuarios
$stmt = $pdo->prepare("SELECT id, username, email, avatar_url FROM users ORDER BY email");
$stmt->execute();
$users = $stmt->fetchAll();

echo "Total usuarios: " . count($users) . "\n\n";

foreach ($users as $user) {
    $avatarInDB = $user['avatar_url'];
    $avatarInFiles = getAvatarByEmail($user['email']);
    
    echo "📧 Usuario: {$user['username']} ({$user['email']})\n";
    echo "   BD avatar_url: " . ($avatarInDB ? $avatarInDB : "NULL") . "\n";
    echo "   Archivo en /uploads/: " . ($avatarInFiles ? $avatarInFiles : "NO ENCONTRADO") . "\n";
    
    // Simular lo que devolvería el API
    $resultAvatar = $avatarInDB ?: $avatarInFiles;
    echo "   ✓ Se devolvería: " . ($resultAvatar ? $resultAvatar : "NULL (sin avatar)") . "\n";
    
    // Verificar si el archivo realmente existe
    if ($avatarInFiles) {
        $filePath = __DIR__ . '/' . $avatarInFiles;
        $exists = file_exists($filePath);
        $size = $exists ? filesize($filePath) : 0;
        echo "   📁 Archivo: " . ($exists ? "✓ EXISTE (" . formatBytes($size) . ")" : "✗ NO EXISTE") . "\n";
    }
    
    echo "\n";
}

function formatBytes($bytes) {
    if ($bytes < 1024) return $bytes . " B";
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 2) . " KB";
    return round($bytes / (1024 * 1024), 2) . " MB";
}

echo "=== FIN DE LA PRUEBA ===\n";
