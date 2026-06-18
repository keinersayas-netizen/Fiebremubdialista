# 🚀 Configuración de Automatización MatchDay

## ⚡ Instalación Rápida (2 pasos)

### Opción A: Instalación Automática (Recomendado)

```bash
cd /var/www/html/fiebremundialista
bash install_cron.sh
```

✅ Eso es todo. El cron estará activo en 5 minutos.

---

### Opción B: Instalación Manual

```bash
# 1. Ver instrucciones
php manage_cron.php --install

# 2. Copiar la línea que aparece

# 3. Agregar al crontab
crontab -e

# 4. Pegar y guardar (Ctrl+X, luego Y)
```

---

## 🔍 Verificar Estado

```bash
php manage_cron.php --status
```

Debería mostrar:
- ✓ Script instalado
- ✓ Clave secreta almacenada
- ✓ Frecuencia: Cada 5 minutos

---

## 🧪 Prueba Manual

```bash
php manage_cron.php --test
```

---

## ✨ ¿Qué se automatiza?

✓ **Cada 5 minutos**:
  - Obtiene últimos resultados de la API
  - Verifica pronósticos de partidos finalizados
  - Calcula y asigna puntos automáticamente
  - Actualiza posiciones en el leaderboard

---

## 🔒 Protecciones Activadas

✓ **Bloqueo de edición**: 40 minutos antes del partido
  - Los usuarios NO pueden editar/crear pronósticos después de este tiempo
  - Protege contra cambios de último minuto

✓ **Verificación automática**: Sin intervención manual
  - No hay que esperar ni hacer nada
  - Los puntos aparecen al instante cuando un partido termina

---

## 📝 Cambios en api.php

✓ **Línea ~981**: `isWithin40MinutesBefore()` 
  - Antes: 30 minutos
  - Ahora: **40 minutos** antes del partido

✓ **Línea ~377 y ~404**: Validación de edición
  - Usa la nueva función con 40 minutos

✓ **Función `verifyPredictions()`**
  - Verificará automáticamente vía cron cada 5 minutos

---

## 🛠️ Mantenimiento

### Ver si está activo
```bash
crontab -l | grep cron_verify
```

### Cambiar frecuencia
```bash
crontab -e

# Cambiar:
# */5  = cada 5 minutos
# */15 = cada 15 minutos
# */30 = cada 30 minutos
# 0 *  = cada hora
```

### Ver logs (si existen)
```bash
tail -f /var/log/syslog | grep CRON
```

### Remover cron
```bash
crontab -e
# Eliminar la línea de cron_verify_matches.php
```

---

## ✅ Checklist Finalización

- [ ] Ejecutaste `bash install_cron.sh` o `php manage_cron.php --install`
- [ ] Agregaste la línea al crontab
- [ ] Verificaste con `php manage_cron.php --status`
- [ ] Hiciste prueba con `php manage_cron.php --test`
- [ ] Los 40 minutos de bloqueo están activos

---

## 🎯 Resultado Final

✅ **Partidos se actualizan automáticamente**
✅ **Pronósticos se verifican sin intervención**
✅ **Puntos aparecen al instante**
✅ **Leaderboard siempre actualizado**
✅ **No vuelven a haber pronósticos "Pendiente"**

---

## ❓ Preguntas Frecuentes

**P: ¿Cuánto tarda en verificar?**
R: Máximo 5 minutos desde que el partido termina

**P: ¿Si se cae el servidor?**
R: Vuelve a verificar cuando se reinicia

**P: ¿Puedo cambiar los 40 minutos?**
R: Sí, edita `api.php` línea ~985, cambia `2400` segundos (40 min)

**P: ¿Necesita credenciales especiales?**
R: Solo la clave secreta que genera el instalador

---

**Documentación**: `/var/www/html/fiebremundialista/REPARACION_VERIFICACION.md`
**Scripts**:
- `cron_verify_matches.php` - Motor principal
- `manage_cron.php` - Gestor del cron
- `install_cron.sh` - Instalador automático
