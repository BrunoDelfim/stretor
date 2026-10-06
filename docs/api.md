# API

Endpoints expostos pelo backend (Laravel) e pelo media-service (Node).

## Backend (Laravel)

- `GET /api/health` — health check da API
- `GET /up` — health check do framework
- `GET /api/v1/movies/popular?page=N` — filmes mais assistidos no Brasil
- `GET /api/v1/movies/trending?page=N` — tendências do dia (filmes, animação e séries) — alimenta a Home unificada
- `GET /api/v1/movies/search?query=...` — busca por título na navbar (filmes e séries, via `/search/multi` do TMDB)
- `GET /api/v1/movies/{id}` — detalhes do filme (modal)
- `GET /api/v1/movies/{id}/fontes` — fontes de torrent para reprodução

> As respostas do TMDB são cacheadas no Redis (`TMDB_CACHE_TTL`, padrão 3600s)
> para respeitar o rate limit da API e acelerar a Home.

### Home unificada (tendências do dia)

A Home consome `GET /api/v1/movies/trending`, que usa o endpoint
`/trending/all/day` do TMDB para misturar **filmes, animação e séries** em um
único fluxo. O backend descarta os itens cujo lançamento (`release_date` para
filmes, `first_air_date` para séries) seja posterior à data de hoje — só entra
na Home o que já está disponível para assistir.

Cada item normalizado carrega o campo `tipo` (`movie` ou `tv`), que o frontend
usa para distinguir o conteúdo. A classificação indicativa é buscada no endpoint
correto conforme o tipo: `/movie/{id}/release_dates` para filmes e
`/tv/{id}/content_ratings` para séries, com cache por `{tipo}:{id}` para evitar
colisão entre ids de filmes e séries.

### Paginação da Home (rolagem infinita)

Os endpoints `popular` e `trending` aceitam o parâmetro `page` (padrão `1`) e
devolvem, além da lista, os metadados de paginação usados pela rolagem infinita:

```json
{
  "data": [ { "id": 1, "titulo": "..." } ],
  "meta": {
    "page": 1,
    "total_pages": 500,
    "has_more": true
  }
}
```

O campo `has_more` é o que o frontend usa para decidir se continua pedindo
páginas. Quando ele vem `false`, a rolagem infinita para. O comportamento do lado
do cliente está detalhado em [Frontend](frontend.md).

### Limite de páginas

O TMDB reporta cerca de 1000 páginas em `popular`/`trending`, o que representaria
milhares de títulos e um DOM pesado sem ganho real de descoberta. Por isso a
rolagem infinita para no teto definido por `TMDB_MAX_PAGES` (padrão `25`).
Ao atingir o limite, o backend responde com `has_more: false` — sem chamadas
extras ao TMDB. Quem procura um título específico usa a busca, que consulta o
catálogo inteiro.

O teto também cobre os dois casos em que o catálogo acaba antes do previsto: o
HTTP 400 do TMDB (página além do limite) e uma página com `results` vazio. Ambos
são tratados como fim de catálogo, evitando que a rolagem fique pedindo páginas
vazias indefinidamente.

### Detalhes do filme

O endpoint de detalhes enriquece o payload com `duracao` (ex.: `2h 19min`),
`elenco` (5 principais atores), `trailer` (chave do YouTube) e `imdb_id`, usados
pelo modal para exibir o botão "Assistir", o trailer sob demanda e os créditos.
O `imdb_id` é o identificador mais preciso para a busca de fontes de torrent.

### Detalhes da série

`GET /api/v1/movies/{id}/serie` devolve a mesma ficha do filme (título, sinopse,
capa, nota, ano, gêneros, elenco e trailer), acrescida de:

- `numero_temporadas` e `numero_episodios` — totais da série;
- `temporadas` — lista com `numero`, `nome`, `qtd_episodios`, `ano` e `capa`.

A classificação indicativa vem de `/tv/{id}/content_ratings` (séries não têm
`release_dates`) e a duração usa o `episode_run_time` como referência. A
temporada 0 (Especiais) é descartada para não confundir a numeração regular.

### Episódios da temporada

`GET /api/v1/movies/{id}/temporada/{numero}` devolve os episódios normalizados:

```json
{
  "data": {
    "temporada": 1,
    "nome": "Temporada 1",
    "ano": 2019,
    "episodios": [
      {
        "numero": 1,
        "temporada": 1,
        "titulo": "Piloto",
        "sinopse": "...",
        "capa": "https://image.tmdb.org/t/p/w500/...",
        "nota": 8.1,
        "duracao": "58min",
        "data_exibicao": "2019-07-25"
      }
    ]
  }
}
```

### Fontes de torrent

O endpoint de fontes devolve a lista já ordenada por prioridade — dublado em
PT-BR primeiro e, dentro do idioma, as fontes com mais seeds. Como dificilmente
o filme terá fonte dublada logo na primeira tentativa, o frontend percorre a
lista inteira até encontrar uma que conecte.

A busca acontece em **duas fases**. A primeira pergunta pelo título traduzido —
é o que os trackers brasileiros publicam. Só quando essa fase não junta PT-BR
suficiente (a meta de `TORRENTS_META_PT_BR`) o serviço repete a busca pelo título
original: algumas traduções ficam curtas demais para o buscador do site
("Homem-Aranha" devolve o desenho, "Spider-Man" devolve o filme). As fontes das
duas fases são fundidas sem duplicar (a chave é o infohash). A chave
`TORRENTS_TITULO_ORIGINAL_SEGUNDA_TENTATIVA` desliga a segunda fase.

