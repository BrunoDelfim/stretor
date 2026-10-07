#!/bin/sh
# Entrypoint do backend Laravel (php-fpm).
# Objetivo: preparar o ambiente de forma idempotente e NUNCA derrubar o
# container por falhas recuperáveis (ex.: APP_KEY já existente, migration
# sem alterações). Erros fatais de infraestrutura (vendor ausente) ainda
# abortam, pois indicam build incorreto.
set -e

cd /var/www/backend

echo "[backend] Iniciando bootstrap..."

# ---------------------------------------------------------------------------
# 0. DNS
# ---------------------------------------------------------------------------
# O `resolv.conf` que o Docker gera aponta só para o forwarder embutido dele
# (127.0.0.11) — um hop a mais no caminho da resolução, e foi ele que falhou nas
# rajadas medidas neste stack: o cliente HTTP do backend devolvia
# `Resolving timed out after 3000 milliseconds` para o agregador enquanto a
# consulta direta aos mesmos servidores respondia no mesmo instante. Sem uma
# segunda opção na lista, cada rajada era uma busca perdida.
#
# Aqui o forwarder continua **primeiro** de propósito: é ele quem resolve os
# nomes internos do compose (`postgres`, `redis`, `prowlarr`, `searxng`) sem ida
# à internet, e essa é a resolução que o backend usa a cada requisição. Os
# servidores externos entram como reserva, e o `timeout:1 attempts:2` é o que
# torna a reserva barata: uma consulta externa que o forwarder não responde cai
# para o Cloudflare em cerca de um segundo, em vez de gastar os cinco rounds do
# ajuste padrão. No media-service a ordem se inverte — ver
# docker/media-service/entrypoint.sh —, porque aquele container fala só com a
# internet e a origem do arquivo é outra.
DNS_PRIMARIO="${DNS_PRIMARIO:-1.1.1.1}"
DNS_SECUNDARIO="${DNS_SECUNDARIO:-8.8.8.8}"

{
  echo "nameserver 127.0.0.11"
  echo "nameserver $DNS_PRIMARIO"
  if [ -n "$DNS_SECUNDARIO" ]; then echo "nameserver $DNS_SECUNDARIO"; fi
  echo "options timeout:1 attempts:2 ndots:0"
} > /etc/resolv.conf

echo "[backend] DNS: 127.0.0.11 (interno) + $DNS_PRIMARIO (reserva)"

# ---------------------------------------------------------------------------
# 0. Diretórios de storage/cache
# ---------------------------------------------------------------------------
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chown -R stretor:stretor storage bootstrap/cache 2>/dev/null || true

# Diretório de logs do php-fpm (o volume pode sobrescrever permissões do build)
mkdir -p /var/log/php-fpm
chown -R stretor:stretor /var/log/php-fpm 2>/dev/null || true
chmod -R 775 /var/log/php-fpm 2>/dev/null || true

# ---------------------------------------------------------------------------
# 1. .env
# ---------------------------------------------------------------------------
if [ ! -f .env ]; then
  echo "[backend] Criando .env a partir de .env.example"
  cp .env.example .env
fi

# ---------------------------------------------------------------------------
# 1.1. Sincroniza variáveis sensíveis vindas do ambiente do container
# ---------------------------------------------------------------------------
# O .env do backend nasce do .env.example (sem segredos). As chaves reais são
# injetadas pelo docker-compose como variáveis de ambiente. O Laravel lê o
# ambiente com prioridade sobre o .env, mas deixar o arquivo desatualizado
# confunde quem inspeciona o container e quebra ferramentas que leem o .env
# diretamente (ex.: artisan tinker). Aqui espelhamos essas chaves no arquivo.
sync_env_var() {
  key="$1"
  value="$2"
  [ -z "$value" ] && return 0

  if grep -q "^${key}=" .env; then
    # Substitui a linha preservando o restante do arquivo.
    escaped=$(printf '%s' "$value" | sed 's/[&|]/\\&/g')
    sed -i "s|^${key}=.*|${key}=${escaped}|" .env
  else
    printf '\n%s=%s\n' "$key" "$value" >> .env
  fi
}

