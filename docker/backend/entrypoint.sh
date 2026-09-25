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
sync_env_var "TORRENTS_BASE_URL" "${TORRENTS_BASE_URL:-}"
sync_env_var "TORRENTS_CACHE_TTL" "${TORRENTS_CACHE_TTL:-}"

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