```json
{
  "data": {
    "filme_id": 550,
    "titulo": "Clube da Luta",
    "ano": 1999,
    "fontes": [
      {
        "id": "hash-do-torrent",
        "titulo": "Clube.da.Luta.1999.1080p.BluRay.Dublado",
        "qualidade": "1080p",
        "idioma": "pt-BR",
        "idioma_rotulo": "Dublado",
        "provedor": "torznab",
        "provedor_rotulo": "Indexador (Torznab)",
        "tamanho": "2,1 GB",
        "seeds": 120,
        "peers": 30,
        "magnet": "magnet:?xt=urn:btih:..."
      }
    ]
  }
}
```

`provedor` identifica a origem da fonte (`torznab` para o indexador, `yts` para a
reserva em inglês) e `provedor_rotulo` é o texto pronto para exibição. O overlay
do player mostra esse rótulo junto do idioma, o que explica de relance por que um
filme veio com áudio original. Detalhes em
[Integrações](integracoes.md#de-onde-veio-a-fonte).

#### Quando a lista volta vazia

Com o corte de idioma ligado, uma lista vazia responde por si: o título existe,
mas não em português. A resposta chega com `200`, `fontes: []` e a explicação em
`data.mensagem` — *"Este título ainda não está disponível em português. Assim que
sair uma versão dublada ou em dual áudio, ele aparece aqui."* O frontend mostra
esse texto direto no overlay, então ele é escrito para o usuário, não para o log.

A outra mensagem possível é a de provedor indisponível (*"Nenhum provedor de fontes
pôde ser consultado..."*), que é diagnóstico de configuração (chave de API faltando,
serviço fora do ar) e pede outra ação. Quem escolhe entre as duas é o
`TorrentController`, perguntando ao serviço se havia provedor disponível antes de
culpar o idioma.

O corte roda **durante** a busca — inclusive na varredura do stream direto, que não
passa pela ordenação final —, então uma lista vazia significa que os dois canais
foram ouvidos: o acervo web e os trackers. Detalhes em
[Integrações](integracoes.md).

#### Busca por episódio

Para séries, o frontend envia também `temporada` e `episodio` na query. Com eles,
o backend monta o termo de busca no padrão `Titulo S01E02` em vez do título solto
— cada episódio é um release próprio, então a numeração é o que identifica o
arquivo. Sem esses parâmetros, a busca de filme segue exatamente como antes.

A busca é cacheada no Redis (`TORRENTS_CACHE_TTL`, padrão 1800s) e o provedor
fica isolado no `TorrentService` — trocar de API significa reescrever apenas a
normalização.

## Media Service (Node)

- `GET /health` — health check
- `POST /api/media/probe` — extrai metadados de um arquivo
- `POST /api/media/transcode` — transcodifica vídeo
- `POST /api/media/extract-audio` — extrai áudio de vídeo

### Sessões de reprodução

O fluxo de reprodução usa sessões efêmeras. Cada sessão conecta um torrent,
escolhe o arquivo de vídeo, decide o modo de conversão e publica o HLS que o
player consome.

- `POST /api/media/sessao` — cria a sessão a partir de `{ magnet, filme_id }`.
  Responde `202` na hora com `{ sessao_id, status }`; a conexão e a conversão
  seguem em segundo plano.
- `GET /api/media/sessao/{id}/status` — estado atual (`conectando`,
  `aguardando`, `convertendo`, `pronto` ou `erro`), com a mensagem do momento.
  Depois que a conversão começa, o status traz também `duracao`, `tempo_base`
  (deslocamento da timeline local após um seek remoto) e o idioma real lido por
  `ffprobe` na faixa de áudio: `idioma_audio` (código ISO), `idioma_audio_rotulo`
  (rótulo em PT-BR, ex. `Português (Brasil)`) e `idiomas_audio` (todas as faixas,
  com número de canais e marcação de faixa padrão). É esse rótulo que o player
  mostra como selo "Áudio: ..." sobre o vídeo, confirmando a dublagem.
- `POST /api/media/sessao/{id}/seek` — reposiciona a conversão a partir de um
  tempo alvo em segundos (`{ "tempo": 1234.5 }`). É chamado quando o usuário
  arrasta a barra para além do trecho já convertido; a timeline reinicia do
  ponto escolhido e o `tempo_base` passa a ser o novo zero.
- `GET /api/media/sessao/{id}/playlist.m3u8` — playlist HLS consumida pelo Plyr.
- `GET /api/media/sessao/{id}/segmento/{arquivo}` — segmentos de vídeo.
- `DELETE /api/media/sessao/{id}` — encerra a sessão e libera o torrent e o
  processo de conversão.

A conversão escolhe o modo mais barato possível a partir dos codecs do arquivo:
`remux` (vídeo e áudio compatíveis, só troca o contêiner), `audio` (converte só
o áudio para AAC) ou `video` (transcodifica o vídeo). Os segmentos são
publicados conforme ficam prontos, então o player começa antes de a conversão
terminar.

## Próximos passos

- Como o frontend consome esses endpoints: [Frontend](frontend.md)
- Integrações externas (TMDB, torrents, Real-Debrid): [Integrações](integracoes.md)
