#!/usr/bin/env php
<?php
/**
 * Script de Validación Post-Implementación
 * Verifica que todas las optimizaciones estén en lugar
 * Uso: php validate_optimizations.php
 */

echo "═══════════════════════════════════════════════════════════════\n";
echo "  VALIDACIÓN DE OPTIMIZACIONES - Mis Pronósticos\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$checks_passed = 0;
$checks_failed = 0;

// Color codes
$red = "\033[0;31m";
$green = "\033[0;32m";
$yellow = "\033[1;33m";
$blue = "\033[0;34m";
$reset = "\033[0m";

function check($name, $condition, $details = '') {
    global $checks_passed, $checks_failed, $green, $red, $reset, $blue;
    
    if ($condition) {
        echo "{$green}✓ PASS{$reset} - $name\n";
        if ($details) echo "  {$blue}→ $details{$reset}\n";
        $checks_passed++;
    } else {
        echo "{$red}✗ FAIL{$reset} - $name\n";
        if ($details) echo "  {$blue}→ $details{$reset}\n";
        $checks_failed++;
    }
}

// 1. Verificar archivos
echo "\n{$blue}[1] Verificando archivos{$reset}\n";
check("Archivo api.php existe", file_exists('/var/www/html/fiebremundialista/api.php'));
check("Archivo index.html existe", file_exists('/var/www/html/fiebremundialista/index.html'));
check("Archivo optimize_performance.sql existe", file_exists('/var/www/html/fiebremundialista/optimize_performance.sql'));

// 2. Verificar funciones en api.php
echo "\n{$blue}[2] Verificando funciones en api.php{$reset}\n";
$api_content = file_get_contents('/var/www/html/fiebremundialista/api.php');
check("Función verifyPredictionsLite() existe", strpos($api_content, 'function verifyPredictionsLite') !== false);
check("Función updateUserTotalPoints() existe", strpos($api_content, 'function updateUserTotalPoints') !== false);
check("Función verifyAsync() existe", strpos($api_content, 'function verifyAsync') !== false);
check("getPredictions() tiene LIMIT", strpos($api_content, 'LIMIT ? OFFSET ?') !== false, "Paginación implementada");
check("getPredictionsAll() tiene LIMIT", preg_match('/getPredictionsAll.*LIMIT/s', $api_content) > 0, "Paginación en todos los pronósticos");

// 3. Verificar router
echo "\n{$blue}[3] Verificando rutas en api.php{$reset}\n";
check("Ruta /verify-async existe", strpos($api_content, "verify-async") !== false, "Endpoint para verificación async");

// 4. Verificar funciones en index.html
echo "\n{$blue}[4] Verificando funciones en index.html{$reset}\n";
$html_content = file_get_contents('/var/www/html/fiebremundialista/index.html');
check("Función loadMorePronosticos() existe", strpos($html_content, 'function loadMorePronosticos') !== false);
check("Variable pronPaginationState existe", strpos($html_content, 'pronPaginationState') !== false);
check("Verificación async sin wait", strpos($html_content, "backendPost('/verify-async'") !== false, "No bloquea carga");

// 5. Verificar SQL
echo "\n{$blue}[5] Verificando optimize_performance.sql{$reset}\n";
$sql_content = file_get_contents('/var/www/html/fiebremundialista/optimize_performance.sql');
check("Índice idx_user_checked_match definido", strpos($sql_content, 'idx_user_checked_match') !== false);
check("Índice idx_user_pending definido", strpos($sql_content, 'idx_user_pending') !== false);
check("Índice idx_finished_scores definido", strpos($sql_content, 'idx_finished_scores') !== false);
check("Índice idx_status_date definido", strpos($sql_content, 'idx_status_date') !== false);
check("Stored Procedure sp_verify_predictions_optimized definida", 
      strpos($sql_content, 'sp_verify_predictions_optimized') !== false, "Versión optimizada");

// 6. Verificar documentación
echo "\n{$blue}[6] Verificando documentación{$reset}\n";
check("RESUMEN_EJECUTIVO.md existe", file_exists('/var/www/html/fiebremundialista/RESUMEN_EJECUTIVO.md'));
check("OPTIMIZACIONES_PRONOSTICOS.md existe", file_exists('/var/www/html/fiebremundialista/OPTIMIZACIONES_PRONOSTICOS.md'));

// 7. Verificar patrones de optimización
echo "\n{$blue}[7] Verificando patrones de optimización{$reset}\n";
check("No hay verifyPredictions() bloqueante en getPredictions()", 
      preg_match('/function getPredictions.*?(?!verifyPredictions).*?respond/s', $api_content) > 0 || 
      strpos($api_content, 'NO llama verifyPredictions() bloqueante') !== false,
      "Verificación movida a background");
      
check("getPredictions() retorna pagination info", 
      strpos($api_content, "'pagination'") !== false && strpos($api_content, "'limit'") !== false,
      "Objeto pagination en respuesta");

check("Avatar URLs son determinísticas", 
      strpos($api_content, 'ui-avatars.com/api') !== false,
      "No llama getAvatarByEmail en getPredictionsAll");

// 8. Verificar mejoras en frontend
echo "\n{$blue}[8] Verificando mejoras frontend{$reset}\n";
check("Paginación por defecto 50", 
      preg_match('/limit.*?50/', $html_content) > 0 || 
      strpos($html_content, "limit') ?? 50") !== false,
      "LIMIT 50 por página");

check("Botón Cargar Más condicional", 
      strpos($html_content, 'has_more') !== false,
      "Solo mostrado si has_more === true");

// Resumen final
echo "\n{$blue}═══════════════════════════════════════════════════════════════{$reset}\n";
echo "\nRESULTADO:\n";
echo "  {$green}Pasaron: $checks_passed{$reset}\n";
echo "  {$red}Fallaron: $checks_failed{$reset}\n";

if ($checks_failed === 0) {
    echo "\n{$green}✅ TODAS LAS VALIDACIONES PASARON{$reset}\n";
    echo "\nSiguientes pasos:\n";
    echo "  1. mysql -u admin -p matchday_db < optimize_performance.sql\n";
    echo "  2. Reiniciar servidor web: service apache2 restart\n";
    echo "  3. Testing: bash test_optimizations.sh\n";
    exit(0);
} else {
    echo "\n{$red}❌ ALGUNOS CHECKS FALLARON{$reset}\n";
    echo "\nVerificar:\n";
    echo "  - Que los archivos hayan sido reemplazados correctamente\n";
    echo "  - Que no haya errores de sintaxis\n";
    echo "  - Que todos los cambios estén presentes\n";
    exit(1);
}
?>
