#!/bin/sh
set -e

cd /app

echo "[frontend] Verificando dependências..."
if [ ! -d node_modules ] || [ ! -f node_modules/.package-lock.json ]; then
  echo "[frontend] Instalando dependências (npm ci)..."
  npm ci
else
  echo "[frontend] node_modules/ já existe, pulando npm ci"
fi

echo "[frontend] Iniciando Vite..."
exec "$@"
