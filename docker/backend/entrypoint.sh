#!/bin/sh
set -e

cd /var/www/backend

echo "[backend] Iniciando bootstrap..."

# 1. .env
if [ ! -f .env ]; then
  echo "[backend] Criando .env a partir de .env.example"
  cp .env.example .env
fi

# 2. Dependências PHP
if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
  echo "[backend] Instalando dependências (composer install)..."
  composer install --no-interaction --prefer-dist --optimize-autoloader
else
  echo "[backend] vendor/ já existe, pulando composer install"
fi

# 3. APP_KEY
if ! grep -q "^APP_KEY=base64:" .env; then
  echo "[backend] Gerando APP_KEY..."
  php artisan key:generate --force
fi

# 4. Permissões
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache || true

# 5. Espera o Postgres
echo "[backend] Aguardando Postgres..."
until php -r "exit(@fsockopen(getenv('DB_HOST') ?: 'postgres', (int)(getenv('DB_PORT') ?: 5432)) ? 0 : 1);"; do
  sleep 2
done
echo "[backend] Postgres disponível."

# 6. Migrations (idempotente)
echo "[backend] Rodando migrations..."
php artisan migrate --force --no-interaction

# 7. Cache de config/rotas (opcional em dev, mas seguro)
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear >/dev/null 2>&1 || true

echo "[backend] Bootstrap concluído. Iniciando php-fpm..."
exec "$@"
