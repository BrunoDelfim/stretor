# Integrações

O Stretor se apoia em serviços externos para catálogo, mídia e downloads. Esta
página separa o que **já está implementado** do que é **roadmap**, para não
confundir estado atual com plano.

## Catálogo (TMDB) — implementado

A integração com o catálogo mundial de filmes usa a API do
[TMDB](https://www.themoviedb.org/). A chave fica apenas no backend
(`TMDB_API_KEY`) e nunca é exposta ao browser.

- Regras de negócio em [`TmdbService.php`](../backend/app/Services/TmdbService.php:1).
- Configuração em [`services.php`](../backend/config/services.php:1).
- Respostas cacheadas no Redis (`TMDB_CACHE_TTL`, padrão 3600s) para respeitar o
  rate limit e acelerar a Home.
- Endpoints expostos em [API](api.md).

## Cache (Redis) — implementado

O Redis guarda o cache das respostas do TMDB. É o que evita bater na API externa
a cada carregamento da Home.

## Torrents — implementado

A busca de fontes de torrent já funciona: ao clicar em "Assistir", o backend
consulta o indexador configurado e devolve a lista ordenada por prioridade —
dublado em PT-BR primeiro.

### Indexador Torznab (Prowlarr) — provedor principal

Os sites generalistas de torrent não oferecem uma API limpa como o YTS, e o
catálogo do YTS é quase todo em inglês. Para chegar às fontes dubladas, o
provedor principal é um **indexador Torznab** — o [Prowlarr](https://prowlarr.com/)
sobe junto com a stack e unifica vários trackers (públicos e privados) atrás de
uma única API.

- O Prowlarr roda no serviço `prowlarr` do [`docker-compose.yml`](../docker-compose.yml:170)
  e o painel fica em `http://localhost:9696`.
- **Configuração manual única**: cadastrar os trackers no painel e copiar a
  chave da API para `TORRENTS_TORZNAB_KEY` no `.env`. Sem essa chave o sistema
  cai para o YTS (catálogo em inglês).
- Regras de negócio em [`TorznabService.php`](../backend/app/Services/TorznabService.php:1)
  e [`TorrentService.php`](../backend/app/Services/TorrentService.php:1).
- A consulta usa `t=search` com a categoria de filmes (`TORRENTS_TORZNAB_CATEGORIA`,
  padrão `2000`) e lê os atributos `seeders`/`peers`/`magneturl`/`infohash` de
  cada item.
- O YTS continua como **reserva**, consultado só quando o indexador não está
  configurado.

### Prioridade de idioma

O usuário quer o filme **dublado em PT-BR**. A ordenação coloca as fontes
marcadas como dubladas no topo; inglês ou idioma original só entram quando não
existe torrent em PT-BR com peers. A dedução do idioma pelo título fica em
[`IdiomaFonte.php`](../backend/app/Enums/IdiomaFonte.php:59), que reconhece tags
como `dublado`, `nacional`, `pt-br` e `áudio pt`.

### Fontes sem peers são descartadas

Uma fonte sem peers conecta mas nunca envia dados — era a causa das sessões
presas em `aguardando` com `percentual: 0`. Duas defesas:

- O backend **filtra** qualquer fonte com `seeds === 0` ou magnet vazio antes de
  devolver a lista.
- O media-service expõe `POST /verificar`, que adiciona o magnet, espera alguns
  segundos e responde `{ ok, peers, seeds, velocidade }` — o backend pode testar
  a fonte antes de entregá-la ao micro-serviço.

### Demais regras

- A busca prioriza o `imdb_id` (mais preciso que o título, que traz remakes).
  Quando só há o título, os resultados são filtrados pelo ano do filme.
- O YTS devolve em `url` um link de download, não uma lista de trackers; o
  serviço completa o magnet com anunciadores públicos para o WebTorrent achar
  peers.
- Respostas cacheadas no Redis (`TORRENTS_CACHE_TTL`, padrão 1800s).
- O motor de torrent roda no media-service (biblioteca `webtorrent`), que
  conecta a fonte e serve o vídeo convertido em HLS.

### Ajustes obrigatórios no media-service

Problemas de ambiente e de streaming encontrados na validação, já tratados:

- **Rota dos segmentos HLS**: o FFmpeg escreve `segmento-N.ts` e a playlist os
  referencia de forma relativa. O player resolve esse nome sobre a URL da
  playlist, chegando em `/media/sessao/<id>/segmento-N.ts`. A rota do Express
  precisa receber o arquivo direto (`/sessao/:id/:arquivo`), sem o nível
  intermediário `/segmento/` — caso contrário o player recebe 404 e o vídeo não
  toca. As rotas específicas (`/status`, `/playlist.m3u8`) são registradas antes
  da genérica para não serem capturadas por ela.
- **Regex do Nginx com grupos nomeados**: a regra dos segmentos usava `$1`/`$2`
  sem grupos de captura correspondentes, então o `proxy_pass` montava
  `/api/media/sessao//` e o Express respondia 404. A correção usa grupos
  nomeados (`?<sessao_id>`, `?<recurso>`) referenciados por nome.
- **Ordem das locations no Nginx**: locations por regex são avaliadas na ordem
  em que aparecem e a primeira que casa vence. A regra específica dos segmentos
  (`proxy_buffering off`) estava **depois** da genérica `/media/(.*)`, então
  nunca era alcançada e os segmentos eram acumulados pelo buffer do proxy. A
  específica agora vem primeiro.
- **ETag no endpoint de status**: o Express habilita ETag por padrão. Como o
  status fica idêntico entre dois polls seguidos (mesmo `status`, mesma
  `mensagem`), o navegador respondia `304 Not Modified` com corpo vazio. O axios
  entregava `data` vazio, `status.status` virava `undefined` e o frontend nunca
  enxergava o `pronto` — o overlay ficava preso no polling para sempre. O ETag
  foi desligado globalmente (`app.set('etag', false)`) e o endpoint de status
  envia `Cache-Control: no-store`.

- **uTP desligado** (`new WebTorrent({ utp: false })`): o módulo nativo
  `utp-native` provoca *segfault* (SIGSEGV) neste container, derrubando o
  serviço inteiro. TCP, DHT e trackers continuam ativos e são suficientes.
- **Patch do webtorrent** (`patches/webtorrent+2.8.5.patch`): a versão 2.8.5
  chama `arr2hex(infoHash)` esperando um `Uint8Array`, mas o `parse-torrent` 11.x
  devolve string — o processo morria com `ERR_INVALID_ARG_TYPE`. O patch aceita
  os dois formatos e é reaplicado automaticamente pelo `postinstall`
  (`patch-package`).
- **Cabeçalho no fim do arquivo**: muitos MP4 de torrent guardam o átomo `moov`
  no final. A análise insiste até o cabeçalho ficar legível e só a aceita quando
  vídeo e áudio foram identificados — um cabeçalho lido pela metade faria o
  FFmpeg "concluir" sem gerar segmento nenhum.
- **Reprodução progressiva**: o caminho da conversão é escolhido pela posição do
  índice do contêiner. MKV/WebM e MP4 *faststart* trazem o índice no começo e
  fluem por um pipe alimentado pelo torrent — o FFmpeg publica cada segmento
  conforme os bytes chegam e o player abre **antes** do fim do download. MP4/MOV
  com o `moov` no fim não fluem por pipe: o FFmpeg lê o MP4 sequencialmente e não
  busca o índice depois de atravessar o `mdat` (aborta com `partial file`).
  Reordenar o fluxo também não serve, porque as tabelas de amostras do `moov`
  (`stco`/`co64`) guardam offsets absolutos do arquivo original — mover o índice
  para a frente invalida esses offsets e o segmento sai com 0 byte. A detecção
  fica em [`localizarMoov`](../media-service/src/services/hls.js:341) e a decisão
  em [`indiceEstaNoFim`](../media-service/src/services/sessoes.js:254).
- **`moov` no fim sem esperar o download inteiro**: em vez de aguardar o arquivo
  completo, [`priorizarIndice`](../media-service/src/services/sessoes.js:298)
  localiza o `moov` e pede ao WebTorrent **apenas aquele intervalo**
  (`arquivo.select(10, inicio, fim)`). Assim que o trecho chega,
  [`aguardarTrecho`](../media-service/src/services/sessoes.js:334) libera a
  conversão do disco — o filme abre sem esperar os vários GB do `mdat`. Só se o
  `moov` não for localizável é que caímos no download completo.
- **Timeout de dados da fonte**: o evento `ready` do WebTorrent só garante os
  metadados, não que existam peers enviando bytes. Sem essa checagem, uma fonte
  morta prendia a sessão por 120 s. [`aguardarDados`](../media-service/src/services/sessoes.js:517)
  falha em 30 s (`TIMEOUT_DADOS_MS`) se nenhum byte chegar, liberando o frontend
  para tentar a próxima fonte.
- **Telemetria de download**: o status da sessão expõe `peers`, `velocidade` e
  `baixado`, além do `percentual`. É o que permite ao overlay mostrar "(sem
  peers)" e distinguir "conectando" de "baixando de verdade".
- **Buffer inicial**: o player só é liberado com 8 segmentos (~32 s de vídeo) em
  disco, folga suficiente para uma conexão de 4 Mbps converter o próximo trecho
  enquanto o atual toca, sem interrupção.
- **Retomada removida**: `-ss`, `-hls_flags append_list` e toda a lógica de
  "passada interrompida" deixaram de existir. A playlist usa `-hls_list_size 0`
  (mantém todos os segmentos) e `#EXT-X-ENDLIST` é anexado só ao final da
  conversão — sem essa tag o `hls.js` continuaria esperando segmentos que nunca
  viriam.
- **Playlist `EVENT` → `VOD` ao concluir**: enquanto a conversão corre a playlist
  é `#EXT-X-PLAYLIST-TYPE:EVENT`, que o `hls.js` trata como transmissão ao vivo —
  a duração total fica `Infinity` e a barra de progresso não anda. Ao terminar,
  [`finalizarPlaylist`](../media-service/src/services/hls.js:237) troca o tipo
  para `VOD` e anexa `#EXT-X-ENDLIST`; a partir daí o Plyr lê a duração real
  **sem nenhuma manipulação do player**. A duração também é exposta no status da
  sessão para o overlay exibir o tempo restante.
- **Normalização de timestamps**: `-fflags +genpts` gera PTS monotônicos e
  `-avoid_negative_ts make_zero` ancora a timeline em zero. Sem isso o `#EXTINF`
  da playlist deixa de bater com os PTS reais e o `hls.js` trava no MSE (anexa o
  buffer, mas o playhead não avança e ele para de pedir segmentos). **Não** se
  usa `-copyts` nem `-start_at_zero`: eles preservam os timestamps de origem, mas
  o muxer HLS então corta os segmentos em pontos que não coincidem com os
  keyframes e os *parameter sets* (SPS/PPS) do H.264 acabam no segmento errado —
  o decodificador acusa `non-existing PPS 0` e nenhum frame sai.
- **Seleção do arquivo antes da análise**: `arquivo.select(1)` manda o WebTorrent
  baixar o arquivo inteiro em ordem, do começo para frente. A prioridade
  explícita evita o valor 0 ("sem prioridade"), que ainda desperta o interesse do
  torrent mas deixa a intenção obscura.

### Endereço do media-service no frontend

O Express monta as rotas em `/api/media`, mas o Nginx expõe o serviço em
`/media` e reescreve o prefixo. Existem, portanto, **dois prefixos distintos**:

| Camada | Prefixo | Quem usa |
| --- | --- | --- |
| Público (Nginx) | `/media` | `VITE_MEDIA_SERVICE_URL` |
| Interno (Express) | `/api/media` | rotas do media-service |

O store de API ([`api.js`](../frontend/src/stores/api.js:23)) normaliza a base
para que os dois cenários funcionem:

- **Porta crua do Node** (`http://localhost:3000`): falta o prefixo interno, que
  é acrescentado → `http://localhost:3000/api/media`.
- **Prefixo público** (`/media` ou `http://localhost/media`): o Nginx já reescreve
  para `/api/media`, então nada é acrescentado.

Atenção ao segundo caso: tratar `http://localhost/media` como "porta crua"
gerava o caminho duplicado `/api/media/api/media/sessao`, e o Express respondia
`Cannot POST /api/media/api/media/sessao`. Como o overlay percorre as fontes em
sequência ([`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:223)),
o erro se repetia uma vez por fonte — daí a rajada de requisições com o mesmo
404.

### Legendas — roadmap

Ainda não implementado:

- Suporte a APIs de legendas próprias.
- Rotinas para **extração de legendas diretamente dos arquivos de torrent**.

## Real-Debrid — roadmap

Previsto nas regras do projeto, ainda não implementado:

- Integração completa com o serviço Real-Debrid para gerenciamento e cache de
  downloads.

## Próximos passos

- Endpoints atuais: [API](api.md)
- Arquitetura dos serviços: [Arquitetura](arquitetura.md)
