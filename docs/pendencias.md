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

## Link direto sem extensão de vídeo fica de fora

**Observado ao ligar o suporte a `url` dos addons Stremio.** O protocolo permite
que o stream seja um arquivo pronto, e é assim que vários addons entregam vídeo
sem torrent. Só entra o que termina em `.mp4`, `.m4v`, `.webm`, `.mkv`, `.mov`
ou `.m3u8` **no caminho**. Ficam de fora:

- redirecionador de embed e página de player;
- proxy do próprio addon (`/resolve/...`);
- entrega de serviço pago (`real-debrid.com/d/...`, `alldebrid.com/...`).

O motivo é o contrato do
[`prepararSessaoDireta()`](../media-service/src/services/sessoes.js:549): ele
exige uma extensão em `EXTENSOES_DIRETAS` e recusa o resto com
`formato_desconhecido`. Aceitar no backend um link que morre na primeira checagem
só trocaria o momento da falha.

**Para abrir esse caminho** seria preciso o media-service sondar a URL com
`ffprobe` e aceitar quando o `Content-Type` for vídeo, mesmo sem extensão. É
mudança de outro escopo (custo de sondagem e risco de confundir página com
arquivo) e **não** inclui serviço pago: o Stretor não deve depender de conta de
entrega para funcionar.

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

