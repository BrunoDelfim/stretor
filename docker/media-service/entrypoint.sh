#!/bin/sh
set -e

cd /app

# O DNS do container é reescrito aqui, e o motivo foi medido.
#
# O forwarder embutido do Docker (127.0.0.11) é um hop a mais no caminho da
# resolução, e foi ele que falhou nas rajadas observadas neste serviço: numa
# amostragem, a consulta direta aos mesmos servidores respondeu no instante em
# que o `getaddrinfo` pelo forwarder devolvia `EAI_AGAIN` — o erro que o FFmpeg
# reporta como `Failed to resolve hostname ... Try again`.
#
# Esta imagem é Alpine (musl), e o `getaddrinfo` do musl percorre a lista de
# servidores do `resolv.conf`. A lista abaixo põe os servidores externos primeiro
# — o caminho que respondeu durante o blip — e deixa o forwarder do Docker por
# último, como rede de segurança para os nomes internos do compose. Isso não
# custa espera: um resolvedor público responde `ENOTFOUND` para `redis` em
# milissegundos, então a queda para o 127.0.0.11 é imediata.
# `DNS_PRIMARIO` chega do compose (o mesmo valor que alimenta o `dns:` do
# serviço). O padrão existe para o caso de o container subir sem a variável: sem
# ele, o `if` abaixo não rodaria e a correção desapareceria em silêncio.
DNS_PRIMARIO="${DNS_PRIMARIO:-1.1.1.1}"
DNS_SECUNDARIO="${DNS_SECUNDARIO:-8.8.8.8}"

if [ -n "$DNS_PRIMARIO" ]; then
  {
    echo "nameserver $DNS_PRIMARIO"
    if [ -n "$DNS_SECUNDARIO" ]; then echo "nameserver $DNS_SECUNDARIO"; fi
    echo "nameserver 127.0.0.11"
    echo "options timeout:2 attempts:2 ndots:0"
  } > /etc/resolv.conf

  echo "[media-service] DNS: $DNS_PRIMARIO (fallback 127.0.0.11)"
fi

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
