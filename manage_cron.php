<?php
/**
 * Instalador/Verificador del Cron Job de MatchDay
 * Ejecutar: php manage_cron.php [--install|--status|--remove|--test]
 */

$action = $argv[1] ?? '--status';

echo "\n";
echo "════════════════════════════════════════════════════════════\n";
echo "  🔧 GESTOR: Cron Job MatchDay Verification\n";
echo "════════════════════════════════════════════════════════════\n\n";

$projectPath = __DIR__;
$cronScript = $projectPath . '/cron_verify_matches.php';
$secretFile = $projectPath . '/.cron_secret';

// Acciones disponibles
match ($action) {
    '--install'  => installCron($cronScript, $secretFile),
    '--status'   => statusCron($cronScript, $secretFile),
    '--remove'   => removeCron($cronScript),
    '--test'     => testCron($cronScript, $secretFile),
    default      => showHelp($action),
};

function installCron($cronScript, $secretFile) {
    if (!file_exists($cronScript)) {
        echo "❌ ERROR: $cronScript no existe\n\n";
        return;
    }

    // Generar clave secreta
    $secret = bin2hex(random_bytes(16));
    file_put_contents($secretFile, $secret);
    chmod($secretFile, 0600);

    echo "✓ Instalación del Cron Job\n\n";
    echo "Para instalar, ejecuta en tu servidor (como root):\n\n";
    echo "  crontab -e\n\n";
    echo "Y agrega esta línea:\n\n";
    $cronDir = dirname($cronScript);
    echo "  */5 * * * * cd $cronDir && php cron_verify_matches.php --secret=$secret >/dev/null 2>&1\n\n";
    echo "Alternativamente, ejecuta:\n\n";
    echo "  bash $cronScript/../install_cron.sh\n\n";

    echo "═════════════════════════════════════════════════════════\n";
    echo "Clave secreta guardada en: $secretFile\n";
    echo "═════════════════════════════════════════════════════════\n\n";
}

function statusCron($cronScript, $secretFile) {
    if (!file_exists($cronScript)) {
        echo "❌ Script de cron no encontrado: $cronScript\n\n";
        return;
    }

    $cronInstalled = isCronInstalled($cronScript);
    $secretExists = file_exists($secretFile);

    echo "📊 ESTADO DEL SISTEMA\n\n";
    
    echo "Script de cron:\n";
    echo "   " . ($cronInstalled ? "✓" : "✗") . " Instalado y activo\n";
    
    echo "\nClave secreta:\n";
    echo "   " . ($secretExists ? "✓" : "✗") . " Almacenada\n";
    
    if ($secretExists) {
        $secret = trim(file_get_contents($secretFile));
        echo "   Clave: " . substr($secret, 0, 16) . "...\n";
    }

    echo "\nFrecuencia:\n";
    echo "   • Cada 5 minutos\n";

    echo "\nFuncionalidades automáticas:\n";
    echo "   ✓ Actualiza partidos desde API externa\n";
    echo "   ✓ Verifica pronósticos cuando los partidos terminan\n";
    echo "   ✓ Calcula y asigna puntos automáticamente\n";

    echo "\n";
    if (!$cronInstalled) {
        echo "⚠️  El cron no está instalado.\n";
        echo "Ejecuta: php manage_cron.php --install\n";
    }
    echo "\n";
}

function removeCron($cronScript) {
    echo "❌ Para remover el cron, ejecuta:\n\n";
    echo "  crontab -e\n\n";
    echo "Y elimina la línea que contiene 'cron_verify_matches.php'\n\n";
}

function testCron($cronScript, $secretFile) {
    echo "🧪 PRUEBA: Ejecutando una vez...\n\n";
    
    if (!file_exists($cronScript)) {
        echo "❌ Script no encontrado\n\n";
        return;
    }

    if (!file_exists($secretFile)) {
        echo "❌ Clave secreta no encontrada\n";
        echo "Ejecuta primero: php manage_cron.php --install\n\n";
        return;
    }

    $secret = trim(file_get_contents($secretFile));
    
    echo "Ejecutando: php cron_verify_matches.php --secret=$secret\n\n";
    echo "═════════════════════════════════════════════════════════\n";
    
    passthru("php " . escapeshellarg($cronScript) . " --secret=" . escapeshellarg($secret));
    
    echo "═════════════════════════════════════════════════════════\n\n";
}

function isCronInstalled($cronScript) {
    $output = shell_exec('crontab -l 2>/dev/null');
    return $output && strpos($output, 'cron_verify_matches.php') !== false;
}

function showHelp($action) {
    echo "❌ Acción desconocida: $action\n\n";
    echo "Uso: php manage_cron.php [opción]\n\n";
    echo "Opciones:\n";
    echo "   --install   Mostrar instrucciones de instalación\n";
    echo "   --status    Ver estado actual del cron\n";
    echo "   --remove    Instrucciones para remover cron\n";
    echo "   --test      Ejecutar una prueba del cron\n\n";
}
?>