sync_env_var "TMDB_API_KEY" "${TMDB_API_KEY:-}"
sync_env_var "TMDB_LANGUAGE" "${TMDB_LANGUAGE:-}"
sync_env_var "TMDB_REGION" "${TMDB_REGION:-}"
sync_env_var "TMDB_MAX_PAGES" "${TMDB_MAX_PAGES:-}"
sync_env_var "MEDIA_SERVICE_URL" "${MEDIA_SERVICE_URL:-}"
sync_env_var "TORRENTS_TORZNAB_URL" "${TORRENTS_TORZNAB_URL:-}"
sync_env_var "TORRENTS_TORZNAB_KEY" "${TORRENTS_TORZNAB_KEY:-}"
sync_env_var "TORRENTS_TORZNAB_CATEGORIA" "${TORRENTS_TORZNAB_CATEGORIA:-}"
sync_env_var "TORRENTS_TORZNAB_CATEGORIA_SERIE" "${TORRENTS_TORZNAB_CATEGORIA_SERIE:-}"
sync_env_var "TORRENTS_TORZNAB_ORCAMENTO" "${TORRENTS_TORZNAB_ORCAMENTO:-}"
sync_env_var "TORRENTS_BASE_URL" "${TORRENTS_BASE_URL:-}"
sync_env_var "TORRENTS_CACHE_TTL" "${TORRENTS_CACHE_TTL:-}"
sync_env_var "TORRENTS_CACHE_BYPASS" "${TORRENTS_CACHE_BYPASS:-}"
sync_env_var "TORRENTS_TORRENTIO_IDIOMAS" "${TORRENTS_TORRENTIO_IDIOMAS:-}"
sync_env_var "TORRENTS_TORRENTIO_BUSCA_AMPLA" "${TORRENTS_TORRENTIO_BUSCA_AMPLA:-}"
sync_env_var "TORRENTS_APENAS_PT_BR" "${TORRENTS_APENAS_PT_BR:-}"
sync_env_var "TORRENTS_PACKS_HABILITADOS" "${TORRENTS_PACKS_HABILITADOS:-}"
sync_env_var "TORRENTS_PACKS_QUALQUER_IDIOMA" "${TORRENTS_PACKS_QUALQUER_IDIOMA:-}"
sync_env_var "TORRENTS_LEGENDAS_FALLBACK" "${TORRENTS_LEGENDAS_FALLBACK:-}"
sync_env_var "TORRENTS_TERMOS_SERIE_HABILITADO" "${TORRENTS_TERMOS_SERIE_HABILITADO:-}"
sync_env_var "TORRENTS_KNABEN_HABILITADO" "${TORRENTS_KNABEN_HABILITADO:-}"
sync_env_var "TORRENTS_KNABEN_URL" "${TORRENTS_KNABEN_URL:-}"
sync_env_var "TORRENTS_KNABEN_LIMITE" "${TORRENTS_KNABEN_LIMITE:-}"
sync_env_var "TORRENTS_STREMIO_ADDONS" "${TORRENTS_STREMIO_ADDONS:-}"
sync_env_var "TORRENTS_MINIMO_FONTES" "${TORRENTS_MINIMO_FONTES:-}"
sync_env_var "TORRENTS_META_PT_BR" "${TORRENTS_META_PT_BR:-}"
sync_env_var "TORRENTS_COBERTURA_COMPLETA" "${TORRENTS_COBERTURA_COMPLETA:-}"
sync_env_var "TORRENTS_FONTES_SUFICIENTES_POR_PROVEDOR" "${TORRENTS_FONTES_SUFICIENTES_POR_PROVEDOR:-}"
sync_env_var "TORRENTS_INSPECAO_PACKS_LIMITE" "${TORRENTS_INSPECAO_PACKS_LIMITE:-}"
sync_env_var "TORRENTS_INSPECAO_TIMEOUT" "${TORRENTS_INSPECAO_TIMEOUT:-}"
sync_env_var "TORRENTS_INSPECAO_CACHE_TTL" "${TORRENTS_INSPECAO_CACHE_TTL:-}"
sync_env_var "TORRENTS_PASSE_CLOUDFLARE_HOSTS" "${TORRENTS_PASSE_CLOUDFLARE_HOSTS:-}"
sync_env_var "TORRENTS_PASSE_CLOUDFLARE_CLEARANCE" "${TORRENTS_PASSE_CLOUDFLARE_CLEARANCE:-}"
sync_env_var "TORRENTS_PASSE_CLOUDFLARE_TOKEN" "${TORRENTS_PASSE_CLOUDFLARE_TOKEN:-}"
sync_env_var "TORRENTS_PASSE_CLOUDFLARE_AGENTE" "${TORRENTS_PASSE_CLOUDFLARE_AGENTE:-}"
sync_env_var "PROWLARR_URL" "${PROWLARR_URL:-}"
sync_env_var "PROWLARR_CONFIG_PATH" "${PROWLARR_CONFIG_PATH:-}"
sync_env_var "PROWLARR_TEMPO_LIMITE" "${PROWLARR_TEMPO_LIMITE:-}"
sync_env_var "PROWLARR_INDEXADORES" "${PROWLARR_INDEXADORES:-}"
sync_env_var "PROWLARR_PROXY_INDEXADORES" "${PROWLARR_PROXY_INDEXADORES:-}"
sync_env_var "FLARESOLVERR_URL" "${FLARESOLVERR_URL:-}"
sync_env_var "PROWLARR_PROXY_ATIVO" "${PROWLARR_PROXY_ATIVO:-}"

