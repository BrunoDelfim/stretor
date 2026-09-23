#!/bin/sh
set -e

# Cria o .env raiz a partir do .env.example, se ainda não existir.
if [ ! -f .env ]; then
  echo "[init] Criando .env a partir de .env.example"
  cp .env.example .env
else
  echo "[init] .env já existe, nada a fazer"
fi

# Sentinela: marca que o bootstrap já rodou com sucesso.
# Em execuções seguintes, o init pode ser pulado (ver Makefile: up-fast).
touch .bootstrap-done
echo "[init] Bootstrap concluído."
