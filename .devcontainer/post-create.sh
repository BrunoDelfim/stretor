#!/usr/bin/env bash
# Preparação do Codespace na criação (roda uma única vez, quando o Space nasce).
#
# O objetivo é deixar o repositório pronto para o `post-start.sh` subir o stack
# sem intervenção manual. Aqui só cuidamos do que é estado local do Space e não
# deve ser versionado: o arquivo .env.
set -euo pipefail

cd "$(dirname "$0")/.."
RAIZ="$(pwd)"

echo "[codespace] Preparando o ambiente do Stretor..."

# ---------------------------------------------------------------------------
# 1. Arquivo .env
# ---------------------------------------------------------------------------
# O .env é ignorado pelo git (contém segredos), então cada Space novo precisa
# criá-lo a partir do .env.example. É a única configuração manual permitida
# pelo projeto — e aqui ela é feita automaticamente para não travar a subida.
if [ ! -f .env ]; then
  echo "[codespace] Criando .env a partir do .env.example"
  cp .env.example .env
else
  echo "[codespace] .env já existe, preservando o conteúdo atual"
fi

# ---------------------------------------------------------------------------
# 2. Ajustes específicos do Codespaces no .env
# ---------------------------------------------------------------------------
# O .env.example aponta o frontend para http://localhost. No PC local isso está
# certo, mas no Space quebra: o navegador do usuário resolve "localhost" para a
# máquina dele, não para o container, e a Home fica sem filmes. Aqui as URLs
# viram relativas (mesmo host), que é como o Nginx serve frontend e API na
# mesma origem — o navegador fala com a porta 80 encaminhada e o Nginx roteia
# para os serviços internos.
#
# O HMR do Vite também muda: ele precisa da porta pública do proxy HTTPS do
# Codespaces (443), e não da 80 do PC local. Com 80, o websocket de recarga
# tentaria ws:// numa página HTTPS e o navegador bloquearia.
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
ajustar_variavel VITE_HMR_CLIENT_PORT "443"

# ---------------------------------------------------------------------------
# 3. Sentinela de bootstrap
# ---------------------------------------------------------------------------
# O serviço `init` do compose cria o .env se ele faltar. Como já criamos aqui,
# marcamos a sentinela para o `make up-fast` saber que pode pular o init.
touch .bootstrap-done

echo "[codespace] Preparação concluída."
echo "[codespace] O stack será iniciado automaticamente na próxima etapa."
