<?php
/**
 * Script para sincronizar avatares existentes en /uploads/ con la base de datos
 * Ejecutar: php sync_avatars.php
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

$uploadDir = __DIR__ . '/uploads/';
$extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$updated = 0;
$notFound = 0;
$alreadySet = 0;

echo "=== SINCRONIZANDO AVATARES ===\n\n";

// Leer archivos en /uploads/
$files = scandir($uploadDir);

foreach ($files as $file) {
    if ($file === '.' || $file === '..') continue;
    
    // Extraer email del nombre del archivo
    // Formato: email.extensión
    $info = pathinfo($file);
    $filename = $info['filename'];
    $ext = strtolower($info['extension']);
    
    // Si el nombre contiene @, probablemente sea un email
    if (strpos($filename, '@') !== false) {
        $email = $filename;
        $avatarPath = 'uploads/' . $file;
        
        // Buscar usuario con ese email
        $stmt = $pdo->prepare("SELECT id, avatar_url FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();
        
        if ($user) {
            // Verificar si ya tiene avatar_url
            if ($user['avatar_url']) {
                echo "[OK] ✓ $email → Ya tiene avatar: {$user['avatar_url']}\n";
                $alreadySet++;
            } else {
                // Actualizar avatar_url
                $updateStmt = $pdo->prepare("UPDATE users SET avatar_url = ? WHERE id = ?");
                $updateStmt->execute([$avatarPath, $user['id']]);
                echo "[UPDATE] ✓ $email → Asignado: $avatarPath\n";
                $updated++;
            }
        } else {
            echo "[NOT FOUND] ✗ $email → Usuario no encontrado en la BD\n";
            $notFound++;
        }
    }
}

echo "\n=== RESUMEN ===\n";
echo "Actualizados: $updated\n";
echo "Ya tenían avatar: $alreadySet\n";
echo "No encontrados en BD: $notFound\n";
echo "\nDone!\n";
