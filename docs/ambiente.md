# Ambiente

Como o projeto sobe, o que é automático e quais variáveis precisam de atenção.

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
>
> **Para fontes dubladas:** preencha `TORRENTS_TORZNAB_KEY` com a chave da API do
> Prowlarr (veja abaixo). Sem ela o sistema cai para o YTS, cujo catálogo é quase
> todo em inglês.

### 2. Subir os containers

```bash
docker compose up -d --build
```

Pronto. Nesta única etapa o sistema executa, de forma automática e idempotente:

| Etapa | Onde acontece |
|-------|---------------|
| Criação do `.env` raiz (se ausente) | serviço `init` → [`scripts/bootstrap.sh`](../scripts/bootstrap.sh:1) |
| Criação do `.env` do backend | [`docker/backend/entrypoint.sh`](../docker/backend/entrypoint.sh:1) |
| Espelhamento de `TMDB_API_KEY` e demais chaves no `.env` do backend | [`docker/backend/entrypoint.sh`](../docker/backend/entrypoint.sh:1) |
| `composer install` (se `vendor/` ausente) | [`docker/backend/entrypoint.sh`](../docker/backend/entrypoint.sh:1) |
| Geração da `APP_KEY` | [`docker/backend/entrypoint.sh`](../docker/backend/entrypoint.sh:1) |
| `php artisan migrate` | [`docker/backend/entrypoint.sh`](../docker/backend/entrypoint.sh:1) |
| `npm install` do frontend | [`docker/frontend/entrypoint.sh`](../docker/frontend/entrypoint.sh:1) |
| `npm install` do media-service | [`docker/media-service/entrypoint.sh`](../docker/media-service/entrypoint.sh:1) |

> As etapas são idempotentes: subir novamente não reinstala nem reexecuta o que
> já está pronto. Para forçar tudo do zero, use `make fresh`.

### 3. Configurar o indexador de torrents (Prowlarr)

O Prowlarr sobe junto com a stack e é o que dá acesso às fontes dubladas em
PT-BR. **Não há nada para configurar à mão**: na primeira subida o backend roda
`php artisan prowlarr:provisionar`
([`ProwlarrService.php`](../backend/app/Services/ProwlarrService.php:1)), que

1. lê a chave da API direto do `config.xml` que o Prowlarr grava no volume
   compartilhado (`prowlarr_config`) — você não copia nem cola chave;
2. espera o Prowlarr responder (na primeira subida ele gasta alguns segundos
   criando o banco interno antes de aceitar requisições);
3. cadastra os indexadores públicos PT-BR versionados no projeto, sem duplicar
   o que já existe.

A chave descoberta é gravada em `TORRENTS_TORZNAB_KEY` no `.env` do backend e
exportada para o php-fpm, destravando o degrau 2 da busca. Para refazer o
provisionamento a qualquer momento, rode `make prowlarr`.

> Se o provisionamento falhar (Prowlarr fora do ar, volume recém-apagado), a
> subida **não** é interrompida: a busca simplesmente cai para o degrau 1
> (nativa) e, em seguida, para o degrau 3 (YTS).

#### Definição customizada de indexador público PT-BR

O Prowlarr só traz, de fábrica, trackers brasileiros **privados** (que exigem
conta e convite). Para um indexador público, o projeto versiona uma definição
própria em [`docker/prowlarr/Definitions/Custom/`](../docker/prowlarr/Definitions/Custom/torrentdosfilmes.yml:1),
montada em `/config/Definitions/Custom/` dentro do container pelo
[`docker-compose.yml`](../docker-compose.yml:204). Assim a definição sobrevive a
recriações do container e é versionada junto com o código.

> **Domínio instável**: os trackers públicos brasileiros trocam de endereço com
> frequência (bloqueio judicial, expiração de domínio, sequestro por sites de
> aposta). Se o Prowlarr passar a devolver `Name does not resolve` ou
> `Unable to connect`, atualize a lista `links` do arquivo `.yml` com o endereço
> atual do site. O restante da definição (seletores, categorias, filtros)
> continua válido.

### 4. Acessar os serviços

Tudo é servido pelo Nginx na **porta 80**:

- Aplicação (frontend + API): http://localhost
- API health check: http://localhost/api/health
- Media Service (via proxy): http://localhost/media/health
- Media Service (direto): http://localhost:3000/health
- Painel do Prowlarr: http://localhost:9696

## Variáveis de ambiente

O `.env.example` é um espelho fiel do `.env`: contém todas as chaves, mas sem os
valores reais. As variáveis que exigem atenção:

| Variável | Obrigatória | Descrição |
|----------|-------------|-----------|
| `TMDB_API_KEY` | **Sim** | Chave da API do TMDB. Sem ela a Home não carrega filmes. |
| `TMDB_CACHE_TTL` | Não | Tempo de cache das respostas do TMDB no Redis (padrão `3600`s). |
| `TMDB_MAX_PAGES` | Não | Teto de páginas da rolagem infinita (padrão `25`, ~500 filmes). |
| `TORRENTS_TORZNAB_KEY` | Não | Chave da API do Prowlarr. É **preenchida automaticamente** na subida; só defina para apontar a um Prowlarr externo. |
| `TORRENTS_TORZNAB_URL` | Não | URL interna do indexador (padrão `http://prowlarr:9696`). |
| `TORRENTS_TORZNAB_CATEGORIA` | Não | Categoria Torznab de filmes (padrão `2000`). |
| `TORRENTS_BASE_URL` | Não | Provedor de reserva (YTS), usado quando o indexador não está configurado. |
| `TORRENTS_CACHE_TTL` | Não | Tempo de cache da busca de fontes (padrão `1800`s). |
| `PROWLARR_PORT` | Não | Porta do painel do Prowlarr (padrão `9696`). |
| `PROWLARR_URL` | Não | Endereço interno do Prowlarr usado no provisionamento (padrão `http://prowlarr:9696`). |
| `PROWLARR_CONFIG_PATH` | Não | Caminho do `config.xml` dentro do backend (padrão `/prowlarr-config/config.xml`). Vazio desativa o provisionamento. |
| `PROWLARR_TEMPO_LIMITE` | Não | Tempo limite, em segundos, das chamadas de provisionamento (padrão `20`). |

## A sentinela `.bootstrap-done`

Na raiz do projeto existe um arquivo sentinela `.bootstrap-done`. Ele marca que o
bootstrap já foi executado com sucesso, permitindo que subidas seguintes usem
`make up-fast` (sem rebuild e sem reexecutar o init). Para forçar o bootstrap de
novo, use `make reset-init` ou `make fresh`.

## Próximos passos

- Comandos do dia a dia: [Comandos](comandos.md)
- Detalhes dos serviços: [Arquitetura](arquitetura.md)
