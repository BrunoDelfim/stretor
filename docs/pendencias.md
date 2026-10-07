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
