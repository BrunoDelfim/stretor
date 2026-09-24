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

## Torrents — implementado (parcial)

A busca de fontes de torrent já funciona: ao clicar em "Assistir", o backend
consulta o provedor configurado (`TORRENTS_BASE_URL`, padrão `https://yts.gg`) e
devolve a lista ordenada por prioridade — dublado em PT-BR primeiro.

- Regras de negócio em [`TorrentService.php`](../backend/app/Services/TorrentService.php:1).
- O provedor fica isolado atrás de um contrato normalizado: trocar de API
  significa reescrever apenas a normalização.
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

- **uTP desligado** (`new WebTorrent({ utp: false })`): o módulo nativo
  `utp-native` provoca *segfault* (SIGSEGV) neste container, derrubando o
  serviço inteiro. TCP, DHT e trackers continuam ativos e são suficientes.
- **Patch do webtorrent** (`patches/webtorrent+2.8.5.patch`): a versão 2.8.5
  chama `arr2hex(infoHash)` esperando um `Uint8Array`, mas o `parse-torrent` 11.x
  devolve string — o processo morria com `ERR_INVALID_ARG_TYPE`. O patch aceita
  os dois formatos e é reaplicado automaticamente pelo `postinstall`
  (`patch-package`).
- **Cabeçalho no fim do arquivo**: muitos MP4 de torrent guardam o átomo `moov`
  no final. O serviço prioriza esse trecho no WebTorrent antes de ler os
  metadados, e só aceita a análise quando vídeo e áudio foram identificados —
  um cabeçalho lido pela metade faria o FFmpeg "concluir" sem gerar segmento.
- **Conversão supervisionada**: o FFmpeg lê o arquivo como se fosse local e
  aborta ao alcançar a fronteira do download (código 183). Isso não é falha: o
  supervisor espera o torrent avançar e retoma a conversão de onde parou,
  anexando os novos segmentos à mesma playlist.

### Endereço do media-service no frontend

O Express monta as rotas em `/api/media`, mas o Nginx expõe o serviço em
`/media` e reescreve o prefixo. Se `VITE_MEDIA_SERVICE_URL` apontar para a porta
crua do Node (`http://localhost:3000`), as chamadas chegariam em `/sessao` e o
Express responderia `Cannot POST /sessao`. O store de API normaliza a base para
garantir o prefixo correto nos dois casos.

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
