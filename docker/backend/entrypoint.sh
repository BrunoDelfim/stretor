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
# 2. Dependências PHP (instaladas no build da imagem)
# ---------------------------------------------------------------------------
if [ ! -f vendor/autoload.php ]; then
  echo "[backend] ERRO FATAL: vendor/ não encontrado. Rode 'docker compose build backend'."
  exit 1
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
