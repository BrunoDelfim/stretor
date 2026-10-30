# Pendências conhecidas

Registro do que ficou **em aberto**: comportamento observado, com a evidência que
permite reproduzir, que ainda não tem correção fechada. Não é o diário de bordo
(esse é [`plans/`](../plans/), que guarda o "porquê" das decisões já tomadas) —
aqui só entra o que ainda precisa de ação.

## Escolha de arquivo entrega o filme no lugar do episódio — resolvido no fallback

**Observado nos logs do media-service.** O pack

```
[Anime Time] Attack On Titan (Complete Collection) (S01-S04+OVA+Movies+Junior High)
  [BD] [Dual Audio][1080p][HEVC 10bit x265][AAC][Eng Sub]
```

(132 arquivos) entregou **o mesmo filme** para três episódios diferentes:

| Pedido | Arquivo escolhido |
| --- | --- |
| S01E01 | `Attack On Titan Movies/[Anime Time] Attack on Titan Movie  04 - Chronicle.mkv` |
| S04E01 | idem (`Movie 04 - Chronicle`) |
| S04E28 | idem (`Movie 04 - Chronicle`) |

E o pack `[WF] Shingeki no Kyojin [BD 1080p x265 OPUS][DUAL]` entregou
`[WF] Shingeki no Kyojin - 21 [BD 1080p x265 OPUS][DUAL][53ABD9C7].mkv` para
**S01E01**.

**Diagnóstico.** Nos dois casos o veredito é o **fallback do maior vídeo**: o
[`escolherArquivoDeVideo()`](../media-service/src/services/sessoes.js:1839) não
achou o episódio nas pastas de temporada e caiu no maior arquivo do pacote — que,
num pack grande, é quase sempre um filme (≈2 h contra ≈24 min de um episódio).

**O que já foi feito.** O commit *"fix(busca): pack de anime acha o episodio pela
numeracao absoluta"* passou a casar o episódio pela numeração que corre pela série
inteira (o menor número da pasta da temporada é o episódio 1 dela). Isso cobre o
caso "S04E28 → `... - 87.mkv`", coberto pelo teste
[`escolha-arquivo.test.js`](../media-service/test/escolha-arquivo.test.js).

> **Ressalva importante:** o container do media-service carrega os módulos Node no
> *boot*, então os logs acima podem refletir a versão **anterior** à correção.
> Reconferir ao vivo com esses dois packs antes de tratar como bug aberto.

**O fallback também foi fechado (2026-10-27).** Mesmo com a numeração absoluta, o
caminho "nada casou, pega o maior vídeo" continuava entregando o filme: num pack
grande o maior arquivo é justamente o de `Movies/`. Agora, quando há episódio
pedido, os **extras saem da disputa** antes do fallback —
[`pareceExtra()`](../media-service/src/services/sessoes.js:2080) reconhece as
pastas `Movies/`, `Filmes/`, `Specials/`, `Extras/`, `OVA/`, `Featurettes/` e os
arquivos `NCOP`/`NCED` (abertura e encerramento sem crédito, que ficam na pasta da
temporada). Sobrando episódio, o fallback pega o maior **deles**; só quando o pack
tem apenas extras é que o maior vídeo geral volta a valer, para não recusar a
fonte inteira. Coberto por quatro testes em
[`escolha-arquivo.test.js`](../media-service/test/escolha-arquivo.test.js).

**Se ainda persistir, atacar por aqui:**

1. **O fallback não deveria entregar filme.** Antes de desistir e pegar o maior
   vídeo, excluir os caminhos de extra — `Movies/`, `Filmes/`, `Specials/`,
   `OVA/` — da disputa. Um filme ou especial nunca é a resposta certa de um
   pedido de episódio de temporada, e o maior-vídeo do pacote acaba sendo ele.
2. **Reconhecer melhor a pasta da temporada.** Se o nome da pasta escapa de
   [`segmentoEhDaTemporada()`](../media-service/src/services/sessoes.js:2033), o
   deslocamento da numeração absoluta nunca é calculado e o pack cai no fallback.
   Registrar no log qual pasta foi considerada da temporada ajuda a fechar isso
   quando o pack real estiver em mãos.

## Link direto sem extensão de vídeo — resolvido no media-service

**Observado ao ligar o suporte a `url` dos addons Stremio.** O protocolo permite
que o stream seja um arquivo pronto, e é assim que vários addons entregam vídeo
sem torrent. Até 2026-10-25, o `prepararSessaoDireta()` exigia uma extensão em
`EXTENSOES_DIRETAS` **no caminho** e recusava o resto com `formato_desconhecido`.

