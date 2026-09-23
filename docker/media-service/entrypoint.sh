#!/bin/sh
set -e

cd /app

echo "[media-service] Verificando dependências..."
if [ ! -d node_modules ] || [ ! -f node_modules/.package-lock.json ]; then
  echo "[media-service] Instalando dependências (npm ci)..."
  npm ci
else
  echo "[media-service] node_modules/ já existe, pulando npm ci"
fi

mkdir -p /app/storage /app/tmp

echo "[media-service] Iniciando serviço..."
exec "$@"
