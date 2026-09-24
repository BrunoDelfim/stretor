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

## Como subir

O ambiente sobe inteiro com **um único comando**. A única configuração manual
permitida é o arquivo `.env`.

```bash
git clone <repo-url> stretor
cd stretor
cp .env.example .env
# Preencha TMDB_API_KEY no .env (obrigatório)
docker compose up -d --build
```

Pronto. Dependências, chave da aplicação, migrations e instalação de pacotes
acontecem automaticamente nos entrypoints dos containers. Acesse
http://localhost.

> **Obrigatório:** preencha `TMDB_API_KEY` no `.env` com a sua chave da API do
> [TMDB](https://www.themoviedb.org/settings/api). Sem ela a Home não carrega os
> filmes. A chave fica apenas no backend e nunca é exposta ao browser.

## Comandos essenciais

```bash
make up        # Sobe tudo com rebuild
make up-fast   # Sobe sem rebuild (usa a sentinela .bootstrap-done)
make down      # Para tudo
make fresh     # Recomeça do zero (apaga volumes)
make logs      # Acompanha os logs
```

A lista completa está em [Comandos](docs/comandos.md).

## Documentação

O detalhamento do projeto vive no manual em [`docs/`](docs/README.md):

| Página | O que você encontra |
|--------|---------------------|
| [Arquitetura](docs/arquitetura.md) | Componentes, portas, fluxo entre serviços e estrutura de pastas. |
| [Ambiente](docs/ambiente.md) | Variáveis de `.env`, bootstrap automático e como subir tudo. |
| [Comandos](docs/comandos.md) | Alvos do `Makefile`, `docker compose`, `artisan` e `npm`. |
| [API](docs/api.md) | Endpoints do backend e do media-service, paginação e cache. |
| [Frontend](docs/frontend.md) | Organização Vue, rolagem infinita, skeletons e componentes. |
| [Integrações](docs/integracoes.md) | TMDB, cache Redis, torrents/legendas e Real-Debrid. |
| [Fluxo de Branches](docs/fluxo-branches.md) | Estratégia de branches e commits. |

> A pasta [`plans/`](plans/) guarda o **registro histórico** das decisões de
> implementação (o "porquê" de cada entrega). O manual em `docs/` descreve como o
> sistema funciona **hoje**.

## Licença

MIT
