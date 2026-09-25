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

A busca de fontes de torrent já funciona e roda em **três degraus**, para não
depender de um único serviço: ao clicar em "Assistir", o
[`CatalogoProvedores`](../backend/app/Services/Torrents/CatalogoProvedores.php:1)
consulta os provedores nativos do backend (degrau 1) e só desce para o
Prowlarr/Torznab (degrau 2) e, por fim, para o YTS (degrau 3) quando o degrau
anterior não devolve nenhuma fonte dublada válida. A lista final sai ordenada por
prioridade — dublado em PT-BR primeiro.

- **Degrau 1 — provedores nativos do backend**: trackers públicos PT-BR via HTTP
  direto com parser HTML, além de apibay, Torrentio e BT4G. Ficam em
  `app/Services/Torrents/` e não exigem chave de API.
- **Degrau 2 — Prowlarr/Torznab**: ver a seção abaixo.
- **Degrau 3 — YTS**: reserva final; o catálogo é quase todo em inglês.
- A cascata só avança de degrau quando o anterior não devolve fonte PT-BR válida,
  e é o [`TorrentService`](../backend/app/Services/TorrentService.php:1) quem
  recolhe o resultado do catálogo e o ordena.

### Indexador Torznab (Prowlarr) — degrau 2 da busca

