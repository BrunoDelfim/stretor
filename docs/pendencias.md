# Pendências conhecidas

Registro do que ficou **em aberto**: comportamento observado, com a evidência que
permite reproduzir, que ainda não tem correção fechada. Não é o diário de bordo
(esse é [`plans/`](../plans/), que guarda o "porquê" das decisões já tomadas) —
aqui só entra o que ainda precisa de ação.

## Escolha de arquivo entrega o filme no lugar do episódio

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

**Se persistir, atacar por aqui:**

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

- **DoodStream** (`playmogo.com/e/<id>`) entrega por `pass_md5`, com token de
  sessão e `Referer` do embed; a sondagem devolveu "File not found" com o
  `Referer` certo, então falta entender qual cookie o endpoint espera.
- **Byse** (`bysebuho.com/e/<id>`) é uma SPA: o HTML tem 1,6 KB e um
  `<script type="module">`, e o vídeo só existe depois de a aplicação chamar a
  API interna.

**Para abrir esses dois**, o desenho é o mesmo: uma receita por host dentro do
[`LeitorVideobox`](../backend/app/Services/Torrents/LeitorVideobox.php:1), sempre
com confirmação por sonda — nunca por posição de token no HTML. Enquanto não
houver receita, o servidor é registrado no log como visto e recusado.

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

