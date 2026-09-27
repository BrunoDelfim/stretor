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

> **DNS dos containers:** o `docker-compose.yml` já fixa Cloudflare (`1.1.1.1`) e
> Google (`8.8.8.8`) como resolvers do backend, do Prowlarr e do FlareSolverr
> ([`docker-compose.yml`](../docker-compose.yml:257)). Isso não é detalhe: quem
> resolve o domínio do tracker é o Chromium embutido no FlareSolverr
> ([`docker-compose.yml`](../docker-compose.yml:248)), e o resolver padrão do
> host, herdado da operadora, costuma bloquear ou devolver NXDOMAIN — o indexador
> aparece como "fora do ar" no painel sem que haja nada errado com ele. O
> FlareSolverr ainda recebe `DNS_OVER_HTTPS=true`, que resolve por fora do UDP/53
> do provedor. Se precisar trocar, ajuste `DNS_PRIMARIO`/`DNS_SECUNDARIO` no
> `.env` e recrie os containers (`docker compose up -d --force-recreate backend
> prowlarr flaresolverr`), porque a diretiva `dns` só entra no container na
> criação dele.

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
3. cadastra os indexadores públicos versionados no projeto — o `1337x`, o
   `thepiratebay` e o `torrentgalaxy`, definidos em `PROWLARR_INDEXADORES` —
   sem duplicar o que já existe.

A chave descoberta é gravada em `TORRENTS_TORZNAB_KEY` no `.env` do backend e
exportada para o php-fpm, destravando o degrau 2 da busca. Para refazer o
provisionamento a qualquer momento, rode `make prowlarr`.

> Se o provisionamento falhar (Prowlarr fora do ar, volume recém-apagado), a
> subida **não** é interrompida: a busca simplesmente cai para o degrau 1
> (nativa) e, em seguida, para o degrau 3 (YTS).

> **Tags no painel do Prowlarr:** a tag criada pelo projeto (`flaresolverr`) não é
> enfeite — é o **vínculo com o proxy**. O Prowlarr só encaminha pelo FlareSolverr
> os indexadores que carregam essa tag, então quem quiser marcar o 1337x ou o
> TorrentGalaxy com uma tag própria deve **somá-la** à que já existe, nunca
> substituí-la: trocada, o `blocked by CloudFlare Protection` volta na hora. As
> tags do Prowlarr também
> **não filtram idioma** — "só dublado" é decisão do backend
> (`TORRENTS_APENAS_PT_BR`). O provisionamento mescla as tags do indexador e
> nunca apaga as suas.

#### Definição customizada de indexador público PT-BR (arquivada)

O Prowlarr só traz, de fábrica, trackers brasileiros **privados** (que exigem
conta e convite). Para um indexador público, o projeto versiona uma definição
própria em [`docker/prowlarr/Definitions/Custom/`](../docker/prowlarr/Definitions/Custom/torrentdosfilmes.yml:1),
montada em `/config/Definitions/Custom/` dentro do container pelo
[`docker-compose.yml`](../docker-compose.yml:204). Assim a definição sobrevive a
recriações do container e é versionada junto com o código.

Essa definição está **fora do provisionamento**: o domínio do tracker foi
sequestrado e o endereço hoje serve um site de apostas. Os indexadores
cadastrados automaticamente (`1337x`, `thepiratebay` e `torrentgalaxy`) são
definições oficiais do Prowlarr. Ter o `.yml` na pasta apenas o deixa disponível
no painel — quem decide o que é cadastrado é a lista `indexadores` de
[`config/services.php`](../backend/config/services.php:176). Quando o site
voltar, inclua o id `torrentdosfilmes` nessa lista.

