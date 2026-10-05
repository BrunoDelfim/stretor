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
# .env a partir do exemplo para não travar a subida. O ajuste do HMR é
# reaplicado aqui também, senão o Vite tentaria recarregar pela porta 80 e a
# recarga automática não funcionaria no proxy HTTPS do Codespaces.
if [ ! -f .env ]; then
  echo "[codespace] .env ausente; criando a partir do .env.example"
  cp .env.example .env
fi

# O .env do PC local aponta o frontend para http://localhost. No Space isso
# quebra: o navegador do usuário resolve "localhost" para a máquina dele, não
# para o container, e a Home fica sem filmes. Aqui os caminhos viram relativos
# (mesmo host), que é como o Nginx serve frontend e API na mesma origem.
ajustar_variavel() {
  local chave="$1" valor="$2"
  if grep -q "^${chave}=" .env; then
    sed -i "s|^${chave}=.*|${chave}=${valor}|" .env
  else
    printf '%s=%s\n' "$chave" "$valor" >> .env
  fi
}

ajustar_variavel VITE_API_URL "/api"
ajustar_variavel VITE_MEDIA_SERVICE_URL "/media"

# O HMR do Vite precisa apontar para a porta pública do proxy HTTPS do
# Codespaces (443), e não para a 80 do PC local — senão a recarga automática
# tenta um websocket em ws:// e o navegador bloqueia por ser página HTTPS.
ajustar_variavel VITE_HMR_CLIENT_PORT "443"

# ---------------------------------------------------------------------------
# 3. Sobe o stack
# ---------------------------------------------------------------------------
# `--build` garante que as imagens reflitam o código atual do repositório. O
# compose é idempotente: containers já no ar são apenas reconciliados, e o
# entrypoint de cada serviço pula o que já está pronto (vendor, node_modules,
# migrations). É o equivalente ao `make up` do PC local.
#
# A subida é repetida até o stack convergir. Na retomada do Space o daemon do
# Docker-in-Docker às vezes aceita a conexão antes de estar pronto para criar
# containers, e o `up` retorna tendo subido só parte dos serviços — foi assim
# que o Nginx e o frontend ficaram de fora e a porta 80 respondia 404. Repetir
# o comando é seguro: o compose só cria o que falta.
SERVICOS_ESPERADOS="backend frontend media-service nginx postgres redis prowlarr flaresolverr searxng"
TENTATIVAS_UP=0
while :; do
  TENTATIVAS_UP=$((TENTATIVAS_UP + 1))
  echo "[codespace] Executando docker compose up -d --build (tentativa $TENTATIVAS_UP)..."
  docker compose up -d --build || true

  FALTANDO=""
  for servico in $SERVICOS_ESPERADOS; do
    if ! docker compose ps --status running --services 2>/dev/null | grep -qx "$servico"; then
      FALTANDO="$FALTANDO $servico"
    fi
  done

  if [ -z "$FALTANDO" ]; then
    echo "[codespace] Todos os serviços estão no ar."
    break
  fi

  if [ "$TENTATIVAS_UP" -ge 3 ]; then
    echo "[codespace] AVISO: serviços ainda ausentes após 3 tentativas:$FALTANDO"
    echo "[codespace] Veja o motivo com: docker compose logs --tail=50"
    break
  fi

  echo "[codespace] Ainda faltam:$FALTANDO — aguardando 5s e repetindo."
  sleep 5
done

# ---------------------------------------------------------------------------
# 4. Relatório de estado
# ---------------------------------------------------------------------------
echo "[codespace] Estado dos containers:"
docker compose ps

echo ""
echo "[codespace] Stretor no ar. Acesse pela aba 'Ports' do Codespaces, na porta 80."
echo "[codespace] Logs: docker compose logs -f"

# Lembrete da única variável obrigatória. O .env nasce do exemplo, sem a chave,
# e sem ela a Home não carrega filmes. Avisar aqui evita a caçada ao erro.
if ! grep -q '^TMDB_API_KEY=.\+' .env; then
  echo ""
  echo "[codespace] ATENÇÃO: TMDB_API_KEY está vazia no .env."
  echo "[codespace] A Home não carrega filmes sem ela. Preencha e rode: make aplicar"
fi
