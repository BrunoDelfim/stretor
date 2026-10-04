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

A Home usa `/trending/all/day` para unificar filmes, animação e séries em um só
fluxo, descartando lançamentos futuros. A classificação indicativa é buscada no
endpoint certo por tipo (`/movie/{id}/release_dates` ou
`/tv/{id}/content_ratings`), com cache separado por `{tipo}:{id}`.

Para séries, o catálogo também alimenta o modal: `/tv/{id}` traz a ficha completa
com a lista de temporadas e `/tv/{id}/season/{numero}` traz os episódios de cada
temporada (capa, sinopse, título, nota e duração por episódio). Ambos passam pelo
mesmo cache do TMDB.

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

### Filme ou episódio: o mesmo `imdb_id` não basta

O TMDB reaproveita o **mesmo `imdb_id`** para uma série e para um filme homônimo.
Isso já causou um bug real: ao abrir o S01E01 de *American Horror Story*, a lista
trazia o filme *M. Butterfly (1993)* — o provedor consultou o catálogo de filmes
usando o identificador da série e devolveu o que estava lá.

Por isso o contrato
[`ProvedorTorrents::buscar()`](../backend/app/Contracts/ProvedorTorrents.php:61)
recebe também `temporada` e `episodio`. Cada provedor decide o que fazer com esse
contexto, e o resultado se divide em dois grupos:

- **Provedores por identificador** — Torrentio e YTS. O Torrentio troca o caminho
  conforme o contexto: `/stream/movie/{imdbId}.json` para filme e
  `/stream/series/{imdbId}:{temporada}:{episodio}.json` para episódio. O YTS é um
  catálogo **exclusivo de filmes**, então devolve vazio quando a busca é de
  episódio — melhor não trazer nada do que trazer o filme errado.

  Esses provedores dependem do `imdb_id`, e aí mora uma pegadinha do TMDB: o
  endpoint `/movie/{id}` devolve `imdb_id` no corpo principal, mas o `/tv/{id}`
  **não** — o identificador da série só aparece dentro de `external_ids`. Como o
  [`TmdbService::detalhesSerie()`](../backend/app/Services/TmdbService.php:241) não
  pedia esse bloco, toda série saía com `imdb_id` nulo; o Torrentio então abstinha-se
  em silêncio (ele exige um id começando com `tt`) e sobrava só o APIBay, que busca
  por nome. Era exatamente o sintoma de "só o APIBay acha *American Horror Story*".
  A correção foi incluir `external_ids` no `append_to_response` e copiar
  `external_ids.imdb_id` para a raiz do payload antes de normalizar.
- **Provedores por nome** — TrackersBr, BT4G, APIBay e Torznab. Aqui o termo já
  chega pronto do [`TorrentService`](../backend/app/Services/TorrentService.php:120)
  no formato `Título S01E01` (via
  [`TermosBusca::episodio()`](../backend/app/Services/Torrents/TermosBusca.php:56)).
  Duas particularidades: o **ano sai do termo** (o release do episódio carrega o
  ano de exibição, não o da série) e o APIBay passa a **aceitar a categoria 205
  (TV)**, que normalmente é ignorada por ser conteúdo de série.

  O ano fora do termo vale para **todos** os provedores por nome, inclusive os que
  montam o termo internamente com `TermosBusca::base()`. O `TrackersBr` reanexava
  o ano da série ao termo já numerado (`American Horror Story S01E01 2011`), o que
  derrubava o recall justamente nos trackers que publicam o episódio — por isso
  ele agora usa o termo cru quando o contexto é de episódio.

  No APIBay a checagem de categoria de episódio tinha a lógica invertida: a
  condição `$categoria < 200 || $categoria > 299 || $categoria !== 205` é
  verdadeira para toda categoria diferente de 205, então só itens com categoria
  `0` passavam — os episódios legítimos (205) eram descartados. A regra correta é
  aceitar `0` (categoria não informada) ou `205`.

#### O termo de episódio precisa carregar as variações dubladas

A busca de **filme** sempre montou as variações dubladas
(`Título 2011 dublado`, `... dual áudio`) via
[`TermosBusca::paraDublado()`](../backend/app/Services/Torrents/TermosBusca.php:93).
A busca de **episódio**, porém, montava só o termo puro `Título S01E01` — as
variações existiam em
[`TermosBusca::episodioDublado()`](../backend/app/Services/Torrents/TermosBusca.php:72),
mas **nunca eram chamadas**. O efeito era o sintoma de "só vem fonte em idioma
original": os provedores por nome recebiam apenas o termo puro, devolviam dezenas
de lançamentos em inglês e o release nacional ficava fora da primeira página. O
Torrentio, que responde por identificador, também só devolve releases
internacionais — então a lista inteira saía como `Idioma original`.

A correção foi fazer o
[`TorrentService::titulosDeEpisodio()`](../backend/app/Services/TorrentService.php:120)
acrescentar as variações dubladas de cada título, na ordem: termo puro primeiro
(base para os provedores por nome e para o Torrentio) e as variações dubladas em
seguida. Com isso o `temDublado()` da cascata volta a funcionar e o dublado
aparece antes do legendado.

Como o termo agora **já chega com a tag**, os provedores por nome não podem
reanexá-la — senão gerariam `... S01E01 dublado dublado`, que não casa com
release nenhum. Por isso
[`TermosBusca::jaEDublado()`](../backend/app/Services/Torrents/TermosBusca.php:132)
detecta a tag e cada provedor (APIBay, BT4G, TrackersBr, Torznab) só completa o
termo quando ele ainda não a traz.

#### O título original é a segunda tentativa, não a primeira

A busca de filme e de episódio montava uma **lista única** de títulos com o
traduzido e o original, e consultava os dois sempre. O título original entrava
como segunda tentativa porque algumas traduções ficam curtas demais para o
buscador do site ("Homem-Aranha" devolve o desenho, "Spider-Man" devolve o
filme) — mas, na prática, ele era consultado mesmo quando o dublado já tinha
vindo, gastando orçamento e enchendo a lista de releases em inglês.

A correção foi separar a busca em **duas fases**. A primeira pergunta só pelo
título traduzido; a segunda, só pelo original, e apenas quando a primeira ficou
abaixo da meta PT-BR. Quem decide é
[`TorrentService::valeSegundaTentativa()`](../backend/app/Services/TorrentService.php:152),
que fecha a passagem em três casos: a chave
`TORRENTS_TITULO_ORIGINAL_SEGUNDA_TENTATIVA` desligada, a ausência de um título
original distinto do traduzido e a coleta já suficiente. A leitura da
suficiência é pública no catálogo
([`CatalogoProvedores::ptBrSuficiente()`](../backend/app/Services/Torrents/CatalogoProvedores.php:733)),
que é quem conhece a meta.

As fontes das duas fases são fundidas por
[`TorrentService::mesclarFontes()`](../backend/app/Services/TorrentService.php:180),
sem repetir: a mesma fonte pode voltar nas duas buscas (um release nacional que
casa os dois nomes), e a chave é o `id` (infohash), que identifica o torrent de
verdade. A primeira fase fica na frente, porque é a aposta PT-BR.

Como cada fase pergunta por um título só,
[`titulosDeBusca()`](../backend/app/Services/TorrentService.php:213) e
[`titulosDeEpisodio()`](../backend/app/Services/TorrentService.php:241) deixaram
de receber o par traduzido/original e passaram a receber um título.

#### Provedores por identificador são consultados uma única vez

Com as variações dubladas, o termo de episódio passou a render quatro entradas
(puro + três dubladas). O Torrentio ignora o termo — só o `imdb_id` importa —,
então consultá-lo por variação seriam quatro requisições idênticas ao provedor
mais lento da cascata. Por isso o
[`CatalogoProvedores`](../backend/app/Services/Torrents/CatalogoProvedores.php:35)
separa os provedores em duas listas: `primarios` (por nome) e `porIdentificador`
(Torrentio), e este último é consultado **uma vez só**, com o termo puro.

A numeração também entra na **chave de cache** de cada provedor
(`...|temporada|episodio`). Sem ela, o resultado do S01E01 seria servido para o
S01E02 durante todo o TTL.

#### A fonte precisa declarar a numeração pedida

Os provedores não são infalíveis na numeração. O Torrentio responde pela série
inteira e **mistura temporadas na mesma lista**: pedir o S01E01 de "American
Horror Story" devolveu um release `S10E01` em dual áudio. Como o dublado sobe
para o topo da ordenação, essa fonte de outra temporada era a **primeira tentada
pelo player** — e só depois de ela falhar o fluxo chegava à original correta. O
sintoma era exatamente "tentou uma fonte dublada e depois foi para a original".

A correção é uma peneira em cada provedor, apoiada em
[`TermosBusca::correspondeAoEpisodio()`](../backend/app/Services/Torrents/TermosBusca.php:147):
o título do release é lido por
[`TermosBusca::numeracaoDoTitulo()`](../backend/app/Services/Torrents/TermosBusca.php:180)
e, se ele **declarar** uma numeração diferente da pedida, a fonte é descartada.

A regra é conservadora de propósito: releases **sem numeração nenhuma** (packs,
nomes nacionais) passam, porque não há como provar que estão errados — descartá-los
apagaria fontes legítimas. A leitura cobre as grafias que aparecem na prática:
`S01E02`, `s1e2`, `1x02` e `Temporada 1 Episódio 2`.

A peneira foi aplicada em todos os provedores que podem devolver temporada errada:

- [`ProvedorTorrentio`](../backend/app/Services/Torrents/ProvedorTorrentio.php:128)
  — o caso que originou o bug;
- [`ProvedorApibay`](../backend/app/Services/Torrents/ProvedorApibay.php:113) e
  [`ProvedorBt4g`](../backend/app/Services/Torrents/ProvedorBt4g.php:163) — acervo
  mundial, devolvem temporadas vizinhas na busca por nome;
- [`ProvedorTrackersBr`](../backend/app/Services/Torrents/ProvedorTrackersBr.php:202)
  — o buscador do site ignora a numeração e pode trazer a página de outra temporada.

#### O Torrentio precisa da configuração de idioma na URL

O Torrentio aceita uma configuração embutida na própria URL, no segmento que
antecede `/stream`. Sem ela, ele responde com o **catálogo padrão** — quase todo
em inglês —, e era essa a razão de uma série trazer só fontes `Idioma original`
mesmo com o resto da cascata saudável. O
[`ProvedorTorrentio`](../backend/app/Services/Torrents/ProvedorTorrentio.php:45)
passou a montar a URL como
`{base}/{configuração}stream/series/{imdbId}:{temporada}:{episodio}.json`, com
`language=portuguese` vindo de `TORRENTS_TORRENTIO_IDIOMAS`.

Com `language=portuguese`, o Torrentio passa a incluir os provedores que publicam
releases nacionais (Comando, BluDV, ThePirateBay com faixa PT) e devolve os
lançamentos `Dublado` / `Dual Áudio` / `PORTUGUÊS BR` que faltavam. A lista de
idiomas fica na config para poder ser ampliada sem mexer no código; vazia,
desliga o filtro e volta ao padrão.

Duas armadilhas custaram tempo aqui e valem o registro:

1. **A URL precisa ser montada inteira.** O `Http::baseUrl($base)->get($caminho)`
   do Laravel descarta o caminho do host quando o caminho passado começa com
   `/`, e o segmento de configuração (`language=portuguese/`) é justamente parte
   do caminho. Montar `$base.'/'.$caminho` numa única URL garante que o filtro
   chegue ao Torrentio.
2. **A variável precisa chegar ao container.** O `docker-compose.yml` não
   repassava `TORRENTS_TORRENTIO_IDIOMAS` para o serviço `backend`, e o
   `entrypoint.sh` também não a sincronizava no `.env`. Sem isso, o valor
   definido no `.env` da raiz nunca era visto pelo Laravel. As duas pontas foram
   corrigidas.

#### A segunda consulta sem o filtro (só para série)

O `language=portuguese` é um corte **na origem**: o Torrentio só devolve o stream
que ele próprio já classificou como português. Para o pack de temporada isso é um
problema — muitos vêm rotulados de um jeito que o filtro não reconhece (grafias de
"multi áudio", "completa legendado") e o pack some antes de o nosso parser olhar o
nome do release, que é quem decide o idioma de fato.

Por isso o [`ProvedorTorrentio::buscar()`](../backend/app/Services/Torrents/ProvedorTorrentio.php:53)
dispara uma **segunda consulta**, sem o segmento de configuração, quando a busca é
de série (temporada e episódio presentes). As duas respostas são fundidas por
infohash: a consulta filtrada tem prioridade — é a leitura com o idioma mais
provável — e a ampla só acrescenta o que ainda faltava. Filme fica de fora: o
catálogo de filme é bem servido pelo filtro, e a consulta extra só encheria a lista
de originais em inglês.

O trecho comum às duas consultas saiu para o helper
[`consultar()`](../backend/app/Services/Torrents/ProvedorTorrentio.php:166) — elas
só divergem no segmento embutido na URL; a montagem, a leitura do rótulo e o corte
de numeração são idênticos. A segunda consulta respeita o mesmo orçamento global:
só sai se ainda houver tempo, e o teto é o que resta.

| Variável | Padrão | O que faz |
|----------|--------|-----------|
| `TORRENTS_TORRENTIO_BUSCA_AMPLA` | `true` | Liga a segunda consulta ao Torrentio sem filtro de idioma, só para série. Filme segue apenas com o filtro. |

#### O nome do release está no rótulo, não no `name` do stream

O Torrentio devolve cada stream com três campos de texto e é fácil pegar o
errado:

- `name` — só o provedor e a qualidade (`"Torrentio\n720p"`);
- `title` — o **rótulo completo**, com o nome do release na primeira linha, os
  metadados na segunda (`👤 0 💾 1.62 GB ⚙️ ThePirateBay`) e as faixas de áudio e
  bandeiras nas seguintes;
- `behaviorHints.filename` — o nome do arquivo dentro do torrent, quando existe.

O [`ProvedorTorrentio`](../backend/app/Services/Torrents/ProvedorTorrentio.php:160)
usava o `name` como título da fonte. Como ele não traz a numeração nem as tags de
idioma, **toda** fonte do Torrentio chegava ao catálogo como `"Torrentio 720p"`:
a validação de episódio não tinha o que conferir e a classificação de idioma caía
em `Idioma original`. Com `TORRENTS_APENAS_PT_BR=true`, o corte descartava as 23
fontes e a série aparecia vazia mesmo com o Torrentio respondendo.

A correção passou a extrair o nome da **primeira linha do rótulo**
([`nomeDoRotulo()`](../backend/app/Services/Torrents/ProvedorTorrentio.php:210)),
preferindo o `filename` quando ele vem. Assim a numeração (`S01E01`) e as tags
(`PORTUGUÊS BR`, `DUAL`) voltam a chegar ao catálogo.

Junto veio a segunda metade do problema: o Torrentio marca o áudio português com
a bandeira de **Portugal** (🇵🇹), não a do Brasil. A classificação passou a
aceitar 🇵🇹 e as grafias `português`/`portugues`/`portuguese` — mas foi
exatamente esse reconhecimento que, mais tarde, abriu o defeito descrito a
seguir.

#### O rótulo do Torrentio é promessa, não prova

