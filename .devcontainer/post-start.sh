#!/usr/bin/env bash
# Subida do stack a cada vez que o Codespace liga.
#
# O Codespaces suspende o Space por inatividade e, ao retomar, os containers
# param. Este script garante que o sistema volte ao ar sozinho, sem o usuário
# precisar rodar `make up` na mão — o mesmo comportamento do PC local, onde o
# `restart: unless-stopped` do compose já cuida disso.
set -euo pipefail

cd "$(dirname "$0")/.."

echo "[codespace] Subindo o Stretor..."

# ---------------------------------------------------------------------------
# 1. Espera o daemon do Docker responder
# ---------------------------------------------------------------------------
# Na retomada do Space o daemon do Docker-in-Docker pode levar alguns segundos
# para aceitar conexões. Sem esta espera, o primeiro `docker compose` falha com
# "Cannot connect to the Docker daemon" e o stack não sobe.
echo "[codespace] Aguardando o daemon do Docker..."
TENTATIVAS=0
until docker info >/dev/null 2>&1; do
  TENTATIVAS=$((TENTATIVAS + 1))
  if [ "$TENTATIVAS" -ge 30 ]; then
    echo "[codespace] ERRO: o daemon do Docker não respondeu após 60s."
    exit 1
  fi
  sleep 2
done
echo "[codespace] Docker disponível."

# ---------------------------------------------------------------------------
# 2. Garante o .env
# ---------------------------------------------------------------------------
# Se o Space foi recriado sem passar pelo post-create (caso raro), recria o
# .env a partir do exemplo para não travar a subida.
if [ ! -f .env ]; then
  echo "[codespace] .env ausente; criando a partir do .env.example"
  cp .env.example .env
fi

# ---------------------------------------------------------------------------
# 3. Sobe o stack
# ---------------------------------------------------------------------------
# `--build` garante que as imagens reflitam o código atual do repositório. O
# compose é idempotente: containers já no ar são apenas reconciliados, e o
# entrypoint de cada serviço pula o que já está pronto (vendor, node_modules,
# migrations). É o equivalente ao `make up` do PC local.
echo "[codespace] Executando docker compose up -d --build..."
docker compose up -d --build

# ---------------------------------------------------------------------------
# 4. Relatório de estado
# ---------------------------------------------------------------------------
echo "[codespace] Estado dos containers:"
docker compose ps

echo ""
echo "[codespace] Stretor no ar. Acesse pela aba 'Ports' do Codespaces, na porta 80."
echo "[codespace] Logs: docker compose logs -f"
