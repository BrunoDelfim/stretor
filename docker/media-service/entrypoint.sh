#!/bin/sh
set -e

cd /app

echo "[media-service] Verificando dependências..."
LOCK_HASH_FILE="node_modules/.lock-hash"
CURRENT_HASH="$(sha256sum package-lock.json 2>/dev/null | awk '{print $1}')"
if [ ! -d node_modules ] || [ ! -f "$LOCK_HASH_FILE" ] || [ "$(cat "$LOCK_HASH_FILE" 2>/dev/null)" != "$CURRENT_HASH" ]; then
  echo "[media-service] Instalando dependências (npm install)..."
  npm install
  echo "$CURRENT_HASH" > "$LOCK_HASH_FILE"
else
  echo "[media-service] node_modules/ atualizado, pulando npm install"
fi

mkdir -p /app/storage /app/tmp

echo "[media-service] Iniciando serviço..."
exec "$@"