Isso virou bloqueio real quando o Pobreflix passou a ser resolvido pelo
StreamTape: o arquivo é servido por
`https://streamtape.com/get_video?id=...&token=...`, e nem esse endereço nem o
redirecionamento final (um caminho de CDN com o nome do filme) terminam em
`.mp4`. Eram 1,6 GB de MP4 recusados por causa do texto do link.

**O que mudou:** o media-service passou a descobrir o formato **pela origem**
quando a URL não declara extensão. A primeira versão fazia isso com um `fetch` de
dois bytes lendo o `Content-Type`; funcionava na máquina de desenvolvimento, mas
**no container o cliente HTTP do Node não alcança os CDNs de embed** — a conexão
TCP abre e o `fetch`/`https.get` morre em `ETIMEDOUT` para `*.tapecontent.net` e
`*.mxcontent.net`, enquanto o FFmpeg lê os mesmos endereços sem dificuldade (ele
faz Happy Eyeballs e tem as opções de reconexão). O efeito era o pior possível: o
backend já tinha confirmado o vídeo, o media-service recusava a fonte com
`formato_desconhecido`, e o player pedia `/fontes` de novo — em laço.

A sonda agora é o **próprio ffprobe** (`format.format_name`), com três
tentativas, porque é a mesma leitura que a conversão fará em seguida: se ele não
abre, o FFmpeg também não abriria. `mov,mp4,...` vira `.mp4`, `matroska,webm`
vira `.mkv`, `hls` vira `.m3u8`. Validado no container: `ffprobe` abriu o
StreamTape (`h264` 720p + `aac`) e a sessão de Lanterns S01E01 foi a **pronto**,
com a playlist publicada.

O que **continua** de fora, e por decisão de produto:

- entrega de serviço pago (`real-debrid.com/d/...`, `alldebrid.com/...`) — o
  Stretor não deve depender de conta de entrega;
- redirecionador de embed e página de player — são páginas, não arquivos; o
  [`ResolvedorEmbed`](../backend/app/Services/Torrents/ResolvedorEmbed.php:1)
  percorre apenas cadeias de receita conhecida.

No addon Stremio, a extensão no caminho **continua** sendo exigida
([`urlDeVideo()`](../backend/app/Services/Torrents/LeituraStreamStremio.php:194)):
ali não há como sondar cada link sem gastar o orçamento da busca, e o addon
entrega o mesmo arquivo em vários mirrors. Quem abre o caminho sem extensão é a
cadeia do scraper, que já provou a relevância da página antes de resolver o
player.

## Os outros servidores do videobox (DoodStream e Byse)

**Observado em 2026-10-25/26, nas páginas reais.** O `pobreflixtv.democrat` declara
quatro servidores dublados para o mesmo título. Dois já são percorridos:

- **StreamTape** (`streamtape.com/e/<id>`) — `get_video` com token em
  concatenação de JavaScript. É o primeiro da fila, e também o espelho que o site
  mais deixa apodrecer: episódios antigos respondem "Video not found!".
- **MixDrop** (`mixdrop.top/e/<id>`) — o endereço sai de um script empacotado
  (`eval(function(p,a,c,k,e,d)...)`), desempacotado no backend, e leva a um
  `mxcontent.net/...mp4?s=...&e=...` com `Range` e faststart. Entrou porque era
  ele que salvava o episódio quando o StreamTape estava morto.

Continuam de fora:

- **DoodStream** (`playmogo.com/e/<id>`). A receita foi decifrada e validada até o
  fim em 2026-10-26: a página publica `pass_md5/<caminho>` e o `token`, o `GET`
  em `/pass_md5/<caminho>` devolve a base do arquivo (num `*.cloudatacdn.com`) e o
  `makePlay()` só concatena 10 caracteres sorteados e `?token=…&expiry=<ms>`. O
  que impede o uso é o **destino**: essa base responde `302` para
  `https://<id>.dood.video/...`, e no container `*.dood.video` resolve para
  **`127.0.0.1`** (bloqueio de DNS do host) — conexão recusada. O `206
  video/mp4` do CDN antes do salto também não traz assinatura de MP4
  (`ftyp`/`moov`/`mdat`) nos primeiros 4 KB, então nem serviria como arquivo.
  Sem alcançar o host final, não há receita que resolva; se o DNS do host deixar
  de bloquear, a receita acima é o mapa.
- **Byse** (`bysebuho.com/e/<id>`) é uma SPA: o HTML tem 1,6 KB e um
  `<script type="module">`, e o vídeo só existe depois de a aplicação chamar a
  API interna.

