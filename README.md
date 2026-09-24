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
| `frontend`      | Vue 3 + Vite + Pinia + Tailwind| 5173 (interna) |
| `backend`       | Laravel 11 (PHP 8.3-FPM)       | 9000 (FPM)   |
| `nginx`         | Nginx 1.27 Alpine              | **80**       |
| `media-service` | Node.js 20 + FFmpeg            | 3000         |
| `postgres`      | PostgreSQL 16                  | 5432         |
| `redis`         | Redis 7 Alpine                 | 6379         |

> **Acesso principal:** http://localhost (Nginx na porta 80 faz proxy para o
> frontend Vite e para a API Laravel).

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

O ambiente sobe inteiro com **um único comando**. A única configuração manual
permitida é o arquivo `.env` — todo o resto (dependências, chave da aplicação,
migrations e instalação de pacotes do frontend) acontece automaticamente nos
entrypoints dos containers.

### 1. Clonar e configurar variáveis

```bash
git clone <repo-url> stretor
cd stretor
cp .env.example .env
```

> **Obrigatório:** preencha `TMDB_API_KEY` no `.env` com a sua chave da API do
> [TMDB](https://www.themoviedb.org/settings/api). Sem ela a Home não carrega os
> filmes e exibe uma mensagem de erro amigável. A chave fica apenas no backend e
> nunca é exposta ao browser.

### 2. Subir os containers

```bash
docker compose up -d --build
```

Pronto. Nesta única etapa o sistema executa, de forma automática e idempotente:

| Etapa | Onde acontece |
|-------|---------------|
| Criação do `.env` raiz (se ausente) | serviço `init` → [`scripts/bootstrap.sh`](scripts/bootstrap.sh:1) |
| Criação do `.env` do backend | [`docker/backend/entrypoint.sh`](docker/backend/entrypoint.sh:1) |
| Espelhamento de `TMDB_API_KEY` e demais chaves no `.env` do backend | [`docker/backend/entrypoint.sh`](docker/backend/entrypoint.sh:1) |
| `composer install` (se `vendor/` ausente) | [`docker/backend/entrypoint.sh`](docker/backend/entrypoint.sh:1) |
| Geração da `APP_KEY` | [`docker/backend/entrypoint.sh`](docker/backend/entrypoint.sh:1) |
| `php artisan migrate` | [`docker/backend/entrypoint.sh`](docker/backend/entrypoint.sh:1) |
| `npm install` do frontend | [`docker/frontend/entrypoint.sh`](docker/frontend/entrypoint.sh:1) |
| `npm install` do media-service | [`docker/media-service/entrypoint.sh`](docker/media-service/entrypoint.sh:1) |

> As etapas são idempotentes: subir novamente não reinstala nem reexecuta o que
> já está pronto. Para forçar tudo do zero, use `make fresh`.

### 3. Acessar os serviços

Tudo é servido pelo Nginx na **porta 80**:

- Aplicação (frontend + API): http://localhost
- API health check: http://localhost/api/health
- Media Service (via proxy): http://localhost/media/health
- Media Service (direto): http://localhost:3000/health

### 4. Comandos úteis

```bash
# Ver logs
docker compose logs -f backend

# Parar tudo
docker compose down

# Parar e remover volumes (apaga dados)
docker compose down -v

# Recomeçar do zero (remove volumes e refaz o bootstrap)
make fresh

# Rodar migrations manualmente (normalmente desnecessário: é automático)
docker compose exec backend php artisan migrate

# Rodar queue worker
docker compose exec backend php artisan queue:work
```

> As migrations rodam sozinhas na subida do backend. O comando manual existe
> apenas para casos pontuais de diagnóstico.

## Endpoints iniciais

### Backend (Laravel)

- `GET /api/health` — health check da API
- `GET /up` — health check do framework
- `GET /api/v1/movies/popular` — filmes mais assistidos no Brasil (Home)
- `GET /api/v1/movies/search?query=...` — busca por título (navbar)
- `GET /api/v1/movies/{id}` — detalhes do filme (modal)

> As respostas do TMDB são cacheadas no Redis (`TMDB_CACHE_TTL`, padrão 3600s)
> para respeitar o rate limit da API e acelerar a Home.

O endpoint de detalhes enriquece o payload com `duracao` (ex.: `2h 19min`),
`elenco` (5 principais atores) e `trailer` (chave do YouTube), usados pelo modal
para exibir o botão "Assistir", o trailer sob demanda e os créditos.

### Media Service (Node)

- `GET /health` — health check
- `POST /api/media/probe` — extrai metadados de um arquivo
- `POST /api/media/transcode` — transcodifica vídeo
- `POST /api/media/extract-audio` — extrai áudio de vídeo

## Fluxo de Branches

O projeto adota uma estratégia simples de três branches para separar o que está
estável do que ainda está em construção:

| Branch        | Papel                                                             |
|---------------|-------------------------------------------------------------------|
| `main`        | Versão estável. Só recebe merge quando o projeto está pronto.     |
| `development` | Linha de trabalho ativa. Toda feature nasce e evolui aqui.        |
| `fix`         | Correções de bugs, mescladas de volta em `development`.           |

O dia a dia acontece na `development`. Ao concluir uma entrega relevante, o
código é mesclado na `main`. Correções pontuais saem da `development` para a
`fix` e retornam por merge, mantendo o histórico coeso.

```bash
# Trabalho diário
git checkout development

# Correção de bug
git checkout -b fix/nome-do-ajuste development
# ... commit ...
git checkout development && git merge fix/nome-do-ajuste

# Entrega estável
git checkout main && git merge development
```

## Licença

MIT
