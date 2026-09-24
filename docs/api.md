# API

Endpoints expostos pelo backend (Laravel) e pelo media-service (Node).

## Backend (Laravel)

- `GET /api/health` — health check da API
- `GET /up` — health check do framework
- `GET /api/v1/movies/popular?page=N` — filmes mais assistidos no Brasil (Home)
- `GET /api/v1/movies/search?query=...` — busca por título (navbar)
- `GET /api/v1/movies/{id}` — detalhes do filme (modal)
- `GET /api/v1/movies/{id}/fontes` — fontes de torrent para reprodução

> As respostas do TMDB são cacheadas no Redis (`TMDB_CACHE_TTL`, padrão 3600s)
> para respeitar o rate limit da API e acelerar a Home.

### Paginação da Home (rolagem infinita)

O endpoint `popular` aceita o parâmetro `page` (padrão `1`) e devolve, além da
lista, os metadados de paginação usados pela rolagem infinita:

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

O TMDB reporta cerca de 1000 páginas em `popular`, o que representaria milhares
de filmes e um DOM pesado sem ganho real de descoberta. Por isso a rolagem
infinita para no teto definido por `TMDB_MAX_PAGES` (padrão `25`, ~500 filmes).
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

### Fontes de torrent

O endpoint de fontes devolve a lista já ordenada por prioridade — dublado em
PT-BR primeiro e, dentro do idioma, as fontes com mais seeds. Como dificilmente
o filme terá fonte dublada logo na primeira tentativa, o frontend percorre a
lista inteira até encontrar uma que conecte.

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
        "tamanho": "2,1 GB",
        "seeds": 120,
        "peers": 30,
        "magnet": "magnet:?xt=urn:btih:..."
      }
    ]
  }
}
```

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