**Para abrir esses dois**, o desenho é o mesmo: uma receita por host dentro do
[`LeitorVideobox`](../backend/app/Services/Torrents/LeitorVideobox.php:1), sempre
com confirmação por sonda — nunca por posição de token no HTML. Enquanto não
houver receita, o servidor é registrado no log como visto e recusado.

## A descoberta de páginas do TokyVideo não fecha com motor gratuito

**Observado em 2026-10-27.** O TokyVideo tem o episódio dublado de "Donas de Casa
Desesperadas" S01E01 (`/br/video/desperate-housewives-pt-01x01-nicsfilm`), e o
provedor **já extrai** a fonte quando recebe essa página: o MP4 do CDN é aceito
pela isenção de mesmo site e o `ffprobe` do media-service o abre (2.615 s,
`mov,mp4,...`). O que não existe é um caminho **gratuito** até ela:

- SearXNG (o motor do projeto) não devolve a página em nenhum termo testado,
  inclusive `site:tokyvideo.com ...`;
- a busca do próprio site (`POST api.tokyvideo.com/search-videos`): o upload é
  **não listado** — 56 resultados por página, paginando até a 6ª, e nenhum é ele;
  o autocomplete (`search-ajax`) é pior e devolve notícia;
- DuckDuckGo HTML responde `202` (desafio) para o IP do container e o Bing não
  traz o vídeo.

**Não há plano de API paga**: o projeto é 100% gratuito. O suporte ao motor
`brave` continua no código, mas **inerte** — sem chave ele é descartado antes de
qualquer requisição — e não é recomendado.

