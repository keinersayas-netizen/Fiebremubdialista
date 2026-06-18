# 🔧 REPARACIÓN: Sistema de Verificación de Pronósticos

## 📋 Problemas Identificados

1. **Código con referencias a columna inexistente**: La función `verifyPredictions()` intentaba actualizar `users.total_points` que no existe en la BD
2. **Caché de partidos desactualizada**: Los 50 partidos en caché tienen casi 9 días de antigüedad y todos están en estado "notstarted"
3. **Ningún pronóstico verificado**: Con los partidos siempre en "notstarted", los pronósticos nunca pueden ser verificados

## ✅ Reparaciones Realizadas

### 1. Limpieza del Código API (`api.php`)

✓ Removida lógica de actualización de `users.total_points` (líneas 790-802)
✓ Removida función `updateUserTotalPoints()` 
✓ Limpiada función `verifyPredictionsLite()` 
✓ El total_points se calcula dinámicamente en `leaderboard()` (no se guarda en BD)

### 2. Scripts de Actualización Creados

#### `cron_verify_matches.php` - Actualización Automática
Ejecuta cada 5 minutos (o intervalo deseado):
- Obtiene 20 partidos mundialistas aleatorios con pronósticos
- Actualiza datos desde API externa
- Verifica pronósticos de partidos finalizados
- Recalcula puntos

**Uso**:
```bash
php cron_verify_matches.php --secret=MI_CLAVE_SECRETA
```

#### `update_matches.sh` - Script Manual
Ejecutar manualmente cuando sea necesario:
```bash
bash update_matches.sh
```

## 🚀 SETUP RECOMENDADO

### Opción 1: Cron Job Automático (RECOMENDADO)

1. **Acceder al servidor**:
```bash
ssh usuario@tuservidor.com
```

2. **Editar crontab**:
```bash
crontab -e
```

3. **Agregar esta línea** (ejecuta cada 5 minutos):
```cron
*/5 * * * * cd /var/www/html/fiebremundialista && php cron_verify_matches.php --secret=CLAVE_SECRETA_SEGURA >/dev/null 2>&1
```

Cambiar `CLAVE_SECRETA_SEGURA` por una clave de verdad.

4. **Verificar que se agregó**:
```bash
crontab -l
```

### Opción 2: Cron Job cada 15 minutos
```cron
*/15 * * * * cd /var/www/html/fiebremundialista && php cron_verify_matches.php --secret=CLAVE_SECRETA >/dev/null 2>&1
```

### Opción 3: Cron Job cada hora
```cron
0 * * * * cd /var/www/html/fiebremundialista && php cron_verify_matches.php --secret=CLAVE_SECRETA >/dev/null 2>&1
```

## 📊 Verificar que está Funcionando

### Script de Diagnóstico
```bash
php test_verify_diagnosis.php
```

Debería mostrar:
- ✓ Partidos finalizados incrementando
- ✓ Pronósticos verificados incrementando
- ✓ Distribución de puntos (0, 2, 5, 7, 12 pts)
- ✓ Leaderboard actualizado

### Ver Logs del Cron (si está habilitado)
```bash
# En sistemas Linux
grep CRON /var/log/syslog | tail -20

# O revisar directamente si guardaste logs
tail -f /var/www/html/fiebremundialista/cron_log.txt
```

## 🔍 Troubleshooting

### Los pronósticos aún no se verifican

**Posible causa**: API externa no responde
- Verificar que `EXT_API_BASE` y `EXT_API_TOKEN` en `api.php` sean correctos
- Probar llamada manual:
```bash
curl -H "Authorization: Token 5d5b7145bcf005bd2b0e6a26e43a956c3a130d5f" \
  "https://sports.bzzoiro.com/api/events/123456/"
```

### Permisos de ejecución
```bash
chmod +x /var/www/html/fiebremundialista/update_matches.sh
chmod +x /var/www/html/fiebremundialista/cron_verify_matches.php
```

### Ver si el cron se ejecutó
```bash
# Crear log de prueba
php cron_verify_matches.php --secret=test 2>&1 | tee /var/www/html/fiebremundialista/cron_test.log

# Ver el resultado
cat /var/www/html/fiebremundialista/cron_test.log
```

## 📝 Notas Técnicas

1. **Sistema de Puntos** (sin cambios):
   - 12 pts: Marcador exacto (2-1 = 2-1)
   - 7 pts: Resultado general (acertó ganador + goles de un equipo)
   - 5 pts: Resultado parcial (acertó ganador/empate, falló goles)
   - 2 pts: Solo goles acertados (sin acertar ganador)
   - 0 pts: Sin aciertos

2. **Caché de Partidos**:
   - TTL: 120 segundos (se refresca si > 2 min sin actualizar)
   - Partidos mundialistas (is_mundial=1) siempre se actualizan
   - En vivo: se actualizan cada 15 segundos
   - Finalizados: se verifican una sola vez y se marcan con result_checked=1

3. **Seguridad**:
   - El script cron requiere un `--secret` para ejecutar
   - En producción, usar una clave aleatoria compleja
   - Ejemplo: `php -r "echo bin2hex(random_bytes(32));"`

## ✨ Resultado Esperado

Después de configurar el cron:
1. ✓ Partidos se actualizan automáticamente cada 5 minutos
2. ✓ Pronósticos se verifican en tiempo real
3. ✓ Leaderboard se actualiza automáticamente
4. ✓ Usuarios ven sus puntos incrementando
5. ✓ API responde sin errores

---

**Fecha de reparación**: Junio 11, 2026
**Versión API**: 3.0
