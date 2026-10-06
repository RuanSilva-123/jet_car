#!/bin/sh
# Backup do JetCar: banco (pg_dump, formato custom) + arquivos privados (fotos da vistoria).
# Roda no container "backup" (imagem do Postgres: pg_dump da mesma versão do servidor).
#
#   Manual:  docker compose exec backup sh /scripts/backup.sh
#
# Saída em /backups (pasta ./backups do projeto):
#   db/jetcar_AAAA-MM-DD_HHMMSS.dump     banco
#   files/storage_AAAA-MM-DD_HHMMSS.tar.gz  fotos e demais arquivos privados
#   last-backup.json                   situação do último backup (mostrada no painel)
set -u

BACKUP_DIR="${BACKUP_DIR:-/backups}"
KEEP_DAYS="${BACKUP_KEEP_DAYS:-14}"
# Segundos no nome + sufixo opcional (ex.: antes-restore): um backup nunca sobrescreve outro
STAMP="$(date +%Y-%m-%d_%H%M%S)${BACKUP_LABEL:+_$BACKUP_LABEL}"
STARTED="$(date -Iseconds)"

mkdir -p "$BACKUP_DIR/db" "$BACKUP_DIR/files"

status() {
  # $1 ok (true/false)  $2 mensagem de erro (ou vazio)  $3 arquivo do banco  $4 arquivo de fotos
  db_size=0; files_size=0
  [ -n "$3" ] && [ -f "$BACKUP_DIR/db/$3" ] && db_size=$(wc -c < "$BACKUP_DIR/db/$3")
  [ -n "$4" ] && [ -f "$BACKUP_DIR/files/$4" ] && files_size=$(wc -c < "$BACKUP_DIR/files/$4")
  count=$(find "$BACKUP_DIR/db" -name 'jetcar_*.dump' | wc -l)
  error=$(printf '%s' "$2" | tr -d '"\\' | tr '\n' ' ')
  cat > "$BACKUP_DIR/last-backup.json.tmp" <<JSON
{
  "ok": $1,
  "started_at": "$STARTED",
  "finished_at": "$(date -Iseconds)",
  "db_file": "$3",
  "db_size": $db_size,
  "files_file": "$4",
  "files_size": $files_size,
  "backups_count": $count,
  "keep_days": $KEEP_DAYS,
  "error": $( [ -n "$error" ] && printf '"%s"' "$error" || printf 'null' )
}
JSON
  mv "$BACKUP_DIR/last-backup.json.tmp" "$BACKUP_DIR/last-backup.json"
}

# 1. Banco: grava num arquivo temporário e só renomeia se o dump e a conferência passarem
DB_FILE="jetcar_$STAMP.dump"
TMP="$BACKUP_DIR/db/$DB_FILE.partial"
if [ -e "$BACKUP_DIR/db/$DB_FILE" ]; then
  echo "[backup] ERRO: $DB_FILE já existe" >&2
  exit 1
fi
if ! output=$(pg_dump -Fc -Z 6 -f "$TMP" 2>&1); then
  rm -f "$TMP"
  echo "[backup] ERRO no pg_dump: $output" >&2
  status false "pg_dump: $output" "" ""
  exit 1
fi
# Conferência: o arquivo precisa ser legível pelo pg_restore
if ! pg_restore --list "$TMP" > /dev/null 2>&1; then
  rm -f "$TMP"
  echo "[backup] ERRO: o dump gerado não passou na conferência" >&2
  status false "dump inválido (pg_restore --list falhou)" "" ""
  exit 1
fi
mv "$TMP" "$BACKUP_DIR/db/$DB_FILE"

# 2. Arquivos privados (fotos da vistoria), se a pasta existir
FILES_FILE=""
if [ -d /storage/app/private ]; then
  FILES_FILE="storage_$STAMP.tar.gz"
  if ! tar -czf "$BACKUP_DIR/files/$FILES_FILE.partial" -C /storage/app private 2>/dev/null; then
    rm -f "$BACKUP_DIR/files/$FILES_FILE.partial"
    status false "falha ao compactar os arquivos" "$DB_FILE" ""
    exit 1
  fi
  mv "$BACKUP_DIR/files/$FILES_FILE.partial" "$BACKUP_DIR/files/$FILES_FILE"
fi

# 3. Retenção: apaga backups com mais de KEEP_DAYS dias (e sobras de execuções interrompidas)
find "$BACKUP_DIR/db" -name 'jetcar_*.dump' -mtime +"$KEEP_DAYS" -exec rm -f {} +
find "$BACKUP_DIR/files" -name 'storage_*.tar.gz' -mtime +"$KEEP_DAYS" -exec rm -f {} +
find "$BACKUP_DIR" -name '*.partial' -mmin +60 -exec rm -f {} +

status true "" "$DB_FILE" "$FILES_FILE"
echo "[backup] OK: db/$DB_FILE${FILES_FILE:+ e files/$FILES_FILE}"
