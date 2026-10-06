#!/bin/sh
# Restaura um backup do banco. APAGA os dados atuais e põe os do arquivo no lugar.
#
#   1. Pare quem usa o banco:   docker compose stop app queue scheduler
#   2. Restaure:                docker compose exec backup sh /scripts/restore.sh jetcar_2026-10-08_030000.dump --confirmar
#   3. Suba de novo:            docker compose start app queue scheduler
#
# As fotos (files/storage_*.tar.gz) são restauradas à parte — veja o README.
set -eu

BACKUP_DIR="${BACKUP_DIR:-/backups}"

if [ $# -lt 2 ] || [ "$2" != "--confirmar" ]; then
  echo "Uso: sh /scripts/restore.sh <arquivo .dump> --confirmar"
  echo "Backups disponíveis:"
  ls -1t "$BACKUP_DIR/db" 2>/dev/null | grep '\.dump$' | head -n 20
  exit 1
fi

FILE="$1"
[ -f "$FILE" ] || FILE="$BACKUP_DIR/db/$1"
if [ ! -f "$FILE" ]; then
  echo "Arquivo não encontrado: $1" >&2
  exit 1
fi

echo "[restore] conferindo $FILE"
pg_restore --list "$FILE" > /dev/null

# Rede de segurança: backup do estado atual antes de sobrescrever (arquivo próprio, *_antes-restore)
echo "[restore] guardando uma cópia do banco atual antes de restaurar"
BACKUP_LABEL=antes-restore sh /scripts/backup.sh

echo "[restore] restaurando $FILE em $PGDATABASE"
pg_restore --clean --if-exists --no-owner --no-privileges --single-transaction -d "$PGDATABASE" "$FILE"
echo "[restore] concluído. Suba de novo: docker compose start app queue scheduler"