Para este título o caminho gratuito **já existe** e foi consertado junto: o
`tvpobreflix.com` (e o `verpobreflix.net`) embutem o `plenoflu.com`, e a cadeia
voltou a resolver quando o `Referer` do embed foi corrigido (ver
[integrações](integracoes.md#a-travessia-do-embed--do-iframe-ao-masterm3u8)).
Medido: 31 s, fonte direta pt-BR, áudio `por` e playlist 200.

Se um dia for preciso cobrir um site cuja busca não indexa o acervo, a saída
gratuita é um motor próprio (SearXNG com o Google funcionando) ou um adaptador de
busca do site — nunca uma API paga.

## A descida para o episódio no videobox — resolvida

**Observado em 2026-10-25, resolvido em 2026-10-26.** O
`pobreflixtv.democrat` separa filme (`/filmes/online/...`), série
(`/series/online/...`) e episódio (`/episodios/online/<slug>-<T>x<E>-<id>/`). A
busca devolve a **ficha da série**, e o `playerData` do id da série vem com
`servers_dub` vazio — por isso a série caía na cascata de torrents mesmo tendo
acervo no site.

O caminho é o próprio endpoint do player:
`do=episodesList&id=<série>&season=<temporada>` devolve
`{"episodes":[{"number":"1","url":".../episodios/online/..."}]}`. Quando o
pedido traz temporada e episódio, o [`LeitorVideobox`](../backend/app/Services/Torrents/LeitorVideobox.php:1)
consulta essa lista, escolhe o episódio **pelo número** e recomeça a leitura na
página dele — onde o id é o do episódio e os servidores existem.

Duas guardas: a descida acontece **uma vez** (ficha → episódio; a página do
episódio tem `data-temp`/`data-ep` preenchidos e não desce de novo), e sem
temporada/episódio no pedido a ficha é tratada como qualquer página sem servidor.
Medido no acervo real: "Round 6" S02E02 → fonte direta pt-BR em 14,3 s, sem a
cascata de torrents entrar.

**O que continua em aberto** nesse caminho: a lista é pedida por temporada, e o
`season` é o único parâmetro — se o site mudar a forma da aba (ou passar a paginar
episódios), o leitor devolve `null` e a busca segue para o agregador seguinte. É a
mesma fragilidade de scraping de todo o resto, com sintoma no log
(`Leitor videobox: episódio não encontrado na ficha`).

## O fallback do espelho por id (`redeflixapi.store`) não é consumido

O espelho endereçável por id tem um endpoint de saúde
(`POST /wp-json/api/v1/player-resolve`) que, quando o arquivo principal morre,
responde `mode: embed_fallback` apontando para
`https://redeflixapi.store/filme/{tmdb}`. No diagnóstico da conta expirada do
`nixplay.lat` (2026-10-24), esse host respondia `HTTP 520` — e o próprio espelho
não tinha para onde cair.

O Stretor **não** consome esse fallback: ele devolve uma página de embed, não um
arquivo, e o [`ResolvedorEmbed`](../backend/app/Services/Torrents/ResolvedorEmbed.php:1)
só percorre cadeias de receita conhecida. Se o host voltar, vale reavaliar com
uma sonda manual antes de escrever qualquer cadeia nova.


## O catálogo por id (`playerflix`) — resolvido

**Observado e resolvido em 2026-10-26.** O passo zero só tinha o VOD
(`vizer.autos`): quando ele não tinha o título, a busca voltava para o motor —
justamente o elo que falha no conteúdo raro. O EmbedMovies (`embedmovies.org`)
aponta para `myembed.biz`, que entrega o `playerflix.ink`, um catálogo
**indexado por id do TMDB** (`/inc/Ajax.php?type=tv&id=…&season=…&episode=…`).
Entre os players que ele devolve, o `watchplay.shop` publica o `.m3u8` em texto
puro (`createMyPlayer({url:"…"})` + `MyPlayerAudio="Dublado"`), então não há
ofuscação no caminho final.

Medido no acervo real: filme `969681` e série `93405`/T1E1 → **1 fonte `direto`
em PT-BR em ~4 s**, sessão `pronto`, `idioma_audio: por`, playlist e segmento
200 pelo proxy. Os segmentos mascarados (`0000.css`) passam porque o
media-service já afrouxa a trava de extensão do demuxer. Ver
[integrações](integracoes.md#o-catálogo-por-id-o-playerflix-e-a-receita-do-watchplay).

**O que continua em aberto:** `embedplayer2.xyz`, `embedplayabyss.top` e
`superflixapi.fun` aparecem no catálogo em português e não têm receita — o
SuperFlix, porém, já é consultado pelo [`BuscaAgregadores`]. Receita nova só
entra com sonda provando o arquivo, como manda o passo zero. O
`embed.warezcdn.com` da lista de fontes está morto (o `.net` é domínio à venda).

## O `yt-dlp` como extrator genérico de hosters — avaliado, não integrado

**Observado em 2026-10-26.** O `yt-dlp` instala no Alpine do media-service por
`apk add yt-dlp` (versão 2026.03.17) e resolve o **TokyVideo** até o MP4 direto
com token novo — o que fecharia a ponta de reprodução da pendência de descoberta
acima, se a página fosse descoberta. Testado nos players do catálogo por id
(`embedplayer2.xyz`, `v1.watchplay.shop`, `superflixapi.fun`): todos respondem
`Unsupported URL`, então ele **não** substitui as receitas nativas.

Não foi integrado ainda. Se entrar, o desenho é o mesmo dos outros extractores
do media-service: um endpoint que chama o binário com timeout curto e devolve a
URL resolvida; o custo é ~40 MB na imagem e a versão presa à do Alpine.

## O socorro do FlareSolverr não cabe no canal do stream direto

**Observado em 2026-10-27**, no caso "Donas de Casa Desesperadas" S06E13.

A conta que decide se o socorro vale a pena é
`necessario = maxTimeout + CUSTO_DO_SOCORRO_SEGUNDOS (20)` em
[`ClienteHttp`](../backend/app/Services/Torrents/ClienteHttp.php:1025). Com o
`maxTimeout` de 10 s que a página pede, **necessario = 30 s** — exatamente o
`TORRENTS_STREAM_DIRETO_ORCAMENTO` padrão. Ou seja: qualquer trabalho antes da
primeira página (as buscas dos agregadores levam 1–4 s) deixa o socorro fora do
orçamento. Nos logs do caso: `restante 25, necessario 30` na ficha e `restante 8,
necessario 28` na página do catálogo — nas duas, o socorro foi recusado.

A recusa é o comportamento correto **para páginas que ele resolveria**: uma
chamada que não caiba custa o orçamento inteiro e chega tarde. Mas o número está
preso ao `maxTimeout` pedido, e não ao que resta: com 25 s restantes o socorro
poderia ser chamado com `maxTimeout` de 5 s, que é o que a conta permite. Duas
saídas possíveis, nenhuma aplicada:

- **Adaptar o pedido ao restante** — recusar só abaixo de
  `CUSTO_DO_SOCORRO_SEGUNDOS + 5` e pedir `maxTimeout = restante - 20`.
- **Separar o orçamento do socorro** — reservar ~30 s do canal para a primeira
  página que falhar, em vez de deixar o socorro competir com as seguintes.

Antes de mexer, vale medir: nesta rodada o FlareSolverr **não resolveu** nenhum dos
hosts que bloqueiam. Medição de 2026-10-27, `maxTimeout` de 7 s:

| Host | Resultado | Custo |
| --- | --- | --- |
| `flixie.com.br` | `Error solving the challenge` | 10,4 s |
| `br2.trendcinema.com` | `Error solving the challenge` | 10,2 s |
| `www.ferreirafilmes.com.br` | `Challenge not detected!` (não havia desafio) | 6,0 s |

O `tvpobreflix.top` já tinha falhado antes (5 s e 10 s, 16,4 s e 14,0 s). Ou seja:
no acervo que o stream direto percorre, o socorro **não entrega** — ele só custa
tempo. Adaptar o orçamento não traria fonte, e é por isso que a mudança não foi
feita: o caminho que resolveu o caso foi o degrau da temporada, não o navegador do
proxy. Se algum host novo passar a resolver pelo FlareSolverr, a conta acima é o
ponto de partida.


## O `401` do `nixplay.lat` marcava link morto como verificado — resolvido

**Observado em 2026-10-29, com "Ponto Sem Retorno" (TMDB 1384216).** A Home
mostrava o selo **Verificado** e, ao abrir o player, a mensagem era *as fontes
conectaram, mas não enviaram dados*. A fonte gravada era
`nixplay.lat/movie/testelogado-vods/GwXanZ3Dj/1384216.mp4`, e uma leitura direta
com `Range` devolvia `HTTP 401` com o corpo `Credenciais inválidas.` (25 bytes).

**Diagnóstico.** Duas peças se somaram. O `401` não estava na lista de status que
condenam o link (`404`/`410`/`503`), então a sonda de cabeçalho o lia como "não
decidido" e aprovava — a postura tímida da sonda existia para não perder fonte
boa. E o passo zero do stream direto usava só essa sonda de existência: uma URL
que respondia qualquer coisa entrava na lista e **dispensava a cascata**, que é
quem teria achado o torrent do Torrentio.

**Correção.** O `401` entrou em `STATUS_DE_ARQUIVO_MORTO` (sem credencial não há
vídeo), e o passo zero passou a exigir `pareceVideoServido()`: um `Range` de 1 KB
cujo conteúdo precisa ter cara de mídia (`ftyp`, `#EXTM3U`, `Content-Type` de
vídeo). O mesmo vale para o catálogo do playerflix. Depois da correção, o mesmo
título foi re-testado: o link do `nixplay` foi descartado no log (`o VOD apontou
para uma fonte sem arquivo`) e a busca fechou com o torrent do Torrentio em
`pt-BR`.

**Efeito colateral encontrado no caminho.** A verificação da Home perguntava aos
provedores **sem o `imdb_id`** (a lista de tendências não o traz), e Torrentio e
addon Stremio só procuram por identificador. O mesmo título saía "sem fonte" na
rotina e com fonte no player. A rotina agora resolve os identificadores em lote
(`TmdbService::identificadoresEmLote()`, com cache) antes de testar cada página.

## O player abria outra obra no lugar do filme pedido — resolvido no porteiro

**Observado em 2026-10-30, com "Cara de Barro" (Clayface, TMDB 1400940).** O
usuário abriu o filme e o player tocou **outra obra**. A busca no APIBay
explicava: para o termo "Clayface" ele devolve, entre outros,
`The Batman S01E13 The Clayface of Tragedy 1080p` (episódio do desenho de 2004),
`Batman – One Bad Day - Clayface 1 (2023) (Comic) PDF` (quadrinho) e
`Joker's Asylum II - Clayface (2010)` (outro quadrinho).

**Diagnóstico.** O porteiro de relevância da montagem
([`nomeRelevante()`](../backend/app/Services/TorrentService.php:1165)) julgava
apenas palavras do título. "Clayface" está no nome dos três, então os três
passavam — e o primeiro com seeds ia para a lista. O player pedia o arquivo ao
media-service, que escolhe o maior vídeo do torrent: o desenho do Batman. O ano
não era conferido em lugar nenhum, então nem um release de 2010 era barrado.

**Correção.** O porteiro novo
([`fonteServeAoPedido()`](../backend/app/Services/TorrentService.php:1051)) julga
o que o nome prova: material de leitura (PDF/CBZ/EPUB/comic) nunca entra;
numeração de episódio não responde a pedido de filme; e ano declarado que não
bate com o do título (tolerância de um ano) é outra obra. A régua é conservadora
— nome sem ano, sem extensão e sem numeração passa —, e os casos de borda estão
em `PorteiroDaObraTest` (título "1917", resolução `1920x1080`, `.mkv.zip`,
"DOC" de documentário).

**O que ficou de fora.** O filme de 2026 só tem release TS/CAM em inglês, e a
política de idioma (`TORRENTS_SOMENTE_PT_BR_OU_LEGENDADO`) não oferece original
sem legenda PT-BR. Para esse título a busca devolve zero fontes — o que é
honesto, mas não é o mesmo problema deste registro.