Os sites generalistas de torrent não oferecem uma API limpa como o YTS, e o
catálogo do YTS é quase todo em inglês. Por isso o [Prowlarr](https://prowlarr.com/)
sobe junto com a stack e unifica vários trackers (públicos e privados) atrás de
uma única API Torznab. Ele é o **degrau 2** da cadeia: só é consultado quando a
busca nativa do backend (degrau 1) não encontra nenhuma fonte dublada válida. O
YTS é o degrau 3 — a reserva do Prowlarr.

- O Prowlarr roda no serviço `prowlarr` do [`docker-compose.yml`](../docker-compose.yml:190)
  e o painel fica em `http://localhost:9696`.
- **Sem configuração manual**: na subida do container o backend descobre a chave
  da API e cadastra os indexadores PT-BR sozinho — ver "Provisionamento
  automático do Prowlarr" abaixo.
- Regras de negócio em [`TorznabService.php`](../backend/app/Services/TorznabService.php:1)
  e [`TorrentService.php`](../backend/app/Services/TorrentService.php:1).
- A consulta usa `t=search` com a categoria de filmes (`TORRENTS_TORZNAB_CATEGORIA`,
  padrão `2000`) e lê os atributos `seeders`/`peers`/`magneturl`/`infohash` e
  `language` de cada item.
- São **duas consultas por filme**: uma com o termo normal (`título ano`) e outra
  com `título ano dublado`, montada por
  [`buscarDublado()`](../backend/app/Services/TorznabService.php:55). O termo base
  fica em [`termoBase()`](../backend/app/Services/TorznabService.php:66) e o
  pedido HTTP em [`consultar()`](../backend/app/Services/TorznabService.php:76).
  Sem esse segundo termo, o nome do filme sozinho quase nunca devolvia o release
  nacional.
- Quem marca cada resultado com a origem (`provedor`/`provedor_rotulo`) é o
  [`TorrentService`](../backend/app/Services/TorrentService.php:122) — ver
  "De onde veio a fonte?".
- O YTS continua como **reserva**, consultado quando o indexador não está
  configurado **ou** quando está configurado mas não devolveu nenhuma fonte. Nos
  dois casos o motivo vira um `Log::warning`, para o log explicar por que a lista
  veio do YTS.
- **Ausência de chave não é erro**: sem chave o degrau 2 é simplesmente pulado e
  a busca desce para o YTS.
- A cascata só avança de degrau quando o anterior não devolve fonte PT-BR válida,
  e é o [`CatalogoProvedores`](../backend/app/Services/Torrents/CatalogoProvedores.php:1)
  quem orquestra a ordem.

### Provisionamento automático do Prowlarr

O Prowlarr nasce sem chave conhecida pelo backend e sem nenhum indexador
cadastrado. Em vez de exigir configuração manual no painel, o entrypoint do
backend executa `php artisan prowlarr:provisionar`
([`ProvisionarProwlarr`](../backend/app/Console/Commands/ProvisionarProwlarr.php:1))
depois de o Postgres responder:

1. o [`ProwlarrService`](../backend/app/Services/ProwlarrService.php:1) lê a
   chave da API direto do `config.xml` do Prowlarr, montado **somente leitura**
   em `/prowlarr-config` (volume `prowlarr_config`, compartilhado com o backend);
2. aguarda `/api/v1/system/status` responder — na primeira subida o Prowlarr
   gasta alguns segundos criando o banco interno antes de aceitar requisições;
3. consulta `/api/v1/indexer` e cadastra apenas o que ainda não existe, o que
   torna o processo **idempotente**: subir de novo não duplica indexador nem
   reseta configuração;
4. imprime a chave numa linha `PROWLARR_API_KEY=...`, que o entrypoint captura e
   grava em `TORRENTS_TORZNAB_KEY` no `.env` (e exporta para o php-fpm).

Tudo é tolerante a falha: os erros viram aviso no log e o backend sobe
normalmente. Para refazer o provisionamento sem reiniciar nada, use
`make prowlarr`.

### Definição customizada de indexador público PT-BR

O Prowlarr não traz nenhum tracker brasileiro **público** de fábrica — os que
vêm embutidos (`amigosshare`, `bjshare`, `brasiltracker`, `capybarabr`,
`locadora`, `mdan`, `samaritano`, `shakaw`) são todos privados e exigem conta e
convite. Para ter uma fonte pública em PT-BR, o projeto versiona uma definição
própria:

- Arquivo: [`docker/prowlarr/Definitions/Custom/torrentdosfilmes.yml`](../docker/prowlarr/Definitions/Custom/torrentdosfilmes.yml:1).
- O [`docker-compose.yml`](../docker-compose.yml:204) monta essa pasta em
  `/config/Definitions/Custom/` dentro do container, então a definição é
  versionada com o código e sobrevive a recriações.
- É essa definição (id `torrentdosfilmes`) que o provisionamento cadastra como
  indexador, a partir da lista `indexadores` em
  [`config/services.php`](../backend/config/services.php:126). Para somar outro
  tracker público, basta soltar o `.yml` na mesma pasta e incluir o id na lista.
- O Prowlarr lê o arquivo no boot; para recarregar depois de editá-lo, use
  `docker compose restart prowlarr`.
- A definição é do tipo `Cardigann` (raspagem de HTML) e devolve os resultados
  no padrão Torznab, com as tags de idioma (`Dublado`, `Dual Audio`,
  `Legendado`) preservadas no título — é o que o backend usa para priorizar o
  dublado.

> **Domínio instável**: os trackers públicos brasileiros trocam de endereço com
> frequência. Se o Prowlarr devolver `Name does not resolve` ou
> `Unable to connect`, atualize a lista `links` do `.yml` com o endereço atual.
> O restante da definição continua válido.

### Prioridade de idioma

O usuário quer o filme **dublado em PT-BR**. A ordenação coloca as fontes
marcadas como dubladas no topo; inglês ou idioma original só entram quando não
existe torrent em PT-BR com peers.

O idioma é deduzido por **dois caminhos**, em ordem de confiança:

1. **Atributo do indexador** — alguns trackers devolvem o idioma num atributo
   `torznab:attr name="language"`. O
   [`TorznabService`](../backend/app/Services/TorznabService.php:150) repassa o
   valor cru em `idioma` e o
   [`TorrentService`](../backend/app/Services/TorrentService.php:212) o
   interpreta com
   [`IdiomaFonte::deduzirDoIdioma()`](../backend/app/Enums/IdiomaFonte.php:116),
   que reconhece códigos como `pt`, `pt-br`, `por` e `portuguese`.
2. **Tags no título** — quando o atributo não existe ou não é reconhecido, cai
   em [`IdiomaFonte::deduzirDoTitulo()`](../backend/app/Enums/IdiomaFonte.php:59),
   que reconhece `dublado`, `nacional`, `pt-br` e `áudio pt`.

Como a tag do título é a pista mais forte — e é justamente ela que os releases
brasileiros carregam —, a busca foi além da dedução: o `TorznabService` faz a
segunda pergunta com o termo `dublado` e o
[`TorrentService`](../backend/app/Services/TorrentService.php:169) funde as duas
listas com os dublados na frente, sem duplicar por infohash
([`mesclarFontes()`](../backend/app/Services/TorrentService.php:169)). Uma falha
em uma das consultas não derruba a outra — cada uma é isolada por
[`buscarNoTorznab()`](../backend/app/Services/TorrentService.php:148).

A conclusão prática: um filme só vem com **áudio original em inglês** quando
nenhuma fonte PT-BR passou pelo filtro de peers. Não é escolha do sistema, é
escassez de fonte dublada — daí valer a pena conferir a origem da fonte (abaixo)
antes de suspeitar do código.

### De onde veio a fonte?

Cada item devolvido por `GET /api/filmes/{id}/fontes` carrega dois campos que
identificam a origem:

| Campo | Valores | Significado |
| --- | --- | --- |
| `provedor` | `torznab` \| `yts` | identificador estável, para lógica |
| `provedor_rotulo` | `Indexador (Torznab)` \| `YTS` | rótulo para exibição |

O [`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:560) junta
esse rótulo com o idioma detectado e mostra, abaixo do contador "Fonte X de Y"
(ex.: `Indexador (Torznab) · Dublado` ou `YTS · Idioma original`). É o que
responde, de relance, se a lista veio do indexador onde as tags PT-BR foram
configuradas ou da reserva em inglês.

Pelo lado do servidor, os logs do
[`consultarProvedores()`](../backend/app/Services/TorrentService.php:85) registram
a transição:

- `Torznab configurado, mas sem fontes para o título.` — o indexador respondeu,
  mas nada passou pelos filtros (título/ano/peers). Vale para as duas consultas.
- `Nenhuma fonte dublada em PT-BR para o título.` — há fontes, mas nenhuma
  marcada como dublada, nem pela tag do título nem pelo atributo do indexador.
  É o log que separa "não existe fonte em PT-BR" de "a ordenação falhou".
- `Torznab não configurado; a busca usará apenas o YTS (inglês).` — falta a
  `TORRENTS_TORZNAB_KEY` no `.env`.

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
  FFmpeg "concluir" sem gerar segmento nenhum. O layout lido vai para o log da
  sessão (`cabecalho=ftyp>moov>mdat`), junto da duração e do `start_time` do
  contêiner (`inicioFonte=`).
- **Cabeçalho lido ANTES de escolher disco ou pipe**: é a correção decisiva do
  começo "aleatório". Antes, o caminho vinha da posição do índice
  (`indiceEstaNoFim`) e o cabeçalho era procurado depois. Um MP4 com o `moov` no
  fim caía no **pipe**, que o FFmpeg não consegue reler: ele atravessa o `mdat`
  sem conseguir voltar ao índice e passa a decodificar no primeiro ponto com
  dados. Com `-avoid_negative_ts make_zero` a timeline é reancorada em zero, e o
  resultado era exatamente o sintoma relatado — relógio em `00:00` com imagem de
  um pedaço do meio. Agora
  [`prepararSessao`](../media-service/src/services/sessoes.js:162) chama
  [`analisarComEspera`](../media-service/src/services/sessoes.js:846)
  **primeiro** e só então decide o caminho.
- **Reprodução progressiva**: o caminho da conversão é escolhido pela posição do
  índice do contêiner. MKV/WebM e MP4 *faststart* trazem o índice no começo e
  fluem por um pipe alimentado pelo torrent — o FFmpeg publica cada segmento
  conforme os bytes chegam e o player abre **antes** do fim do download. MP4/MOV
  com o `moov` no fim não fluem por pipe: o FFmpeg lê o MP4 sequencialmente e não
  busca o índice depois de atravessar o `mdat` (aborta com `partial file`).
  Reordenar o fluxo também não serve, porque as tabelas de amostras do `moov`
  (`stco`/`co64`) guardam offsets absolutos do arquivo original — mover o índice
  para a frente invalida esses offsets e o segmento sai com 0 byte. A detecção
  fica em [`localizarMoov`](../media-service/src/services/hls.js:558) (sobre
  [`mapearCaixas`](../media-service/src/services/hls.js:581), que lê apenas os
  cabeçalhos das caixas) e a decisão em
  [`indiceEstaNoFim`](../media-service/src/services/sessoes.js:452).
- **Cauda antecipada**: quando o índice ainda não está visível, é quase certo que
  esteja no fim do arquivo, então
  [`anteciparCauda`](../media-service/src/services/sessoes.js:357) pede desde já
  a faixa onde o `moov` deve estar. Assim o cabeçalho fica legível sem esperar o
  download chegar ao fim naturalmente.
- **`moov` no fim sem esperar o download inteiro**: em vez de aguardar o arquivo
  completo, [`priorizarIndice`](../media-service/src/services/sessoes.js:497)
  localiza o `moov` e pede ao WebTorrent **apenas aquele intervalo**
  (`arquivo.select(10, inicio, fim)`). Assim que o trecho chega, a conversão é
  liberada do disco — o filme abre sem esperar os vários GB do `mdat`. Só se o
  `moov` não for localizável é que caímos no download completo.
- **Contiguidade por peças, não por percentual**: um arquivo com `progress` alto
  pode ter buracos, e ler um trecho furado do disco devolve **zeros** em vez de
  bloquear — ao contrário do stream do WebTorrent, que espera. Por isso a espera
  olha o *bitfield* do torrent:
  [`pecaPresente`](../media-service/src/services/sessoes.js:530) e
  [`faixaPresente`](../media-service/src/services/sessoes.js:549) provam que cada
  peça da faixa chegou, e
  [`aguardarPecas`](../media-service/src/services/sessoes.js:580) é quem espera.
  Antes de converter do disco,
  [`aguardarInicio`](../media-service/src/services/sessoes.js:625) exige os
  primeiros megabytes contíguos. O antigo `aguardarTrecho` (baseado em
  `progress`) foi removido — era ele que deixava passar o arquivo furado e
  produzia o começo no meio do filme.
- **Timeout de dados da fonte**: o evento `ready` do WebTorrent só garante os
  metadados, não que existam peers enviando bytes. Sem essa checagem, uma fonte
  morta prendia a sessão por 120 s. [`aguardarDados`](../media-service/src/services/sessoes.js:790)
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
  [`finalizarPlaylist`](../media-service/src/services/hls.js:436) troca o tipo
  para `VOD` e anexa `#EXT-X-ENDLIST`; a partir daí o Plyr lê a duração real
  **sem nenhuma manipulação do player**. A duração também é exposta no status da
  sessão para o overlay exibir o tempo restante.
- **Espera do `close` antes de finalizar a playlist**: o FFmpeg ainda reescreve a
  playlist depois do evento `end` — ele faz o flush final do muxer ao encerrar o
  processo, e essa escrita sobrescrevia a nossa. Era por isso que a playlist
  terminava com `#EXT-X-ENDLIST` (o FFmpeg o adiciona na saída limpa) mas ainda
  com `#EXT-X-PLAYLIST-TYPE:EVENT`, mantendo o `hls.js` no modo ao vivo mesmo
  após o fim. O handler agora espera `processo.once('close')` antes de chamar
  `finalizarPlaylist`, acessando o processo por `controle.comando.ffmpegProc`
  (propriedade exposta pelo fluent-ffmpeg).
- **Regularidade dos segmentos**: no modo `remux` usamos `-c copy`, e o muxer HLS
  só corta em keyframes. Encodes de torrent trazem um keyframe a cada ~10 s, então
  saíam segmentos de 10.4 s intercalados com outros de 0.9 s. Essa irregularidade
  inflava o `#EXT-X-TARGETDURATION` para 10 e tornava o `liveSyncPosition` do
  `hls.js` imprevisível — o player saltava centenas de segmentos ao se posicionar.
  `-hls_flags independent_segments` declara `#EXT-X-INDEPENDENT-SEGMENTS`,
  garantindo que cada segmento comece num keyframe completo e possa ser buscado
  isoladamente.
- **`-force_key_frames` só no modo vídeo**: a opção é de **saída** — controla o
  encoder, não o demuxer. Declará-la como opção de entrada faz o FFmpeg abortar
  com `Option force_key_frames ... cannot be applied to input url pipe:0`, e a
  sessão morria com `write EPIPE` (o fluxo do torrent tentava escrever num pipe
  já fechado). Além disso, no `remux` e no `audio` o vídeo é copiado (`-c copy`):
  não há encoder para forçar keyframes. Por isso ela vive em
  [`aplicarModo`](../media-service/src/services/hls.js:464), apenas no ramo
  `video`, onde `libx264` reencoda e `expr:gte(t,n_forced*4)` produz segmentos
  uniformes de 4 s.
- **Modo decidido também pelos keyframes**: como `-c copy` não permite forçar
  keyframes, um encode com keyframes esparsos continuaria gerando segmentos
  irregulares mesmo em `remux`. Por isso [`analisarArquivo`](../media-service/src/services/hls.js:94)
  mede o intervalo médio entre keyframes com [`medirIntervaloKeyframes`](../media-service/src/services/hls.js:196)
  (amostra dos primeiros 12, via `ffprobe -show_entries packet=pts_time,flags`).
  A varredura é limitada por `-read_intervals %+300`: sem essa janela o ffprobe
  percorre o arquivo inteiro até juntar a amostra e, no caminho de fluxo — com o
  arquivo ainda sendo baixado —, trava nos buracos do download e devolve `null`.
  Com a medição vazia, `decidirModo` mantinha o `remux` mesmo com keyframes
  esparsos, e era daí que saíam os segmentos irregulares de ~10,4 s. A janela
  também permite resolver assim que a amostra fecha, sem esperar o processo
  encerrar sozinho. Se o intervalo passa de `INTERVALO_KEYFRAME_MAXIMO` (6 s),
  [`decidirModo`](../media-service/src/services/hls.js:291) promove o modo para
  `video` — aceita o custo de CPU em troca de uma timeline regular. A medição só
  roda quando o vídeo é compatível, único caso em que a decisão depende dela. O
  intervalo medido aparece no log da sessão (`keyframes=10.00s`) para
  diagnóstico.
- **Normalização de timestamps**: `-fflags +genpts` gera PTS monotônicos e
  `-avoid_negative_ts make_zero` ancora a timeline em zero. Sem isso o `#EXTINF`
  da playlist deixa de bater com os PTS reais e o `hls.js` trava no MSE (anexa o
  buffer, mas o playhead não avança e ele para de pedir segmentos). **Não** se
  usa `-copyts` nem `-start_at_zero`: eles preservam os timestamps de origem, mas
  o muxer HLS então corta os segmentos em pontos que não coincidem com os
  keyframes e os *parameter sets* (SPS/PPS) do H.264 acabam no segmento errado —
  o decodificador acusa `non-existing PPS 0` e nenhum frame sai.
- **Janela de leitura deslizante**: `arquivo.select(1)` mandava o WebTorrent
  baixar o arquivo inteiro e espalhar os pedidos por todo o filme — como o
  WebTorrent escolhe pela raridade das peças, a região logo à frente de quem está
  lendo ficava cheia de buracos e a conversão morria de fome depois do começo.
  Agora [`iniciarJanela`](../media-service/src/services/sessoes.js:297) mantém uma
  faixa estreita acompanhando a leitura: uma banda com prioridade 6 e um trecho
  imediato com prioridade 8, recalculados a cada 700 ms por
  [`deslizar`](../media-service/src/services/sessoes.js:317). A prioridade fica
  **abaixo** dos 10 usados para abrir o `moov` e a cauda, então o cabeçalho
  continua ganhando a corrida. O tamanho da faixa sai do
  [`bytesPorSegundo()`](../media-service/src/services/sessoes.js:203) (vazão
  medida do torrent, ou tamanho do arquivo dividido pela duração como
  estimativa); sem vazão estimável, a janela é desligada e o código volta ao
  `select(1)`.
- **Posição de leitura**: a janela precisa saber quanto já foi entregue. No
  caminho de *pipe*, um
  [`Transform`](https://nodejs.org/api/stream.html#class-streamtransform) conta os
  bytes que passam a caminho do FFmpeg. No caminho de disco, a posição é o que a
  playlist **já publicou** — a soma dos `#EXTINF` por
  [`somarDuracaoDaPlaylist()`](../media-service/src/services/hls.js:531), lida por
  [`tempoPublicado()`](../media-service/src/services/sessoes.js:241). Usar o
  `progress` do FFmpeg direto não serve: ele é reportado com atraso e deixa a
  leitura disparar à frente dos dados.
- **Freio da leitura no disco**: ler um arquivo esparso não dá erro, dá zero — o
  FFmpeg atravessa o trecho inválido e o filme "acaba" no meio. Para evitar isso,
  [`medirFronteira()`](../media-service/src/services/sessoes.js:259) descobre até
  onde o arquivo está realmente contíguo e
  [`conferirLeitura()`](../media-service/src/services/sessoes.js:349) compara essa
  fronteira com a posição de leitura. Quando a folga cai abaixo da margem, o
  FFmpeg é **congelado** com `SIGSTOP` e descongelado com `SIGCONT` ao recuperar,
  via [`pausar`/`retomar`](../media-service/src/services/hls.js:438). Congelar
  preserva o estado do processo; reiniciá-lo recomeçaria a conversão do zero.
- **VOD só com o filme inteiro**: [`finalizarPlaylist()`](../media-service/src/services/hls.js:476)
  troca `EVENT`→`VOD` e acrescenta `#EXT-X-ENDLIST` **apenas** se a duração
  publicada chegar perto da duração real (tolerância de `max(60s, 10%)`). Uma
  conversão interrompida por falta de dados continua `EVENT`, então o player
  espera em vez de anunciar um filme de 15 segundos.
- **Diagnóstico do FFmpeg no log**: o `stderr` do processo é filtrado por
  [`iniciarConversao()`](../media-service/src/services/hls.js:288) — só as linhas
  de `Input`/`Output`/`Duration`/`start`/`Stream` e os avisos de
  `error`/`invalid`/`corrupt`/`missing` viram log. É isso que permite comparar o
  `start` do input com o `inicioFonte` e ver de onde a conversão realmente
  começou. [`registrarDiagnostico`](../media-service/src/services/sessoes.js:725)
  ainda imprime o começo da playlist (`#EXT-X-MEDIA-SEQUENCE`, primeiro
  `#EXTINF` e a contagem de segmentos) logo após o buffer inicial.
- **Encerramento completo da sessão**: fechar o player ou desistir de uma fonte
  chama [`encerrarSessao`](../media-service/src/services/sessoes.js:1331), que
  marca `sessao.cancelada` **antes** de qualquer espera — todas as rotinas de
  timeout em [`sessoes.js`](../media-service/src/services/sessoes.js:1) checam
  esse sinal e abortam com `SessaoCancelada`. Em seguida
  [`pararConversao`](../media-service/src/services/sessoes.js:1271) encerra a
  janela de leitura, mata o FFmpeg com `SIGKILL` e destrói o contador de bytes,
  [`removerTorrent`](../media-service/src/services/sessoes.js:1313) tira o
  torrent do cliente compartilhado (com `destroyStore: true`, apagando os bytes
  baixados) e o diretório da sessão é removido. Sem isso, o torrent do filme
  anterior continuava baixando e o próximo "Assistir" podia reencontrá-lo pela
  metade.
- **Sessões são voláteis**: o registro de sessões é um `Map` **em memória**, sem
  persistência. Um reinício do processo — inclusive o automático do
  `restart: unless-stopped` depois de uma queda — apaga todas as sessões, e o
  `GET /sessao/<id>/status` passa a devolver `404 Sessão não encontrada`. O
  frontend trata esse 404 como estado terminal (`inexistente`) e desiste da
  fonte em vez de consultar para sempre — ver
  [Frontend](frontend.md#sessão-que-sumiu-do-servidor). Vale lembrar que os
  handlers de `uncaughtException`/`unhandledRejection` em
  [`index.js`](../media-service/src/index.js:1) apenas registram o erro e deixam
  o processo seguir, então uma exceção não tratada pode deixar o serviço num
  estado inconsistente antes de o contêiner reiniciar.

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
