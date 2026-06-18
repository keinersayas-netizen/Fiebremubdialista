#!/bin/bash
# Instalador automático del Cron Job para MatchDay
# Ejecutar como: bash install_cron.sh

echo "════════════════════════════════════════════════════════════"
echo "  🔧 INSTALADOR: Cron Job MatchDay Verification"
echo "════════════════════════════════════════════════════════════"
echo ""

# Detectar usuario
WEB_USER=$(ps aux | grep -m 1 'apache\|www-data\|nginx' | awk '{print $1}')
if [ -z "$WEB_USER" ] || [ "$WEB_USER" = "root" ]; then
    WEB_USER="www-data"
fi

PROJECT_PATH="/var/www/html/fiebremundialista"
CRON_SCRIPT="$PROJECT_PATH/cron_verify_matches.php"
SECRET_FILE="$PROJECT_PATH/.cron_secret"

echo "📋 Parámetros:"
echo "   • Usuario web: $WEB_USER"
echo "   • Ruta proyecto: $PROJECT_PATH"
echo "   • Script: $CRON_SCRIPT"
echo "   • Archivo secret: $SECRET_FILE"
echo ""

# Verificar que el script existe
if [ ! -f "$CRON_SCRIPT" ]; then
    echo "❌ ERROR: $CRON_SCRIPT no existe"
    exit 1
fi

echo "✓ Script encontrado"
echo ""

# Generar clave secreta aleatoria
SECRET_KEY=$(php -r "echo bin2hex(random_bytes(16));")
printf "%s" "$SECRET_KEY" > "$SECRET_FILE"
chmod 600 "$SECRET_FILE"
echo "✓ Clave secreta guardada"
echo "   • Clave secreta: ${SECRET_KEY:0:16}..."
echo ""

# Crear backup del crontab actual
BACKUP_FILE="/tmp/crontab_backup_$(date +%s).txt"
crontab -u "$WEB_USER" -l > "$BACKUP_FILE" 2>/dev/null || true

echo "📦 Backup de crontab anterior guardado en: $BACKUP_FILE"
echo ""

# Crear nueva entrada de cron
CRON_ENTRY="*/5 * * * * cd $PROJECT_PATH && php cron_verify_matches.php --secret=$SECRET_KEY >/dev/null 2>&1"

# Agregar al crontab si no existe ya
if crontab -u "$WEB_USER" -l 2>/dev/null | grep -q "cron_verify_matches.php"; then
    echo "⚠️  Ya existe una entrada de cron para este script"
    echo "   Se actualizará la existente..."
    TEMP_CRON=$(mktemp)
    crontab -u "$WEB_USER" -l | grep -v "cron_verify_matches.php" > "$TEMP_CRON"
    echo "$CRON_ENTRY" >> "$TEMP_CRON"
    crontab -u "$WEB_USER" "$TEMP_CRON"
    rm "$TEMP_CRON"
else
    echo "➕ Agregando nueva entrada de cron..."
    TEMP_CRON=$(mktemp)
    crontab -u "$WEB_USER" -l 2>/dev/null | grep -v "^#" > "$TEMP_CRON" || true
    echo "" >> "$TEMP_CRON"
    echo "# MatchDay - Verificación automática de pronósticos (instalado: $(date))" >> "$TEMP_CRON"
    echo "$CRON_ENTRY" >> "$TEMP_CRON"
    crontab -u "$WEB_USER" "$TEMP_CRON"
    rm "$TEMP_CRON"
fi

echo ""
echo "════════════════════════════════════════════════════════════"
echo "✓ Instalación completada"
echo "════════════════════════════════════════════════════════════"
echo ""
echo "📌 INFORMACIÓN IMPORTANTE:"
echo ""
echo "1️⃣  El cron se ejecutará CADA 5 MINUTOS para:"
echo "    • Actualizar resultados de partidos"
echo "    • Verificar pronósticos automáticamente"
echo "    • Calcular puntos"
echo ""
echo "2️⃣  Clave secreta (guardar en lugar seguro):"
echo "    $SECRET_KEY"
echo ""
echo "3️⃣  Para correr validación COMPLETA una vez en el servidor:"
echo "    cd $PROJECT_PATH && php cron_verify_matches.php --secret=$SECRET_KEY --mode=full"
echo ""
echo "4️⃣  URL para correr validación completa desde navegador:"
echo "    https://TU-DOMINIO/api.php/cron?secret=$SECRET_KEY&mode=full"
echo ""
echo "5️⃣  Para cambiar la frecuencia, edita con:"
echo "    crontab -e -u $WEB_USER"
echo ""
echo "6️⃣  Para verificar que está activo:"
echo "    crontab -l -u $WEB_USER | grep cron_verify"
echo ""
echo "7️⃣  Para ver logs (si los hay):"
echo "    tail -f /var/log/syslog | grep CRON"
echo ""
echo "8️⃣  Cambios en api.php:"
echo "    ✓ Bloqueo de edición: 40 minutos antes del partido"
echo "    ✓ Todos los pronósticos se verifican automáticamente"
echo ""
echo "════════════════════════════════════════════════════════════"
