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

#### O corte no limite não pode descartar as dubladas

A lista é cortada em `LIMITE_FONTES` (20) antes de ir para o frontend. O corte,
porém, não pode ser cego: um release nacional costuma ter pouquíssimos seeds —
um WEB-DL gringo de 70 seeds esmaga um dublado de 1 seed na ordenação por seeds.
Quando o provedor devolve muitas opções em inglês, cortar a lista ordenada em 20
descartaria justamente a dublada que o usuário procura.

Foi o que aconteceu com "Grey's Anatomy": o Torrentio devolveu 26 streams, dos
quais 2 dubladas, e a única que sobreviveu ao corte foi a de maior seed. A outra
(`Dual Áudio 720p By-LuanHarper`, 1 seed) ficou de fora enquanto 19 originais
entraram.

A regra em [`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:171)
agora reserva espaço para **todas** as fontes dubladas e em dual áudio antes de
completar o restante com as demais. Se as dubladas já preencherem o limite, elas
são a resposta e nenhuma original entra. Só quando não há dublada alguma é que a
lista é preenchida apenas com originais/legendadas.

#### O corte precisa acontecer dentro da cascata, não depois dela

O critério de parada da cascata é `temDublado()`: assim que aparece uma fonte
dublada ou em dual áudio, os degraus seguintes não são consultados. O problema é
que esse critério rodava sobre a lista **crua**, antes de qualquer validação.

Foi o que produziu a lista vazia em "American Horror Story" S01E01: o Torrentio
devolveu um dual áudio de **S10E01** (ele mistura temporadas na resposta da
série), o `temDublado()` viu "dual áudio" e encerrou a busca no degrau 1. Depois,
na ordenação, aquele release foi descartado pela numeração errada — e não sobrou
nada, sem que o Torznab ou o YTS fossem tentados.

A correção move os dois cortes para dentro da cascata, em
[`CatalogoProvedores::aproveitaveis()`](../backend/app/Services/Torrents/CatalogoProvedores.php:255),
aplicado ao fim de cada grupo de provedores:

1. **Numeração** — descarta releases que declaram temporada/episódio diferente
   da pedida (os sem numeração passam, para não apagar packs legítimos).
2. **Idioma** — com `apenas_pt_br` ligado, só dublado e dual áudio seguem.

Assim o `temDublado()` só enxerga fontes que de fato servem, e a cascata desce
para o próximo degrau quando o atual só trouxe release inútil.

#### A parada é julgada pelo degrau, não pela lista acumulada

O `temDublado()` continua sendo o critério de parada, mas agora sobre **o que
cada etapa devolveu**, e não sobre a lista acumulada. A diferença apareceu em
"American Horror Story" S01E01, com o dado exato vindo da tela: o sistema
oferecia **uma fonte só**, rotulada `Torrentio · Dublado`.

O grupo por identificador (Torrentio) é consultado uma única vez, com o termo
puro, e entrava na lista **antes** do laço de termos. Como o `temDublado()` olhava
o acumulado, ele enxergava aquele release dublado já no primeiro termo — que é o
termo puro, sem a tag — e encerrava a cascata ali. As três variações dubladas
montadas por `TorrentService::titulosDeEpisodio()` (`dublado`, `dublada`, `dual
áudio`) e o degrau 2 inteiro nunca eram perguntados.

A correção, em
[`CatalogoProvedores::buscar()`](../backend/app/Services/Torrents/CatalogoProvedores.php:87),
captura o resultado de cada etapa numa variável própria (`$desteTermo`) e julga
esse recorte:

1. O grupo por identificador continua entrando na lista, mas **não decide** a
   parada.
2. Cada termo do degrau nativo é perguntado; a busca só encerra se **aquela
   etapa** trouxe dublado ou dual áudio.
3. O degrau 2 percorre os mesmos termos com o mesmo julgamento.

Uma fonte promissora de um degrau anterior não pode calar a etapa que existe
justamente para achar o dublado — era isso que produzia "uma fonte só" com o
indexador cheio de lançamentos nacionais disponível logo abaixo.

Cada etapa deixa uma linha em
[`CatalogoProvedores::registrarEtapa()`](../backend/app/Services/Torrents/CatalogoProvedores.php:220):

```text
Etapa da cascata de torrents concluída. {"degrau":"indexador","titulo":"American Horror Story S01E01 dublado","fontes":2,"dubladas":2,"encerra":true}
```

`degrau`, `titulo`, `fontes`, `dubladas` e `encerra` contam a história da busca
sem instrumentar nada depois: se o rastro parou em `nativos` com `dubladas: 0`, o
suspeito é o `temDublado()`; se o `indexador` nunca aparece, o degrau 2 não foi
alcançado.

#### Enquanto só há áudio PT-BR, o resto é descartado

A prioridade de idioma resolve a *ordem*, mas não o *desperdício*: com o player
ainda restrito a áudio em português, uma fonte legendada ou em idioma original
que entra na lista só faz o frontend tentar, falhar e passar para a próxima —
cada tentativa custa uma sessão no media-service.

Por isso [`TorrentService::ordenar()`](../backend/app/Services/TorrentService.php:180)
agora descarta, antes de ordenar, tudo que não seja **dublado** ou **dual áudio**
quando `TORRENTS_APENAS_PT_BR` está ligado (padrão `true`). A lista chega curta
ao frontend e o teste fica rápido.

O corte é reversível por configuração de propósito: no dia em que legendado e
idioma original forem suportados, basta `TORRENTS_APENAS_PT_BR=false` para a
lista voltar a trazer todas as faixas — sem tocar em código.

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