# ---------------------------------------------------------------------------
# 2. Dependências PHP
# ---------------------------------------------------------------------------
# Normalmente o vendor/ já vem do build da imagem. Se o volume sobrescreveu o
# diretório ou o build foi feito sem as dependências, instalamos aqui para que
# um simples 'docker compose up' deixe o ambiente pronto, sem passos manuais.
if [ ! -f vendor/autoload.php ]; then
  echo "[backend] vendor/ ausente. Instalando dependências com composer..."
  if [ -f composer.lock ]; then
    composer install --no-interaction --no-scripts --no-progress --prefer-dist --optimize-autoloader
  else
    composer update --no-interaction --no-scripts --no-progress --prefer-dist --optimize-autoloader
  fi
fi
chown -R stretor:stretor vendor 2>/dev/null || true

if [ ! -f composer.lock ]; then
  echo "[backend] AVISO: composer.lock ausente. Rebuild a imagem para gerar um lock determinístico."
fi

# ---------------------------------------------------------------------------
# 3. APP_KEY (só gera se ainda não existir)
# ---------------------------------------------------------------------------
if ! grep -q "^APP_KEY=base64:" .env; then
  echo "[backend] Gerando APP_KEY..."
  if ! php artisan key:generate --force; then
    echo "[backend] AVISO: falha ao gerar APP_KEY. Continuando (pode ser erro de config)."
  fi
else
  echo "[backend] APP_KEY já configurada, pulando."
fi

# ---------------------------------------------------------------------------
# 4. Permissões
# ---------------------------------------------------------------------------
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache || true

# ---------------------------------------------------------------------------
# 5. Espera o Postgres
# ---------------------------------------------------------------------------
echo "[backend] Aguardando Postgres em ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
ATTEMPTS=0
until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'postgres', (int)(getenv('DB_PORT') ?: 5432)) ? 0 : 1);"; do
  ATTEMPTS=$((ATTEMPTS + 1))
  if [ "$ATTEMPTS" -ge 60 ]; then
    echo "[backend] ERRO FATAL: Postgres não respondeu após 120s."
    exit 1
  fi
  sleep 2
done
echo "[backend] Postgres disponível."

# ---------------------------------------------------------------------------
# 5.1. Prowlarr: configuração automática (degrau 2 da busca)
# ---------------------------------------------------------------------------
# O Prowlarr sobe junto com o stack, mas nasce sem chave conhecida pelo backend
# e sem nenhum indexador cadastrado. O comando abaixo lê a chave da API direto
# do config.xml do volume compartilhado e cadastra os indexadores públicos
# PT-BR. Se TORRENTS_TORZNAB_KEY já vier preenchida (Prowlarr externo), não
# mexemos em nada. Qualquer falha aqui é deliberadamente ignorada: sem o
# Prowlarr, o backend continua servindo a busca nativa e o YTS.
if [ -z "${TORRENTS_TORZNAB_KEY:-}" ]; then
  echo "[backend] Provisionando o Prowlarr (pode levar alguns segundos)..."
  PROWLARR_SAIDA="$(php artisan prowlarr:provisionar 2>&1 || true)"
  printf '%s\n' "$PROWLARR_SAIDA" | grep '^\[prowlarr\]' || true
  PROWLARR_CHAVE="$(printf '%s\n' "$PROWLARR_SAIDA" | sed -n 's/^PROWLARR_API_KEY=//p' | tail -n1)"
  if [ -n "$PROWLARR_CHAVE" ]; then
    sync_env_var "TORRENTS_TORZNAB_KEY" "$PROWLARR_CHAVE"
    export TORRENTS_TORZNAB_KEY="$PROWLARR_CHAVE"
  else
    echo "[backend] AVISO: chave do Prowlarr não obtida. A busca seguirá sem o degrau 2."
  fi
fi

# ---------------------------------------------------------------------------
# 6. Migrations (idempotente, com sentinela para pular quando nada mudou)
# ---------------------------------------------------------------------------
MIGRATION_SENTINEL="storage/framework/.migrations-done"
if [ -f "$MIGRATION_SENTINEL" ] && [ -z "$(find database/migrations -newer "$MIGRATION_SENTINEL" -name '*.php' 2>/dev/null)" ]; then
  echo "[backend] Nenhuma migration nova desde a última execução, pulando."
else
  echo "[backend] Rodando migrations..."
  if php artisan migrate --force --no-interaction; then
    touch "$MIGRATION_SENTINEL"
  else
    echo "[backend] AVISO: migrations falharam. O container continuará para permitir diagnóstico."
  fi
fi

# ---------------------------------------------------------------------------
# 7. Limpa caches de config/rotas (evita cache obsoleto entre deploys)
# ---------------------------------------------------------------------------
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true
php artisan view:clear >/dev/null 2>&1 || true

echo "[backend] Bootstrap concluído. Iniciando php-fpm..."
# O master do php-fpm roda como root para fazer o drop de privilégios por pool
# (user = stretor em /usr/local/etc/php-fpm.d/zz-stretor.conf).
exec "$@"