> **Domínio instável**: os trackers públicos brasileiros trocam de endereço com
> frequência (bloqueio judicial, expiração de domínio, sequestro por sites de
> aposta). Se o Prowlarr devolver `Name does not resolve` ou `Unable to connect`,
> atualize a lista `links` do arquivo `.yml` com o endereço atual do site. O
> restante da definição (seletores, categorias, filtros) continua válido.

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
| `TORRENTS_TORZNAB_CATEGORIA_SERIE` | Não | Categoria Torznab de séries (padrão `5000`). Separada da de filmes porque o Prowlarr filtra por categoria. |
| `TORRENTS_APENAS_PT_BR` | Não | Quando o PT-BR sozinho alcança `TORRENTS_MINIMO_FONTES`, a lista final é só a faixa PT-BR — dublado, dual áudio e packs que atravessam o corte. Se não alcança, a reserva (original/legendado) completa a lista (padrão `true`). |
| `TORRENTS_PACKS_HABILITADOS` | Não | Busca packs de temporada (`S01 completa`, `Temporada 1 completa`) como **último** termo do episódio. É o que destrava séries antigas, cujo episódio isolado não tem mais seed; o media-service baixa só o arquivo do episódio pedido de dentro do pacote (padrão `true`). |
| `TORRENTS_PACKS_QUALQUER_IDIOMA` | Não | Isenta **apenas** a fonte marcada como pack do corte de `TORRENTS_APENAS_PT_BR`. O pack de série antiga quase nunca vem marcado como dublado; sem a exceção ele é achado e descartado, e a lista volta vazia. Episódios e filmes seguem o corte normal, e o pack entra atrás do dublado (padrão `true`). |
| `TORRENTS_TERMOS_SERIE_HABILITADO` | Não | Acrescenta termos de série sem numeração de episódio e sem "completa" (`"... dublado"`, `"... temporada N"`, `"... SN"`) **depois** dos termos de pack. É o que faz o buscador casar o nome do pacote multi-temporada das séries antigas. As fontes vindas deles passam pelo gate de temporada: quem não declara a temporada pedida é descartado (padrão `true`). |
| `TORRENTS_MINIMO_FONTES` | Não | Piso de fontes PT-BR que dispensa a reserva. Se as dubladas/duais (e packs) sozinhas alcançam esse piso, a lista é só elas; se não, a reserva completa até o teto de 20 (padrão `15`). |
| `TORRENTS_META_PT_BR` | Não | Orçamento de fontes PT-BR que a cascata tenta juntar antes de encerrar a coleta. Atingido o alvo, os degraus seguintes não são consultados (padrão `6`). |
| `TORRENTS_INSPECAO_PACKS_LIMITE` | Não | Quantos packs, no máximo, têm o conteúdo inspecionado por busca quando o nome não prova PT-BR (padrão `6`). |
| `TORRENTS_INSPECAO_TIMEOUT` | Não | Tempo máximo, em segundos, esperando os metadados de cada pack inspecionado (padrão `12`). |
| `TORRENTS_INSPECAO_CACHE_TTL` | Não | Tempo de cache do veredito da inspeção por infohash, em segundos. Só respostas definitivas são guardadas (padrão `86400`). |
| `TORRENTS_KNABEN_HABILITADO` | Não | Liga/desliga o Knaben, meta-buscador de indexadores públicos que devolve os packs nacionais das séries antigas (padrão `true`). |
| `TORRENTS_KNABEN_URL` | Não | Endpoint da API do Knaben (padrão `https://api.knaben.org/v1`). Use o domínio `.org`: o `.eu` responde `503`. Vazio desliga o provedor. |
| `TORRENTS_KNABEN_LIMITE` | Não | Quantos resultados pedir por termo ao Knaben (padrão `20`). |
| `TORRENTS_STREMIO_ADDONS` | Não | Addons Stremio hospedados, consultados por `imdb_id`, separados por vírgula (padrão `https://thepiratebay-plus.strem.fun`). O Torrentio tem provedor próprio e não precisa entrar aqui. |
| `TORRENTS_BASE_URL` | Não | Provedor de reserva (YTS), usado quando o indexador não está configurado. |
| `TORRENTS_CACHE_TTL` | Não | Tempo de cache da busca de fontes (padrão `1800`s). |
| `DNS_PRIMARIO` / `DNS_SECUNDARIO` | Não | Resolvers dos containers `backend`, `prowlarr` e `flaresolverr` (padrão `1.1.1.1` / `8.8.8.8`). Evitam o bloqueio de DNS da operadora sobre domínios de tracker. |
| `FLARESOLVERR_URL` | Não | Endereço interno do FlareSolverr (padrão `http://flaresolverr:8191`). O backend o cadastra como proxy no Prowlarr e liga o 1337x a ele por tag. |
| `FLARESOLVERR_DNS_OVER_HTTPS` | Não | Faz o Chromium do FlareSolverr resolver por DoH, fora do UDP/53 do provedor (padrão `true`). É o caminho que resta quando o resolver da operadora bloqueia o tracker. |
| `FLARESOLVERR_LOG_LEVEL` | Não | Nível de log do serviço `flaresolverr` (padrão `info`). Use `debug` para investigar os desafios do CloudFlare. |
| `PROWLARR_PROXY_ATIVO` | Não | Liga o cadastro do proxy no Prowlarr e a associação do 1337x por tag (padrão `true`). Em `false`, o 1337x volta a ficar inativo. |
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
