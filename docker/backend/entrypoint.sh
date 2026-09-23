#!/bin/sh
set -e

cd /var/www/backend

echo "[backend] Iniciando bootstrap..."

# Garante que os diretórios de storage existam e sejam graváveis
mkdir -p storage/framework/{cache/data,sessions,views} storage/logs bootstrap/cache
chown -R stretor:stretor storage bootstrap/cache 2>/dev/null || true

# 1. .env
if [ ! -f .env ]; then
  echo "[backend] Criando .env a partir de .env.example"
  cp .env.example .env
fi

# 2. Dependências PHP (instaladas no build da imagem)
if [ ! -f vendor/autoload.php ]; then
  echo "[backend] ERRO: vendor/ não encontrado. Rode 'docker compose build backend'."
  exit 1
fi
# Garante que o usuário stretor possa escrever em vendor/ (evita reinstalações em loop)
chown -R stretor:stretor vendor 2>/dev/null || true

# 2b. NUNCA rodar composer install em runtime: dependências vêm do build.
#     Se o vendor estiver desatualizado, o correto é rebuildar a imagem.
if [ ! -f composer.lock ]; then
  echo "[backend] AVISO: composer.lock ausente. Rebuild a imagem para gerar um lock determinístico."
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
echo "[backend] Aguardando Postgres em ${DB_HOST:-postgres}:${DB_PORT:-5432}..."
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
# NÃO usar su-exec aqui: o master do php-fpm precisa rodar como root para
# escrever em /proc/self/fd/2 (stderr) e fazer o drop de privilégios por pool
# (user = stretor em /usr/local/etc/php-fpm.d/zz-stretor.conf).
exec "$@"
