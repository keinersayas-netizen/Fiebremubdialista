#!/bin/bash
# Script para actualizar los resultados de partidos desde la API externa
# Ejecutar: bash update_matches.sh

API_URL="http://localhost/fiebremundialista/api.php/matches?mundial=1"

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Actualizando partidos desde API..."
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

# Intentar actualizar partidos de la API
echo "1. Actualizando caché de partidos..."
RESPONSE=$(curl -s -X GET "$API_URL")
echo "$RESPONSE" | grep -q '"source"' && echo "✓ Caché actualizado" || echo "⚠ Respuesta inesperada"

# Esperar un segundo
sleep 1

# Forzar verificación de pronósticos
echo ""
echo "2. Verificando pronósticos..."
VERIFY_RESPONSE=$(curl -s -X POST "http://localhost/fiebremundialista/api.php/verify" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer TEST_TOKEN")

echo "$VERIFY_RESPONSE" | grep -q '"verified"' && echo "✓ Verificación completada" || echo "⚠ Respuesta inesperada"

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Actualización completada"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
