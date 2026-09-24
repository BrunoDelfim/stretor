# API

Endpoints expostos pelo backend (Laravel) e pelo media-service (Node).

## Backend (Laravel)

- `GET /api/health` — health check da API
- `GET /up` — health check do framework
- `GET /api/v1/movies/popular?page=N` — filmes mais assistidos no Brasil (Home)
- `GET /api/v1/movies/search?query=...` — busca por título (navbar)
- `GET /api/v1/movies/{id}` — detalhes do filme (modal)

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
`elenco` (5 principais atores) e os dados do trailer, usados pelo modal para
exibir o botão "Assistir", o player de trailer e os créditos.

O trailer é escolhido priorizando o idioma: PT-BR primeiro, depois inglês e, por
fim, o idioma original. Dentro de cada idioma, o trailer oficial tem preferência.
Três campos compõem o contrato:

| Campo | Descrição |
|-------|-----------|
| `trailer` | Chave do vídeo no YouTube, ou `null` se não houver trailer. |
| `trailer_idioma` | Idioma do áudio escolhido (ex.: `pt`, `en`), ou `null`. |
| `trailer_legenda` | Idioma da legenda a pedir ao player (ex.: `pt`), ou `null` para abrir sem legenda. |

A legenda fica desligada quando o áudio já está em PT-BR — legendar o que se ouve
seria redundante. Em qualquer outro idioma pedimos legenda em PT-BR; o próprio
player do YouTube liga a faixa se ela existir e ignora se não existir, sem
consulta a API paga.

## Media Service (Node)

- `GET /health` — health check
- `POST /api/media/probe` — extrai metadados de um arquivo
- `POST /api/media/transcode` — transcodifica vídeo
- `POST /api/media/extract-audio` — extrai áudio de vídeo

## Próximos passos

- Como o frontend consome esses endpoints: [Frontend](frontend.md)
- Integrações externas (TMDB, torrents, Real-Debrid): [Integrações](integracoes.md)
