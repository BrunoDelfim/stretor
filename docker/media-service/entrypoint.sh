#!/bin/sh
set -e

cd /app

echo "[media-service] Verificando dependências..."
# A assinatura precisa cobrir os patches além do lockfile: o `postinstall` roda o
# patch-package, então um patch novo só entra em vigor se o install for refeito.
# Sem isso, um node_modules antigo (sem o patch) parecia atualizado pelo hash.
LOCK_HASH_FILE="node_modules/.lock-hash"
CURRENT_HASH="$(cat package-lock.json patches/*.patch 2>/dev/null | sha256sum | awk '{print $1}')"
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
