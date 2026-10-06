#!/bin/sh
# Agendador do container "backup": roda o backup todo dia no horário BACKUP_TIME (HH:MM,
# fuso TZ). Se o container estava parado no horário, faz o backup ao subir quando o
# último tem mais de 24 horas — nenhum dia fica sem backup por causa de reinício.
set -u

BACKUP_DIR="${BACKUP_DIR:-/backups}"
BACKUP_TIME="${BACKUP_TIME:-03:00}"
MARKER="$BACKUP_DIR/.last-run-date"

mkdir -p "$BACKUP_DIR"
echo "[backup] agendado para todo dia às $BACKUP_TIME ($(date +%Z)); guardando ${BACKUP_KEEP_DAYS:-14} dias em $BACKUP_DIR"

# Sem backup nas últimas 24 h (primeira subida, servidor desligado na madrugada...): faz agora
if [ -z "$(find "$BACKUP_DIR/db" -name 'jetcar_*.dump' -mmin -1440 2>/dev/null)" ]; then
  echo "[backup] nenhum backup nas últimas 24 horas: fazendo agora"
  sh /scripts/backup.sh && date +%Y-%m-%d > "$MARKER"
fi

while true; do
  today="$(date +%Y-%m-%d)"
  if [ "$(date +%H:%M)" = "$BACKUP_TIME" ] && [ "$(cat "$MARKER" 2>/dev/null)" != "$today" ]; then
    sh /scripts/backup.sh && echo "$today" > "$MARKER"
  fi
  sleep 30
done
