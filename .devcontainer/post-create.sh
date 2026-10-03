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
# O Codespaces expõe cada porta encaminhada numa URL pública própria. O Nginx
# continua na 80 dentro do Space, então as URLs relativas do frontend
# (http://localhost/api e http://localhost/media) funcionam sem alteração: o
# navegador do usuário fala com a porta 80 encaminhada e o Nginx roteia para
# os serviços internos. Não é preciso reescrever VITE_API_URL.
#
# O que muda é o HMR do Vite: ele precisa saber a porta pública do proxy do
# Codespaces para o websocket de recarga. O Vite lê isso do próprio host da
# página quando `hmr.clientPort` está definido como 443 (HTTPS do Codespaces),
# então ajustamos a variável abaixo para o frontend usar a porta certa.
if ! grep -q '^VITE_HMR_CLIENT_PORT=' .env; then
  printf '\n# Porta pública do HMR do Vite atrás do proxy HTTPS do Codespaces.\n' >> .env
  printf 'VITE_HMR_CLIENT_PORT=443\n' >> .env
fi

# ---------------------------------------------------------------------------
# 3. Sentinela de bootstrap
# ---------------------------------------------------------------------------
# O serviço `init` do compose cria o .env se ele faltar. Como já criamos aqui,
# marcamos a sentinela para o `make up-fast` saber que pode pular o init.
touch .bootstrap-done

echo "[codespace] Preparação concluída."
echo "[codespace] O stack será iniciado automaticamente na próxima etapa."
