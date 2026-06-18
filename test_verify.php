<?php
// test_verify.php
// Ejecuta la verificación de pronósticos directamente desde CLI.

if (php_sapi_name() !== 'cli') {
    echo "Este script debe ejecutarse desde la línea de comandos.\n";
    exit(1);
}

require __DIR__ . '/api.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo "No se pudo inicializar la conexión PDO.\n";
    exit(1);
}

try {
    $result = verifyPredictions($pdo);
    echo "Verificación completada.\n";
    echo "Total actualizados: " . ($result['total'] ?? 0) . "\n";
    if (!empty($result['updated'])) {
        echo "Detalles de pronósticos actualizados:\n";
        foreach ($result['updated'] as $pred) {
            echo "- pred_id=" . $pred['pred_id'] .
                 " user_id=" . $pred['user_id'] .
                 " match_id=" . $pred['match_id'] .
                 " total=" . $pred['points_total'] .
                 " exact=" . $pred['points_exact'] .
                 " winner=" . $pred['points_winner'] . "\n";
        }
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
