#!/bin/sh
set -e

cd /app

echo "[frontend] Verificando dependências..."
LOCK_HASH_FILE="node_modules/.lock-hash"
CURRENT_HASH="$(sha256sum package-lock.json 2>/dev/null | awk '{print $1}')"
if [ ! -d node_modules ] || [ ! -f "$LOCK_HASH_FILE" ] || [ "$(cat "$LOCK_HASH_FILE" 2>/dev/null)" != "$CURRENT_HASH" ]; then
  echo "[frontend] Instalando dependências (npm ci)..."
  npm ci
  echo "$CURRENT_HASH" > "$LOCK_HASH_FILE"
else
  echo "[frontend] node_modules/ atualizado, pulando npm ci"
fi

echo "[frontend] Iniciando Vite..."
exec "$@"
