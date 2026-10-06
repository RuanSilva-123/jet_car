#!/bin/sh
set -e

# node_modules vive num volume nomeado (binários Linux, separados do host Windows).
# Reinstala quando o package-lock.json muda.
LOCK_HASH="$(md5sum package-lock.json 2>/dev/null | cut -d' ' -f1)"
if [ ! -d node_modules/.bin ] || [ "$(cat node_modules/.lock-hash 2>/dev/null)" != "$LOCK_HASH" ]; then
    echo "Instalando dependências do frontend..."
    if [ -f package-lock.json ]; then npm ci; else npm install; fi
    md5sum package-lock.json | cut -d' ' -f1 > node_modules/.lock-hash
fi

exec "$@"