A busca vai configurada com `language=portuguese` (veja a seção anterior), e isso
tem um efeito colateral que custou uma reprodução inteira: o Torrentio **anota a
resposta toda** com a bandeira de português. A
[`normalizarStream()`](../backend/app/Services/Torrents/ProvedorTorrentio.php:160)
repassava o rótulo completo (`$nome.' '.$rotulo`) para a classificação, e a
bandeira fazia **todas** as fontes saírem como `Dublado` — inclusive um release
americano do EZTV (`lanterns.2026.s01e01.1080p.web.h264-cakes[EZTVx.to].mkv`) e
um WEB-DL `ENG/ITA`. Como o idioma do provedor tem precedência dentro do
[`NormalizaFonte::montarFonte()`](../backend/app/Services/Torrents/NormalizaFonte.php:47),
a tag do próprio nome do arquivo nunca era consultada. O resultado: o `usort` de
[`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:202)
empatava tudo na mesma prioridade e o desempate caía para os **seeds** — logo, o
release com mais seeds (o EZTV) subia ao topo e tocava em inglês sob o rótulo
"Dublado".

A correção tem duas camadas, porque uma só não fecha o buraco:

1. **Classificar pelo nome do release.** A
   [`idiomaDoNome()`](../backend/app/Services/Torrents/ProvedorTorrentio.php:300)
   lê apenas o nome do arquivo (o `behaviorHints.filename` ou a primeira linha do
   rótulo), não o rótulo inteiro. O que o nome não provar, o
   `IdiomaFonte::deduzirDoTitulo()` resolve pelas tags do próprio nome (`DUAL`,
   `DUBLADO`, `PT-BR`, `NACIONAL`). Releases como o do EZTV passam a ser
   `original` e saem no corte de `TORRENTS_APENAS_PT_BR`.
2. **Porteiro de idioma em tempo real.** Mesmo com a ordenação corrigida, um
   release ambíguo pode chegar ao player. A sondagem do arquivo
   ([`temFaixaPortuguesa()`](../media-service/src/services/hls.js:301)) vira o
   fato `tem_audio_pt` no status da sessão
   ([`obterSessao()`](../media-service/src/services/sessoes.js:1343)). O frontend
   ([`aguardarFonte()`](../frontend/src/components/PlayerOverlay.vue:1449))
   compara a promessa da fonte (`idioma` = `pt-BR`/`dual`) com esse fato: se a
   fonte prometia português e o arquivo só tem áudio original, a sessão é
   descartada e o laço segue para a próxima — em vez de tocar em inglês. Quando
   nenhuma fonte passa pelo porteiro, o overlay diz "Nenhuma fonte traz áudio em
   português" em vez de um genérico "não conseguiu conectar".

#### Os trackers PT-BR nativos saem do ar com frequência — e hoje não sobrou nenhum

Vale registrar o diagnóstico que motivou a mudança acima: os dois trackers
públicos que vinham por padrão estavam **inutilizáveis** —
`torrentdosfilmes.tv` passou a servir um site de apostas (o domínio mudou de
dono) e `torrentsfilmeshd.net` não resolvia mais. O BT4G respondia `403` com o
desafio do Cloudflare, e o APIBay simplesmente não tem episódios de série (é
catálogo mundial em inglês). Ou seja: **nenhum provedor por nome estava
entregando release dublado**, e o Torrentio — único que respondia — trazia só
inglês. A lição é que a lista de trackers nativos precisa ser tratada como
volátil e revisada de tempos em tempos; o Torrentio, por ser um agregador
mantido por terceiros, é o caminho mais estável para o dublado.

Por isso [`trackers_br_urls`](../backend/config/services.php:57) nasce **vazia**:
manter um endereço morto só gasta duas requisições fadadas ao erro por termo
perguntado — são quatro por episódio. Com a lista vazia o provedor se declara
indisponível por [`disponivel()`](../backend/app/Services/Torrents/ProvedorTrackersBr.php:63)
e é pulado de forma limpa; o degrau 1 segue com APIBay, Torrentio e BT4G. Para
reativar, basta preencher `TORRENTS_TRACKERS_BR_URLS` com um domínio vivo.

#### O FlareSolverr agora socorre também a busca nativa (degrau 1)

O diagnóstico acima tem uma causa que passou batido por muito tempo: **o
FlareSolverr só servia ao Prowlarr**. O backend o cadastrava como proxy dos
indexadores do degrau 2 e pronto — os provedores nativos do degrau 1 falavam
HTTP direto. Quando o Cloudflare bloqueia, o `Http::get()` do Laravel recebe o
desafio e o provedor simplesmente devolve vazio. Foi assim que o BT4G passou a
responder `403` e os trackers PT-BR sumiram: não era o site que estava fora do
ar, era o nosso cliente que não atravessava a barreira.

A correção é o [`ClienteHttp`](../backend/app/Services/Torrents/ClienteHttp.php:1),
um cliente compartilhado que faz o que o Stremio faz por baixo dos panos — quando
a porta da frente fecha, entra-se pela dos fundos:

1. **Direto.** A requisição normal, rápida e sem custo. É o que resolve a maioria
   dos casos (APIBay e Knaben, por exemplo, quase nunca precisam de proxy).
2. **Pelo FlareSolverr.** Só quando a direta falha de um jeito que cheira a
   bloqueio: status `403`/`429`/`503` ou corpo com a marca do desafio ("Just a
   moment...", "cf-chl", "checking your browser"). O FlareSolverr abre um
   Chromium, resolve o desafio e devolve o HTML já liberado — que o cliente
   reconstrói como uma `Response` do Laravel, para o provedor continuar lendo
   `->body()` e `->json()` sem um caminho paralelo.

O socorro é **silencioso e opcional**: sem `FLARESOLVERR_URL` configurada, o
cliente se comporta exatamente como o `Http` direto de antes. Nenhuma
configuração ausente derruba a busca — no pior caso, ela volta ao comportamento
antigo. A chave liga/desliga é `TORRENTS_PROXY_NATIVO` (padrão `true`) e o teto
da chamada ao navegador é `TORRENTS_PROXY_NATIVO_TIMEOUT` (padrão `70`s, acima
do `maxTimeout` enviado ao FlareSolverr, para que o erro venha dele com
diagnóstico).

Os quatro provedores nativos por nome foram ligados ao cliente:

- [`ProvedorBt4g`](../backend/app/Services/Torrents/ProvedorBt4g.php:25) — o caso
  mais crítico: o pool direto voltava vazio com o `403` do Cloudflare e o acervo
  de DHT ficava inacessível. Agora, quando o pool inteiro falha, o socorro é
  termo a termo pelo FlareSolverr.
- [`ProvedorTrackersBr`](../backend/app/Services/Torrents/ProvedorTrackersBr.php:32)
  — os trackers PT-BR vivem atrás do Cloudflare com frequência; sem o socorro,
  um desafio virava "site fora do ar".
- [`ProvedorApibay`](../backend/app/Services/Torrents/ProvedorApibay.php:26) — o
  APIBay costuma responder direto, então o navegador só entra quando o pool
  inteiro volta vazio.
- [`ProvedorKnaben`](../backend/app/Services/Torrents/ProvedorKnaben.php:32) — o
  caminho isolado passa pelo cliente; no lote, o socorro entra quando nenhum
  termo respondeu pelo caminho direto.

O custo do socorro é o de abrir um navegador (segundos por chamada), por isso ele
é sempre a **segunda** tentativa e só dispara diante de bloqueio real — o caminho
comum continua sendo o HTTP direto.

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
- A consulta usa `t=search` e lê `seeders`/`peers`/`magneturl`/`infohash` e
  `language` de cada item; o termo vai nos dois nomes que o protocolo usa (`q` e
  `query`), porque o caminho JSON procura em `query`.
- **A rota do Prowlarr muda entre versões e não avisa quando erra.** O Prowlarr
  publica o *mesmo* acervo por caminhos diferentes — o proxy Torznab por
  indexador (`/{id}/api`), a variação Newznab
  (`/api/v1/indexer/{id}/newznab`) e a API interna
  (`/api/v1/indexer/{id}/search`, que responde **JSON**, não RSS) — além da rota
  agregada (`/api`), que busca em todos os indexadores de uma vez. Errar a rota
  **não** gera erro: a resposta volta vazia e o degrau 2 fica zerado em silêncio,
  com o release aparecendo normalmente no painel do Prowlarr. Foi assim que um
  episódio dublado passou a devolver zero mesmo com o indexador saudável. Por
  isso o
  [`TorznabService::consultarIndexador()`](../backend/app/Services/TorznabService.php:166)
  tenta as rotas em ordem e memoriza a que respondeu
  ([`rotas()`](../backend/app/Services/TorznabService.php:214)); as que respondem
  erro saem da lista pelo resto da requisição, para não custar uma ida e volta
  por termo.
- **A rota memorizada é por indexador.** O caminho carrega o id dentro dele
  (`/3/api`), então guardar uma só para todos fazia o indexador 1 ser consultado
  pelo caminho do indexador 3 e receber o **acervo errado**. O mapa é `id => rota`
  e o `Log::debug` `Rota Torznab vencedora.` diz qual caminho venceu cada consulta.
- **A primeira rota que responde 200 encerra a busca daquele indexador, mesmo
  vazia.** As candidatas são caminhos para o *mesmo* acervo: se uma respondeu sem
  falha, um corpo vazio significa "este indexador não tem o release", não "tentei
  o caminho errado". Sem essa parada, cada termo de episódio virava seis idas e
  voltas e a busca a frio passava dos 40 s; com ela são duas por termo e a mesma
  busca fecha em ~14 s.
- **O 1337x responde ora com o acervo, ora com zero.** Atrás do FlareSolverr o
  mesmo termo devolve 7 itens numa chamada e nenhum na seguinte, sem erro. Zero
  ali **não** é sinal de rota errada: é a instabilidade do tracker. Foi medido com
  o termo `American Horror Story S01E01`, que ora traz os releases gringos, ora
  nada.
- **O formato do corpo decide o leitor**, não a rota: XML é lido como Torznab e
  JSON como o `ReleaseResource` do Prowlarr
  (`seeders`/`leechers`/`magnetUrl`/`infoHash`), em
  [`itensDoCorpo()`](../backend/app/Services/TorznabService.php:316). Item que
  chega só com infohash ganha magnet montado com os anunciadores do projeto por
  [`magnetDoInfohash()`](../backend/app/Services/TorznabService.php:383).
- **Erro do protocolo vem com HTTP 200.** O Newznab devolve um `<error>` no corpo
  (chave recusada, indexador bloqueado); como o status engana, o
  [`pedir()`](../backend/app/Services/TorznabService.php:245) lê o corpo e marca a
  rota como ruim, liberando as demais — sem isso a recusa encerraria a busca
  por aquele indexador. O registro do código e da descrição fica em
  [`interpretarXml()`](../backend/app/Services/TorznabService.php:481). E quando
  **nenhuma** rota responde com sucesso sai um `Log::warning` com as rotas
  tentadas
  ([`avisarRotasEsgotadas()`](../backend/app/Services/TorznabService.php:401));
  rota viva com lista vazia é resposta legítima do indexador e não gera aviso —
  só um `Log::debug` com o começo do corpo, para distinguir corpo vazio, XML sem
  itens e formato inesperado.
- Como o backend não guarda os ids (eles nascem no provisionamento), o
  [`TorznabService`](../backend/app/Services/TorznabService.php:431) lista
  `/api/v1/indexer` e consulta cada um, agregando os resultados. Um indexador
  fora do ar não derruba os outros.
- **Não filtrar por `enable`.** Esse campo controla só se o indexador participa
  das buscas automáticas do Prowlarr, não se o endpoint Torznab dele responde.
  Como o provisionamento grava **desabilitado** quando o tracker está fora do ar
  (fallback do Cloudflare/domínio sequestrado), filtrar por `enable` deixaria a
  lista de ids vazia e o degrau 2 nunca seria consultado — exatamente o sintoma
  de "nenhuma fonte" com os indexadores já cadastrados.
- **A categoria muda com o tipo de mídia** — e isso não é detalhe. O Prowlarr
  filtra por categoria, então pedir um episódio com `cat=2000` (filmes) devolve
  **zero resultados**, por mais que o release esteja indexado. Filmes usam
  `TORRENTS_TORZNAB_CATEGORIA` (padrão `2000`) e séries usam
  `TORRENTS_TORZNAB_CATEGORIA_SERIE` (padrão `5000`). Era por isso que uma série
  vinha vazia mesmo com o indexador saudável: a busca de episódio batia na
  categoria de filme. A escolha fica em
  [`ProvedorTorznab::buscar()`](../backend/app/Services/Torrents/ProvedorTorznab.php:75)
  e o parâmetro é repassado por
  [`TorznabService::consultar()`](../backend/app/Services/TorznabService.php:76).
- São **duas consultas por filme**: uma com o termo normal (`título ano`) e outra
  com `título ano dublado`, montada por
  [`buscarDublado()`](../backend/app/Services/TorznabService.php:101). O termo base
  fica em [`termoBase()`](../backend/app/Services/TorznabService.php:112) e o
  pedido HTTP em [`consultar()`](../backend/app/Services/TorznabService.php:126).
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
4. para os trackers que recusam o teste de busca por causa do CloudFlare,
   registra antes o FlareSolverr como *Indexer Proxy* e liga o indexador a ele
   por tag (detalhes em *O 1337x vive atrás do CloudFlare*);
5. imprime a chave numa linha `PROWLARR_API_KEY=...`, que o entrypoint captura e
   grava em `TORRENTS_TORZNAB_KEY` no `.env` (e exporta para o php-fpm).

Tudo é tolerante a falha: os erros viram aviso no log e o backend sobe
normalmente. Para refazer o provisionamento sem reiniciar nada, use
`make prowlarr`.

#### Duas armadilhas que faziam o cadastro falhar

O provisionamento falhava com `Falha ao cadastrar o indexador "torrentdosfilmes"`
por dois motivos independentes, ambos silenciosos:

1. **O `PROWLARR_CONFIG_PATH` não chegava ao container.** O `ProwlarrService`
   lê esse caminho via `env()`, mas o serviço `backend` do
   [`docker-compose.yml`](../docker-compose.yml:56) não o declarava. Sem o
   caminho, o `chave()` devolvia `null` e o fluxo parava antes de tentar
   cadastrar qualquer coisa. Agora as três variáveis do Prowlarr
   (`PROWLARR_URL`, `PROWLARR_CONFIG_PATH`, `PROWLARR_TEMPO_LIMITE`) são
   repassadas explicitamente ao backend.

2. **O corpo do POST levava campos somente-leitura.** O
   [`modelosDoSchema()`](../backend/app/Services/ProwlarrService.php:363)
   devolve o modelo do `/api/v1/indexer/schema`, que inclui campos que o
   Prowlarr calcula sozinho (`infoLink`, `capabilities`, `indexerUrls`,
   `description`, `language`, `encoding`, `protocol`, `privacy`,
   `supportsRss`, `supportsSearch`, `definitionFile`…). Reenviá-los faz a API
   responder **400**. O [`cadastrarIndexador()`](../backend/app/Services/ProwlarrService.php:464)
   agora remove esses campos antes do POST e guarda o corpo da resposta de erro
   em `ultimoErro`, que aparece na mensagem do comando — sem isso, um 400 virava
   só "falha ao cadastrar", sem pista do campo recusado.

#### O cadastro não pode depender do teste de busca

O Prowlarr roda uma busca de validação ao cadastrar um indexador **habilitado** e
**rejeita o cadastro** se ela voltar vazia, com a mensagem *"Query successful, but
no results were returned from your indexer"*. Isso é fatal para trackers públicos,
que caem com frequência: o indexador nunca é gravado e o degrau 2 fica
permanentemente vazio.

A primeira tentativa é o caminho normal: POST habilitado com `forceSave: true` no
**corpo** do JSON. O detalhe que custou uma rodada de depuração é que `forceSave`
como *query string* (`?forceSave=true`) é ignorado em silêncio — o Prowlarr roda a
validação do mesmo jeito. O campo precisa ir no corpo.

Como nem todo tracker honra o `forceSave`, o
[`cadastrarIndexador()`](../backend/app/Services/ProwlarrService.php:464) tem uma
segunda tentativa: grava o indexador **desabilitado** (`enable: false`). Sem
`enable`, o Prowlarr não dispara o teste de busca e aceita a definição mesmo com o
site fora do ar. Em seguida o
[`habilitarIndexador()`](../backend/app/Services/ProwlarrService.php:542) faz um
`PUT` com o objeto atual (buscado antes para não perder os campos que o Prowlarr
preencheu sozinho) marcando `enable: true`. Se a habilitação falhar, o indexador
continua cadastrado — só inativo —, o que ainda é melhor do que perder a
definição. O cadastro sobrevive à queda e volta a funcionar sozinho quando o site
retorna.

Só que o `PUT` de habilitação **também** roda o teste de busca. Para um tracker
atrás do CloudFlare, então, a segunda tentativa grava o indexador e a terceira o
deixa exatamente onde estava: desabilitado. É o caso do 1337x, e é o assunto da
subseção abaixo.

#### O 1337x vive atrás do CloudFlare

O painel do Prowlarr acusa `Unable to access 1337x.to, blocked by CloudFlare
Protection`. Não é defeito de cadastro: o CloudFlare devolve ao Prowlarr a página
do desafio (*"Just a moment…"*) em vez do HTML da busca, e qualquer teste de
indexador morre ali. O `forceSave` não salva, porque não há resultado algum a
forçar.

A saída suportada é o **FlareSolverr**: um serviço que abre um navegador headless,
resolve o desafio e devolve os cookies da sessão liberada. O Prowlarr o consome
como *Indexer Proxy* e o associa aos indexadores por **tag** — todo indexador que
carregue a mesma tag sai pelo proxy.

O arranjo é automático, como o resto do provisionamento:

- o [`docker-compose.yml`](../docker-compose.yml:248) sobe o serviço
  `flaresolverr` na rede interna, sem porta publicada no host: só o Prowlarr fala
  com ele;
- antes de cadastrar os indexadores, o
  [`provisionarProxy()`](../backend/app/Services/ProwlarrService.php:616) cria a
  tag, lê o modelo do proxy em `/api/v1/indexerproxy/schema` e o registra
  apontando para `FLARESOLVERR_URL`;
- o Prowlarr **testa a conexão toda vez que grava o proxy**, inclusive num `PUT`.
  Por isso o provisionamento não reenvia um proxy que já aponta para o mesmo
  endereço: sem nada a mudar, o registro existente basta. Só quando
  `FLARESOLVERR_URL` muda é que ele apaga o antigo e recria — reaproveitando o
  caminho do primeiro cadastro, que é o que funciona. Sem esse cuidado, um
  FlareSolverr que ainda estivesse subindo derrubava o provisionamento inteiro e
  o proxy ficava "indisponível" sem que houvesse nada de errado;
- o backend espera o FlareSolverr ficar **saudável** antes de subir
  (`depends_on: condition: service_healthy`), para não tentar gravar o proxy
  enquanto o Chromium ainda está abrindo;
- os indexadores listados em `proxy_indexadores`
  ([`config/services.php`](../backend/config/services.php:189) — hoje o `1337x` e
  o `torrentgalaxy`) recebem a tag já no corpo do cadastro, e aí o teste de busca
  passa dentro do `forceSave`, com o indexador **nascendo ativo**;
- quem já tem o 1337x gravado desabilitado (stack subido antes desta mudança) é
  resgatado pelo
  [`ajustarIndexadorComProxy()`](../backend/app/Services/ProwlarrService.php:885),
  que reaplica a tag e religa o indexador. O provisionamento pula o que já existe
  por definição, então sem esse passo ele nunca seria testado de novo. Já quando o
  indexador carrega a tag do proxy e está habilitado, ele sai sem gravar: o `PUT`
  reexecutaria o teste de busca inteiro — desafio do CloudFlare incluso — para não
  mudar nada;
- a tag do proxy é **vínculo, não rótulo**: o Prowlarr só encaminha pelo
  FlareSolverr os indexadores que a carregam. E as tags de um indexador são
  **cumulativas** — quem quiser marcar o 1337x com uma tag própria precisa
  **somar** à existente, nunca trocá-la. Trocada, o proxy deixa de valer e o
  `blocked by CloudFlare Protection` volta na hora, o que faz parecer que a
  integração pifou. Por isso o provisionamento **mescla** as tags em vez de
  substituir a lista: as do usuário continuam de pé, e a do proxy é reposta mesmo
  que alguém a apague pelo painel;
- essas tags não filtram idioma nenhum. "Só dublado" é decisão do backend
  (`TORRENTS_APENAS_PT_BR` e `TermosBusca::paraDublado()`), não do Prowlarr:
  marcar o 1337x com `dublado` não muda um único resultado;
- salvar um indexador roda o teste de busca, e a primeira passada pelo FlareSolverr
  ainda sobe um navegador: essas gravações usam um teto de 90s
  (`TEMPO_LIMITE_TESTE`), em vez do tempo limite padrão de 20s. Sem essa folga o
  `PUT` estourava por timeout, o indexador continuava inativo e nada aparecia no
  log.
- o primeiro acesso a um domínio protegido pode estourar o teto de 60s do próprio
  FlareSolverr (`Timeout after 60.0 seconds`) sem que haja nada errado: o cookie
  obtido fica em cache dentro dele, e o caminho de busca que o Prowlarr usa o
  reaproveita. Um `POST` manual a `https://1337x.to/` costuma estourar no exato
  momento em que o teste de busca do indexador passa.
- o FlareSolverr é o segundo container a **resolver domínios por conta própria** —
  quem consulta o DNS é o Chromium lá dentro. Por isso ele recebe o mesmo bloco
  `dns:` do Prowlarr, e o `DNS_OVER_HTTPS` ligado
  ([`docker-compose.yml`](../docker-compose.yml:257)): o resolver do operador
  bloqueia o 1337x, e a falha chega disfarçada de duas formas. Numa, o Chromium
  não resolve e o FlareSolverr devolve `500` com
  `net::ERR_NAME_NOT_RESOLVED`, que o Prowlarr reporta como *"HTTP request
  failed: [500:InternalServerError] ... /v1"*. Na outra, o resolver devolve uma
  página de bloqueio: **não há desafio do CloudFlare**, o FlareSolverr responde
  `200` com `Challenge not detected!` e sem cookies, e o Prowlarr recusa com
  *"Empty cookies returned by FlareSolverr"* — mensagem que parece culpa do
  proxy, mas era DNS. O `DNS_OVER_HTTPS` cobre o caso em que o provedor
  intercepta até o UDP/53;
- o Chromium embutido precisa de `/dev/shm` com folga: o padrão de 64 MB do
  Docker o mata ao subir, e o FlareSolverr passa a devolver `500` em toda
  requisição. Daí o `shm_size: "1gb"`
  ([`docker-compose.yml`](../docker-compose.yml:263)).

O `torrentdosfilmes` fica **fora** dessa lista de propósito: o problema dele não é
desafio do CloudFlare, e sim o domínio sequestrado — não há proxy que resolva
isso.

Para desligar o proxy, basta `PROWLARR_PROXY_ATIVO=false` no `.env`: os
indexadores barrados voltam a ser gravados desabilitados, sem erro fatal e sem
derrubar o degrau 2.

#### O domínio PT-BR foi sequestrado

Em 2026-09, `torrentdosfilmes.tv` deixou de ser tracker: o domínio foi
sequestrado e hoje serve um site de apostas (*"377bet Casino"*). O
`torrentsfilmeshd.net` também não resolve mais.

Sem tracker PT-BR público ativo, o degrau 2 passou a depender de definições
oficiais do Prowlarr (que não precisam de `.yml` próprio). Hoje são três, em
`PROWLARR_INDEXADORES`: o `1337x` (acervo amplo e de longa data), o
`thepiratebay` e o `torrentgalaxy` — os dois últimos entraram porque também
publicam release marcado como dublado/dual áudio e são de acesso livre, sem
convite. O `1337x` e o `torrentgalaxy` vivem atrás do CloudFlare, e é o proxy
FlareSolverr descrito acima que os tira de inativo; o `thepiratebay` vai direto.
O proxy não é por causa do domínio, e sim do CloudFlare.

O `torrentdosfilmes` saiu da lista `indexadores` em
[`config/services.php`](../backend/config/services.php:176). Enquanto estava
nela, o provisionamento o recadastrava a cada subida — inclusive depois de
alguém removê-lo no painel, porque o comando só sabe **somar** indexador, nunca
podar. Manter um endereço sequestrado na lista custava consulta em vão a cada
termo perguntado (são quatro por episódio).

### Definição customizada de indexador público PT-BR (arquivada)

O Prowlarr não traz nenhum tracker brasileiro **público** de fábrica — os que
vêm embutidos (`amigosshare`, `bjshare`, `brasiltracker`, `capybarabr`,
`locadora`, `mdan`, `samaritano`, `shakaw`) são todos privados e exigem conta e
convite. Para ter uma fonte pública em PT-BR, o projeto versiona uma definição
própria:

- Arquivo: [`docker/prowlarr/Definitions/Custom/torrentdosfilmes.yml`](../docker/prowlarr/Definitions/Custom/torrentdosfilmes.yml:1).
- O [`docker-compose.yml`](../docker-compose.yml:204) monta essa pasta em
  `/config/Definitions/Custom/` dentro do container, então a definição é
  versionada com o código e sobrevive a recriações.
- **Ela está fora do provisionamento.** Ter o `.yml` na pasta só a deixa
  disponível no painel; quem manda no cadastro automático é a lista `indexadores`
  de [`config/services.php`](../backend/config/services.php:176). Hoje essa lista
  traz o `1337x`, o `thepiratebay` e o `torrentgalaxy`. Quando o domínio do
  tracker voltar, inclua `torrentdosfilmes` nela — e só então o Prowlarr o
  cadastra. Para somar outro tracker público, basta soltar o `.yml` na mesma
  pasta e incluir o id na lista.
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

### Indexador de canais do Telegram (revogado)

O projeto chegou a operar, **fora do versionamento**, um serviço próprio de
indexação: o `telegram-indexer`, em Python com Telethon, lia canais públicos de
séries em PT-BR e publicava o acervo como um feed Torznab, que o Prowlarr
cadastrava como mais um indexador. A abordagem foi **revogada em definitivo** —
não há mais canais de séries confiáveis e não há perspectiva de voltarem.

- O serviço, seu `Dockerfile` e o volume `telegram_data` saíram do disco; as
  variáveis `TELEGRAM_*` saíram dos `.env` locais. Não há commit desse código: a
  árvore versionada não tem nenhuma referência a Telegram.
- As credenciais do Telegram (`api_id`, `api_hash` e a sessão) foram revogadas.
- No Prowlarr pode restar a entrada órfã do indexador criado à época; ela é
  removível no painel e nenhum código do projeto depende dela.
- O provisionamento do Prowlarr segue restrito aos trackers públicos (seção
  acima) e não conhece mais nenhum indexador Torznab próprio.

### Prioridade de idioma

O usuário quer o filme **dublado em PT-BR**. A busca **coleta** fontes em duas
pilhas — as que provam áudio PT-BR e as de reserva (idioma original ou legendado) —
e só no fim monta a lista. Com o corte duro ligado (o padrão), a lista final é só a
pilha PT-BR, cortada no teto de `LIMITE_FONTES`; a reserva só entra quando não há
nenhuma fonte PT-BR.

O idioma é deduzido por **dois caminhos**, em ordem de confiança:

1. **Atributo do indexador** — alguns trackers devolvem o idioma num atributo
   `torznab:attr name="language"`. O
   [`TorznabService`](../backend/app/Services/TorznabService.php:150) repassa o
   valor cru em `idioma` e o
   [`IdiomaFonte::deduzirDoIdioma()`](../backend/app/Enums/IdiomaFonte.php:113) o
   interpreta, reconhecendo códigos como `pt`, `pt-br`, `por` e `portuguese`.
2. **Tags no título** — quando o atributo não existe ou não é reconhecido, cai
   em [`IdiomaFonte::deduzirDoTitulo()`](../backend/app/Enums/IdiomaFonte.php:74).

#### A lista de indícios de áudio PT-BR é única

Antes, cada canto do código tinha a sua própria noção de "dublado", e isso produzia
divergências: o backend reconhecia `dual`, o detector do pack não; o detector do
media-service reconhecia a bandeira 🇧🇷, o do backend não. A lista agora mora num
lugar só — [`IndiciosPtBr`](../backend/app/Support/IndiciosPtBr.php:1) no backend e
[`contemIndicioPtBr()`](../media-service/src/utils/idiomas.js:184) no media-service —
e é a mesma em toda parte.

Basta **um** indício para provar áudio PT-BR; não precisa ter todos:

| Indício | Exemplo |
| --- | --- |
| `dublado`, `dublada`, `dublagem` | `AHS S01 Dublado 720p` |
| `dual` / `dual audio` | `Dual Áudio By-LuanHarper` |
| `multi áudio` / `multi audio` | `Multi Áudio 1080p` |
| `duplo áudio` / `duplo audio` | `Duplo Áudio 720p` |
| `nacional` / `brasileiro` | `Nacional` |
| `português` / `portuguese` / `pt` | `PORTUGUÊS BR`, `[pt-br]` |
| `brasil` / `brazil` / `brazilian` | `Brasil` |
| emoji 🇧🇷 | `🇧🇷 1080p` |

O `pt` só vale como **palavra inteira** — `720p` não conta, e é por isso que a
regra exige borda de palavra.

#### Legendado sem áudio PT-BR é reserva, não dublado

Um release `Legendado pt BR` traz a marca de português, mas o áudio é o original e
as legendas é que são PT-BR. Ele **não** entra na pilha PT-BR boa: cai na reserva.
É o que separa `Clube da Luta 1999 Bluray 720p Legendado pt BR` das fontes que o
usuário de fato quer ouvir.

#### O corte duro de idioma: só áudio PT-BR provado

O `apenas_pt_br` decide *quanto* da reserva entra para completar o mínimo — mas a
reserva ainda carrega original e legendado, e a lista acabava cheia de releases que
o usuário brasileiro não aproveita. A chave
[`somente_pt_br_ou_legendado`](../backend/config/services.php:249) fecha essa porta:
com ela ligada, a montagem final entrega só o **áudio PT-BR provado**. Sobram
dublado e dual áudio; o original em inglês e o legendado (áudio original com legenda
PT-BR) somem.

O legendado cai junto com o original de propósito: o usuário quer **ouvir** em
português, não ler. Um release `Legendado pt BR` tem áudio original e legenda
PT-BR — serve para quem aceita ler, mas não é o que a lista deve oferecer quando o
critério é áudio nacional.

**O corte só vale se sobrar fonte PT-BR.** A montagem primeiro separa as pilhas e
só então decide: se a pilha PT-BR tem ao menos uma fonte, a lista é só ela; se está
**vazia**, o corte é revertido e a reserva volta. Uma lista vazia não é "só PT-BR",
é o player sem nada para tentar — e aí o sistema se comporta como antes, entregando
o que existe, mesmo que seja legendado ou original. É a diferença entre *preferir*
áudio nacional e *exigir* o que talvez não exista.

**Não há preenchimento com reserva.** Quando as PT-BR acabam, a lista para — mesmo
que o teto de `LIMITE_FONTES` (`4`) não tenha sido alcançado. A reserva não completa
a lista até o teto: ela só entra quando a pilha PT-BR está vazia. Uma série com duas
dubladas volta com duas fontes, não com duas dubladas mais duas legendadas.

A diferença entre as duas chaves é de natureza, não de grau:

- `apenas_pt_br` é sobre **quanto** de reserva entra. Ele não descarta nada: só
  decide se a reserva completa a lista quando a pilha boa não alcança o piso.
- `somente_pt_br_ou_legendado` é sobre **se** a reserva entra. Ele é um corte
  duro, aplicado no [`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:271)
  depois da separação das pilhas e antes da exceção de pack — um pack em inglês não
  escapa pela porta do `packs_qualquer_idioma`, que existe para o pack nacional sem
  marca de dublagem, não para ressuscitar o original.

O corte é por **prova de áudio PT-BR**: dublado e dual passam; o que não prova —
original, legendado, idioma vazio ou desconhecido — cai. A leitura é pelo
[`ePtBr()`](../backend/app/Services/TorrentService.php:410). Desligue a chave para
trazer a reserva de volta ao fim da lista mesmo quando há fonte PT-BR.

#### A cascata coleta com orçamento, não para no primeiro acerto

O critério de parada antigo (`temDublado()`) encerrava a busca assim que o primeiro
resultado PT-BR aparecia e completava o resto com idioma original — o usuário pedia
dublado e recebia uma dublada em 1º e originais em 2º a 20º. A cascata agora
**acumula** até juntar um orçamento de fontes PT-BR
([`coletaSuficiente()`](../backend/app/Services/Torrents/CatalogoProvedores.php:298),
contra `TORRENTS_META_PT_BR`, padrão `6`) ou esgotar termos e degraus.

Cada fonte que passa pelo filtro de numeração é **etiquetada**, não descartada, em
[`aproveitaveis()`](../backend/app/Services/Torrents/CatalogoProvedores.php:571):
ganha `pt_br` (entra na pilha boa) ou vira reserva. Nada de aproveitável some por
idioma; a decisão de ordem fica para o fim.

#### O orçamento da busca inteira: um relógio só para os três degraus

O orçamento de coleta acima decide **quando parar de procurar**; este decide
**quanto tempo a busca pode durar**. São coisas diferentes, e a falta da segunda
foi o que fez o `/fontes` de um episódio estourar o tempo do frontend.

Cada degrau já tinha o seu teto — `TORRENTS_TEMPO_LIMITE` por requisição,
`TORRENTS_PROXY_NATIVO_TIMEOUT` para o socorro pelo FlareSolverr e
`TORRENTS_TORZNAB_ORCAMENTO` para o indexador —, mas os tetos não conversavam
entre si. Um provedor bloqueado custa a tentativa direta (até 15 s) **mais** o
socorro pelo FlareSolverr (até 70 s), e isso se repete a cada termo do episódio.
Como a cascata é sequencial, o pior caso é a **soma** de tudo — e essa soma não
tinha limite nenhum. Com os termos de episódio passando de dez e quatro provedores
por nome, o `/fontes` ultrapassava os 60 s de `TIMEOUT_REQUISICAO_MS` e a
requisição era cancelada antes de a lista chegar à tela.

O [`OrcamentoBusca`](../backend/app/Services/Torrents/OrcamentoBusca.php:1) é o
relógio único que a busca inteira enxerga. Ele é registrado como **singleton** no
[`AppServiceProvider`](../backend/app/Providers/AppServiceProvider.php:13) de
propósito: o catálogo e o cliente HTTP precisam ver o **mesmo** prazo, senão o
socorro pelo FlareSolverr começaria uma espera de 70 s a poucos segundos do fim.

O ciclo é curto:

1. [`CatalogoProvedores::buscar()`](../backend/app/Services/Torrents/CatalogoProvedores.php:196)
   abre o orçamento com `TORRENTS_ORCAMENTO_BUSCA` (padrão `45`).
2. Antes de cada termo, antes de cada degrau **e antes de cada provedor dentro de
   uma rodada**, a cascata consulta
   [`orcamentoEsgotado()`](../backend/app/Services/Torrents/CatalogoProvedores.php:365);
   ao estourar, para onde está e devolve o que já recolheu. A checagem dentro da
   rodada é o que fecha a última brecha: sem ela, uma rodada iniciada a segundos do
   fim ainda percorria todos os provedores restantes, e a soma deles estourava o
   tempo do frontend mesmo com o orçamento global em 45 s.
3. O [`ClienteHttp`](../backend/app/Services/Torrents/ClienteHttp.php:250) limita o
   socorro pelo FlareSolverr ao que resta do orçamento — um `maxTimeout` de 70 s
   não pode começar quando faltam 3 s. O mesmo corte vale para a **tentativa
   direta**: [`tempoDisponivel()`](../backend/app/Services/Torrents/ClienteHttp.php:165)
   encolhe o `tempo_limite` de cada requisição ao que sobra, e uma requisição que
   já nasce fora do prazo nem começa.
4. O [`TorznabService`](../backend/app/Services/TorznabService.php:116) também
   enxerga o relógio: o prazo do degrau passa a ser o menor entre o próprio
   (`TORRENTS_TORZNAB_ORCAMENTO`) e o que resta da busca inteira.
5. [`encerrar()`](../backend/app/Services/Torrents/CatalogoProvedores.php:549)
   fecha o orçamento. É o único ponto por onde todos os desfechos passam; deixá-lo
   de pé faria a próxima busca herdar um relógio já vencido.

##### O orçamento precisa alcançar também os provedores de pool

O relógio só vale se **todos** os provedores o enxergarem. O `ClienteHttp` cobre
quem faz requisição por ele — o [`ProvedorTrackersBr`](../backend/app/Services/Torrents/ProvedorTrackersBr.php:344)
é o caso —, mas os provedores que disparam vários termos de uma vez com
`Http::pool` montavam o próprio `PendingRequest` e nunca liam o prazo. Eram eles:
[`ProvedorKnaben`](../backend/app/Services/Torrents/ProvedorKnaben.php:103),
[`ProvedorApibay`](../backend/app/Services/Torrents/ProvedorApibay.php:148),
[`ProvedorBt4g`](../backend/app/Services/Torrents/ProvedorBt4g.php:119),
[`ProvedorTorrentio`](../backend/app/Services/Torrents/ProvedorTorrentio.php:53) e
[`ProvedorAddonStremio`](../backend/app/Services/Torrents/ProvedorAddonStremio.php:57).
O sintoma era o pior possível: a cascata achava que o prazo tinha acabado e
encerrava, enquanto um pool aberto segundos antes ainda esperava os 15 s cheios
por termo — o tempo gasto não voltava e a lista saía curta.

A ponte é a trait [`ConsultaComOrcamento`](../backend/app/Services/Torrents/ConsultaComOrcamento.php:1),
que dá a esses provedores duas leituras do mesmo relógio:

- [`tempoDeConsulta()`](../backend/app/Services/Torrents/ConsultaComOrcamento.php:22)
  devolve o **menor** entre o teto do provedor e o que resta do orçamento. É esse
  número que vira o `timeout` do pool. Um provedor que já nasce fora do prazo
  devolve `0` e nem abre a conexão.
- [`temOrcamento()`](../backend/app/Services/Torrents/ConsultaComOrcamento.php:40)
  responde se ainda cabe tentar — usado no socorro termo a termo do Knaben pelo
  FlareSolverr, que é caro e não pode começar a segundos do fim.

O `OrcamentoBusca` é injetado no construtor de cada um desses provedores. Como é
singleton, todos leem o **mesmo** relógio que o `CatalogoProvedores` abriu.

##### A rodada de abertura: os primários por nome correm junto com os por identificador

Fechar o orçamento não bastava. A cascata consultava os provedores **por
identificador** (Torrentio, addons Stremio) sozinhos no primeiro termo e só
depois checava `coletaSuficiente()`. Como o Torrentio responde rápido e costuma
trazer PT-BR, a meta batia e a busca encerrava **antes** de perguntar aos
primários por nome — e o Knaben, que é justamente quem enxerga os packs nacionais
das séries antigas, nunca era consultado. O sintoma que o usuário relatou foi
exato: "achou 3 fontes, dubladas, mas todas do Torrentio; antes vinha do Knaben
também".

A correção tem duas partes, em
[`buscar()`](../backend/app/Services/Torrents/CatalogoProvedores.php:196):

1. O primeiro termo virou uma **rodada de abertura** que consulta os dois grupos
   antes de qualquer corte. Os primários por nome vêm **primeiro**, e não por
   acaso: o [`jaTemEpisodioAproveitado()`](../backend/app/Services/Torrents/CatalogoProvedores.php:690)
   lê o censo **inteiro**, então se o Torrentio rodasse antes e marcasse uma
   fonte, os termos de pack/série dos primários seriam pulados na mesma rodada.
   Rodando os primários primeiro, o Knaben recebe o degrau completo antes de
   qualquer contagem.
2. O [`buscarGrupo()`](../backend/app/Services/Torrents/CatalogoProvedores.php:789)
   ganhou o parâmetro `$dispensarSocorro`. Na abertura ele vai `true` para os
   primários: mesmo que um deles já tenha achado episódio, os demais ainda
   recebem os termos de pack/série, porque é a **única** chance que têm de achar
   o pack. Nos termos seguintes o parâmetro fica `false` e o corte de economia
   volta a valer — quem tem episódio vivo não precisa do pack.

O laço de termos começa em `array_slice($titulos, 1)`: o primeiro já foi coberto
pela abertura. Depois do laço, um `coletaSuficiente()` decide se vale descer ao
indexador — o corte que antes acontecia dentro do laço passa a valer só depois de
todos os primários terem respondido.

Ao estourar, sai uma linha de aviso com o degrau onde a busca parou:

```text
Orçamento da busca de torrents esgotado; devolvendo o que foi recolhido. {"degrau":"nativos","orcamento":45}
```

O valor é o teto que manda: os orçamentos por degrau (`TORRENTS_TORZNAB_ORCAMENTO`,
por exemplo) só apertam **dentro** dele. Ajuste `TORRENTS_ORCAMENTO_BUSCA` para
baixo se o frontend reclamar de lentidão, ou para cima se a lista vier curta demais
por corte de tempo — mas mantenha-o abaixo de `TIMEOUT_REQUISICAO_MS` (`60000` no
frontend), senão o corte acontece do lado de lá e a lista se perde.

##### O socorro do FlareSolverr não pode ser termo a termo

O orçamento explicava o corte, mas não o **tamanho** da espera. A requisição de
`/fontes` ainda levava ~47 s mesmo com o relógio em 45 s, e a cobertura mostrava
onde o tempo morria:

```text
bt4g: sem_resultado, 1 consulta, ms=30501
knaben: com_fonte, 1 consulta, ms=15030
```

Os dois provedores que usam `Http::pool` gastavam, sozinhos, mais que o orçamento
inteiro. A causa não estava no pool — um teste direto devolvia `403` em 0,26 s —,
mas no **socorro pelo FlareSolverr**, que rodava **termo a termo**. Cada chamada
ao navegador custa de 12 a 17 s, e o BT4G multiplicava isso por três termos.

Pior: o socorro era inútil. O `403` do BT4G é o Cloudflare barrando o acesso
direto, e o navegador do FlareSolverr esbarra no **mesmo** desafio — nos testes
ele devolveu `500` depois de ~12 s. Ou seja, pagava-se o preço do navegador para
colher exatamente o mesmo bloqueio.

A correção separa dois cenários que antes eram tratados como um só, em
[`ProvedorBt4g::baixar()`](../backend/app/Services/Torrents/ProvedorBt4g.php:119)
e [`ProvedorKnaben::buscarVarios()`](../backend/app/Services/Torrents/ProvedorKnaben.php:103):

- **Chegou resposta HTTP** — inclusive um `403` — significa que o espelho está de
  pé e quem barrou foi o Cloudflare. O FlareSolverr não passa por ele, então o
  socorro é **pulado**. É o que a flag `$respondeu` registra.
- **Nenhuma resposta chegou** aponta para bloqueio de rede, que o navegador
  **pode** contornar. Só aí o FlareSolverr entra — e com **um único termo**
  (`$termos[0]`), não com a lista inteira.

```php
$respondeu = true; // qualquer resposta HTTP prova que o espelho está de pé

if ($htmls === [] && ! $respondeu && $this->cliente->proxyDisponivel()) {
    // só aqui vale abrir o navegador — e só para o primeiro termo
}
```

O resultado da mudança foi imediato: a mesma busca caiu de **46,7 s para 23,1 s**,
com o BT4G respondendo em 696 ms (quatro consultas) e o Knaben em 2,2 s — e as
fontes continuaram chegando. O tempo que sobra agora é do Torznab (8,8 s), que é
o degrau caro por natureza e já respeita o orçamento.

##### A busca percorre todos os provedores, e não para na primeira meta

O corte por meta tinha um efeito colateral que só apareceu no uso real: assim que
a cascata juntava `meta_pt_br_coleta` fontes PT-BR, ela **encerrava** — e os
provedores que ainda não tinham sido perguntados ficavam de fora. O sintoma era
uma lista inteira vinda de um provedor só, quando outros tinham material que
nunca chegou a ser consultado.

O comportamento padrão passou a ser o de **percorrer o registro inteiro**. A
chave é [`buscar_todos`](../backend/config/services.php:353)
(`TORRENTS_BUSCAR_TODOS`, ligada por padrão), lida pelo helper
[`buscarTodos()`](../backend/app/Services/Torrents/CatalogoProvedores.php:700).
Com ela ligada, a meta PT-BR deixa de encerrar a busca: ela só marca o ponto em
que não vale mais insistir nos termos restantes do degrau atual. Quem decide
quando parar é o orçamento.

O que faz a busca **avançar** de um provedor para o outro é o
[`suficientePorProvedor()`](../backend/app/Services/Torrents/CatalogoProvedores.php:753):
um provedor que já rendeu `fontes_suficientes_por_provedor` fontes (padrão 4) é
deixado de lado — não por ter falhado, mas por já ter dado o que tinha —, e a vez
passa ao próximo. Quem não tem fonte ou está indisponível é pulado na hora, sem
consumir o orçamento.

O efeito prático, medido na busca de AHS S01E02:

```text
torrentio      : com_fonte         | 1 consulta  |  333 ms
addon_stremio  : barrado_no_filtro | 1 consulta  |  563 ms
trackers_br    : sem_credencial    | 0 consultas |    0 ms
apibay         : com_fonte         | 1 consulta  | 3588 ms
knaben         : com_fonte         | 1 consulta  | 1569 ms
bt4g           : sem_resultado     | 4 consultas | 1606 ms
torznab        : sem_resultado     | 4 consultas | 8094 ms
yts            : sem_resultado     | 1 consulta  |    0 ms
```

Nenhum provedor ficou `nao_consultado`: os oito foram visitados. O preço é a
latência — a busca fria leva ~27 s em vez de encerrar cedo —, e por isso o
orçamento global continua sendo o freio. Se precisar do comportamento antigo, de
resposta rápida com o primeiro acerto, basta desligar `TORRENTS_BUSCAR_TODOS`.

#### A montagem final: idioma manda, pack desempata dentro do idioma

A lista final sai de
[`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:271), que
separa as fontes em duas pilhas — **pilha boa** e **reserva** — e ordena as duas
pela mesma chave de mérito.

A pilha boa é só áudio PT-BR **provado**: dublado e dual áudio. A reserva é todo o
resto — legendado, original e os packs de idioma não provado.

Com `TORRENTS_APENAS_PT_BR` ligado (o padrão), a lista final é **só a pilha boa**,
cortada no teto de `LIMITE_FONTES` (`4`). Não há preenchimento com reserva: quando
as PT-BR acabam, a lista para. O teto é curto de propósito — o usuário quer poucas
opções boas, não um catálogo — e pode voltar com menos de quatro, ou até vazia, se
o provedor não tiver áudio PT-BR provado. A reserva só entra quando **não existe
nenhuma** fonte PT-BR: aí a lista devolve o que houver, para o player não ficar sem
alternativa nenhuma.

Esse corte é o que faz o censo bater com a realidade. Antes, o APIBay aparecia como
`com_fonte` porque a cascata contava as fontes *antes* da montagem; a montagem
descartava todas (só original, sem PT-BR) e o relatório mentia. Agora o censo é
reconciliado com a lista que de fato saiu — ver
[`reconciliarCenso()`](../backend/app/Services/Torrents/CatalogoProvedores.php:544)
e a situação `descartado_na_montagem` mais abaixo.

A ordem dentro de cada pilha vem de uma chave única,
[`chaveDeOrdem()`](../backend/app/Services/TorrentService.php:389):
**`[idioma, pack, provedor, -seeds]`**. Ela diz a regra em uma linha:

1. **Idioma manda.** Dublado (0), dual (1), legendado (2) e original (3) saem nessa
   faixa, nunca intercalados. Como a reserva só entra depois da pilha boa,
   **nenhum original aparece antes de um dublado ou dual**.
2. **O pack desempata dentro do idioma.** Dentro da mesma faixa, episódio vem antes
   de pack. Assim um pack dublado fica atrás dos episódios dublados, mas ainda à
   frente de um episódio em inglês — e o pack do Torrentio deixa de encobrir os
   episódios do Knaben, do TPB+ e do APIBay.
3. **Provedor em bloco.** Dentro de `(idioma, pack)`, cada provedor fica junto, na
   ordem em que a cascata o consulta
   ([`ordemDeProvedores()`](../backend/app/Services/TorrentService.php:366)):
   Torrentio, addon Stremio, nativos, Torznab e YTS.
4. **Seeds fecham.** Dentro do bloco, mais seeds primeiro.

O pack de idioma não provado **não conta** para o mínimo: ele sobrevive ao corte
de idioma por causa de `TORRENTS_PACKS_QUALQUER_IDIOMA`, mas entra pela reserva e
aparece no fim, depois dos episódios de qualquer idioma. Como a pilha boa tem
prioridade absoluta, um dublado de 1 seed entra na frente de um WEB-DL de 70 seeds
— sem que os originais mais "populares" empurrem a dublada para fora.

Quando o mesmo torrent chega por mais de um provedor, fica a leitura de **melhor
áudio** — dublado antes de dual, dual antes de original —, não a do provedor que
respondeu primeiro: quem classifica é o áudio do arquivo, não o provedor. A marca
de pack, por ser propriedade do torrent e não da leitura, sobrevive à troca.

Com `TORRENTS_APENAS_PT_BR=false`, a reserva inteira também pode entrar — sem tocar
em código.

Cada etapa deixa uma linha em
[`CatalogoProvedores::registrarEtapa()`](../backend/app/Services/Torrents/CatalogoProvedores.php:315):

```text
Etapa da cascata de torrents concluída. {"degrau":"indexador","titulo":"American Horror Story S01E01 dublado","fontes":2,"pt_br":2,"reserva":0,"encerra":true}
```

`degrau`, `titulo`, `fontes`, `pt_br`, `reserva` e `encerra` contam a história da
busca sem instrumentar nada depois: se a etapa parou com `pt_br: 2` e `encerra:
true`, o orçamento foi atingido; se parou com `pt_br: 0` e `reserva: 12`, os termos
renderam fonte, mas nenhuma dublada.

Como o conjunto e o próprio texto das entradas cacheadas mudaram, a `VERSAO_CACHE`
do [`CatalogoProvedores`](../backend/app/Services/Torrents/CatalogoProvedores.php:49)
subiu para `12`.

### De onde veio a fonte?

Cada item devolvido por `GET /api/filmes/{id}/fontes` carrega dois campos que
identificam a origem:

| Campo | Valores | Significado |
| --- | --- | --- |
| `provedor` | `trackers_br` \| `apibay` \| `bt4g` \| `torrentio` \| `torznab` \| `yts` | identificador estável, para lógica |
| `provedor_rotulo` | `Tracker PT-BR` \| `APIBay` \| `BT4G` \| `Torrentio` \| `Indexador (Torznab)` \| `YTS` | rótulo para exibição |

Os três primeiros formam o degrau 1 (busca por nome), o `torrentio` entra pelo
`imdb_id` e o `torznab` é o degrau 2; o `yts` é a reserva em inglês.

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

#### O censo não pode mentir: `descartado_na_montagem`

A cobertura de provedores (`GET /api/filmes/{id}/fontes/cobertura`) conta, por
provedor, quantas fontes passaram pelo gate da cascata (`aproveitadas`) e quantas
de fato chegaram à lista final (`na_lista`). A situação de cada provedor sai de
[`situacaoDoCenso()`](../backend/app/Services/Torrents/CatalogoProvedores.php:637):

| Situação | Quando acontece |
| --- | --- |
| `com_fonte` | passou pelo gate **e** tem pelo menos uma fonte na lista final |
| `descartado_na_montagem` | passou pelo gate, mas a montagem descartou todas |
| `barrado_no_filtro` | nada passou pelo gate da cascata |
| `sem_resultado` | o provedor respondeu, mas sem resultado para o título |
| `nao_consultado` | o orçamento acabou antes de visitá-lo |

O caso `descartado_na_montagem` é o que fecha o furo do APIBay. Ele trazia 15
fontes para "American Horror Story" S01E01 — todas originais em inglês, com seeds
reais — e o censo antigo o marcava como `com_fonte`, porque contava as fontes
*antes* da montagem. A montagem, que só aceita áudio PT-BR provado, descartava
todas, e o relatório dizia que havia fonte onde a lista estava vazia.

A correção é
[`reconciliarCenso()`](../backend/app/Services/Torrents/CatalogoProvedores.php:544),
chamada por [`TorrentService::fontes()`](../backend/app/Services/TorrentService.php:45)
depois de `ordenar()`: ela zera `na_lista` e recontá-lo a partir da lista que
realmente saiu. Assim o censo passa a refletir a lista, e não a cascata — um
provedor só é `com_fonte` se a fonte dele sobreviveu até o fim.

#### O `👤 0` do Torrentio não significa "fonte morta"

O Torrentio usa o mesmo `👤 0` para dois casos distintos: "não há peers" e "não
consegui medir". O segundo é o mais comum em releases nacionais, que ele indexa
sem passar pelo rastreador de peers — e o rótulo do release dublado de "Grey's
Anatomy" trazia exatamente `👤 0 💾 456.78 MB ⚙️ BluDV`.

Como o catálogo descarta toda fonte com 0 seeds, tratar esse 0 como definitivo
apagava dubladas legítimas antes de elas chegarem à interface. Por isso
[`ProvedorTorrentio::seedsDoRotulo()`](../backend/app/Services/Torrents/ProvedorTorrentio.php:186)
aplica um piso de 1: o valor medido nunca é devolvido como 0. Quem confirma se a
fonte vive é o media-service, que mede os peers na prática antes de abrir a
reprodução — o mesmo raciocínio que já valia para o rótulo sem contagem alguma.

### Fontes sem peers são descartadas

Uma fonte sem peers conecta mas nunca envia dados — era a causa das sessões
presas em `aguardando` com `percentual: 0`. Hoje a defesa é em camadas:

- O backend **filtra** qualquer fonte com `seeds === 0` ou magnet vazio antes de
  devolver a lista. O piso de 1 do Torrentio (acima) evita que uma dublada
  legítima caia aqui por um "0 não medido".
- O media-service **falha rápido** e diz por quê. O motivo sobe no status da
  sessão, no campo `motivo`:

| Rótulo | Quando acontece | O que significa |
| --- | --- | --- |
| `sem_peers` | o magnet não publicou metadados e não há peer algum | fonte morta: trocar de lançamento |
| `sem_metadados` | havia peers, mas o `info` do torrent não chegou | rede ruim: vale tentar de novo |
| `sem_dados` | o torrent ficou pronto e nenhum byte desceu | a fonte negocia e não entrega |
| `sem_video` | o torrent não traz arquivo de vídeo reconhecido | o lançamento não serve |

- O overlay converte o **placar das tentativas** na mensagem final, em vez do
  "nenhuma fonte conseguiu conectar" genérico: `Nenhuma fonte tem peers
  disponíveis. Tente outro lançamento.` diz o que fazer, enquanto `Nenhuma fonte
  traz áudio em português.` continua sendo uma conclusão firme — todas as
  tentativas foram reprovadas pelo porteiro de idioma.

#### "Sem peers" nem sempre é fonte morta

O rótulo `sem_peers` tem duas leituras muito diferentes: a fonte morreu de
verdade, ou é a **rede do container** que não resolve tracker nem entra no DHT.
As duas ficam idênticas no log, e por muito tempo tratamos só a primeira — o
usuário via "sem peers" numa fonte que estava viva e já tinha rodado no sistema.

O `media-service` é quem abre o torrent, e ele ficava sem as duas coisas que o
`backend`, o `prowlarr` e o `flaresolverr` já tinham:

- **DNS confiável.** O resolver herdado da operadora bloqueia ou devolve
  `NXDOMAIN` para domínios de tracker. Sem resolver os anunciadores nem alcançar
  os roteadores do DHT, o WebTorrent não forma malha. O `media-service` agora
  recebe o mesmo `dns:` dos outros containers (`DNS_PRIMARIO`/`DNS_SECUNDARIO`).
- **Porta de escuta publicada.** O container só fazia conexões de saída. Sem
  publicar a porta do WebTorrent em TCP e UDP, nenhuma conexão de entrada chega —
  a malha fica pela metade e a chance de achar peer cai muito. A porta é fixa
  (`MEDIA_TORRENT_PORT`, padrão `51413`) e precisa bater com o `torrentPort` do
  cliente em [`sessoes.js`](../media-service/src/services/sessoes.js:54): uma
  porta aleatória a cada subida nunca seria alcançável de fora.

O cliente também passou a ligar o **DHT explicitamente** (`dht: true`): é ele que
acha peers sem depender de tracker, então um anunciador fora do ar não derruba
mais a malha sozinho. O uTP segue desligado — o `utp-native` provoca `SIGSEGV`
neste ambiente e derruba o processo inteiro.

Quando os metadados não chegam, o log de diagnóstico agora diz **por que**:
`peers`, número de `trackers` anunciados, porta de escuta e estado do DHT. Se a
lista de anunciadores vier vazia, o problema é de montagem do magnet, não de
rede; se vier cheia e os peers forem zero, é a malha que não fecha.

O prazo dos metadados caiu de 45 s para 20 s
([`TIMEOUT_METADADOS_MS`](../media-service/src/services/sessoes.js:67)): os 45 s
anteriores consumiam metade da paciência do overlay (90 s) sem gerar um byte. O
ramo de reuso — `cliente.get` de um magnet já adicionado — **não tinha prazo
nenhum**, então bastava a primeira tentativa falhar para a segunda pendurar o
overlay para sempre; agora os dois caminhos passam por
[`esperarMetadados()`](../media-service/src/services/sessoes.js:1148).

O `POST /verificar` continua disponível para testar a fonte antes de abrir a
sessão, mas deixou de ser o caminho principal: quem mede os peers de verdade
agora é a própria sessão, que já precisa esperar os dados de qualquer forma.

#### A paciência com a peça se mede pelo progresso, não pelo relógio

Fonte viva com poucos seeds é o pior caso do acervo antigo: o torrent conecta,
negocia e **entrega**, só que devagar. O erro `Tempo esgotado aguardando o
trecho do vídeo` aparecia justamente aí — não porque a fonte morreu, mas porque
o prazo era um relógio fixo que corria contra a velocidade da malha, e não
contra a saúde dela.

A espera por peças em [`aguardarPecas()`](../media-service/src/services/sessoes.js:1166)
deixou de ser um `setTimeout` de prazo único. Agora ela acompanha o
`torrent.downloaded` a cada verificação:

- **Cada byte novo renova a paciência.** Enquanto o contador de bytes baixados
  avança, o relógio de estagnação reinicia. Um seed que entrega 200 KB/s por
  vinte minutos nunca é interrompido — ele está progredindo, só que no ritmo
  dele.
- **Só a estagnação real derruba.** Se o `downloaded` fica parado por
  `MEDIA_ESTAGNACAO_PECAS_MS` (padrão 90 s), aí sim a sessão desiste: a fonte
  parou de entregar, não está apenas lenta.
- **Há um teto absoluto.** `MEDIA_TETO_PECAS_MS` (padrão 15 min) impede que uma
  fonte patológica que pinga um byte a cada minuto prenda a sessão para sempre.
  O teto é a rede de segurança; a estagnação é o critério normal.

O mesmo critério vale para [`aguardarDownloadCompleto()`](../media-service/src/services/sessoes.js:1264),
que antes também tinha prazo fixo. E [`aguardarInicio()`](../media-service/src/services/sessoes.js:1233)
perdeu os 180 s cravados: agora herda a paciência por progresso, porque o começo
do arquivo é exatamente onde o primeiro seed lento demora mais.

Quando o prazo estoura, o log diz **o que estava acontecendo** — segundos
parado, bytes baixados, número de peers, velocidade e a faixa pedida. Isso
separa "a fonte nunca entregou" de "a fonte entregou e parou", que antes eram a
mesma linha de erro.

#### A janela de leitura abre mais quando há poucos peers

Com poucos peers ativos, o gargalo não é só a velocidade: é a **variedade** de
peças disponíveis na malha. Pedir uma janela estreita demais concentra a
requisição em poucos pedaços que talvez só um peer tenha — e se esse peer estiver
lento, a leitura trava nele.

[`tamanhoDaJanela()`](../media-service/src/services/sessoes.js:374) agora observa
`torrent.numPeers`: quando a malha tem `MEDIA_PEERS_ESCASSOS` (padrão 3) ou menos
peers, a janela é multiplicada por `MEDIA_FATOR_JANELA_ESCASSOS` (padrão 2),
respeitando o teto de `ANTECEDENCIA_MAXIMA_BYTES`. Com mais peças elegíveis, o
seletor do WebTorrent tem de onde escolher entre peers vizinhos em vez de
depender de um único fornecedor do trecho.

As quatro variáveis seguem o espelhamento de sempre — `.env`, `.env.example` e
o bloco `environment:` do `media-service` no `docker-compose.yml` — e têm padrão
embutido no código, então o serviço sobe sem configuração manual.

### Demais regras

- A busca prioriza o `imdb_id` (mais preciso que o título, que traz remakes).
  Quando só há o título, os resultados são filtrados pelo ano do filme.
- O YTS devolve em `url` um link de download, não uma lista de trackers; o
  serviço completa o magnet com anunciadores públicos para o WebTorrent achar
  peers.
- Respostas cacheadas no Redis (`TORRENTS_CACHE_TTL`, padrão 1800s).
- O motor de torrent roda no media-service (biblioteca `webtorrent`), que
  conecta a fonte e serve o vídeo convertido em HLS.

#### O cache que prende o resultado velho

As consultas por provedor ficam em cache por `TORRENTS_CACHE_TTL` (padrão 1800 s).
Isso mantém o `/fontes` barato, mas também prende um resultado limitado por até um
TTL inteiro: a lista de uma série antiga pode ter sido montada quando os termos de
busca eram restritos e continuar sendo servida depois da correção.

`TORRENTS_CACHE_BYPASS=true` ignora a **leitura** do cache nas consultas externas
durante a busca. Não desliga o cache: o provedor é reconsultado e o resultado novo
grava por cima. É o que libera a lista presa sem esperar o TTL vencer nem limpar o
Redis à mão — ligue só pontualmente, desaloje o resultado e volte para `false`.

O caminho vale para os dois formatos de consulta do catálogo — termo a termo
([`buscarComCache()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1371))
e em lote
([`buscarLoteComCache()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1460)) —
porque o Knaben responde o degrau inteiro numa rodada e tem a sua própria chave,
que cobre o **conjunto** de termos. Com o bypass ligado, o `do_cache` do censo não
incrementa: a leitura não aconteceu, então a consulta conta como real.

| Variável | Padrão | O que faz |
|----------|--------|-----------|
| `TORRENTS_CACHE_BYPASS` | `false` | Ignora a leitura do cache das consultas externas (Torrentio, Knaben, BT4G, APIBay e Torznab) durante a busca. O resultado novo volta a ser gravado. |

### Packs de temporada — o socorro das séries antigas

Série antiga não falha por falta de provedor: falha por falta de seed. O episódio
isolado de *American Horror Story* já não tem quem o sirva, mas o **pack da
temporada** segue vivo porque interessa a muita gente de uma vez. Por isso a busca
passou a procurar o pacote quando os termos de episódio não acham fonte dublada.

- Os termos são montados por
  [`TermosBusca::packTemporada()`](../backend/app/Services/Torrents/TermosBusca.php:94)
  e [`TermosBusca::packTemporadaDublado()`](../backend/app/Services/Torrents/TermosBusca.php:115):
  `<título> S01 completa`, `<título> Temporada 1 completa`,
  `<título> Season 1 complete` e as versões `... completa dublada`.
- Eles entram **no fim** da lista de termos de episódio em
  [`TorrentService::titulosDeEpisodio()`](../backend/app/Services/TorrentService.php:131),
  depois dos termos de episódio e dos dublados. A cascata para no primeiro termo
  que rende dublado, então uma série que já funciona continua resolvendo nos
  termos de episódio: o pack só é consultado quando os anteriores se esgotam.
- A frente é só de episódio. O fluxo de filme não passa por `titulosDeEpisodio()`,
  então não vê termo de pack nenhum.
- Um pack da temporada errada tem seeds de sobra — e abriria o episódio errado.
  [`TermosBusca::correspondeAoEpisodio()`](../backend/app/Services/Torrents/TermosBusca.php:240)
  ganhou um crivo conservador: quando o título **declara** uma temporada (lida em
  [`TermosBusca::temporadaDoTitulo()`](../backend/app/Services/Torrents/TermosBusca.php:279))
  diferente da pedida, a fonte é descartada. Sem marcador de pack, o título passa
  como sempre passou.
- Como o pack muda o resultado de entradas já cacheadas no Redis, a
  `VERSAO_CACHE` do
  [`CatalogoProvedores`](../backend/app/Services/Torrents/CatalogoProvedores.php:49)
  subiu para `10`, invalidando as respostas antigas.
- O pack é **um torrent com todos os episódios**. O media-service passou a aceitar
  `temporada`/`episodio` em
  [`criarSessao()`](../media-service/src/services/sessoes.js:178) e escolhe o
  arquivo do episódio dentro do pacote em
  [`escolherArquivoDeVideo()`](../media-service/src/services/sessoes.js:1242),
  casando `S01E02`/`T01E02`/`1x02`/`Temporada 1 ... Episódio 2` em
  [`caminhoCorrespondeAoEpisodio()`](../media-service/src/services/sessoes.js:1283).
  Sem correspondência, mantém o comportamento antigo (maior arquivo de vídeo).
  Chamadas que mandam só `magnet` seguem válidas — o par é opcional.
- **Escolher o arquivo não basta: é preciso isolá-lo.** O WebTorrent seleciona
  **todos** os arquivos do torrent com prioridade 1 por padrão. Num pack isso
  espalha o download pelos episódios inteiros e o episódio pedido fica sem os
  pedaços iniciais — o FFmpeg não lê o cabeçalho e a sessão trava em
  "aguardando" com ~11% baixado. A correção está em
  [`isolarArquivoDoEpisodio()`](../media-service/src/services/sessoes.js:1535):
  ela percorre `torrent.files`, chama `deselect()` (com queda para `select(0)`)
  em **todos** os arquivos menos o alvo e devolve prioridade 1 só ao episódio
  escolhido. A janela deslizante de leitura
  ([`iniciarJanela()`](../media-service/src/services/sessoes.js:456)) continua
  subindo as prioridades do trecho em reprodução por cima dessa base.
- O isolamento roda **duas vezes**: no preparo da sessão, antes de a janela
  começar a mexer nas prioridades, e de novo no
  [`reposicionarEmSegundoPlano()`](../media-service/src/services/sessoes.js:2122)
  antes de reabrir a janela — um seek não pode devolver prioridade aos outros
  episódios. Sessões de filme (`episodio === null`) não passam pelo isolamento.
- O frontend propaga o par pelo
  [`streamingService.criarSessao()`](../frontend/src/services/streaming.js:74) e pelo
  [`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:1506).

#### A exceção de idioma do pack

Os packs entraram na busca, mas no primeiro teste real — *American Horror Story*,
S01E01 — continuaram sem aparecer: a busca achava o pacote e o **descartava**
depois. Naquele momento o idioma **desclassificava** a fonte em **dois** pontos, e
os dois reprovavam o pack:

1. [`CatalogoProvedores::aproveitaveis()`](../backend/app/Services/Torrents/CatalogoProvedores.php:571),
   ainda dentro da cascata, para o `temDublado()` julgar cada degrau sobre fontes
   que de fato atendem ao pedido;
2. [`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:241),
   antes de devolver a lista ao frontend.

Como o pack de série antiga quase nunca vem marcado como dublado, os dois o
jogavam fora — e a lista voltava vazia, o oposto do socorro pretendido. O desenho
mudou depois: o idioma deixou de desclassificar a fonte e passou apenas a
**etiquetá-la** (`pt_br`) e a definir a faixa em que ela aparece; quanto da reserva
entra na lista é decisão da montagem final. Sobrou **um** ponto de filtro, e é nele
que a exceção do pack vive hoje. A correção continua cirúrgica:

- A fonte é **marcada como pack ainda na cascata**, em
  [`CatalogoProvedores::marcarPacks()`](../backend/app/Services/Torrents/CatalogoProvedores.php:361),
  chamado por `buscarGrupo()`. A marca nasce ali, e não no chamador, porque é este
  método que entrega a lista já filtrada: ela precisa sobreviver ao filtro de
  `aproveitaveis()` e à ordenação posterior. O chamador só informa se o termo
  corrente é de pack, por
  [`eTermoDePack()`](../backend/app/Services/Torrents/CatalogoProvedores.php:334),
  que devolve `false` sempre que não há temporada **e** episódio — um filme jamais
  é tratado como pack. A marca saiu do termo e passou a sair do **conteúdo** da
  fonte; o porquê está em
  [O pack se prova pelo nome do torrent](#o-pack-se-prova-pelo-nome-do-torrent-não-pelo-termo-consultado).
- [`TermosBusca::eTermoDePack()`](../backend/app/Services/Torrents/TermosBusca.php:135)
  reconhece o termo exigindo **os dois** sinais: um marcador de pacote
  (`completa`/`completo`/`complete`/`superpack`) e uma numeração de temporada. Um
  filme de título *The Complete ...* que passe por aqui não é confundido.
- A isenção vale **apenas** para a fonte marcada como pack, e num único ponto: o
  filtro de [`ordenar()`](../backend/app/Services/TorrentService.php:256). Episódio
  comum e filme seguem a regra normal.
- O pack de idioma não provado **não** entra na pilha boa: ele vai para a reserva e
  aparece **depois dos episódios de qualquer idioma**. Dublado e dual continuam na
  frente por [`IdiomaFonte::prioridade()`](../backend/app/Enums/IdiomaFonte.php:41);
  dentro de cada idioma, a chave
  [`chaveDeOrdem()`](../backend/app/Services/TorrentService.php:330) põe o episódio
  antes do pack. É o que impede o pack do Torrentio de encobrir os episódios do
  Knaben, do TPB+ e do APIBay.
- A exceção é reversível por configuração: `TORRENTS_PACKS_QUALQUER_IDIOMA`
  (padrão `true`). Em `false`, o comportamento anterior volta — pack desmarcado
  volta a ser descartado pelo filtro de idioma. Veja
  [Ambiente](ambiente.md#variáveis-de-ambiente).
- A `VERSAO_CACHE` subiu para `10`: a mudança atinge entradas já cacheadas, e a
  chave precisa mudar para a reconsulta valer na próxima busca.
- O log `Etapa da cascata de torrents concluída.` ganhou o campo `packs`, com
  quantas fontes atravessaram pela exceção. Sem esse número, um pack que entra
  legendado some no total de `fontes` e não dá para saber se a exceção funcionou.

#### O pack se prova pelo nome do torrent, não pelo termo consultado

O primeiro teste com o pack já marcado ainda devolvia **uma** fonte. O problema não
estava na exceção de idioma, e sim em *quem* era marcado como pack: a marca saía do
**termo** da consulta, não do conteúdo. Todo resultado do termo `S01 completa`
virava pack — inclusive um `S01E01` avulso que respondera àquele termo —, enquanto o
pacote de verdade, que voltara sob um termo de episódio, nunca era marcado e caía no
corte de `apenas_pt_br`.

A prova do pack está no **nome do torrent**, e ele não é o nome do arquivo. O
protocolo Stremio traz os dois: `behaviorHints.filename` é o arquivo *dentro* do
torrent (aponta para um episódio só) e a primeira linha do rótulo é o nome do
release (a temporada inteira). Como o filho esconde o pai, ler só o arquivo perdia a
evidência do pacote. A leitura passou a guardar os dois: `titulo` continua sendo o
arquivo (bom para exibir e conferir numeração) e `release` leva o nome do torrent,
como se vê em
[`LeituraStreamStremio::normalizarStream()`](../backend/app/Services/Torrents/LeituraStreamStremio.php:28).

A marcação então deixou de olhar para o termo:

- [`CatalogoProvedores::marcarPacks()`](../backend/app/Services/Torrents/CatalogoProvedores.php:361)
  decide por fonte, lendo `release ?? titulo`.
- Um título que **declara episódio** nunca é pack —
  [`TermosBusca::numeracaoDoTitulo()`](../backend/app/Services/Torrents/TermosBusca.php:383)
  corta de saída. É o que impede o próprio filho do pacote
  (`Season 1 - Episode 1 - Pilot.mkv`) de ser tratado como pacote.
- Sem numeração de episódio, é pack quem **cobre a temporada pedida**, segundo
  [`TermosBusca::temporadaNoRelease()`](../backend/app/Services/Torrents/TermosBusca.php:327).
  Ele é mais tolerante que o `temporadaDoTitulo()` do corte de numeração — aceita
  `S01`, `Temporada 1`, `Season 1`, `1ª Temporada` e as **faixas** (`S1-S5`,
  `Seasons 1 to 8`) —, porque o socorro da série antiga costuma vir em pacotes
  multi-temporada.
- Quando o termo corrente **é** de pack, ele ainda vale como último recurso, mas só
  para o que não tem temporada declarada: o marcador fraco não promove um feixe de
  episódios a pacote.

#### O idioma do pack mora no nome do torrent

Marcar o pack certo revelou um segundo engano: o pacote nacional *"American Horror
Story 1ª Temporada [2011 DUAL AUDIO] 720p"* aparecia como **Idioma original**. A
classificação de idioma lia só o nome do arquivo — e o arquivo de um pacote raramente
carrega a tag (`S01E01.mkv`). Quem diz *DUAL ÁUDIO* é o release.

[`NormalizaFonte::montarFonte()`](../backend/app/Services/Torrents/NormalizaFonte.php:37)
passou a aceitar `idioma_titulo`, um texto alternativo para a dedução, e a leitura do
Stremio manda os **dois** nomes ali. O resultado é o pack dual subindo ao lado do
dublado, em vez de se perder entre os originais — no teste da série, a ordem veio com
o dublado em 1º, o pack Dual Áudio em 2º e os demais em idioma original.

#### Quando o nome não basta, a inspeção abre o pack

O nome do torrent cobre a maioria dos packs nacionais, mas não todos: um
`American Horror Story (2011) Season 1 to 8 with Extras [1080p H265][MP3 5.1 Ch]`
não diz nada sobre idioma — e ainda assim pode ser dublado. Para esses casos, a
busca **abre o pack** e olha os nomes dos arquivos lá dentro.

A inspeção só entra quando o nome **não** provou PT-BR, e é a última cartada,
porque custa uma sessão no media-service:

1. [`CatalogoProvedores::confirmarIdiomaDoPack()`](../backend/app/Services/Torrents/CatalogoProvedores.php:517)
   intercepta a fonte já marcada como pack que ainda não é PT-BR.
2. [`InspecaoPack::apurar()`](../backend/app/Services/Torrents/InspecaoPack.php:47)
   chama `POST /api/media/metadados` com o magnet e o infohash.
3. O media-service
   ([`inspecionarTorrent()`](../media-service/src/services/sessoes.js:1969)) abre o
   torrent, lê a lista de arquivos e aplica
   [`contemIndicioPtBr()`](../media-service/src/utils/idiomas.js:184) sobre cada
   caminho. Se **qualquer** arquivo (ou a pasta do pack) provar PT-BR, a resposta
   traz `indicio_pt_br: true` e a `prova` que o sustenta.
4. Com `true`, a fonte é promovida a **Dublado** e ganha
   `idioma_por_inspecao: true` — o frontend e os logs sabem que a prova veio do
   conteúdo, não da tag.

O custo é contido por três travas, todas em
[`services.php`](../backend/config/services.php:180):

| Trava | Config | Padrão |
| --- | --- | --- |
| Teto de packs inspecionados por busca | `TORRENTS_INSPECAO_PACKS_LIMITE` | `6` |
| Tempo máximo de espera por pack | `TORRENTS_INSPECAO_TIMEOUT` | `12`s |
| Cache do veredito por infohash | `TORRENTS_INSPECAO_CACHE_TTL` | `86400`s |

O cache guarda **só respostas definitivas** — nunca o `sem_peers` nem o tempo
esgotado: um pack que não respondeu agora pode responder no próximo minuto, e
gravar "sem PT-BR" ali envenenaria o resultado até o TTL inteiro. O veredito é
chaveado por infohash e versão
([`InspecaoPack::VERSAO_CACHE`](../backend/app/Services/Torrents/InspecaoPack.php:37)),
pelo mesmo motivo da `VERSAO_CACHE` da busca.

Um pack que a inspeção não prova PT-BR **não** é descartado: ele desce para a
reserva, como qualquer outro original, e ainda aparece na lista — atrás das fontes
dubladas.

### Knaben e addons Stremio hospedados — o socorro que faltava

A exceção de pack destravou o que a cascata **já achava** — ela não faz a busca achar
mais. No teste da série antiga, mesmo com a exceção, a busca nativa voltava com
**uma** fonte: os termos de pack passavam por provedores que simplesmente não indexam
o release nacional. A Fase 2B acrescenta duas fontes ao degrau 1, por dois caminhos —
ambos atrás de configuração e desligáveis sem reverter código.

#### `ProvedorKnaben` — meta-buscador com API JSON

O Knaben agrega dezenas de indexadores atrás de uma API JSON sem chave e varre
trackers que os nossos provedores nativos não cobrem. É de lá que saem os packs
nacionais das séries antigas: nos testes, `S01 completa` devolveu o *"American Horror
Story S01 Completa Legendado PT-BR"*, e `Temporada 1` devolveu o *"1ª Temporada [2011
DUAL AUDIO] 720p"*.

A armadilha do contrato é o `search_type`. Com `"score"` — o valor que a maioria dos
exemplos usa — a API **ignora a `query`** e devolve os torrents mais semeados do
acervo inteiro: perguntar por *American Horror Story* devolvia Adobe Photoshop. Só com
`"100%"` a API trata a `query` como busca de verdade, exigindo que todos os termos
casem. Está fixo e comentado em
[`ProvedorKnaben::buscar()`](../backend/app/Services/Torrents/ProvedorKnaben.php:58) —
trocar de volta por `"score"` ressuscita o bug em silêncio.

Outras decisões vieram dos testes:

- **`order_by`**: a API aceita `seeders`, `date`, `size` e `peers`. `relevance` não
  existe e responde HTTP 400; ordenamos por `seeders` para os packs vivos subirem.
- **Piso de seeds**: o Knaben informa a contagem, mas ela vem de indexadores em cache
  e os packs antigos aparecem com `0` mesmo vivos — foi o caso do próprio pack PT-BR.
  Como [`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:211)
  descarta quem tem zero seeds, tratar esse `0` como definitivo repetiria o descarte
  que a Fase 2 veio resolver. O provedor aplica o mesmo piso `SEEDS_NAO_MEDIDOS` de
  [`NormalizaFonte`](../backend/app/Services/Torrents/NormalizaFonte.php:1); quem
  confirma se a fonte vive é o media-service, que mede os peers na prática.
- **Corte de numeração**: um pack de `S01` pode vir junto de um `S10E01` da mesma
  série, e o dublado de outra temporada subiria ao topo. O corte é o mesmo dos outros
  provedores por nome; releases sem numeração (os packs) passam.

#### `ProvedorAddonStremio` — addons hospedados por `imdb_id`

O protocolo do Stremio provou o valor: um addon público responde por `imdb_id` (e por
temporada/episódio, no caso de série) com uma lista de streams já pronta, varrendo
trackers que o backend não consulta sozinho. Em vez de um provedor por addon, o
[`ProvedorAddonStremio`](../backend/app/Services/Torrents/ProvedorAddonStremio.php:31)
consulta **todos os endereços** de `TORRENTS_STREMIO_ADDONS` em paralelo
(`Http::pool`) e trata cada resposta pelo mesmo contrato.

- Série usa `/stream/series/{imdb}:{temporada}:{episodio}.json`; filme, só
  `/stream/movie/{imdb}.json`. É o mesmo caminho do Torrentio, e trocar um pelo outro
  devolve o conteúdo errado (o mesmo `imdb_id` serve à série e a um filme homônimo).
- Cada entrada pode trazer o **segmento de configuração** junto do host (ex.:
  `https://torrentio.strem.fun/language=portuguese`); o provedor só concatena
  `/stream/...` ao final e o segmento chega intacto ao addon.
- O corte de numeração e a deduplicação por infohash são os da cascata: o mesmo
  torrent em dois addons entra uma vez só.
- O Torrentio tem provedor próprio (carrega a configuração de idioma na URL) e não
  precisa estar nesta lista; como a mesclagem deduplica por hash, listá-lo aqui seria
  inofensivo.

#### A leitura do protocolo Stremio virou trait

O Torrentio já lia esse mesmo dialeto: todo o metadado (release, seeds, tamanho)
vem embutido no rótulo `title`, com emojis separando os campos. Com um segundo
provedor falando o mesmo protocolo, a leitura saiu de
[`ProvedorTorrentio`](../backend/app/Services/Torrents/ProvedorTorrentio.php:27) para a
trait [`LeituraStreamStremio`](../backend/app/Services/Torrents/LeituraStreamStremio.php:20)
— `normalizarStream()`, `nomeDoRotulo()`, `seedsDoRotulo()`, `tamanhoDoRotulo()` e
`idiomaDoNome()`. O provedor do Torrentio ficou só com o que é dele (a configuração de
idioma na URL), e o comportamento não mudou.

O que mudou depois — e vale para os dois provedores — é `normalizarStream()` passar a
entregar **dois** nomes adiante: o arquivo como `titulo` e o torrent como `release`,
este último alimentando tanto a marcação de pack quanto a dedução de idioma. Veja
[O pack se prova pelo nome do torrent](#o-pack-se-prova-pelo-nome-do-torrent-não-pelo-termo-consultado).

#### Variáveis da Fase 2B

| Variável | Padrão | O que faz |
|----------|--------|-----------|
| `TORRENTS_KNABEN_HABILITADO` | `true` | Liga/desliga o Knaben. Ele bate num único host externo; se ele cair ou passar a limitar requisições, dá para desligá-lo sem tocar no código. |
| `TORRENTS_KNABEN_URL` | `https://api.knaben.org/v1` | Endpoint da API. Use `api.knaben.org` — o `api.knaben.eu` responde 503. Vazio também desliga o provedor. |
| `TORRENTS_KNABEN_LIMITE` | `20` | Quantos resultados pedir por termo. Cada termo é uma requisição; o corte de idioma descarta o resto. |
| `TORRENTS_STREMIO_ADDONS` | `https://thepiratebay-plus.strem.fun` | Addons Stremio hospedados, por vírgula. O Torrentio tem provedor próprio e não precisa estar aqui. |

A `VERSAO_CACHE` subiu de `6` para `11`, depois para `12` com a coleta por
orçamento e a inspeção de packs, e para `13` com a busca ampla da série — o termo
novo muda o conjunto de fontes que o provedor devolve. Cada provedor novo e cada
correção de leitura mudam o conjunto — ou o próprio texto — das fontes já cacheadas
no Redis (as entradas antigas guardam o `titulo`, o `idioma` e até a marca de pack do
código anterior), então a chave precisa mudar para a reconsulta valer já na próxima
busca, sem depender de limpar o Redis à mão.

### Termos de série e o gate de temporada

A exceção de pack resolveu o **descarte**, mas não o **alcance**. Nos provedores que
buscam por nome, todos os termos que a cascata já montava carregam palavras que o
release do pacote nacional não tem: `S01E01` (numeração de episódio) e `completa`. Como
o [`ProvedorKnaben`](#provedorknaben--meta-buscador-com-api-json) exige que **todas** as
palavras casem (`search_type: "100%"`), e os demais casam por relevância com o mesmo
efeito prático, o termo com essas palavras praticamente não encontra o nome real do
pacote — *"American Horror Story 1ª 2ª 3ª Temporadas Dublado e Legendado"*.

[`TermosBusca::serieDublado()`](../backend/app/Services/Torrents/TermosBusca.php:139)
acrescenta termos que **não** têm numeração nem "completa" — `"... dublado"`,
`"... dual áudio"`, `"... temporada N"` e `"... SN"` — para perguntar pela série **pelo
nome**, que é como o pack aparece. Eles entram **por último**, depois até dos termos de
pack, dentro de
[`TorrentService::titulosDeEpisodio()`](../backend/app/Services/TorrentService.php:131).
A posição é a proteção: a cascata para no primeiro termo que devolve dublado, então uma
série recente se resolve muito antes de chegar aqui — a frente existe para o caso
extremo, quando nem o episódio nem o `S01 completa` acham nada.

O preço da largueza é o falso positivo. Um termo sem `S01E01` também casa *"Freak
Show"* — que é a 4ª temporada de American Horror Story e não declara número nenhum. Por
isso as fontes passam por um **gate de temporada** em
[`CatalogoProvedores::aproveitaveis()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1162):
se o release **não** declara episódio e **não** prova a temporada pedida, é descartado.
É a inversão da regra dos outros cortes — aqui o silêncio não é inocente. O
reconhecimento sobe pela mesma tubulação do termo de pack: de
[`CatalogoProvedores::buscar()`](../backend/app/Services/Torrents/CatalogoProvedores.php:196)
para `buscarGrupo()` e daí para `aproveitaveis()`.

Para o gate reconhecer o pacote multi-temporada,
[`TermosBusca::temporadaNoRelease()`](../backend/app/Services/Torrents/TermosBusca.php:393)
passou a ler a **lista** de temporadas: `1ª 2ª 3ª Temporada(s)`, `Temporadas 1, 2 e 3` e
faixas (`S01-S03`, já cobertas antes). Ficou de fora, de propósito, a faixa crua `1-3`:
sem palavra-chave, o risco de casar ano, resolução ou tamanho é maior que o ganho.

O contador do gate aparece no log de cada etapa (`termo_serie` e `barradas_gate`): sem
ele, "0 fontes" no termo de série ficaria indistinguível entre "o provedor não tinha
nada" e "veio, mas nada provou a temporada".

#### O termo amplo: o que o meta-buscador precisa

O [`serieDublado()`](../backend/app/Services/Torrents/TermosBusca.php:200) ainda leva
uma **tag de áudio** em cada termo ("... dublado", "... dual áudio"). No Knaben, que
exige que **todas** as palavras casem, isso ainda corta recall: o pack publicado como
*"American Horror Story S01 Completa Legendado PT-BR"* não casa com nenhum termo que
peça "dublado".

[`TermosBusca::serieAmpla()`](../backend/app/Services/Torrents/TermosBusca.php:231)
fecha essa última fresta: devolve só o **nome da série com a temporada** — `"{nome}
S01"`, `"{nome} temporada 1"`, `"{nome} season 1"` e o nome puro como última rede de
recall —, sem numeração de episódio e sem tag de áudio. A pontuação do título sai pelo
`limpar()` (dois-pontos e hífen atrapalham a busca por palavra-chave). Os termos são
montados, por título candidato, dentro de
[`TorrentService::titulosDeEpisodio()`](../backend/app/Services/TorrentService.php:158),
logo depois dos termos de pack, e entram no lote que a rodada de abertura entrega ao
Knaben.

Quem valida o resultado é o parser interno, no mesmo caminho de sempre: o **gate de
temporada** descarta o que não cobre a temporada pedida e a **classificação de idioma**
lê o nome do release. O termo amplo só amplia o alcance; ele não decide nada.

#### O furo do gate: ele só valia para o termo de série

O gate nasceu amarrado ao termo de série — só as fontes reconhecidas por
[`TermosBusca::eTermoDeSerie()`](../backend/app/Services/Torrents/TermosBusca.php:160)
eram confrontadas com a temporada pedida. O termo de **pack** (`S01 completa`) ficava de
fora, e era justamente por ali que o pack da temporada errada entrava. O caso que expôs
o furo: uma busca por `S01E01` de *American Horror Story* voltava com três fontes, e uma
delas era o pack da **2ª** temporada — `American Horror Story.2ª.Temporada.Dual.Áudio.720p.`
— que, por ter mais seeds e áudio dual, subia ao topo e era a primeira fonte tentada
pelo player. O episódio aberto era de outra temporada.

Eram dois defeitos somados:

1. **O gate não cobria o termo de pack.** `aproveitaveis()` só rodava a confrontação
   quando o termo era de série. O pack da 2ª entrou por um termo de pack e passou.
2. **A leitura da temporada não enxergava o ordinal com ponto.**
   [`TermosBusca::temporadaDoTitulo()`](../backend/app/Services/Torrents/TermosBusca.php:351)
   exigia um marcador de pack e usava `\s*` como separador, que não casa o `.` de
   `2ª.Temporada`. O release declarava a temporada de forma explícita e a leitura
   devolvia `null` — o gate não tinha o que confrontar.

A correção tem três partes:

- **O gate vale para qualquer termo.** `aproveitaveis()` deixou de receber o
  `$termoDeSerie` e roda a confrontação para toda fonte, venha ela de termo de pack ou
  de série. O pack legítimo da 1ª (`1ª Temporada [2011 DUAL AUDIO]`) continua passando
  porque prova a temporada pedida.
- **A leitura da temporada ficou independente do marcador de pack.**
  `temporadaDoTitulo()` não exige mais "completa"/"temporada" e usa `[\s._-]*` como
  separador, cobrindo `2ª.Temporada`. A ordem das checagens passou a ser a da
  especificidade — o ordinal antes do "Temporada N" —, e cada padrão exige uma âncora
  forte (o `S` de release, a palavra-chave ou o ordinal), de modo que o ano do release
  (`2011`) nunca é lido como temporada.
- **A montagem final confronta o pack.** Mesmo com PT-BR provado, um pack cuja
  temporada declarada difere da pedida é descartado em
  [`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:260), por
  [`TorrentService::packDaTemporadaErrada()`](../backend/app/Services/TorrentService.php:439).
  A exceção `TORRENTS_PACKS_QUALQUER_IDIOMA` continua valendo para o pack de idioma
  **não** provado — o que muda é que a temporada dele também é conferida.

A leitura da temporada no gate usa `temporadaNoRelease()`, e não `temporadaDoTitulo()`,
porque o pack pode cobrir uma faixa (`1ª 2ª 3ª Temporadas`, `Seasons 1 to 8`): a leitura
simples devolveria só o primeiro número e reprovaria um pack multi-temporada que inclui a
pedida. `temporadaNoRelease()` aceita a faixa e só reprova quando a pedida está fora
dela.

A regressão está travada em
[`backend/tests/Unit/TermosBuscaTest.php`](../backend/tests/Unit/TermosBuscaTest.php:1):
o pack da 2ª não pode entrar numa busca da 1ª, o pack da 1ª precisa continuar passando,
o ano não pode virar temporada e os packs multi-temporada que incluem a pedida seguem
aceitos. Rode com `docker compose exec backend php vendor/bin/phpunit`.

| Variável | Padrão | O que faz |
|----------|--------|-----------|
| `TORRENTS_TERMOS_SERIE_HABILITADO` | `true` | Liga/desliga os termos de série no fim da cascata de episódio. Ligado, acrescenta `"... dublado"`, `"... temporada N"` e `"... SN"` (sem numeração de episódio e sem "completa") depois dos termos de pack; as fontes que vêm deles passam pelo gate de temporada. Desligar restaura a busca anterior sem reverter código. |

#### O gate perdoa o pack etiquetado, e o pack se prova pelo marcador no nome

Existe um pack de série antiga que nem numera a temporada: *"American Horror Story -
A Série Completa Dublado 1080p"*. Ele é exatamente o socorro que a busca quer — seeds
de sobra e áudio dublado —, mas era ele o `na_lista: 0` que sobrava das séries antigas.
O caminho do descarte era sutil e duplo:

1. [`CatalogoProvedores::marcarPacks()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1096)
   só etiquetava o pacote quando o **termo corrente** era de pack
   (`$termoDePack && $temporadaDeclarada === null`) ou quando o nome provava a
   temporada. Um pacote que chegava por um termo de série (*"... dublado"*) ou de
   episódio, e cujo nome não traz número nenhum, escapava da etiqueta.
2. Sem a etiqueta, o gate de
   [`aproveitaveis()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1271)
   o via como "fonte que nada declara" e o descartava — o censo registrava
   `descartado_na_montagem`.

A correção tem duas partes, uma em cada ponta do mesmo fio:

- **A etiqueta nasce também do marcador textual.** A lista canônica
  [`TermosBusca::MARCADORES_PACK`](../backend/app/Services/Torrents/TermosBusca.php:59)
  cobre as gírias de pacote (`completa`, `complete`, `boxset`, `coleção`,
  `todas as temporadas`, `temporadas completas`, ...) e
  [`TermosBusca::temMarcadorDePack()`](../backend/app/Services/Torrents/TermosBusca.php:88)
  é quem confere. `marcarPacks()` passou a usá-lo como **terceira via de prova**, ao
  lado da cobertura de temporada e do termo de pack. Como só chega ali o nome **sem**
  numeração de episódio (o corte anterior já descartou quem declara `SxxExx`), o
  "completo"/"coleção" no nome é pista de pacote, não ruído de título de filme.
- **O gate perdoa o que já tem a etiqueta.** Em `aproveitaveis()`, a barreira por
  ausência de número só vale quando a fonte **não** é pack
  (`empty($fonte['pack'])`). Um pack etiquetado já provou a temporada por outra via —
  cobertura, marcador ou o próprio termo de pack —, então não faz sentido barrá-lo
  pela mesma ausência de número que o marcador veio justamente suprir.

A defesa contra a temporada errada fica intacta: um pack que **declara** outra
temporada é barrado antes de o gate ser consultado, por
[`TermosBusca::correspondeAoEpisodio()`](../backend/app/Services/Torrents/TermosBusca.php:346),
e a última linha de defesa continua sendo
[`TorrentService::packDaTemporadaErrada()`](../backend/app/Services/TorrentService.php:440).
A etiqueta não é passe livre — é a permissão que o gate dava só a quem provava a
temporada, estendida a quem já foi reconhecido como pacote.

Os testes que travam este comportamento: [`GateFontesTest`](../backend/tests/Unit/GateFontesTest.php:1)
(o pack etiquetado passa, a mesma fonte sem etiqueta não, a homônima não, o episódio
numerado passa e o pack de temporada errada é barrado) e os casos de pack em
[`MontagemFinalTest`](../backend/tests/Feature/MontagemFinalTest.php:1).

#### O pack aprovado não pode morrer no gate da cascata

Mesmo com a etiqueta e o gate ajustados, o sintoma `"situacao":"descartado_na_montagem"`
com `"na_lista": 0` persistia quando o Torrentio e o Knaben encontravam dezenas de packs
(`packs: 10` e `packs: 12` no log). A causa estava em **dois** pontos, e os dois
descartavam o pack **antes** de ele chegar à montagem final:

1. **A numeração de episódio do arquivo interno desqualificava o pack.**
   [`marcarPacks()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1096) fazia
   `continue` quando algum nome declarava `SxxExx` — mas o `release` de um provedor por
   identificador é o nome do **arquivo interno** (`2x13 - Madness Ends`), não o do
   torrent. O pack da temporada chegava por um termo de pack, o arquivo interno escondia
   a temporada e a fonte nunca recebia a etiqueta. Sem etiqueta, o gate a barrava.
2. **O gate exigia a temporada no nome mesmo em termo de pack.**
   [`aproveitaveis()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1271) só
   perdoava a fonte com a etiqueta `pack`. O pack cujo nome nacional não numera nada
   (*"A Série Completa Dublado"*) e que veio de um termo de pack não tinha como provar a
   temporada pelo nome — e era descartado.

A correção faz o pack de temporada **chegar à montagem**:

- **O termo de pack prevalece sobre a numeração.** Em `marcarPacks()`, o corte por
  numeração de episódio só vale quando o termo corrente **não** é de pack. Em termo de
  pack, o arquivo interno numerado não desqualifica: quem confirma o pacote é o termo.
- **O gate perdoa o termo de pack.** `aproveitaveis()` passou a receber `$termoDePack` e
  a quarta via de prova é o próprio termo: se a busca veio de `"... S01 completa"`, o
  termo já declara a temporada pedida. A defesa contra a temporada errada continua em
  [`packDaTemporadaErrada()`](../backend/app/Services/TorrentService.php:453).

O pack que **declara** a temporada errada continua barrado — a exceção não abre espaço
para o pack da 2ª numa busca da 1ª. O teste que trava o comportamento está em
[`MontagemFinalTest`](../backend/tests/Feature/MontagemFinalTest.php:189): o pack da
temporada errada é descartado na montagem.

##### O corte duro de idioma continua valendo na montagem final

Chegar à montagem **não** é entrar na lista. O corte duro de idioma em
[`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:284) continua
sendo a última palavra: com `somente_pt_br_ou_legendado` ligado e havendo alguma fonte
PT-BR, a lista final é **só** o áudio PT-BR provado — dublado e dual. O original em
inglês e o legendado saem de vez.

Houve uma tentativa de anexar os packs de temporada à lista mesmo sem PT-BR provado
(`comPacksDeTemporada()`), tratando-os como "opção de reprodução". Ela foi **revertida**:
o efeito colateral era a lista misturar dois dublados com legendado e original, que é
exatamente o que o corte duro existe para impedir. O pack só entra na lista se **provar**
PT-BR (pela etiqueta de áudio ou pela inspeção do conteúdo); sem prova, ele fica na
reserva e só aparece quando **não há nenhuma** fonte PT-BR — aí o corte é revertido e a
reserva volta, porque uma lista vazia não é "só PT-BR", é o player sem nada para tentar.

Como a lista de indícios de áudio PT-BR foi ampliada (`multi áudio`, `multi audio`,
`duplo áudio`, `duplo audio` em [`IndiciosPtBr`](../backend/app/Support/IndiciosPtBr.php:37)
e no espelho [`idiomas.js`](../media-service/src/utils/idiomas.js:126)), o
`VERSAO_CACHE` de [`InspecaoPack`](../backend/app/Services/Torrents/InspecaoPack.php:37)
subiu para `2`: o veredito cacheado depende da leitura, e a inspeção antiga precisa ser
refeita na próxima busca.

### Ajustes obrigatórios no media-service

#### A paciência com a fonte é ajustável, não fixa

O sistema corta uma fonte por **tempo de espera**, não por velocidade. O
`TIMEOUT_DADOS_MS` conta a espera pelo **primeiro byte**: uma fonte com poucos
peers demora a conectar e, depois de conectada, baixa normalmente. Foi assim que
fontes vivas — que baixam sem problema em qualquer cliente de torrent — eram
descartadas pelo sistema e o player pulava para a próxima até cancelar.

Os limites agora saem do ambiente, para quem tem fontes lentas mas vivas esticar
a paciência sem mexer no código.

**No media-service:**

- `MEDIA_TIMEOUT_DADOS_MS` (padrão `30000`) — espera pelo primeiro byte em
  [`aguardarDados`](../media-service/src/services/sessoes.js:1376).
- `MEDIA_TIMEOUT_METADADOS_MS` (padrão `20000`) — espera pelos metadados do
  magnet em [`adicionarTorrent`](../media-service/src/services/sessoes.js:1158) e
  [`esperarMetadados`](../media-service/src/services/sessoes.js:1189).

**No frontend:**

- `VITE_TIMEOUT_FONTE_MS` (padrão `90000`) — prazo total por fonte em
  [`aguardarFonte`](../frontend/src/components/PlayerOverlay.vue:1651). Numa
  conexão de 1 Mbps o buffer inicial do HLS demora bem mais que os 90 s padrão, e
  uma fonte que baixa normalmente era abandonada no meio do preparo.
- `VITE_ESTAGNACAO_FONTE_MS` (padrão `20000`) — tempo parado a 0 MB/s antes de
  trocar de fonte.

Os limites do media-service precisam ficar **abaixo** do `VITE_TIMEOUT_FONTE_MS`,
para que o backend seja o primeiro a desistir e o overlay receba o motivo real em
vez de um cancelamento do navegador.

**A estagnação só vale para fonte que nunca entregou um byte.** Uma vez que
qualquer byte chegou, a fonte provou estar viva e não é mais abandonada por
estagnação — segue só sob o `VITE_TIMEOUT_FONTE_MS`. Sem essa trava, a velocidade
oscilava até zero entre ciclos enquanto o WebTorrent negociava, e uma conexão
lenta derrubava fontes boas no meio de um download que estava andando. A marca
`jaEntregouBytes` em [`aguardarFonte`](../frontend/src/components/PlayerOverlay.vue:1662)
é o que separa "fonte morta" de "fonte lenta".

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
- **Mapeamento explícito da faixa de áudio**: as fontes dubladas são MKV de
  *dual áudio* — trazem a dublagem PT-BR e o original na mesma caixa. Sem `-map`,
  o FFmpeg leva **todas** as faixas de áudio para o mesmo programa do MPEG-TS, e
  o `hls.js`/MSE não consegue decodificar um segmento com múltiplas faixas: o
  `SourceBuffer` rejeita o pedaço e o player falha com "não foi possível exibir o
  vídeo" — exatamente o sintoma relatado nas fontes dubladas. A conversão agora
  mapeia só o vídeo e a faixa escolhida (`-map 0:v:0 -map 0:a:N?`; o `?` deixa o
  mapeamento opcional, para não abortar quando a faixa some). A escolha fica em
  [`escolherFaixaAudio()`](../media-service/src/services/hls.js:215), que prefere
  o áudio em português (`por`/`pt`/`pt-*` via
  [`normalizarIdioma`](../media-service/src/utils/idiomas.js:87)) e, na falta
  dele, cai na faixa marcada como padrão ou na primeira. O índice escolhido sai
  de [`analisarArquivo()`](../media-service/src/services/hls.js:94) em
  `indiceAudio`, é guardado em `sessao.indiceAudio` e reaproveitado no
  reposicionamento — sem isso um *seek* remontaria a conversão com o mapeamento
  errado. O modo (`remux`/`audio`) também passou a olhar o codec **da faixa
  escolhida**, não o da primeira do arquivo: num dual áudio a dublagem pode vir
  em AC3 e o original em AAC, e decidir pelo codec errado levaria a um `remux`
  que copia um áudio que o MSE não decodifica.
- **A dublagem pode estar só no `title` da faixa**: a fonte rotulada "Torrentio ·
  Dublado" do *Lanterns* tocou em inglês. O rótulo do provedor é uma **promessa
  do indexador**, não um fato do arquivo — [`idiomaDoRotulo`](../backend/app/Services/Torrents/ProvedorTorrentio.php:279)
  só o usa para ordenar e filtrar as fontes. Quem decide o áudio é a faixa lida
  pelo ffprobe, e aí estava o furo: [`escolherFaixaAudio`](../media-service/src/services/hls.js:241)
  procurava português **apenas** na tag `language` (`faixa.codigo`). Muitos
  lançamentos *dual áudio* deixam a dublagem sem código de idioma e a identificam
  só no `title` ("Português (BR)", "DUBLADO", "PT-BR") — e
  [`descreverFaixaAudio`](../media-service/src/services/hls.js:206) já guardava
  esse título em `faixa.rotulo`, mas ninguém o consultava. A faixa PT-BR era
  descartada e o código caía no fallback (`padrao` ou a primeira), que num dual
  costuma ser o original. A escolha agora passa por
  [`descrevePortugues`](../media-service/src/services/hls.js:255), que aceita
  tanto o código (`por`/`pt`/`pt-*`) quanto o título normalizado (contendo
  "portug", "dublado", "dublagem", "pt-br" ou "ptbr"). O log da sessão ganhou uma
  linha com **todas** as faixas (`#0[eng|English] #1[por|Português (BR)]`) para
  separar os dois cenários: arquivo sem PT-BR nenhum (a fonte mentiu) de arquivo
  com PT-BR não reconhecido (bug de escolha).
- **Profundidade de cor decide o modo, não só o codec**: a causa raiz da série
  *Lanterns* — que baixava, convertia e morria com "não foi possível carregar o
  vídeo" — era o **H.264 10-bit**. O `codec_name` de um encode 10-bit
  (`yuv420p10le`) é `h264`, idêntico ao de um 8-bit, então
  [`decidirModo`](../media-service/src/services/hls.js:450) o classificava como
  compatível e o vídeo era **copiado** (`remux`/`audio`). O `SourceBuffer` do
  navegador aceita o `mimeCodec` (`avc1...`) mas **rejeita o `appendBuffer`** de
  um stream 10-bit: o hls.js emite `bufferAppendingError` seguido de
  `mediaSourceRequiresReset` em ciclo, e a reprodução nunca arranca. As séries
  antigas são 8-bit e por isso funcionavam. A correção lê o `pix_fmt` no
  [`analisarArquivo`](../media-service/src/services/hls.js:95) e o repassa a
  `decidirModo`: só os formatos de `PIX_FMT_COMPATIVEIS` (`yuv420p`/`yuvj420p`)
  são copiáveis; **qualquer outro — inclusive a ausência do dado — força o modo
  `video`**. No modo `video`,
  [`aplicarModo`](../media-service/src/services/hls.js:835) agora declara
  `-pix_fmt yuv420p` — sem isso a transcodificação manteria a profundidade de
  origem e o `SourceBuffer` voltaria a recusar o trecho. O `pix_fmt` aparece no
  log da sessão (`pixFmt=yuv420p10le`) para diagnóstico.
- **`pix_fmt` ausente também transcodifica**: a primeira correção de 10-bit não
  bastou. O `bufferAppendingError` voltou numa sessão seguinte porque o
  `codec_name` sai do cabeçalho do contêiner e chega **antes** dos dados,
  enquanto o `pix_fmt` depende do SPS do H.264, que vive nos primeiros quadros do
  stream. Um cabeçalho lido pela metade devolvia `codec_name='h264'` com
  `pix_fmt` vazio: [`analisarComEspera`](../media-service/src/services/sessoes.js:1223)
  aceitava a análise só com os codecs e `decidirModo` tratava a ausência como
  "não é 10-bit", copiando o vídeo. Duas defesas fecham isso: (1) a análise só é
  aceita quando o `pix_fmt` também veio, usando
  [`profundidadeRelevante`](../media-service/src/services/hls.js:422) para exigir
  o dado apenas quando o codec seria copiado — com o torrent completo a análise é
  aceita mesmo sem ele, para não travar para sempre; (2) `decidirModo` inverteu a
  regra: `pixelOk` exige **prova** de 8-bit, então a ausência do dado força
  `video`. Copiar um vídeo de profundidade desconhecida nunca mais acontece.
- **Nível H.264 derivado, nunca fixo**: o modo `video` declarava `-level 4.0` à
  mão. O nível é uma **promessa** gravada no SPS/PPS, e o encoder a escreve mesmo
  quando o conteúdo real a excede — um encode 1080p de bitrate alto (ou 2160p)
  estoura o teto do Nível 4.0 (~20 Mbps) e o cabeçalho mentia. Removemos o
  `-level 4.0`: sem ele, o FFmpeg deriva o nível correto de resolução, bitrate e
  framerate. O perfil `main` permanece — descreve recursos que o encoder
  realmente usa (B-frames, entropia), não um teto de banda. Esta correção é
  preventiva; a falha do caso *Lanterns* era a profundidade de cor, acima.
- **Recuperação de erro de mídia no player**: um `MEDIA_ERROR` fatal do `hls.js`
  não é rede — o segmento chegou, mas o `SourceBuffer` o recusou. Antes, o handler
  em [`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:713) só
  tratava `NETWORK_ERROR`/`FRAG_LOAD_ERROR` como recuperável e mandava qualquer
  outro erro fatal direto para a tela de falha. Agora um `MEDIA_ERROR` chama
  `recoverMediaError()` (até `LIMITE_RECUPERACOES_DE_MIDIA` = 3 vezes), que
  descarta o `SourceBuffer` e cria outro com o mesmo codec, retomando do ponto
  onde parou. O contador zera a cada nova reprodução e a cada *seek* remoto,
  porque a playlist nova é outro stream. É uma rede de segurança: no caso
  *Lanterns* ela não salvava porque o stream inteiro era 10-bit — a correção real
  é a profundidade de cor, acima.
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
- **Duração infinita para a playlist `EVENT`**: como o `#EXT-X-ENDLIST` só
  aparece no fim da conversão (item acima), durante o filme inteiro o hls.js lê a
  playlist como **live** e conhece apenas os trechos já publicados — nos
  primeiros segundos do *Lanterns*, 40 s de 3388. Com o padrão
  `liveDurationInfinity: false`, o `getDurationAndRange()` do hls.js gravava essa
  duração parcial no `mediaSource.duration`; o buffer podia então alcançar o fim
  declarado, o `bufferEOS` chamava `mediaSource.endOfStream()` e o MediaSource
  ficava em `readyState === "ended"`, de onde **todo** `appendBuffer` falha. É
  uma armadilha latente de qualquer playlist que ainda está crescendo, e por isso
  a configuração em
  [`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:474) ficou
  com `liveDurationInfinity: true`: nos níveis live a duração permanece
  `Infinity` e não existe "fim" que o buffer possa alcançar. A barra de progresso
  não perde nada — a duração exibida continua vindo de `aplicarDuracaoReal()`,
  que existe justamente porque a playlist `EVENT` reporta `Infinity`. Fica o
  registro de que **não foi esta** a falha do *Lanterns*: a sonda de erro do
  player passou a imprimir a duração do elemento e o fim do buffer, e o primeiro
  `bufferAppendingError` chegava com `duracao=Infinity` e `buffer=-` — ou seja, o
  `readyState: "ended"` era consequência do primeiro append recusado, e não de um
  EOS que tivéssemos provocado.
- **AAC canônico na saída (`-ac 2` e `-ar 48000`)**: com a duração refutada, o
  `ffprobe` de um segmento real da sessão entregou a causa de verdade. O vídeo do
  trecho decodificava inteiro (`h264 High 1920x960 yuv420p level=40`), enquanto o
  áudio saía como `aac, sample_rate=0, channels=0, channel_layout=unknown`, com
  **todos** os quadros recusados pelo decodificador. Como o vídeo vinha de
  `-c:v copy`, a entrada estava sã: o defeito nascia no reencode do áudio, o
  caminho que [`decidirModo`](../media-service/src/services/hls.js:465) escolhe
  (`audio`) sempre que a faixa não é copiável para o MSE — o caso do E-AC-3 5.1
  dos lançamentos dublados. O encoder AAC do FFmpeg não tem configuração
  equivalente para `5.1(side)`: sem equivalente ele grava
  `channel_configuration = 0` no ADTS e descreve os canais num PCE (*Program
  Config Element*) dentro dos quadros, um cabeçalho que navegador nenhum lê. O
  Chromium responde `MediaError.code = 4` com
  `PipelineStatus::CHUNK_DEMUXER_ERROR_APPEND_FAILED: RunSegmentParserLoop:
  stream parsing failed`, encerra o MediaSource já no primeiro append inválido e
  o resto vira cascata: `bufferAppendingError` → `mediaSourceRequiresReset`,
  esgotando as `recoverMediaError()` até o erro fatal. A correção está em
  [`fixarAacCanonico`](../media-service/src/services/hls.js:838), usada pelos
  modos `audio` e `video`: `-ac 2` e `-ar 48000` produzem um AAC-LC estéreo com
  `channel_configuration = 2` e taxa explícita no ADTS, o formato que qualquer
  decodificador reconhece. O `remux` continua copiando o áudio da fonte sem
  tocar nele — por ali só passam faixas que o navegador já decodifica. No log da
  sessão a linha `[hls] ffmpeg: Stream #0:1: Audio: aac (LC), 48000 Hz, stereo,
  fltp, 192 kb/s` confirma o cabeçalho canônico.
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

#### Trocar de episódio não pode tocar o anterior

O overlay do player observava o **booleano** `aberto` (`props.filme !== null`) para
decidir quando começar e quando limpar. Isso bastava enquanto o único caminho era
"abre o player, fecha o player": o valor ia de `false` a `true` e o watcher
disparava. O problema apareceu na série, quando o usuário fecha o episódio 1 e
escolhe o episódio 2: o pai troca o objeto de uma vez, sem passar por `null`, e o
booleano continua `true`. O watcher não via mudança nenhuma — a sessão do episódio
1 seguia viva no media-service e o player nunca era reconstruído, então o episódio
2 tocava o episódio 1.

A correção troca o gatilho pelo **conteúdo**: o watcher observa
[`props.filme`](../frontend/src/components/PlayerOverlay.vue:1991) inteiro. Cada
episódio é um objeto novo, então a troca sempre conta como caso novo.

##### A limpeza mora num lugar só

A primeira tentativa limpava o título anterior dentro do próprio watcher, e só
quando havia um valor anterior não nulo. Isso deixava de fora o caminho mais
comum: **fechar o player** (a prop vira `null`) e **reabrir** outro episódio. Nesse
caso o watcher chega com o valor anterior `null`, a limpeza era pulada, e o
`<video>` do episódio anterior continuava montado — com o estado ainda em
`reproduzindo`. O player novo reaproveitava o mesmo nó e o episódio 2 tocava o
episódio 1.

Agora a limpeza é incondicional e vive no começo de
[`iniciar()`](../frontend/src/components/PlayerOverlay.vue:1879): ele chama
`destruirPlayer()` e `await limparSessao()` antes de qualquer outra coisa. Os dois
caminhos — troca direta e reabertura após fechar — passam pelo mesmo ponto, sem
assimetria.

##### Abertura e geração são contadores diferentes

Havia ainda uma corrida sutil. `limparSessao()` avançava a geração para invalidar
os passos assíncronos do fluxo que saía. Mas o fluxo novo capturava a geração
**depois** da limpeza, e a limpeza faz uma chamada de rede (`await
encerrarSessao`). Se outra abertura começasse durante essa espera, o fluxo antigo
acordaria com um número maior que o do novo e se acharia o corrente — sobrescrevendo
o player do episódio recém-aberto.

A solução separa as duas responsabilidades:

- **`abertura`** ([`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:151))
  é a identidade da abertura. Só avança em `iniciar()`, uma vez por abertura, e é o
  que os passos assíncronos comparam para saber se ainda são os correntes.
- **`geracao`** continua invalidando os passos do fluxo que sai, mas agora só é
  avançada por `iniciar()`, **depois** da limpeza. `limparSessao()` não mexe mais
  nela — apenas marca `cancelado`, limpa os timers e encerra a sessão no servidor.

Assim, cada abertura tem uma identidade estável desde o primeiro instante, e a
limpeza do episódio anterior nunca derruba o episódio novo.

##### O pack PT-BR numera o episódio sem "SxxExx"

Mesmo com o player reconstruído e a numeração certa chegando ao media-service, o
episódio continuava errado — e o log provou por quê:

```
[WARN] [torrent] nenhum arquivo casa S1E4; usando o maior vídeo do pacote
[INFO] [sessao ...] episódio S01E04 -> arquivo ".../12 - Afterbirth.mp4"
```

O pack dublado **"American Horror Story 1ª Temporada [2011 DUAL ÁUDIO] 720p PT
BR"** nomeia os arquivos como `12 - Afterbirth.mp4` — número do episódio, um
separador e o título. Não há `S01E04` em lugar nenhum. O
[`caminhoCorrespondeAoEpisodio()`](../media-service/src/services/sessoes.js:1294)
não reconhecia esse formato, caía no fallback "maior vídeo do pacote" e devolvia
**sempre o mesmo arquivo** — o maior, que por acaso era o `12 - Afterbirth.mp4`.
Daí a impressão de que "sempre executa o ep 1": na verdade era sempre o mesmo
arquivo do pack, qualquer que fosse o episódio pedido.

A correção ancora o número no **começo do nome do arquivo** (depois da última
barra), seguido de um separador:

```js
new RegExp(`^0*${episodio}\\s*[-._]\\s*\\S`, 'i')
```

A âncora e o separador obrigatório são o que separam o episódio da resolução:
sem eles, `720p` casaria o episódio 7 e `1080p` o episódio 10. O separador
obrigatório já fecha essa brecha, então o lookahead `(?!p\b)` foi **removido** —
ele barrava títulos legítimos que começam com "P" ("Pilot", "Parte", "Prólogo"),
e era por isso que `01 - Pilot.mp4` não casava e o fallback escolhia o maior
vídeo do pacote (o episódio errado). O padrão cobre `12 - Título`, `2. Título`,
`2_Título` e `01 - Pilot`, e convive com os formatos antigos (`S01E02`, `1x02`,
`Episódio 02`, `Capítulo 02`) e com o prefixo `Ep 01`.

#### O pack da 2ª temporada invadia a 1ª quando o `release` era o nome do arquivo

O gate de temporada julgava a fonte por **um** nome só — `release ?? titulo`. Nos
provedores por identificador o `release` é o nome do torrent (a primeira linha do
rótulo), mas em alguns provedores ele acaba sendo o nome do **arquivo interno**.
O log mostrou o caso exato:

```
[sessao 0d901920] episódio S01E01 -> arquivo "American Horror Story.2ª.Temporada.Dual.Áudio.720p.By.Luan.Harper/2x13 - Madness Ends (Season Finale).mp4"
```

O `release` era `2x13 - Madness Ends` e o `titulo` era o nome do torrent
(`2ª.Temporada`). Como `numeracaoDoTitulo('2x13 - Madness Ends')` devolve
temporada=2, episódio=13, o código tratava a fonte como "episódio mal marcado" e
**nunca a marcava como pack** — então a defesa final (`packDaTemporadaErrada()`),
que só julga packs, nem era acionada. O pack dual da 2ª, com mais seeds, subia ao
topo de uma busca da 1ª.

A correção lê **os dois nomes** em três pontos — [`marcarPacks()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1118),
o gate de [`aproveitaveis()`](../backend/app/Services/Torrents/CatalogoProvedores.php:1241)
e a defesa final [`packDaTemporadaErrada()`](../backend/app/Services/TorrentService.php:440).
Dois helpers novos em [`TermosBusca`](../backend/app/Services/Torrents/TermosBusca.php:505)
concentram a leitura:

- `temporadaDosNomes()` — devolve a temporada declarada por **qualquer** dos
  nomes. Um nome que declara "2ª Temporada" prova a temporada mesmo que outro
  traga "2x13".
- `algumNomeCobreTemporada()` — responde se a temporada pedida está coberta por
  algum nome, inclusive faixas ("1ª 2ª 3ª Temporadas").

Só quando **nenhum** nome declara temporada é que a numeração de episódio volta a
decidir — o comportamento de antes, para não apagar episódios legítimos.

#### "Temporadas 720p" era lido como temporada 72

Ao cobrir o caso acima, os testes revelaram um bug pré-existente na leitura de
faixa. A regex de "Temporada 1 / Season 1" tinha o `s?` opcional e um `\s*`
solto: em "American Horror Story 1ª 2ª 3ª **Temporadas 720p**" ela consumia o "s"
de "Temporadas" e pulava para a resolução, capturando `72`. Com o 72 na lista,
`max($numeros)` virava 72 e **qualquer** temporada "cabia" na faixa — o gate
aprovava o que devia reprovar.

A correção acrescenta o lookahead `(?![\d p])` nas duas leituras (a simples e a
de lista), rejeitando tanto o dígito seguinte ("720" não vira "72") quanto a
resolução colada ("720p"). A leitura de faixa ("Seasons 1 to 8") também passou a
vir **antes** do corte de lista vazia: em "Seasons 1 to 8" a regex de lista não
casa nada (o "1" é seguido de " to", não de vírgula), e o early-return apagaria o
pack antes de a faixa ser lida.

#### O arquivo completo que o ffprobe recusa não é "cabeçalho ilegível"

O erro dominante nos logs era `ffprobe exited with code 1`, reportado como "Não
foi possível ler o cabeçalho do vídeo". A causa é que
[`analisarComEspera()`](../media-service/src/services/sessoes.js:1470) desiste
quando `arquivo.progress >= 1` e lança a mesma mensagem para dois desfechos
distintos: o arquivo que **nunca terminou de baixar** (fonte lenta) e o arquivo
**completo que o ffprobe recusou** (corrompido, falso vídeo ou o episódio errado
escolhido pelo fallback). A mensagem agora separa os dois casos e registra o nome
do arquivo no log:

```
[torrent] arquivo completo mas ilegível: "12 - Afterbirth.mp4" (1234567 bytes) — ffprobe exited with code 1
```

#### O caminho do arquivo precisa da pasta do torrent

Um segundo erro, ainda mais enganoso, aparecia como `No such file or directory`
no caminho `/tmp/American Horror Story 1ª Temporada [2011 DUAL ÁUDIO] 720p PT
BR/01 - Pilot.mp4` — com o arquivo existindo no disco. A causa não era o nome
com espaços, acentos e colchetes: o `path.join` monta a string e o fluent-ffmpeg
a repassa como argumento de vetor, sem passar pelo shell. O problema era que o
WebTorrent baixa cada torrent dentro de uma pasta com o **nome do próprio
torrent** (`torrent.path/<torrent.name>/...`), e o `arquivo.path` que ele expõe
já é relativo a essa pasta. Juntar apenas `torrent.path` com `arquivo.path`
produzia um caminho sem o nível intermediário, e o ffprobe procurava o arquivo
um diretório acima de onde ele estava.

O helper [`caminhoDoArquivo()`](../media-service/src/services/sessoes.js:1207)
centraliza essa montagem e cobre também o caso em que o `arquivo.path` já vem
prefixado com o nome do torrent (versões antigas do WebTorrent): nesse cenário
o segmento não é duplicado.

#### O ffprobe não pode ser disparado antes de o arquivo existir

O mesmo log mostrava `percentual: 3` no momento da falha. Nos primeiros
porcentos do download o WebTorrent ainda não criou o arquivo (ou o criou com
tamanho zero), e o ffprobe devolvia `No such file or directory` — indistinguível,
na mensagem, de um arquivo corrompido. Três guardas resolvem o caso:

1. [`prepararSessao()`](../media-service/src/services/sessoes.js:583) agora
   aguarda o começo do filme estar contíguo em disco (`aguardarInicio()`) antes
   de sondar o cabeçalho. Isso também evita que o ffprobe leia um arquivo
   esparso e interprete os zeros do trecho não baixado como dados inválidos.
2. [`analisarComEspera()`](../media-service/src/services/sessoes.js:1470) confere
   o tamanho físico do arquivo a cada volta e só chama o ffprobe quando ele
   passa de `TAMANHO_MINIMO_SONDAGEM` (1 MB).
3. [`analisarArquivo()`](../media-service/src/services/hls.js:116) valida a
   existência do arquivo e lança um erro rotulado com `code = 'ENOENT'`, para o
   laço distinguir "ainda não existe" de "existe mas o ffprobe recusou".

A cobertura está em
[`caminho-arquivo.test.js`](../media-service/test/caminho-arquivo.test.js:1),
que roda com `npm test` (runner nativo do Node).

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

### Stream direto (MP4/HLS) — o socorro do conteúdo raro

A cascata de torrents resolve bem o conteúdo com seeders, mas falha justamente
onde o acervo PT-BR é mais frágil: lançamentos antigos, novelas, filmes de
catálogo. Nesses casos o torrent morreu e nenhuma quantidade de termos de busca
o ressuscita. O **stream direto** é a rede de segurança para esse cenário — o
equivalente ao comportamento de apps como Lumigo/Stremio, que buscam um link de
vídeo pronto (MP4 ou HLS) em vez de uma malha P2P.

#### O provedor é um fallback, não um degrau

[`ProvedorStreamDireto`](../backend/app/Services/Torrents/ProvedorStreamDireto.php:1)
implementa o mesmo contrato `ProvedorTorrents` dos demais, mas **não** entra na
cascata normal. O gatilho mora em
[`TorrentService::fontes()`](../backend/app/Services/TorrentService.php:96), e não
dentro do catálogo: ele dispara **depois** de `ordenar()`. O caminho comum
(torrent PT-BR vivo) não paga nenhum custo extra — a consulta direta nem chega a
acontecer.

O momento importa. A cascata pode receber dezenas de fontes do Torrentio e o
filtro de idioma (`ePtBr`) descartar todas: para o usuário, isso é lista vazia, e
é aí que o socorro vale. Checar a lista **bruta**, antes do filtro, acionaria o
fallback mesmo quando metade do que veio ainda ia ser aproveitada — e o provedor
direto gastaria orçamento à toa. Por isso a checagem é pela lista que
[`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:372)
devolveu, não pela que o catálogo recolheu.

O critério é **"a lista final ficou vazia"**, e o `ordenar()` garante que isso
signifique "não sobrou áudio PT-BR". O corte duro de idioma
(`somente_pt_br_ou_legendado`) não abre exceção para a reserva: quando só veio
release em inglês, o `ordenar()` devolve **lista vazia**, e não o original como
consolação. Antes ele revertia o corte e devolvia a reserva "para o player não
ficar sem nada" — e era exatamente isso que impedia o fallback de disparar, porque
a lista nunca ficava vazia. A reserva em inglês não é resposta para quem pediu
português: o que resolve o conteúdo raro é o stream direto. A reserva só volta
quando o corte está desligado (`somente_pt_br_ou_legendado = false`), aí sim o
usuário aceita qualquer idioma. Se o provedor direto não achar nada, a lista
permanece vazia — o cliente mostra "sem fontes" em vez de oferecer um release que
o usuário não pediu.

O acionamento passa por
[`CatalogoProvedores::buscarFallbackDireto()`](../backend/app/Services/Torrents/CatalogoProvedores.php:473),
que é público justamente para ser chamado de fora da cascata. As fontes diretas
entram já montadas e **não** voltam por `ordenar()`: são o último recurso, e
reordená-las junto com os torrents só as misturaria a uma lista que, por
definição, está vazia.

O fallback abre um **orçamento próprio** (`TORRENTS_STREAM_DIRETO_ORCAMENTO`,
padrão 45 s) porque o orçamento global já foi consumido pelos três degraus. Sem
isso, a consulta direta nasceria sem tempo para responder.

##### A cascata não aborta com a lista de títulos vazia

Havia em `fontes()` um `return []` preventivo: quando
[`titulosDeBusca()`](../backend/app/Services/TorrentService.php:238) ou
[`titulosDeEpisodio()`](../backend/app/Services/TorrentService.php:267) devolviam
lista vazia (título em branco vindo do catálogo), a busca era encerrada **antes**
de qualquer provedor ser consultado. O usuário recebia "nenhuma fonte encontrada"
sem que o Torrentio ou os indexadores tivessem sido ouvidos.

Isso estava errado por dois motivos. Primeiro, os provedores por identificador
(Torrentio, addons Stremio) respondem pelo `imdb_id`, não pelo termo — uma lista
de termos vazia não é motivo para não perguntar. Segundo, mesmo que a cascata
voltasse vazia, o fallback de stream direto ainda teria o título original para
trabalhar; abortar cedo matava essa última chance. A lista vazia é um caso
legítimo, não um erro que justifique desistir da busca inteira. O
[`CatalogoProvedores::buscar()`](../backend/app/Services/Torrents/CatalogoProvedores.php:210)
já trata o caso com segurança: a rodada de abertura só roda se houver um primeiro
título, os laços sobre lista vazia não iteram e o YTS recebe string vazia e se
abstém.

##### O fallback recebe o título traduzido e o original

A cascata só consulta o título original quando
[`valeSegundaTentativa()`](../backend/app/Services/TorrentService.php:177) manda —
é uma segunda fase que custa orçamento. O scraper web não tem esse custo: ele
pergunta pelo nome mais provável, e o título original é justamente o que funciona
para o conteúdo raro ("Desperate Housewives" acha o que "Donas de Casa
Desesperadas" não acha). Por isso, ao montar a lista que vai ao fallback, o
`fontes()` acrescenta o título original ao traduzido, sem duplicar quando ele já
entrou pela segunda fase. Sem isso, um título cuja tradução não rendeu termo
nenhum chegaria ao fallback sem nenhuma pista.

#### O scraper é autónomo: não há API de agregação

A primeira versão do provedor esperava uma API externa configurada em
`TORRENTS_STREAM_DIRETO_FONTES` — um endereço que devolvesse JSON com links
prontos. O problema é que essa API não existe de graça: ou é paga, ou é instável,
ou simplesmente não cobre o acervo PT-BR que motiva o fallback. O provedor virou,
então, um **scraper web autónomo**: ele mesmo procura a página de streaming e
extrai o vídeo dela, sem depender de terceiros.

O fluxo tem quatro etapas, cada uma num arquivo próprio:

1. **A query** — [`TermosStreamDireto`](../backend/app/Services/Torrents/TermosStreamDireto.php:1)
   monta o termo de busca. O provedor de torrents pergunta pelo nome do release
   ("Titulo S01E01 1080p"); o scraper pergunta outra coisa, porque quem publica
   página de streaming escreve o título como o usuário digita no Google — sem tag
   de qualidade, sem codec, sem grupo. Os termos são montados em camadas, do mais
   preciso ao mais amplo: título + numeração + intenção ("Donas de Casa
   Desesperadas 1x01 assistir online dublado"), depois título + numeração, e por
   fim título solto + intenção. A numeração é perguntada em quatro grafias
   (`1x01`, `S01E01`, `Temporada 1 Episódio 1`, `1ª Temporada Episódio 1`) porque
   cada site usa uma. A ordem importa: o provedor consome os termos de cima para
   baixo e para assim que junta páginas suficientes.

   A última camada é a **rede de segurança**: quando o acervo PT-BR não tem
   página nenhuma, o título original entra na busca — "Desperate Housewives
   S01E01" — **sem** a intenção de idioma. A ideia é achar qualquer página de
   vídeo e deixar a normalização posterior decidir o que serve. O catálogo passa
   a lista inteira de títulos (o principal e as variações) via
   `buscarComTitulos()`, e não só o primeiro.

2. **A busca direta e o motor de busca** — antes de acionar o motor web, o
   [`BuscaAgregadores`](../backend/app/Services/Torrents/BuscaAgregadores.php:1)
   pergunta à busca interna dos agregadores de vídeo (ver a subseção *A busca
   direta nos agregadores* logo abaixo). Depois, o
   [`MotorBuscaWeb`](../backend/app/Services/Torrents/MotorBuscaWeb.php:1)
   resolve o termo em URLs de páginas. O motor é o **SearXNG interno** do compose
   (`http://searxng:8080/search`): um meta-buscador que sobe junto com o stack, tem
   cota própria e devolve JSON limpo (`format=json`). Ele não depende de instância
   pública nem do IP do container ser aceito por terceiros — é o caminho que foge
   do bloqueio. A lista de motores é configurável (`stream_direto_motores`). Com
   mais de um endereço, o serviço **percorre todos e agrega** o que cada um
   devolveu — um motor que respondeu com poucos resultados não impede o outro de
   contribuir. Antes o laço parava no primeiro motor com resultado, o que
   desperdiçava os demais quando o primeiro devolvia uma lista magra.

   ##### O DuckDuckGo foi removido do projeto

   A primeira versão usava o **DuckDuckGo HTML/Lite** como motor: página estática,
   sem JavaScript e sem chave de API, com os resultados em `<a class="result__a">`
   (ou `result-link`, no Lite), e o `href` embrulhado num redirecionamento
   (`//duckduckgo.com/l/?uddg=<url-encoded>`) que o serviço decodificava. O DDG,
   porém, passou a bloquear o IP dos containers com **status 202** (rate limit) e a
   responder 200 com página de captcha no corpo — o que obrigava o serviço a
   carregar um parser de HTML, uma varredura de marcas de bloqueio e um tratamento
   especial de status que só existiam para contornar o bloqueio.

   Com o SearXNG interno funcionando, todo esse aparato virou peso morto. O DDG foi
   **removido de todas as camadas**: dos motores padrão, do `.env`, do
   `config/services.php`, do `MotorBuscaWeb` (que perdeu o tipo `ddg`, o parsing de
   HTML, o desembrulho do `uddg`, a varredura de bloqueio e o tratamento de 202) e
   até de dentro do próprio SearXNG, no
   [`docker/searxng/settings.yml`](../docker/searxng/settings.yml:1). Se um dia for
   preciso redundância, ela deve vir de **outra instância SearXNG** — nunca de um
   buscador comercial que bloqueia o IP dos containers.

#### A busca direta nos agregadores — o atalho que dispensa o motor

O motor de busca aberto é a peça mais frágil da corrente do stream direto. Quando
os motores que o SearXNG consulta por baixo (Brave, DuckDuckGo, Google) suspendem
o IP do container — o que é rotina —, sobra o Bing, que devolve só plataforma
legal. A lista negra descarta tudo e a busca volta vazia **antes** de qualquer
página ser visitada: o `stream_direto` termina em `sem_resultado` sem sequer
acionar a renderização.

O [`BuscaAgregadores`](../backend/app/Services/Torrents/BuscaAgregadores.php:1)
fecha essa brecha rodando **antes** do motor: em vez de perguntar ao SearXNG onde
o episódio mora, pergunta direto à **busca interna** dos agregadores de vídeo,
cujo resultado já é a página do título. É o mesmo caminho do usuário — em vez de
googlar "assistir X", digita X na busca do próprio site.

A lista é curta e por conhecimento de causa — hoje, só o `verpobreflix.net`. Cada
agregador declara duas coisas: onde a busca mora (`/search?q=`) e qual prefixo de
caminho identifica o conteúdo na página de resultado (`/series/`). O domínio
serve de âncora — só links do próprio site entram, o que descarta menu, rodapé e
link patrocinado sem precisar de lista negra. O resultado do verpobreflix é a
ficha da série, e o provedor desce dali para a página do episódio pelo caminho que
já existia.

A relevância pelo título é conferida **na hora da busca**, sobre o slug do link —
e não só lá na frente, sobre a página aberta. A página de resultado costuma vir
salpicada de "veja também", e cada link desses custaria uma requisição e uma vaga
do teto de páginas antes de ser descartado. Filtrar cedo é barato e deixa o crivo
final (título da página e URL do vídeo) exatamente onde estava.

O contrato não muda: a saída é uma lista de URLs de página, do mesmo tipo que o
`MotorBuscaWeb::procurar()` devolveria. O provedor processa as duas origens pelo
mesmo caminho — relevância pelo título, prova de mídia e extração —, no laço
`visitar()`. O motor continua rodando **em seguida**, como redundância: se a busca
direta não der em nada, a varredura web acontece igual. Desligar o atalho é só
`TORRENTS_STREAM_DIRETO_BUSCA_DIRETA=false`.

##### O `tokyvideo.com` saiu da lista: busca montada por JavaScript

O `tokyvideo.com` chegou a estar no mapa e foi removido. O `/search?q=` dele é
montado no cliente: o HTML que se baixa sem navegador traz a grade de vídeos
populares da barra lateral e **zero ocorrência do termo pedido** — buscando
"American Horror Story", os endereços que voltaram eram
`/video/the-hobbit-couch-gag`, `/video/cinnamon-rolls-by-skill-level` e afins. Com
a página de resultado vazia de pistas, o agregador só injetava ruído na varredura.
A regra da lista passa a ser essa: só entra agregador cujo resultado de busca
esteja no HTML baixado. O `tokyvideo` continua útil pelo outro caminho — o motor
web acha o episódio (`/br/video/american-horror-story-ep1`) e o provedor extrai o
`.mp4` —, mas não pela busca direta.

##### A query string era descartada antes de sair

A busca direta só funciona porque uma correção veio antes dela. O `get()` do
Laravel recebe os parâmetros num segundo argumento, e o Guzzle os usa para
**substituir** a query embutida na URL. O
[`ClienteHttp`](../backend/app/Services/Torrents/ClienteHttp.php:1) repassava esse
argumento com um array vazio por padrão, então todo endereço com busca já embutida
chegava ao servidor **sem ela**: `verpobreflix.net/search?q=American+Horror+Story`
virava `/search`, e a resposta era a própria home do site — 200, sem erro, sem
resultado. O sintoma era mudo: nenhuma exceção, só um vazio que parecia "o site
não tem isso". Agora o parâmetro só é repassado quando existe; sem ele, a
requisição sai com um argumento só e a query da URL fica intacta. A correção vale
para qualquer chamada que embuta a busca no endereço — inclusive os trackers
PT-BR nativos
([`ProvedorTrackersBr`](../backend/app/Services/Torrents/ProvedorTrackersBr.php:122)),
que montam a busca do mesmo jeito. Hoje eles estão desligados por configuração
(`TORRENTS_TRACKERS_BR_URLS` vazio), mas o defeito voltaria a aparecer quando
fossem ligados.

##### O que a verificação ao vivo mostrou

Com o atalho ligado, a busca direta resolveu "American Horror Story" sem o motor:
voltou a ficha `verpobreflix.net/series/american-horror-story`, o provedor desceu
para `/temporada-1/episodio-1` e registrou "sem arquivo de vídeo extraível". O
episódio está certo — o HTML estático dele traz o iframe
`https://plenoflu.com/tvshow/1413/1/1` —, mas é um **embed**, não um `.mp4`/`.m3u8`,
e o [`ProvedorStreamDireto`](../backend/app/Services/Torrents/ProvedorStreamDireto.php:1)
só aceita arquivo tocável. É exatamente o segundo caminho já registrado na
reverificação logo abaixo: atravessar o player cifrado. A busca direta fez a sua
parte — o que falta não é achar a página, é o player dela.

#### O `plenoflu.com` tem API aberta, mas ela não entrega vídeo

O `plenoflu.com` (que se apresenta como "Pobre Play" / "SuperCDN") é o agregador
de embed que o `verpobreflix.net` usa por baixo. Ele tem site, documentação em
`/doc/` e uma API em `POST /api` — e a pergunta natural foi se dava para falar
com essa API em vez de raspar HTML. A resposta curta é **sim, a API responde sem
cadastro — mas ela não resolve o problema**, e vale registrar por quê.

**A API funciona sem registro.** O cadastro do site está desativado, mas as
ações de leitura não exigem conta. O único requisito é um cabeçalho `Referer`
(qualquer valor serve): sem ele, o site devolve `403 Acesso proibido`; com ele,
`200`. Duas ações bastam para chegar aos players:

- `action=getOptions` com `contentid` (o ID interno do episódio, que a própria
  página do embed expõe como `DIRECT_EPISODE_ID`) devolve a lista de opções de
  player — dublado e legendado.
- `action=getPlayer` com `video_id` devolve, em `data.video_url`, a URL do
  player **codificada em base64** (`base64_decode` revela o endereço).

Para "American Horror Story" S01E01, a cadeia inteira respondeu: `getOptions`
devolveu quatro opções, e `getPlayer` devolveu quatro provedores —
`vaiquecol.com`, `superflixapi.quest`, `streambetter.shop` e `vidsrc.sh`.

**O problema é o que vem depois.** Nenhum desses quatro provedores entrega um
arquivo de vídeo; todos entregam **outra página de player**, e cada uma tem a
sua própria blindagem:

- `vaiquecol.com` usa o **FirePlayer** com o código empacotado (`eval`), e o
  endpoint `getVideo` responde "Video not found" para qualquer ID que não venha
  da sessão do navegador.
- `superflixapi.quest` e `streambetter.shop` estão atrás do **Cloudflare
  Turnstile** — o FlareSolverr devolve a página de "Verificação", não o player.
- `vidsrc.sh` delega ao `cloudorchestranova.com`, cujo token `vs` **expira** e
  cuja API (`data.vidsrc.sh`) devolve só metadados; o `stream_urls` vem cifrado
  em **ChaCha20 via WebAssembly** (`vsdec.js`), decifrado apenas no navegador.

Ou seja: a API do `plenoflu.com` é um **catálogo de embeds**, não uma fonte de
vídeo. Ela economiza a etapa de raspar a página do agregador, mas entrega
exatamente o que o contrato da fonte direta recusa — uma URL de player, não um
`.mp4`/`.m3u8`. O [`prepararSessaoDireta()`](../media-service/src/services/sessoes.js:441)
do media-service exige uma extensão em `EXTENSOES_DIRETAS` e rejeita o resto com
`formato_desconhecido`; oferecer um embed como fonte só produziria uma sessão
que morre na primeira checagem.

**A conclusão prática:** integrar a API do `plenoflu.com` moveria o problema um
degrau adiante, não o resolveria. O que falta não é descobrir o embed — é
**resolver o embed** (executar o JavaScript do player, vencer o Turnstile,
decifrar o ChaCha20), e isso é trabalho de um navegador headless com sessão, não
de um cliente HTTP. Enquanto esse resolvedor não existir, a API do `plenoflu.com`
não tem lugar no caminho da fonte direta.

##### O FlareSolverr não socorre estes provedores — e o motivo é o IP

Vale registrar o teste que fecha a questão, porque ele descarta a saída mais
óbvia ("é só renderizar com o FlareSolverr"). O FlareSolverr **é** um Chromium
headless e executa JavaScript, mas ele falha nos quatro provedores por um motivo
que nenhuma renderização resolve: **o IP do container**.

- `vaiquecol.com/embed2/1413-1-1` devolveu **404** — o caminho mudou desde a
  primeira sondagem. Estes provedores trocam de endereço com frequência.
- `vidsrc.sh/embed/tv/1413/1/1` devolveu **39 bytes** e terminou em
  `about:blank`: um bloqueio ativo, não uma página. O servidor recusou o IP de
  datacenter antes de servir qualquer HTML.

O padrão é o mesmo dos trackers PT-BR que já saíram do ar: **o IP do container é
o problema, não o cliente**. Um navegador headless rodando do mesmo IP recebe o
mesmo bloqueio. Só um proxy residencial (ou o IP do usuário final) mudaria a
resposta — e isso é uma decisão de infraestrutura, não de código.

Por isso a fonte direta continua valendo o que sempre valeu: ela acha o que
está **aberto** (um `.mp4`/`.m3u8` servido sem cerimônia), e devolve zero quando
o único caminho é um embed blindado. Zero é a resposta honesta nesse caso — o
alternativo seria entregar ao usuário uma sessão que morre na primeira checagem.

##### O levantamento das APIs de embed: todas param no mesmo muro

Depois do `plenoflu.com`, a pergunta seguinte foi natural: **existe outra API
gratuita e ilimitada que devolva o arquivo de vídeo direto?** Foi feito um
levantamento das APIs de embed mais conhecidas, testadas de dentro do container
(que é o IP que o sistema realmente usa). O resultado é uniforme, e vale
registrar para não repetir a busca.

| API | Resposta | O que devolveu |
| --- | --- | --- |
| `plenoflu.com` (`/api`) | 200 | Catálogo de embeds (4 provedores), não vídeo |
| `2embed.cc` | 200 | Outro embed (`vidsrc.buzz`), que responde 403 |
| `vidsrc.to` | 200 | Outro embed (`vsembed.ru`) |
| `vsembed.ru` (`/vs_src.php`) | 200 | Outro embed (`cloudorchestranova.com`) com token `vs` |
| `cloudorchestranova.com` | 200 | Player com `vsdec.js` (ChaCha20/WASM) |
| `data.vidsrc.sh` (`/api.php`) | 200 | **Só metadados** — sem `stream_urls` |
| `vidlink.pro` | 200 | App Next.js; a API interna devolve `null` |
| `vidsrc.xyz` / `vidsrc.me` / `vidsrc.cc` | 301/403 | Redirecionamento ou bloqueio |
| `embed.su` / `superembed.stream` | 000/404 | Inacessível ou fora do ar |
| `consumet.org` / `anify.tv` | 301 | Mudou de endereço |

**O padrão é sempre o mesmo: uma API de embed devolve outra API de embed.** A
cadeia só termina num player que monta o vídeo por JavaScript, e cada elo tem
uma trava própria — Turnstile, token que expira, ou cifra ChaCha20 via
WebAssembly. Nenhuma das APIs testadas devolveu um `.mp4`/`.m3u8` utilizável
diretamente.

O caso do `cloudorchestranova.com` é o mais instrutivo, porque foi o mais longe
que se chegou. O `vsdec.js` documenta o mecanismo no próprio cabeçalho: a API
devolve `data.stream_urls` como **uma string cifrada** (base64 de
`nonce||ciphertext` em ChaCha20), junto de um `vs: { w, wasm_url }` que aponta
para o decifrador WASM daquela janela de 5 minutos. O navegador baixa o WASM,
decifra e só então tem a URL do vídeo. Chamando a API direto (com ou sem o token
`vs`), a resposta traz **apenas metadados** — o `stream_urls` nem aparece. A
cifra só é servida a quem o player reconhece como navegador legítimo, e o
FlareSolverr, mesmo executando o JavaScript, terminou com um HTML de 160 bytes:
o WASM não completou a decifração.

**A conclusão do levantamento:** não existe, hoje, uma API gratuita e ilimitada
que entregue o arquivo de vídeo direto. Todas são **catálogos de embed** — o
mesmo papel do `plenoflu.com`. O que separa uma fonte utilizável de um embed
inútil não é a API, é o **resolvedor** que executa o player até o fim. Enquanto
esse resolvedor não existir (e ele esbarra no bloqueio por IP de datacenter),
nenhuma dessas APIs tem lugar no caminho da fonte direta.

   ##### Os tipos de motor que restaram

   O serviço aceita **dois tipos de motor**, e o tipo decide como montar a
   requisição e ler a resposta:

   - `searxng` — SearXNG, com `format=json`. Resultados em `results[].url`. É o
     **motor padrão**: o container sobe junto com o stack, tem cota própria e
     devolve JSON limpo.
   - `brave` — API oficial do Brave Search, exige chave
     (`stream_direto_brave_key`). Resultados em `web.results[].url`.

   O tipo é **inferido do endereço** quando não declarado: um host com `brave`
   vira `brave`, e qualquer outro endereço é tratado como `searxng` — que é o
   padrão do projeto. O host interno `searxng` contém `searx`, então o endereço do
   container é reconhecido sem configuração extra. Também dá para forçar o tipo
   com o prefixo `tipo:url` na lista de motores — útil para uma instância SearXNG
   em domínio próprio, que não denuncia o tipo pelo host.

   O padrão é **só o SearXNG interno**. O endereço é só o endpoint de busca
   (`http://searxng:8080/search`); a query (`q`) e o `format=json` são
   acrescentados na hora da requisição. Um motor do tipo `brave` **sem chave é
   descartado da lista** antes de qualquer requisição — a API responderia 401 e o
   endereço só gastaria uma volta do laço. A lista negra de domínios vale igual
   para os motores JSON.

   ##### O SearXNG sobe junto com o stack

   As duas alternativas anteriores eram ruins. O DuckDuckGo bloqueia o IP dos
   containers com 202, e as instâncias públicas de SearXNG vêm e vão — nenhuma das
   duas é base confiável para um fallback que precisa funcionar justamente quando
   o resto falhou. A solução foi **auto-hospedar o SearXNG**: o serviço
   [`searxng`](../docker-compose.yml:413) usa a imagem oficial
   `searxng/searxng` e é consultado só pela rede interna, em
   `http://searxng:8080/search`. Não expõe porta no host, então não há instância
   pública envolvida nem IP de container bloqueado por terceiros.

   O ponto que faz o container precisar de um arquivo de configuração versionado:
   o SearXNG **desliga a saída JSON por padrão**. Sem `json` na lista
   `search.formats`, toda consulta com `format=json` responde 403 e o motor
   pareceria morto. O [`docker/searxng/settings.yml`](../docker/searxng/settings.yml:1)
   liga o formato e desliga o `limiter` — que bloquearia rajadas por IP, e o
   backend é um único IP (o do container). O healthcheck do serviço já testa a
   consulta com `format=json`: se o JSON estiver desligado, o container não fica
   saudável e o backend não sobe esperando um motor quebrado.

   O backend espera o `searxng` ficar saudável antes de subir
   ([`docker-compose.yml`](../docker-compose.yml:186)), pelo mesmo motivo que
   espera o FlareSolverr: subir apontando para um motor que ainda não responde só
   gastaria o orçamento do fallback à toa.

   ##### A lista negra de domínios: o catálogo não tem vídeo

   Para um título conhecido, o motor de busca ordena por relevância e empurra
   **catálogos e metadados** para o topo: JustWatch, IMDb, Plex, YouTube oficial,
   Wikipédia, TMDB. Essas páginas respondem 200, abrem rápido e não têm vídeo
   nenhum para extrair — o orçamento curto do fallback (12s) era gasto abrindo
   páginas que nunca iam render fonte.

   O [`MotorBuscaWeb`](../backend/app/Services/Torrents/MotorBuscaWeb.php:69)
   mantém uma lista negra (`DOMINIOS_IGNORADOS`) e descarta essas URLs **na
   extração dos resultados**, antes de elas virarem candidatas a página. Filtrar
   depois, no provedor, seria tarde: a URL já teria entrado na fila de visitas.

   O casamento é pelo **host inteiro**, com o ponto à frente
   (`str_ends_with($host, '.'.$dominio)`), para casar subdomínios (`www.imdb.com`)
   sem casar domínios que apenas terminam com o mesmo texto (`naoimdb.com`). URLs
   sem host válido não são descartadas aqui — quem decide se servem é o extrator.

   ##### Três famílias de descarte, não só catálogo

   O SearXNG agrega a web inteira e, para um título conhecido, devolve muito mais
   que catálogo: **fóruns estrangeiros** (Yahoo japonês, Zhihu chinês), **Q&A e
   redes sociais** (Reddit, Quora, Facebook) e **arquivos de referência** (PDFs,
   planilhas, pacotes). Nenhum deles hospeda o vídeo, e abrir cada um custa uma
   requisição do orçamento curto do fallback.

   O filtro passou a classificar o descarte em **três famílias**, cada uma com sua
   lista:

   - `DOMINIOS_IGNORADOS` — catálogo/metadados, fóruns/Q&A/redes sociais,
     buscadores e **consultas de CNPJ/CPF**. Casamento por host, como antes.
   - `TLDS_IGNORADOS` — sufixos de TLD que nunca hospedam vídeo PT-BR (`.jp`,
     `.cn`, `.kr`, `.ru`, `.ir`, `.vn`, `.th`, `.id`, `.tr`, `.pl`, `.ua`, `.kz`).
     É por **sufixo de TLD**, não por host: `.jp` cobre `news.yahoo.co.jp` e
     `search.yahoo.co.jp` de uma vez. Os TLDs de língua portuguesa (`.br`, `.pt`)
     ficam de fora de propósito — são justamente os que podem ter a página
     dublada.
   - `EXTENSOES_IGNORADAS` — extensões que nunca são vídeo (`.pdf`, `.doc`,
     `.docx`, `.xls`, `.xlsx`, `.ppt`, `.pptx`, `.txt`, `.zip`, `.rar`, `.7z`,
     `.torrent`, `.epub`). O extrator já as recusaria, mas descartá-las aqui evita
     gastar uma requisição para descobrir isso.

   [`MotorBuscaWeb::motivoDoDescarte()`](../backend/app/Services/Torrents/MotorBuscaWeb.php:558)
   classifica a URL em `adulto`, `extensao`, `dominio`, `tld` ou `null` (serve). A
   ordem das checagens vai do mais barato ao mais caro — host vazio, conteúdo
   impróprio, extensão, domínio e, por fim, TLD. O log de `debug` **"resultados
   irrelevantes ignorados"** agrupa os descartes **por motivo**, com a quantidade e
   os hosts únicos de cada família: muitos descartes por `tld` significam que a
   query está a alcançar acervo estrangeiro; muitos por `dominio` significam que a
   lista negra está a segurar fóruns. Sem essa separação, só se saberia que "algo"
   foi ignorado.

   ##### A barreira de conteúdo impróprio: o vazamento do `xvideos-cdn.com`

   O motor de busca é uma caixa preta: ele ranqueia por autoridade e relevância, e
   para um título conhecido pode devolver, no meio dos agregadores de vídeo, um
   link de site adulto que apenas compartilha uma palavra do nome. Foi o que
   aconteceu com **"Donas de Casa Desesperadas"**: o resultado incluiu o domínio
   `xvideos-cdn.com` — o CDN de mídia do xvideos, cujo host não carrega o nome
   comercial e por isso passaria batido por uma lista que só olhasse o portal.

   A defesa é em **duas camadas**, centralizadas em
   [`FiltroConteudoAdulto`](../backend/app/Support/FiltroConteudoAdulto.php:1), e
   as duas rodam **antes** de gastar orçamento:

   - **Domínio** (`DOMINIOS_BLOQUEADOS`) — lista negra de hosts adultos conhecidos,
     incluindo os CDNs (`xvideos-cdn.com`, `phncdn.com`, `rdtcdn.com`, `xhcdn.com`,
     `ypncdn.com`, `youjizz-cdn.com`, `xnxx-cdn.com`). O casamento é por **sufixo de
     host com o ponto à frente**, o mesmo cuidado do catálogo: `xvideos.com` barra
     `www.xvideos.com` e `cdn.xvideos.com`, mas **não** um hipotético
     `naoxvideos.com`.
   - **Texto** (`PALAVRAS_BLOQUEADAS`) — palavras-chave proibidas procuradas na URL
     e no título da página. É a rede de segurança para os domínios que ainda não
     estão na lista: um link cujo caminho (`/porn-video-123`) ou título carrega um
     termo adulto é descartado mesmo que o host seja desconhecido. A comparação é
     sobre texto **normalizado** (minúsculo e sem acento, via `iconv`), então
     "Pornô" e "porno" caem na mesma regra.

   A barreira é aplicada em **três pontos** do fluxo, para que nenhuma porta fique
   aberta:

   1. **Na origem** — [`MotorBuscaWeb::motivoDoDescarte()`](../backend/app/Services/Torrents/MotorBuscaWeb.php:558)
      classifica a URL como `adulto` e a descarta antes de ela virar candidata a
      página. É a checagem mais barata e a que evita gastar orçamento.
   2. **Na página** — [`ProvedorStreamDireto::rasparPagina()`](../backend/app/Services/Torrents/ProvedorStreamDireto.php:224)
      revalida a URL da página e lê o `<title>` do HTML antes de extrair qualquer
      vídeo. Um agregador legítimo que hospeda uma página adulta no meio do acervo
      familiar cai aqui.
   3. **No vídeo** — cada link extraído passa pela barreira antes de virar fonte: o
      vídeo pode estar num CDN adulto mesmo que a página que o embute pareça
      inocente.

   O descarte é registrado em `warning` com a URL e o motivo, para o diagnóstico
   ser acionável. A chave `TORRENTS_STREAM_DIRETO_FILTRO_ADULTO` (padrão `true`)
   liga a barreira inteira — desligá-la só faz sentido para depurar um falso
   positivo, e nesse caso o scraper volta a abrir qualquer página que o motor
   devolver.

   ##### A query é natural: a prova de mídia substituiu a âncora de domínio

   A versão anterior ancorava cada termo num domínio fixo com o operador `site:`
   (`site:tokyvideo.com`, `site:dailymotion.com`, `site:ok.ru`, `site:vimeo.com`).
   O ganho de precisão era real, mas o preço era alto: a busca inteira ficava
   refém de um punhado de domínios. Bastava o Tokyvideo sair do ar — ou bloquear
   o IP do container — para o fallback inteiro parar de achar qualquer coisa,
   mesmo com o Dailymotion e o Archive.org vivos e cheios do mesmo título.

   A query agora é **natural**: o título, a numeração e a intenção de streaming,
   sem operador de domínio. Quem decide se o resultado serve não é mais uma lista
   fixa de domínios aceitos, e sim a **prova de mídia** — a página só é aceita se
   a extração encontrar um player ou um link direto de vídeo. A separação é
   deliberada: a query pergunta "onde está este vídeo?", a lista negra corta o
   lixo grosso e a extração responde "aqui há vídeo de verdade". Trocar de
   provedor deixa de exigir mexer na query, e um domínio novo passa a ser aceito
   sem reescrever termo nenhum.

   As intenções de streaming, na ordem em que valem a consulta:

   ```
   assistir online dublado
   assistir online legendado
   assistir online
   filme completo dublado
   serie completa dublada
   ```

   Os dois últimos termos ("filme completo dublado", "serie completa dublada")
   são o que os sites de hospedagem de vídeo usam no próprio título da página —
   diferente de "assistir online", que também aparece em catálogos. A lista é
   configurável em `stream_direto_termos`; vazia, usa o padrão embutido.

   ##### O título já chega numerado: a numeração não pode ser repetida

   O fallback não recebe o título cru do usuário: ele recebe o título **já
   numerado** por [`TermosBusca::episodio()`](../backend/app/Services/Torrents/TermosBusca.php:117),
   que devolve algo como `Donas de Casa Desesperadas S01E01`. O problema é que
   [`TermosStreamDireto::termosDeStreaming()`](../backend/app/Services/Torrents/TermosStreamDireto.php:181)
   monta a numeração por conta própria — e, sem perceber que ela já estava lá,
   colava a sua própria em cima. O termo saía como
   `Donas de Casa Desesperadas S01E01 1x01 assistir online dublado`: uma
   numeração dupla que não existe em página nenhuma. O buscador devolvia zero
   links para o termo mais preciso da lista, e o orçamento inteiro era gasto
   atrás de uma consulta que nunca ia casar.

   A correção mora em
   [`TermosStreamDireto::tituloSemNumeracao()`](../backend/app/Services/Torrents/TermosStreamDireto.php:88):
   antes de numerar, o método pergunta a
   [`TermosBusca::numeracaoDoTitulo()`](../backend/app/Services/Torrents/TermosBusca.php:579)
   se o título já declara uma numeração. Se a numeração declarada for **a mesma
   que foi pedida**, o título é limpo (as quatro grafias — `1x01`, `S01E01`,
   `Temporada 1 Episódio 1`, `1ª Temporada Episódio 1` — são removidas) e a
   numeração é remontada uma única vez. Se for de **outro episódio**, o título
   é preservado como veio: quem pediu sabe o que quer, e o fallback não tem
   autoridade para reescrever a numeração alheia.

   A limpeza vale para **os dois títulos**, não só o principal. O
   `TorrentService` monta a lista inteira com `TermosBusca::episodio()`, então o
   título original ("Desperate Housewives S01E01") chega tão numerado quanto o
   PT-BR. A primeira versão da correção só tratava o primeiro título; o
   alternativo recebia a numeração de novo e o termo saía com duas grafias
   ("... S01E01 dublado 1x01"), que nenhum motor casa. O bloco dos alternativos
   passou a aplicar o mesmo `tituloSemNumeracao()` antes de anexar a numeração.

   ##### A margem de orçamento: não começar o que não cabe

   O sintoma que abriu esta investigação foi um log assim:

   ```
   fallback concluído. {"provedor":"stream_direto","fontes":0,"ms":12683}
   ```

   Doze segundos e meio — o orçamento inteiro do fallback — para devolver zero
   fontes. A causa não era lentidão: era o loop começar uma consulta que não
   cabia mais no relógio. O guarda antigo só perguntava `temOrcamento()`, ou
   seja, "ainda resta algum tempo?". Com 1 segundo restante, a resposta é sim —
   e o loop abria uma página que precisava de 10 segundos para ser lida. O
   orçamento estourava no meio da requisição, a requisição era cancelada e o
   trabalho já feito era jogado fora.

   [`ConsultaComOrcamento::temTempoParaConsulta()`](../backend/app/Services/Torrents/ConsultaComOrcamento.php:79)
   troca a pergunta: em vez de "resta algum tempo?", pergunta "resta tempo
   **suficiente** para esta consulta?". O critério é **proporcional**: o
   restante precisa cobrir uma fração mínima do teto (`$fracaoMinima`, padrão
   metade), com piso de 1 s. Uma consulta de 10 s só é barrada quando restam
   menos de 5 s — tempo insuficiente para valer a conexão. Sem busca em curso, o
   teto próprio vale e nada é bloqueado. Com o piso, o loop para **antes** de
   começar o que não termina — e o que já foi coletado é devolvido em vez de
   perdido no cancelamento.

   ###### A primeira tentativa era uma conta impossível

   A versão inicial somava uma margem fixa ao teto: só começava se o restante
   cobrisse `teto + margem`. Parecia razoável, mas era aritmética impossível
   para o próprio fallback que a motivou. O orçamento do stream direto era de
   **12 s** (`stream_direto_orcamento`) e o teto de cada consulta é de **10 s**
   (`stream_direto_tempo_limite`). Com margem de 2 s, a conta "10 + 2 = 12"
   fechava **apenas no instante zero**: assim que a primeira consulta consumisse
   1 s, o restante caía para 11 s e nenhum termo seguinte era tentado. O
   fallback parava depois do primeiro termo, com orçamento de sobra — o log
   mostrava `termos_com_resultado: 1` e `paginas_visitadas: 0`. (O valor hoje é
   45 s; a conta acima descreve o desenho antigo, que motivou a troca da soma
   pela proporção.)

   A correção foi trocar a soma pela proporção. A margem deixa de ser um valor
   somado ao teto e passa a ser o **piso proporcional** que separa "cabe" de
   "não vale começar". O piso nunca é menor que 1 s, para que uma fração pequena
   não vire zero e libere uma consulta sem tempo nenhum.

   ##### O teto de termos: a rajada é o que o buscador pune

   A query em camadas é boa para achar a página certa, mas cara: um episódio
   gera **dezenas** de termos — 4 grafias de numeração × 5 intenções, mais as
   variações sem intenção e o título original. Cada termo é uma requisição ao
   motor de busca, e disparar todas em sequência é o caminho mais curto para o
   rate limit — o buscador responde com bloqueio e a busca volta com zero links.

   [`TermosStreamDireto::limitarTermos()`](../backend/app/Services/Torrents/TermosStreamDireto.php:173)
   corta a cauda da lista em `stream_direto_max_termos` (padrão 8). O corte é
   seguro porque a lista **já vem ordenada do mais preciso ao mais amplo**: o
   que sobra são os termos que casam com a página certa, e o que cai são os
   genéricos, que rendem menos. Zero ou negativo desliga o corte.

   ##### O intervalo entre consultas: cadência de gente, não de robô

   Mesmo com menos termos, consultas em rajada ainda denunciam o bot. Entre uma
   consulta e a seguinte, [`MotorBuscaWeb::aguardarIntervalo()`](../backend/app/Services/Torrents/MotorBuscaWeb.php:227)
   dorme um tempo **sorteado** entre `stream_direto_intervalo_min` e
   `stream_direto_intervalo_max` (padrão 800–2200 ms). O sorteio é de propósito:
   um intervalo fixo também é padrão de robô.

   A espera vale **entre** consultas, não antes da primeira — atrasar a abertura
   da busca só somaria latência sem proteger nada. E ela é limitada pelo
   orçamento restante: se o fallback está no fim do prazo, a espera encolhe em
   vez de estourá-lo. O intervalo é registrado em `debug` **"aguardando intervalo
   entre consultas"**, com os milissegundos sorteados.

   ##### Os cabeçalhos: tráfego de navegador, não de cliente HTTP

   O [`ClienteHttp`](../backend/app/Services/Torrents/ClienteHttp.php:258) passou
   a enviar cabeçalhos de navegador comum em toda requisição: `Accept` de
   documento HTML, `Accept-Language: pt-BR,pt;q=0.9,en;q=0.8`, `Cache-Control` e
   `Pragma` de navegação, os `Sec-Fetch-*` e `Upgrade-Insecure-Requests`. O
   `User-Agent` vem de `services.torrents.user_agent` (o mesmo dos provedores
   nativos), com um Chrome como padrão — um agente de robô (`GuzzleHttp/...`,
   `curl/...`) é o primeiro item que um filtro anti-bot olha.

   O `Accept-Language` em PT-BR não é só cosmético: ele alinha a resposta do
   motor com o acervo que o fallback procura, e é o que um navegador brasileiro
   mandaria de verdade.

3. **A extração** — [`ExtratorVideo`](../backend/app/Services/Torrents/ExtratorVideo.php:1)
   abre o HTML da página e procura o vídeo em quatro camadas: as tags HTML5
   (`<video src>`, `<source src>`, `data-src`), a configuração dos players web
   (JW Player usa `file:`, Video.js/Plyr usam `sources: [{src:}]`, e há ainda
   `hls:`, `dash:`, `playlist:`), os **iframes de embed** de plataformas conhecidas
   (YouTube, Vimeo, Dailymotion, OK.ru, Archive.org, Odysee, Streamtape, Doodstream,
   entre outras) e, por fim, URLs soltas no corpo com extensão de vídeo (`.mp4`,
   `.m3u8`, `.mpd`, `.webm`, `.mkv`). O HTML dos players costuma escapar as barras
   (`\/`, `\u002F`), então o extrator desescapa antes de validar.

   ##### A prova de mídia é o critério de aceitação

   Com a âncora de domínio fora da query, a página pode vir de qualquer lugar da
   web — e é a **extração** que decide se ela serve. Antes de varrer o HTML inteiro
   atrás de links, o provedor chama
   [`ExtratorVideo::temMidia()`](../backend/app/Services/Torrents/ExtratorVideo.php:181),
   que responde uma pergunta só: **esta página tem um player ou um link direto de
   vídeo?** A checagem aceita tanto um arquivo de vídeo (`.mp4`, `.m3u8`, ...)
   quanto um iframe de embed de plataforma conhecida.

   A página que não passa é descartada **antes** da extração completa, com um
   `debug` **"página sem prova de mídia descartada"**. É o que substitui a
   whitelist: em vez de aceitar por causa do domínio, o provedor aceita por causa
   do **conteúdo** — um fórum ou uma página de catálogo que escapou da lista negra
   não tem player e cai aqui.

   ##### A relevância pelo título é o segundo critério

   Ter player não basta. Uma página de fandom *sobre* a série embute o trailer no
   YouTube e passa na prova de mídia; um agregador devolve um episódio qualquer de
   outro programa quando o título pedido não está no acervo. Nos dois casos o
   player existe, mas o vídeo não é o pedido — foi assim que "American Horror
   Story" abriu um episódio aleatório vindo do `dramatotal.fandom.com` e um
   `historia-4` do Tokyvideo.

   Por isso o provedor faz uma segunda pergunta, antes mesmo da prova de mídia:
   **esta página é sobre o título?** A resposta vem da trait
   [`RelevanciaTitulo`](../backend/app/Services/Torrents/RelevanciaTitulo.php:1),
   que extrai as **palavras-chave** do título — sem as palavras de ligação ("de",
   "the", "of") e sem a numeração do episódio ("1x01", "S01E01") — e confere
   quantas aparecem no endereço ou no `<title>` da página. A comparação é
   normalizada (minúsculas, sem acento), para que "História" case com "historia".

   O limiar padrão é **2** (`stream_direto_min_palavras_chave`): um título
   composto ("American Horror Story") precisa de duas palavras distintas
   ("american" + "story") para provar relevância. Uma só ("american") casaria com
   `americanas.com.br` e `americansportshop.com.br`, que apareceram no log. Um
   título de uma palavra só ("Dexter") não tem como exigir duas — o limiar é
   limitado ao que o título oferece. Zero desliga a checagem e volta ao critério
   antigo, só a prova de mídia.

   A página que não passa é descartada com um `debug` **"página sem relação com o
   título descartada"**, antes de a extração gastar orçamento nela.

4. **A normalização** — o que sai do extrator passa por
   [`NormalizaFonte::montarFonteDireta()`](../backend/app/Services/Torrents/NormalizaFonte.php:96),
   exatamente como antes. O scraper não inventa um contrato novo: ele só troca a
   origem do link.

O provedor para de raspar quando atinge o teto de páginas
(`stream_direto_max_paginas`, padrão 6) ou quando o orçamento acaba. Páginas já
visitadas não são abertas de novo, e as fontes são deduplicadas por URL.

#### Diagnóstico: onde a busca parou

A busca web é frágil por natureza — o motor bloqueia, o HTML muda, o acervo não
cobre. Quando a lista sai vazia, o log diz exatamente onde parou, em vez de
deixar um "zero fontes" mudo:

- `debug` **"iniciando busca"** — título, variações, temporada/episódio e a lista
  completa de termos que serão consultados.
- `debug` **"consultando motores de busca"** — o termo e os motores da vez.
- `debug` **"motor respondeu"** — quantos links aquele motor devolveu.
- `debug` **"domínios de catálogo ignorados"** — quantas URLs da lista negra
  (JustWatch, IMDb, Plex, YouTube, Wikipédia, TMDB, fandom/wiki) foram descartadas
  e quais hosts. É o que separa "o filtro barrou o lixo" de "a busca não achou
  nada".
- `debug` **"domínios consultados"** — os hosts únicos das páginas que passaram
  pelo filtro e foram efetivamente abertas. É o par do log acima: um diz o que
  foi barrado, o outro diz para onde o orçamento foi de fato.
- `debug` **"aguardando intervalo entre consultas"** — os milissegundos
  sorteados antes da próxima consulta ao motor. É o que mostra que o throttle
  está trabalhando, e não que a busca travou.
- `warning` **"motor bloqueou a consulta"** — rate limit/captcha detectado.
- `warning` **"motor devolveu erro HTTP"** / **"não respondeu"** — falha de rede
  ou status ruim.
- `debug` **"página sem relação com o título descartada"** — a página tinha
  player, mas o endereço e o `<title>` não traziam as palavras-chave do título
  buscado. É o que separa "o vídeo é o pedido" de "o vídeo é um episódio
  aleatório".
- `debug` **"vídeos extraídos da página"** — quantos links de vídeo saíram do HTML.
- `debug` **"página sem vídeo extraível"** — a página abriu, mas não tinha vídeo.
- `debug` **"busca concluída"** — termos com resultado, páginas visitadas e total
  de fontes.
- `info` **"nenhuma fonte encontrada"** — o resumo final quando nada saiu.

Para ver esse rastro, suba o nível do canal para `debug` (`LOG_LEVEL=debug` no
`.env`). Em produção o padrão é `error`, então só os `warning`/`info` de falha
aparecem.

##### O rastro do gatilho: por que o fallback não rodou

O rastro acima começa **dentro** do scraper. Se ele nunca aparece, o problema é
antes: o fallback não chegou a ser acionado. Para esse caso há um segundo rastro,
no gatilho e na entrada do provedor:

- `debug` **"lista pós-filtro vazia, acionando o fallback de stream direto"** —
  em [`TorrentService::fontes()`](../backend/app/Services/TorrentService.php:131),
  com os títulos que serão passados. É a prova de que o gatilho disparou.
- `debug` **"fallback de stream direto devolveu"** — quantas fontes voltaram.
- `debug` **"fallback acionado"** — em
  [`CatalogoProvedores::buscarFallbackDireto()`](../backend/app/Services/Torrents/CatalogoProvedores.php:473),
  com títulos, ano, `imdb_id` e temporada/episódio.
- `info` **"fallback desligado, provedor não consultado"** — a chave
  `TORRENTS_STREAM_DIRETO_HABILITADO` está `false`. **Este é o log que explica o
  silêncio**: sem ele, um fallback desligado era indistinguível de um fallback que
  rodou e não achou nada — os dois terminam com a lista vazia e o mesmo aviso
  "Nenhuma fonte de torrent encontrada" no frontend.
- `info` **"fallback sem título para buscar, provedor não consultado"** — a lista
  de títulos chegou vazia.
- `debug` **"fallback concluído"** — fontes devolvidas e tempo gasto.

A ordem de leitura é: se o `debug` "acionando o fallback" aparece mas o
`debug` "fallback acionado" não, o problema está entre o `TorrentService` e o
catálogo. Se o `info` "fallback desligado" aparece, é configuração — ligue
`TORRENTS_STREAM_DIRETO_HABILITADO=true` e rode `php artisan config:clear`, porque
`config()` pode estar servindo o valor antigo do cache.

#### O contrato da fonte direta

Uma fonte direta carrega dois campos que a distinguem de um torrent:

| Campo | Torrent | Direto |
| --- | --- | --- |
| `tipo` | `torrent` | `direto` |
| `magnet` | link magnet | vazio |
| `stream` | vazio | URL MP4/HLS |

A montagem fica em
[`NormalizaFonte::montarFonteDireta()`](../backend/app/Services/Torrents/NormalizaFonte.php:96).
Como não há malha, a fonte usa `SEEDS_NAO_MEDIDOS` (1) para sobreviver ao filtro
de `seeds <= 0` em [`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:347),
que ganhou um ramo próprio para `tipo === 'direto'` — sem ele, o filtro
`magnet === ''` descartaria toda fonte direta.

#### O media-service converte a URL para HLS

O player não sabe a diferença: ele consome a mesma playlist HLS. Quem faz a
ponte é o endpoint `POST /sessao-direta`
([`media.js`](../media-service/src/routes/media.js:80)), que cria uma sessão
direta em [`sessoes.js`](../media-service/src/services/sessoes.js:340). O FFmpeg
lê a URL remota diretamente — num MP4 progressivo ele usa requisições `Range`
para buscar o índice e as amostras; num HLS ele segue a playlist de origem — e
publica os segmentos conforme os bytes chegam, exatamente como no caminho do
torrent.

A sondagem (`ffprobe`) também aceita URL, mas é tolerante a falha: um servidor
que não responde ao ffprobe ainda pode ser lido pelo FFmpeg, então a sessão cai
num modo conservador (`video`, que transcodifica) em vez de desistir.

##### O idioma da fonte direta mora no endereço

O scraper não tem o nome do release para deduzir o idioma — o que ele tem é a
URL da página. Muitos sites separam dublado e legendado pelo caminho
(`/dublado/`, `/legendado/`) ou pelo **segmento de país** no endereço. O
tokyvideo, por exemplo, serve o conteúdo brasileiro em
`tokyvideo.com/br/video/desperate-housewives-pt-01x01`: o `/br/` (Brasil) e o
`-pt-` (português) estão no próprio endereço, sem a palavra "dublado" em lugar
nenhum.

Olhar só "dublado" deixava esses casos caírem em `original`, e o overlay marcava
como idioma original um vídeo que toca dublado. A correção está em
[`ProvedorStreamDireto::idiomaDaPagina()`](../backend/app/Services/Torrents/ProvedorStreamDireto.php:310),
que agora checa, nesta ordem:

1. **"legendado"** primeiro, porque é a única tag que **nega** o áudio PT-BR: um
   endereço "legendado pt br" carrega o `pt` da legenda, não da dublagem. Se
   viesse depois, o código `pt` o classificaria como dublado.
2. **"dublado"/"dublada"**, a prova explícita.
3. **Indícios de PT-BR no caminho** ([`enderecoIndicaPortugues()`](../backend/app/Services/Torrents/ProvedorStreamDireto.php:343)):
   o segmento de país (`/br/`, `/pt/`, `/pt-br/`, `/brasil/`, `/brazil/`) e o
   código `pt` como palavra no slug, via
   [`IndiciosPtBr::temCodigoPt()`](../backend/app/Support/IndiciosPtBr.php:151).

O segmento de país é procurado com as barras à volta (`/br/` é o Brasil, mas
`/bruno/` não é), e o código `pt` usa a borda de palavra do `IndiciosPtBr` para
não casar "script" nem "concept". Quando nada aparece, o idioma fica vazio e a
dedução pelo título assume na montagem — o comportamento antigo, preservado para
não inventar dublagem onde não há pista nenhuma.

##### A fonte direta não tem peers — tem progresso

O overlay aplicava à fonte direta a mesma lógica de estagnação do torrent: sem
peers, "fonte morta". Mas uma sessão direta não tem malha — o `download` fica
`null` de propósito, e o que prova que ela está viva é o **progresso da
conversão**. O resultado era um link HTTP saudável rotulado como "sem peers" e
abandonado no meio da conversão.

O fluxo direto ganhou tratamento próprio em
[`PlayerOverlay::aguardarFonte()`](../frontend/src/components/PlayerOverlay.vue:1690):

- O teto de espera é `TIMEOUT_DIRETO_MS` (5 min), não o `TIMEOUT_FONTE_MS` (90 s)
  pensado para torrent.
- A prova de vida é o `progresso.percentual` da sessão: enquanto ele muda, a
  fonte está trabalhando. Só quando ele para por `ESTAGNACAO_DIRETA_MS` (60 s) é
  que a conversão travou de verdade.
- A mensagem do overlay mostra o percentual (`Convertendo o vídeo... 42%`) em vez
  de "sem peers".

Do lado do media-service, [`aguardarBufferInicial()`](../media-service/src/services/hls.js:932)
deixou de ser um veredito de morte por relógio: ele aceita um callback
`aoProgredir` e, quando a conversão avançou desde a última checagem, **reinicia o
prazo**. Assim o `timeoutMs` mede estagnação real, e não a duração total da
conversão. [`prepararSessaoDireta()`](../media-service/src/services/sessoes.js:441)
passa esse callback comparando o último percentual visto.

#### Configuração

```env
TORRENTS_STREAM_DIRETO_HABILITADO=true
TORRENTS_STREAM_DIRETO_MOTORES=http://searxng:8080/search
TORRENTS_STREAM_DIRETO_BRAVE_KEY=
TORRENTS_STREAM_DIRETO_TERMOS=
TORRENTS_STREAM_DIRETO_MAX_TERMOS=5
TORRENTS_STREAM_DIRETO_INTERVALO_MIN=400
TORRENTS_STREAM_DIRETO_INTERVALO_MAX=1200
TORRENTS_STREAM_DIRETO_MAX_PAGINAS=6
TORRENTS_STREAM_DIRETO_MAX_FONTES=2
TORRENTS_STREAM_DIRETO_TEMPO_LIMITE=8
TORRENTS_STREAM_DIRETO_ORCAMENTO=45
TORRENTS_STREAM_DIRETO_MIN_PALAVRAS_CHAVE=2
TORRENTS_STREAM_DIRETO_FILTRO_ADULTO=true
TORRENTS_STREAM_DIRETO_BUSCA_DIRETA=true
TORRENTS_BUSCA_POR_IDADE_HABILITADA=true
TORRENTS_BUSCA_IDADE_LIMITE_ANOS=2
```

`TORRENTS_STREAM_DIRETO_MOTORES` é a lista de motores de busca (separada por
vírgula). O padrão é o **SearXNG interno** do compose
(`http://searxng:8080/search`), que sobe junto com o stack e não depende de
instância pública nem de buscador comercial. O endereço do SearXNG é só o endpoint
de busca — a query e o `format=json` entram sozinhos. O tipo de cada motor é
inferido do endereço (`brave` → Brave, o resto → SearXNG) ou forçado com o prefixo
`tipo:url` (ex.: `searxng:https://busca.exemplo.com`). O host interno `searxng`
contém `searx`, então é reconhecido sem prefixo. Para usar o Brave, basta
preencher `TORRENTS_STREAM_DIRETO_BRAVE_KEY` — sem a chave, o motor é descartado
da lista.
`TORRENTS_STREAM_DIRETO_TERMOS` sobrescreve as intenções de busca — vazio usa o
padrão (`assistir online dublado`, `assistir online legendado`, `assistir
online`). A query é natural: não há mais operador `site:` nem lista de plataformas
a configurar. Quem filtra o resultado é a lista negra do `MotorBuscaWeb` e, na
ponta, a prova de mídia da extração.
`TORRENTS_STREAM_DIRETO_MAX_PAGINAS` limita quantas páginas o scraper abre por
consulta (padrão 6). `TORRENTS_STREAM_DIRETO_MAX_FONTES` é o alvo de fontes
distintas que encerra a varredura (padrão 2): assim que há este número de fontes
na mão, o laço para, sem gastar o orçamento restante atrás de mais opções. É
diferente do teto de páginas — uma página pode render várias fontes, e o que o
usuário escolhe é a fonte. Zero ou negativo desliga o corte. Como o alvo de fontes
corta cedo quando há fontes, o teto de páginas só pesa no caso em que a busca não
achou nada — e é aí que a folga maior ajuda.

O SearXNG interno é configurado por
[`docker/searxng/settings.yml`](../docker/searxng/settings.yml:1), que liga a
saída JSON (desligada por padrão na imagem) e desliga o `limiter`. Se preferir
uma instância pública, troque o endereço em `TORRENTS_STREAM_DIRETO_MOTORES` — o
formato aceito é `https://instancia/search`.

`TORRENTS_STREAM_DIRETO_MAX_TERMOS` limita quantos termos são consultados por
busca (padrão 5); zero desliga o corte. `TORRENTS_STREAM_DIRETO_INTERVALO_MIN` e
`TORRENTS_STREAM_DIRETO_INTERVALO_MAX` definem a janela, em milissegundos, do
atraso sorteado entre consultas ao motor (padrão 400–1200). Os dois são a defesa
contra o rate limit do motor de busca: menos termos e cadência humana.

`TORRENTS_STREAM_DIRETO_FILTRO_ADULTO` liga a barreira de conteúdo impróprio
(padrão `true`). Com ela ligada, qualquer endereço de domínio adulto conhecido ou
que carregue palavra-chave imprópria é descartado antes de a página ser aberta, e
o título da página aberta também é conferido. Desligar só faz sentido para
depurar um falso positivo — em produção, mantenha ligada.

`TORRENTS_STREAM_DIRETO_BUSCA_DIRETA` liga a **busca direta nos agregadores**
(padrão `true`) — o atalho que roda antes do motor web: em vez de perguntar ao
SearXNG onde o episódio mora, o provedor pergunta à busca interna do próprio
agregador (`verpobreflix.net`), cujo resultado já é a página do título. É a defesa
contra a suspensão dos buscadores comerciais, que hoje deixa o fallback sem nenhum
agregador na lista. As páginas que voltam passam pelo mesmo crivo das que vêm do
motor, e o motor continua rodando em seguida como redundância. Desligar restaura o
fluxo antigo, só pelo motor de busca.

O `.env.example` sobe com a chave ligada; no código o padrão é `false`. Diferente
da versão anterior, **não há mais API externa a configurar**: ligar a chave já
basta para o scraper funcionar. Vale saber que o
scraper depende de páginas de terceiros, então a taxa de acerto varia com o
acervo — conteúdo muito raro pode não ter página nenhuma, e a lista continua
vazia.

### O canal de partida depende da idade da série

O stream direto nasceu como fallback: só entrava depois de a cascata de torrents
terminar vazia. Isso funciona para o conteúdo raro, mas tem um custo escondido —
a cascata roda **primeiro**, e numa série antiga ela gasta o orçamento inteiro
procurando um release que os indexadores já não têm. Quando o scraper finalmente
começa, o relógio global já fechou. Era o timeout: o canal certo chegava por
último, sem tempo.

A correção inverte a ordem para quem precisa. O [`RoteadorBusca`] lê o ano de
lançamento da série e decide o canal de partida:

- **Série recente** (dentro do limiar, padrão 2 anos): torrents primeiro. É onde
  o release fresco está, e o Torrentio responde em segundos.
- **Série antiga** (fora do limiar): stream direto primeiro. O scraper é quem
  acha o conteúdo que os indexadores perderam, e ele começa com o orçamento
  inteiro à disposição.

O critério é o ano de lançamento da **série**, não o da temporada. Uma série de
2004 continua sendo "de 2004" na décima temporada — e é isso que se quer: o
catálogo de torrents envelhece junto com a série, não com a temporada.

#### O fallback cruzado: só a série recente tem segunda chance

Inverter a ordem não podia significar perder o outro canal — mas também não podia
significar gastar o orçamento num canal que não tem o que se procura. A distinção
é a idade:

- **Série recente**: se os torrents não devolvem nada, o stream direto ainda pode
  ter a resposta num agregador de vídeo. O cruzamento é o último recurso, nunca o
  primeiro — e só dispara com a lista **já ordenada e filtrada** vazia.
- **Série antiga**: o stream direto é o **único** canal. Não há cruzamento para os
  torrents. A premissa é que, passado o limiar, os indexadores já não têm o
  release — insistir neles é queimar o relógio que o scraper precisa para achar a
  fonte. Quem resolve série antiga é o scraper, e ele recebe o orçamento inteiro
  para isso.

A montagem final, o corte de idioma e o censo são idênticos nos dois caminhos: a
única coisa que muda é a ordem (e, na série antiga, a ausência do cruzamento).
Por isso os dois canais são métodos privados que devolvem a lista pronta, e não
blocos duplicados dentro de `fontes()`.

##### O censo do stream direto não pode ser apagado pelo fallback cruzado

O stream direto é o único provedor que não passa pela cascata: ele é acionado à
parte, antes ou depois dela, e o seu censo é preenchido à mão em
`buscarFallbackDireto()`. Isso criava uma mentira no relatório quando o roteador
mandava a série antiga para o stream direto primeiro: ele rodava, voltava vazio,
e o fallback cruzado chamava `buscar()` — que **zera o censo** no início. O
registro da consulta ao stream direto era apagado junto, e a cobertura final
dizia `nao_consultado` de um provedor que tinha acabado de ser consultado.

O sintoma era confuso: o log mostrava o stream direto sendo acionado, mas o
relatório jurava que ele nunca foi perguntado. A correção preserva o registro do
stream direto em `reiniciarCenso()`: a cascata zera os demais, mas mantém o que o
fallback direto já contou. Assim `nao_consultado` volta a significar "não foi
perguntado" — e não "foi perguntado, mas a cascata passou por cima do registro".

##### Dois relógios nomeados: o stream direto tem orçamento próprio

O [`OrcamentoBusca`] começou com um relógio só, e a abertura idempotente
(`abrirSeFechado()`) resolvia a soma indevida: o primeiro canal a chegar ancorava o
prazo e os demais o respeitavam. Mas o relógio único tinha um defeito de fundo — os
dois canais têm **custos de ordens diferentes**, e um prazo só não serve aos dois.

Um provedor de torrent responde em milissegundos. O stream direto paga uma
renderização de navegador (FlareSolverr) por página, de 10 a 15 s cada, e precisa
de **duas** no mínimo: a listagem da série e a página do episódio, onde o player
mora. Com o prazo único de 45 s, a cascata de torrents consumia o orçamento inteiro
e o stream direto — que só entra depois, como fallback — nascia sem tempo. O
sintoma era cruel: o FlareSolverr respondia 200 com a página do episódio quando
chamado à mão, mas o provedor desistia antes de chamá-lo, porque `restante()` já
devolvia zero. O usuário via "nenhuma fonte encontrada" para um episódio que
existia.

A correção dá a cada canal o seu **próprio relógio, nomeado**. O canal `torrents`
mantém o orçamento global (`orcamento_busca`, 45 s); o canal `stream_direto` tem o
seu (`stream_direto_orcamento`, 45 s), dimensionado para o custo real do
FlareSolverr. O canal ativo é uma propriedade do objeto, e não um parâmetro
espalhado: o [`ClienteHttp`] e o trait [`ConsultaComOrcamento`] leem o relógio sem
saber de qual canal se trata, e quem troca o canal é o [`CatalogoProvedores`], no
ponto exato em que aciona cada metade da busca.

Os dois orçamentos **não somam** para o usuário: o stream direto só roda quando a
cascata de torrents falhou, então o tempo dele é o tempo da resposta, não uma
adição ao da cascata. O `abrirSeFechado()` continua idempotente **dentro** de cada
canal: se o stream direto já estiver de pé (por exemplo, quando ele é o canal
preferido de uma série antiga e a cascata entra depois como fallback cruzado), o
relógio não é reiniciado.

O valor do orçamento do stream direto precisa cobrir a descida inteira. Com os
12 s herdados do desenho antigo, a primeira página consumia o orçamento todo e a
página do episódio nascia com `restante: 0` — a descida era descoberta e
abandonada no mesmo instante. Os 45 s cobrem as duas renderizações com folga sem
deixar a resposta passar de um minuto.

O fechamento mudou de dono. Antes cada canal fechava o próprio orçamento no
`finally`; agora quem fecha **os dois** é o [`TorrentService`], no fim da busca
inteira, via `fecharOrcamento()` — que chama `fecharTudo()` e devolve o canal ativo
ao padrão. É o único ponto por onde todos os desfechos passam, então é ali que os
relógios encerram — e a próxima busca começa limpa, sem herdar um prazo vencido. O
`finally` do `buscarFallbackDireto()` **não** fecha o relógio do stream direto: só
devolve o canal ativo ao padrão, para a cascata que entrar depois como fallback
cruzado ler o prazo certo.

#### O alvo de fontes: parar quando já há o suficiente

O laço do scraper tinha um defeito silencioso: o corte de "já tenho o bastante"
comparava `count($fontes)` com o **teto de páginas** (`stream_direto_max_paginas`).
São grandezas diferentes — uma página pode render várias fontes —, e o efeito era
o laço só parar depois de abrir o teto inteiro de páginas, mesmo com duas fontes
já na mão. O fallback é socorro, não catálogo: gastar o orçamento atrás de mais
opções quando já há o suficiente para escolher só atrasa a resposta.

A correção separa as duas coisas. `stream_direto_max_paginas` continua limitando
quantas páginas são abertas; `stream_direto_max_fontes` (padrão 2) é o alvo de
fontes distintas que encerra a varredura. O alvo é configurável porque
"suficiente" é uma decisão de operação, não uma verdade do código — e zero ou
negativo desliga o corte, devolvendo o comportamento antigo.

O teto de páginas subiu de 6 para 10 **por causa dessa separação**. Com o alvo de
fontes cortando cedo quando há fontes, o teto só entra em cena quando a busca
**não** achou nada — e aí um punhado de páginas a mais é o que dá chance de
alcançar o agregador certo em vez de morrer no meio do caminho. O custo do teto
maior é pago apenas no caso que já ia falhar.

#### A ordem das páginas: o agregador de vídeo sobe

Separar o alvo do teto não bastava. O provedor abre as páginas na ordem que o
motor devolve, e o motor mistura agregadores de vídeo com sites de nome parecido
que passam pela lista negra sem ter player nenhum. No caso real, o episódio 1
achou o Tokyvideo porque ele caiu entre as primeiras páginas; o episódio 2 não,
porque o teto foi consumido por `donasloja.com.br`, `donasacessorios.com.br` e
`bancodeseries.com.br` — páginas sem prova de mídia — antes de o Tokyvideo ser
alcançado.

A correção é uma **reordenação**, não uma nova lista de aceitação. O
[`MotorBuscaWeb`] mantém uma lista de domínios com vocação de player
(`DOMINIOS_DE_VIDEO`: Tokyvideo, Pobreflix, Rede Canais, Hypeflix, Dailymotion,
Archive.org, entre outros) e sobe essas páginas para o topo, preservando a ordem
original dentro de cada grupo. A prova de mídia continua decidindo o que vira
fonte — um domínio desconhecido com player segue valendo, e um domínio "de vídeo"
sem player segue sendo descartado. A lista só decide **em que ordem** o orçamento
é gasto.

Um domínio que apareceu nos logs como "sem prova de mídia" não entra na lista: o
`bancodeseries.com.br` foi removido justamente por isso. A lista é uma aposta
baseada na prática, e a prática corrige a lista.

#### O FlareSolverr devolvia uma promessa, não uma resposta

O socorro do FlareSolverr (o proxy que resolve o desafio do CloudFlare) tinha um
defeito que só aparecia em produção: a montagem da resposta usava o factory
`Http::response()` do Laravel, e esse factory pode estar com um handler
assíncrono — ou com um `Http::fake()` residual de teste — e nesse caso devolve
uma `FulfilledPromise` em vez da `Response`. O tipo de retorno declarado é
`?Response`, então o PHP estourava com:

```
ClienteHttp::chamarFlareSolverr(): Return value must be of type
?Illuminate\Http\Client\Response, GuzzleHttp\Promise\FulfilledPromise returned
```

O efeito era cruel: páginas **legítimas** que dependiam do proxy para passar pelo
CloudFlare — como o `assistaonline.tv`, que tem o episódio — morriam com erro de
tipo em vez de serem lidas. O bug não tinha relação com o conteúdo da página; era
o estado global do cliente HTTP vazando para dentro do método.

A correção em [`ClienteHttp::chamarFlareSolverr()`](../backend/app/Services/Torrents/ClienteHttp.php:337)
constrói a resposta **direto sobre o PSR-7**:

```php
return new Response(new Psr7Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html));
```

Com `use GuzzleHttp\Psr7\Response as Psr7Response;`. Montar a resposta na mão
elimina a dependência do estado global do cliente HTTP — o método passa a
devolver sempre uma `Response`, independentemente de haver um fake ou um handler
assíncrono registrado.

#### A lista negra ganhou os homônimos de empresa

O motor de busca casa o título com o nome de empresas quando as palavras
coincidem. "American Horror Story" devolveu a **American Airlines**
(`aa.com.br`, `aa.com`) e a **Câmara Americana de Comércio** (`amcham.com.br`) —
três páginas que não têm vídeo nenhum e consumiram o orçamento curto do fallback.
O mesmo vale para "Donas de Casa Desesperadas", que puxa páginas de imobiliárias
e classificados de aluguel.

O [`MotorBuscaWeb`](../backend/app/Services/Torrents/MotorBuscaWeb.php:84) ganhou
uma seção nova em `DOMINIOS_IGNORADOS` — **"Empresas, marcas e instituições
homônimas de títulos"** — com companhias aéreas (`aa.com`, `latam.com`,
`gol.com.br`, `azul.com.br`, `avianca.com`), agências de viagem (`cvc.com.br`,
`decolar.com`, `booking.com`, `airbnb.com`, `trivago.com.br`) e portais
imobiliários (`quintoandar.com.br`, `zapimoveis.com.br`, `vivareal.com.br`,
`imovelweb.com.br`, `loft.com.br`). Nenhum deles é fonte de mídia.

O casamento continua por **host inteiro com o ponto à frente**, então
`americanas.com` (a loja) é barrado sem barrar um hipotético domínio que apenas
termine com o mesmo texto.

#### O player carregado por JavaScript também é prova de mídia

A prova de mídia original só enxergava o que estava no HTML estático: tags
`<video>`, `<source>`, scripts de player conhecidos e iframes de embed. Isso
deixava de fora os sites PT-BR que montam o player **por JavaScript** — o
`pobreflix.bike`, por exemplo, entrega a URL do vídeo num atributo `data-*` que o
JS lê depois, ou num iframe genérico que só ganha `src` em runtime. A página era
descartada como "sem prova de mídia" mesmo tendo o episódio.

O [`ExtratorVideo`](../backend/app/Services/Torrents/ExtratorVideo.php:41) ganhou
três mecanismos novos:

- **`ATRIBUTOS_DE_DADOS`** — uma lista de atributos `data-*` que costumam carregar
  a URL do vídeo (`data-src`, `data-video`, `data-video-src`, `data-file`,
  `data-player`, `data-embed`, `data-hls`, `data-m3u8`, `data-stream`,
  `data-source`, `data-lazy-src`, `data-original`, entre outros). O método
  `dosAtributosDeDados()` varre o HTML atrás deles e desescapa o valor antes de
  testar se é vídeo.
- **`HOSTS_DE_IFRAME_IGNORADOS`** — a lista de hosts que **nunca** são player:
  redes de anúncio (`googlesyndication.com`, `doubleclick.net`, `taboola.com`,
  `outbrain.com`), redes sociais (`facebook.com`, `twitter.com`, `x.com`,
  `linkedin.com`), captcha (`recaptcha.net`, `hcaptcha.com`), analytics
  (`google-analytics.com`, `hotjar.com`, `clarity.ms`) e chat (`intercom.io`,
  `zendesk.com`, `tawk.to`, `crisp.chat`).
- **`dosIframesGenericos()`** — aceita qualquer iframe que **não** seja de host
  ignorado como prova de mídia. Antes só os iframes de `HOSTS_DE_EMBED`
  (YouTube, Vimeo, Dailymotion...) valiam; agora um player desconhecido, hospedado
  num domínio qualquer, também conta — desde que não seja anúncio nem widget.

A precedência importa: o host ignorado **vence** a lista de embeds conhecidos. O
Facebook é o caso — ele está em `HOSTS_DE_EMBED` porque hospeda vídeo, mas o mesmo
domínio serve o plugin de "curtir", que não é player. Sem essa precedência, uma
página de notícia com o botão social passaria na prova de mídia. Por isso
`dosIframesDeEmbed()` pula o host quando `hostDeIframeIgnorado()` o reconhece.

O `temMidia()` passou a considerar `temIframeGenerico()` e `temEmbedEmAtributo()`
como provas válidas, e o `extrair()` passou a incluir `dosAtributosDeDados()` na
colheita de URLs.

#### Duas colheitas: arquivo de vídeo e URL de embed

Nem todo player entrega o arquivo. Alguns entregam só a URL de embed, que o
media-service resolve depois. Para não perder esses casos, o
[`ProvedorStreamDireto::rasparPagina()`](../backend/app/Services/Torrents/ProvedorStreamDireto.php:283)
passou a fazer **duas colheitas** e combiná-las:

```php
$urls = array_merge(
    $this->extrator->extrair($corpo),
    $this->extrator->extrairEmbeds($corpo),
);
```

O `extrair()` pega os arquivos de vídeo (`.mp4`, `.m3u8`...); o `extrairEmbeds()`
pega as URLs de embed — iframes de player e atributos `data-*` que o JavaScript
transforma em player depois. Sem a segunda, os sites de streaming PT-BR que
montam o player por JS ficariam de fora mesmo tendo o episódio.

#### A capa do TMDB não é fonte de vídeo

A prova de mídia por iframe genérico e por atributo `data-*` aceita **qualquer
URL absoluta** que não seja de host ignorado — o que é necessário para os players
de domínio próprio. O efeito colateral apareceu no primeiro teste real: as
"fontes" devolvidas eram as **capas da série** no TMDB
(`image.tmdb.org/t/p/w185/....jpg`).

A causa é o lazy loading. O `data-src` de um `<img>` é uma URL absoluta como
qualquer outra, e o `dosAtributosDeDados()` o captura junto com os `data-*` de
player. Como o host não estava na lista de anúncios, a capa passava na prova de
mídia e virava fonte direta — o player tentava abrir um `.jpg`.

A defesa é uma lista de **extensões que nunca são mídia**
(`EXTENSOES_NAO_MIDIA`: `jpg`, `png`, `gif`, `webp`, `svg`, `css`, `js`, `json`,
`pdf`, `zip`...), aplicada por `pareceNaoMidia()` em **quatro pontos**:

- `extrairEmbeds()` — a colheita de embeds não devolve imagem.
- `temEmbedEmAtributo()` — uma página que só tem capas não passa na prova.
- `temIframeGenerico()` — um iframe de imagem não é player.
- `dosIframesGenericos()` — a colheita de iframes também recusa imagem.

A checagem é pela extensão do **caminho**, sem a query string — o mesmo cuidado
do `pareceVideo()`, porque muitos CDNs penduram parâmetros depois do `?`. O
`image.tmdb.org` também entrou em `HOSTS_DE_IFRAME_IGNORADOS`, como segunda
camada. O player legítimo sem extensão de arquivo (`/embed/abc123`) continua
valendo: a recusa é por extensão de imagem/asset, não por ausência de extensão.

#### O player que só nasce depois do JavaScript

O `Http::get()` lê o HTML que o servidor entrega, e só ele. Isso basta para os
sites que já mandam o `<video>` ou o `<iframe>` no corpo — mas não para os
streaming PT-BR de verdade. O `pobreflix.bike`, o `assistaonline.tv` e o
`verpobreflix.net` entregam uma página **estática sem player nenhum**: o
`<video>` só existe depois que o JavaScript chama o `player-resolve` por AJAX e
injeta o iframe no DOM. O HTML cru tem apenas o link da própria página
(`data-url="/assistir/serie/american-horror-story/"`) e as capas do TMDB
(`data-src="...jpg"`). Nenhum dos dois é vídeo.

O resultado era o pior possível: as páginas **certas** eram visitadas e
descartadas por "sem prova de mídia", porque a prova olhava um HTML que ainda
não tinha o player. O log mostrava o descarte e parecia que o site não tinha o
episódio — quando o episódio estava lá, esperando o script rodar.

A correção é uma **segunda tentativa renderizada**. Quando a prova de mídia
falha no HTML estático, o `ProvedorStreamDireto` entrega a página ao Chromium do
FlareSolverr pelo novo `ClienteHttp::getRenderizado()`. O navegador executa o
JavaScript até o player aparecer e devolve o DOM já montado; a prova de mídia e
a extração são refeitas sobre esse HTML. É o mesmo caminho do socorro contra
Cloudflare, só que sem esperar por um bloqueio para começar — quem chama já sabe
que a versão estática não bastou.

O `getRenderizado()` é deliberadamente separado do `get()`: o `get()` só aciona
o proxy quando fareja bloqueio (status 403/429/503 ou a marca do desafio), e
forçá-lo a renderizar toda página saudável pagaria o custo do navegador em cada
requisição. A renderização é o **último recurso**, acionada apenas quando a
página passou na relevância mas não provou mídia. Sem `FLARESOLVERR_URL`
configurada, o método devolve `null` e o provedor fica com o HTML estático que
já tinha — o comportamento antigo, sem quebra.

Assim o scraper cobre os três formatos que o conteúdo raro usa: o **HTML** puro
(`<video src>`), o **embed** (iframe e `data-*`) e o **player em JavaScript**
(renderizado pelo navegador). Os testes ficam em `ClienteHttpBloqueioTest`
(`test_renderizacao_*`), que exercitam o caminho com e sem proxy.

#### As quatro travas contra o lixo da busca aberta

O scraper é a última linha de defesa do conteúdo raro, e por isso mesmo é o que
mais apanha da internet aberta. Depois de meses vendo o motor gastar quarenta
segundos para voltar de mãos vazias — ou pior, voltar com um vídeo aleatório do
Tokyvideo —, a busca ganhou quatro travas que trabalham juntas: uma lista
branca, uma lista negra, uma validação de relevância no vídeo e um orçamento
menor. Nenhuma delas sozinha resolve; o problema era o conjunto.

**A lista branca de vídeo foi enxugada.** O `DOMINIOS_DE_VIDEO` do
`MotorBuscaWeb` nasceu com vinte e um domínios, quase todos clones piratas que
aparecem e somem a cada trimestre. A lista não aceita nem recusa nada — ela só
**sobe a página na ordem** de raspagem, para o candidato bom ser visitado antes
do lixo. Só que subir um domínio morto na frente é pior do que não subir: o
scraper gasta o orçamento no `pobreflix.bike` (que hoje é SEO spam) e nunca
chega no `tokyvideo.com`. A lista ficou com sete endereços que comprovadamente
hospedam player nativo e limpo: `tokyvideo.com`, `cinepoca.com.br`,
`cinepoca.com`, `dailymotion.com`, `archive.org`, `ok.ru` e `vimeo.com`. Os
clones instáveis saíram — se um deles voltar a funcionar, volta para a lista
com prova, não por herança.

**A lista negra ganhou o adware e o site morto.** Dois problemas novos, dois
mecanismos novos. O primeiro é o `DOMINIOS_DE_ADWARE`, com os domínios que a
investigação flagrou no fim da cadeia de redirecionamento do player do
`pobreflix.bike`: `guiadecapital.com` e `fgtd.online`. O player "respondia" com
sucesso e mandava o usuário para uma página de aposta de cavalo — o clássico
adware disfarçado de fonte. O `motivoDoDescarte()` agora devolve `adware` para
eles, e o descarte acontece na origem, antes de qualquer requisição. O segundo é
o `MARCAS_DE_SITE_MORTO`, que fareja o corpo da página atrás das assinaturas de
hospedagem expirada: `deployment paused`, `site not found`, `account suspended`,
`domain is parked`, `this domain is for sale` e afins. O `assistaonline.tv`
devolvia exatamente a página "Deployment Paused" do Vercel — o site estava
morto, mas o domínio ainda respondia 200, e o scraper tratava aquilo como
página viva. Agora o `ProvedorStreamDireto::pareceSiteMorto()` corta a página
antes de perder tempo extraindo título e vídeo dela.

**O vídeo extraído precisa ter a cara do título.** Esta é a trava que fecha o
falso positivo clássico: a página passa na relevância (o título dela fala do
episódio), mas o vídeo embutido é qualquer coisa. O `videoRelevante()` da trait
`RelevanciaTitulo` exige que a **URL do vídeo** carregue ao menos uma palavra
principal do termo buscado. Buscando "American Horror Story", o vídeo precisa
ter `american`, `horror` ou `story` no slug; um `/video/historia-4` ou um slug
numérico puro é descartado na hora, antes de virar fonte. O limiar aqui é
deliberadamente mais frouxo que o da página — **uma** palavra basta, não duas —
porque o slug do vídeo é curto e muitas vezes carrega só o nome da série. Os
hosts de embed conhecidos (`youtube.com`, `youtu.be`, `youtube-nocookie.com`,
`vimeo.com`) ficam isentos: o ID deles é opaco por natureza e não há slug para
validar.

**O orçamento encolheu para caber no que é viável.** As páginas que sobram
depois das travas acima são poucas e boas, então não faz sentido manter o
orçamento de quando a busca varria vinte candidatos ruins. Os padrões do
`config/services.php` caíram: `stream_direto_max_termos` de 8 para 5,
`stream_direto_max_paginas` de 10 para 6, `stream_direto_tempo_limite` de 10
para 8 segundos, e o intervalo entre requisições de 800–2200 ms para 400–1200
ms. O intervalo menor é seguro justamente porque há menos requisições: o teto
de tempo por página é que segura o abuso, não a pausa entre elas. O efeito
prático é a busca voltar em segundos, e não em quarenta, quando não há fonte.

As quatro travas se cobrem: a lista branca decide **quem** visitar primeiro, a
lista negra corta **o que** nem chega a ser visitado, a relevância do vídeo
recusa **o que** foi extraído errado, e o orçamento garante que o conjunto
inteiro caiba num tempo aceitável. Os testes vivem em `MotorBuscaWebTest`
(`test_dominio_de_adware_*`, `test_dominio_morto_*`), `RelevanciaTituloTest`
(`test_video_*`) e `ProvedorStreamDiretoTest` (`test_pagina_de_site_morto_*`).

#### Sem ano conhecido, a aposta é o fluxo normal

Quando o catálogo não informa o ano, não há como medir idade. Tratar a ausência
como "antiga" mandaria para o scraper uma série recente que os torrents
resolveriam em um segundo — e o scraper é mais lento e menos preciso. A aposta
segura é o fluxo normal: os provedores por identificador (Torrentio, addons
Stremio) respondem pelo `imdb_id` e não dependem do ano.

#### As chaves da estratégia

`TORRENTS_BUSCA_POR_IDADE_HABILITADA` liga a estratégia (padrão `true`).
Desligada, o roteador devolve sempre o canal de torrents e o fluxo volta a ser
exatamente o de antes — a chave existe para poder desligar sem mexer no código,
caso a estratégia se mostre ruim para algum catálogo.

`TORRENTS_BUSCA_IDADE_LIMITE_ANOS` é o limiar, em anos, que separa recente de
antiga (padrão 2). O limiar é **inclusivo**: com 2, uma série de dois anos ainda
é recente e uma de três já é antiga. Zero ou negativo desliga o corte na prática,
porque nenhuma série fica "fora" dele.

#### Reverificação: o gargalo é o motor de busca e o player cifrado, não a lista

A rede de segurança do conteúdo raro foi reexaminada de ponta a ponta — lista
negra, lista branca, motor de busca e player de destino. A conclusão é que as
listas de domínio estão corretas e o que trava mora fora delas.

**A lista negra acertou.** O `assistaonline.tv` responde **HTTP 402** — o domínio
segue morto, já sem nem a página "Deployment Paused" do Vercel. O
`pobreflix.bike` responde `200`, mas a página de episódio entrega só um
`player-resolve` no HTML estático (nenhum `<video>`, nenhum `.m3u8`); forçada a
renderizar pelo FlareSolverr, ela devolve **um único iframe de anúncio**
(`t.dtscout.com`) e nenhum vídeo. É o mesmo veredito de antes: não hospeda o
episódio, hospeda a isca.

**A lista branca está viva.** O `verpobreflix.net`, o `tokyvideo.com` e o
`cinepoca.com.br` respondem `200`. O `verpobreflix.net` segue o agregador de
referência: a página de episódio (`/series/<slug>/temporada-N/episodio-M`) já traz
no **HTML estático** o iframe do player
(`https://plenoflu.com/tvshow/<id>/<temporada>/<episodio>`), sem depender de
JavaScript.

**O gargalo é o motor de busca.** O SearXNG marca `brave`, `duckduckgo`, `google`
e `google cse` como **suspensos** (`too many requests`, `CAPTCHA`, `access
denied`) em toda consulta. Sobra o Bing, que puxa só plataforma legal: Prime
Video, Netflix, Disney+, JustWatch, Plex, AdoroCinema, Filmow. Nenhuma delas é
fonte, e a lista negra as descarta como sempre. O efeito prático é a busca voltar
vazia **antes** de qualquer página ser visitada — o `stream_direto` termina em
`sem_resultado` sem sequer acionar a renderização. O `settings.yml` apostou no
DuckDuckGo para achar os agregadores PT-BR; hoje ele devolve CAPTCHA, e a
aposta ficou sem lastro.

**O player de destino continua cifrado.** A cadeia do `plenoflu.com` foi
reconferida: `getOptions` devolve quatro players e `getPlayer` entrega, em
base64, `vaiquecol.com` (hoje **404**, morto), `superflixapi.quest` e
`streambetter.shop` (**Cloudflare Turnstile**) e `vidsrc.sh` (token que expira,
iframe ofuscado e trava anti-devtools). Nem o FlareSolverr resolve: a página do
`superflixapi.quest` renderizada tem 620 KB e **zero** `.m3u8`/`.mp4`. É um
catálogo de embeds, não uma fonte de vídeo.

**O que isso muda na prática.** Nenhum ajuste de lista resolve, porque o problema
não está na lista: está no motor que não devolve os agregadores e no player que
não entrega arquivo. Dos dois caminhos possíveis, o primeiro **já foi
implementado**: a **busca direta nos agregadores** (`BuscaAgregadores`) pergunta à
busca interna do `verpobreflix.net` (`/search?q=<título>`) antes de acionar o motor
web, e não depende de buscador comercial nenhum. O segundo — um **navegador
próprio** para atravessar os players cifrados (Turnstile) — continua em aberto: o
`ClienteHttp` já renderiza por JavaScript via FlareSolverr, mas o desafio Turnstile
não é resolvido por ele.

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
