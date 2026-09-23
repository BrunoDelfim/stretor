# Stretor

Plataforma de processamento de mídia (vídeo/áudio) com arquitetura de microsserviços.

## Arquitetura

```
┌─────────────┐      ┌──────────────┐      ┌──────────────────┐
│  Frontend   │─────▶│   Backend    │─────▶│  Media Service   │
│  Vue 3      │      │  Laravel 11  │      │  Node + FFmpeg   │
│  (Vite)     │      │  (API REST)  │      │                  │
└─────────────┘      └──────┬───────┘      └────────┬─────────┘
                            │                       │
                     ┌──────▼───────┐        ┌──────▼───────┐
                     │  PostgreSQL  │        │    Redis     │
                     │     16       │        │   (cache)    │
                     └──────────────┘        └──────────────┘
```

### Componentes

| Serviço         | Stack                          | Porta padrão |
|-----------------|--------------------------------|--------------|
| `frontend`      | Vue 3 + Vite + Pinia + Tailwind| 5173         |
| `backend`       | Laravel 11 (PHP 8.3-FPM)       | 9000 (FPM)   |
| `nginx`         | Nginx 1.27 Alpine              | 8000         |
| `media-service` | Node.js 20 + FFmpeg            | 3000         |
| `postgres`      | PostgreSQL 16                  | 5432         |
| `redis`         | Redis 7 Alpine                 | 6379         |

## Estrutura de Pastas

```
stretor/
├── backend/            # API Laravel 11
├── frontend/           # SPA Vue 3
├── media-service/      # Serviço Node.js + FFmpeg
├── docker/             # Dockerfiles e configs
│   ├── backend/
│   ├── frontend/
│   ├── media-service/
│   └── nginx/
├── docker-compose.yml
├── .env.example
└── README.md
```

## Dependências do Sistema

Para rodar **sem Docker** (desenvolvimento local):

- PHP 8.3+ com extensões: `pdo_pgsql`, `bcmath`, `gd`, `zip`, `intl`, `redis`
- Composer 2.x
- Node.js 20+ e npm
- FFmpeg 6+ (para o media-service)
- PostgreSQL 16
- Redis 7

Para rodar **com Docker** (recomendado):

- Docker Engine 24+
- Docker Compose v2

## Como subir o ambiente

### 1. Clonar e configurar variáveis

```bash
git clone <repo-url> stretor
cd stretor
cp .env.example .env
```

### 2. Subir os containers

```bash
docker compose up -d --build
```

### 3. Instalar dependências do backend

```bash
docker compose exec backend composer install
docker compose exec backend cp .env.example .env
docker compose exec backend php artisan key:generate
docker compose exec backend php artisan migrate
```

### 4. Acessar os serviços

- Frontend: http://localhost:5173
- API: http://localhost:8000/api/health
- Media Service: http://localhost:3000/health

### 5. Comandos úteis

```bash
# Ver logs
docker compose logs -f backend

# Parar tudo
docker compose down

# Parar e remover volumes (apaga dados)
docker compose down -v

# Rodar migrations
docker compose exec backend php artisan migrate

# Rodar queue worker
docker compose exec backend php artisan queue:work
```

## Endpoints iniciais

### Backend (Laravel)

- `GET /api/health` — health check da API
- `GET /up` — health check do framework

### Media Service (Node)

- `GET /health` — health check
- `POST /api/media/probe` — extrai metadados de um arquivo
- `POST /api/media/transcode` — transcodifica vídeo
- `POST /api/media/extract-audio` — extrai áudio de vídeo

## Licença

MIT
