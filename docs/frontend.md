# Frontend

Organização da SPA Vue 3 e o detalhamento das features de interface.

## Organização

O frontend separa responsabilidades em pastas próprias:

- **views/** — páginas, como [`HomeView.vue`](../frontend/src/views/HomeView.vue:1).
- **services/** — chamadas de API isoladas, como [`movies.js`](../frontend/src/services/movies.js:1).
- **stores/** — estado global com Pinia, como [`movies.js`](../frontend/src/stores/movies.js:1).
- **router/** — rotas em arquivo próprio.
- **components/** — toda a interface reutilizável.
- **constants/** — valores fixos de domínio, UI e API.
- **composables/** — lógica reativa reutilizável.

### Componentização rígida

As views não criam componentes complexos diretamente. Toda a interface
reutilizável vive em `components/`, e as views compõem esses componentes padrão
via props/slots, adaptando-os às particularidades de cada tela.

### Constantes

Nenhum número mágico fica solto nos componentes. Tempos de animação, limites de
exibição e o timeout das requisições vivem em `constants/`:

| Arquivo | Conteúdo |
|---------|----------|
| [`filmes.js`](../frontend/src/constants/filmes.js:1) | Gêneros, classificações, tamanhos de imagem e limites de exibição. |
| [`ui.js`](../frontend/src/constants/ui.js:1) | Tempos do carrossel, debounce da busca, scroll da navbar e sentinela. |
| [`api.js`](../frontend/src/constants/api.js:1) | Timeout das requisições e prefixo dos endpoints. |

### Composables

No Vue 3 os mixins foram descontinuados em favor dos **composables**: funções
que encapsulam lógica reativa e cuidam do próprio ciclo de vida, importadas
explicitamente por quem as usa.

| Composable | Responsabilidade |
|------------|------------------|
| [`useDebounce.js`](../frontend/src/composables/useDebounce.js:1) | Atrasa a emissão de um evento até o usuário parar de digitar. |
| [`useScrollSolidificacao.js`](../frontend/src/composables/useScrollSolidificacao.js:1) | Calcula a opacidade de fundo da navbar conforme a página rola. |

## Rolagem infinita

O frontend observa o fim do grid com um `IntersectionObserver` (componente
[`InfiniteScrollSentinel.vue`](../frontend/src/components/InfiniteScrollSentinel.vue:1))
e, ao se aproximar do fim, busca a próxima página e a anexa à lista. Quando
`has_more` é `false`, a sentinela é desativada, nenhuma nova requisição é feita e
o fim da lista exibe a mensagem "Você chegou ao fim — não há mais filmes para
carregar no momento.". Durante uma busca ativa a rolagem infinita não é acionada.

O teto de páginas (`TMDB_MAX_PAGES`) é decidido no backend — veja
[API](api.md). O frontend apenas respeita o `has_more` que recebe.

## Player de reprodução

O botão "Assistir" abre o componente
[`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:1), um overlay
em tela cheia que conduz todo o fluxo de reprodução. Ele passa por quatro
estados visíveis:

1. **Procurando** — busca as fontes de torrent no backend.
2. **Tentando** — percorre as fontes em ordem ("tentando fonte 2 de 7"), já que
   dificilmente o filme terá fonte dublada logo na primeira tentativa.
3. **Preparando** — a fonte conectou e o vídeo está sendo convertido; a mensagem
   acompanha o momento (conectando, convertendo o áudio, convertendo o vídeo).
4. **Reproduzindo** — a playlist está pronta e o player é liberado.

O player é o **Plyr**, que recebe apenas uma fonte HLS válida
(`application/x-mpegURL`). Onde o navegador não toca HLS nativamente
(Chrome/Firefox), o `hls.js` faz a ponte; no Safari o suporte é nativo.

### Ordem de inicialização do player

A ordem importa e já causou vários bugs visíveis: o Plyr abria com altura mínima
e controles inertes, o overlay sumia antes do player aparecer, os segmentos
paravam de ser requisitados e — o mais grave — uma fonte já pronta era descartada
em sequência até esgotar a lista.

O fluxo correto, implementado em
[`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:93):

1. O container do vídeo usa **`v-if="estado === 'reproduzindo'"`** (não `v-show`),
   então o elemento só existe no DOM quando deve aparecer. Com `v-show`, o Plyr
   media um elemento com `display: none` e montava o wrapper com 0×0.
2. O estado é levado a `reproduzindo` **antes** do `nextTick`. Esse ponto já foi
   a causa de um loop: mantendo o estado em `preparando`, o container nem entrava
   no DOM, `elementoVideo` continuava `null` e `iniciarPlayer` devolvia `false` —
   o chamador entendia que a fonte falhou e apagava uma sessão que já estava
   pronta, repetindo isso para cada fonte da lista.
3. `await nextTick()` garante que o `<video>` já está renderizado e visível.
4. **O Plyr é criado primeiro**, e só depois o HLS é anexado — no evento `ready`,
   sobre `player.media`. Ao ser instanciado, o Plyr move o `<video>` para dentro
   do próprio wrapper; se o `hls.js` já estivesse anexado, essa movimentação
   quebrava a associação com o MediaSource, os segmentos deixavam de ser
   requisitados e o player tentava carregar a fonte por conta própria.
5. O `play()` é chamado explicitamente — a rejeição por política de autoplay é
   ignorada, deixando os controles disponíveis.

O CSS do componente força `.plyr` e `.plyr__video-wrapper` a ocuparem 100% da
altura do container com `aspect-video`; sem isso o wrapper não herda a área e o
vídeo colapsa. Falhas fatais do `hls.js` são capturadas e levam o overlay ao
estado de erro, em vez de deixar a tela preta sem aviso.

### Desistência de uma fonte

A fonte é considerada **vencedora assim que a playlist fica pronta no servidor**
(`status === 'pronto'` com `playlist` preenchido), em
[`aguardarFonte()`](../frontend/src/components/PlayerOverlay.vue:255). Montar o
player é um passo separado: [`iniciarPlayer()`](../frontend/src/components/PlayerOverlay.vue:93)
não devolve mais booleano e não decide mais o destino da fonte.

Essa separação corrigiu um problema sério: antes, condicionar o sucesso ao
retorno de `iniciarPlayer` fazia uma falha de montagem do Plyr (evento `ready`
que não dispara, `player.media` nulo) descartar uma fonte perfeitamente válida —
o fluxo queimava a lista inteira de fontes por um problema de UI.

Cada fonte tem um limite próprio (`TIMEOUT_FONTE_MS`, 90 s) em vez dos 5 minutos
anteriores: com várias fontes na fila, uma fonte morta prendia o usuário por
minutos.

Ao descartar uma fonte por falha real (timeout ou `status === 'erro'`),
[`limparSessaoAtual()`](../frontend/src/components/PlayerOverlay.vue:304) faz
duas limpezas:

1. **Destrói o `hls.js`** (`destruirPlayer()`). Sem isso, a instância antiga
   continuava viva tentando recarregar a playlist de uma sessão já apagada.
   Como o `hls.js` resolve caminhos relativos contra a base do documento,
   sobrava uma requisição nua `/media/sessao/<hash>` — sem `/playlist.m3u8` —
   que o Express não tem rota para atender (o `Cannot GET` no log do Nginx).
2. **Volta o estado para `preparando`**, desmontando o container do vídeo. O
   Plyr, ao ser destruído, deixa para trás um `<video>` desanexado; sem
   desmontar, a próxima fonte reutilizava um elemento inválido.

O erro fatal do `hls.js` é tratado à parte: ele apenas exibe a mensagem de falha
na UI, sem mexer no fluxo de fontes — que já terminou quando a playlist ficou
pronta.

A URL da playlist é validada em
[`urlPlaylist()`](../frontend/src/services/streaming.js:76) antes de chegar ao
player. Sem o prefixo público `/media`, o `hls.js` resolveria os caminhos
relativos contra a origem do frontend e o Nginx entregaria o `index.html` — o
navegador passava a baixar imagens em vez dos segmentos.

### Conteúdo de fundo durante a reprodução

O player cobre a tela, mas nada abaixo dele é desmontado automaticamente. Sem
cuidado, a Home continua trabalhando por baixo e baixando imagens do TMDB — o
que competia com os segmentos do vídeo e aparecia como "imagens entre as
status".

Três medidas em [`HomeView.vue`](../frontend/src/views/HomeView.vue:92) mantêm o
fundo quieto enquanto o player está aberto:

1. O [`HeroCarousel`](../frontend/src/components/HeroCarousel.vue:5) recebe
   `:pausado` e limpa o `setInterval` do autoplay. Sem isso, ele trocava de slide
   a cada 7 s e baixava um backdrop novo a cada troca.
2. O [`MovieModal`](../frontend/src/components/MovieModal.vue:118) recebe `null`
   como filme, então o `backdrop_alta` (resolução original, a imagem mais pesada)
   sai do DOM. `filmeSelecionado` é preservado e o modal volta ao fechar.
3. O bloco do grid fica `invisible`, evitando que re-renders disparem novas
   capas.

Fechar o overlay encerra a sessão no media-service, liberando o torrent e o
processo de conversão. O polling do status usa os intervalos definidos em
[`ui.js`](../frontend/src/constants/ui.js:1).

## Skeletons de carregamento

Enquanto uma página está sendo buscada, o grid exibe cards fantasma no lugar dos
filmes reais, com um brilho que atravessa da esquerda para a direita (estilo
Netflix). O mesmo vale para o modal: os campos que dependem dos detalhes
(duração, gêneros, sinopse e elenco) aparecem como blocos animados até a resposta
chegar, enquanto título, capa, nota, ano e classificação — que já vêm da
listagem — permanecem visíveis desde o primeiro instante.

Os placeholders são compostos a partir de
[`SkeletonBlock.vue`](../frontend/src/components/SkeletonBlock.vue:1) e
[`MovieCardSkeleton.vue`](../frontend/src/components/MovieCardSkeleton.vue:1), e a
animação respeita `prefers-reduced-motion`.

## Próximos passos

- Endpoints consumidos: [API](api.md)
- Decisões por trás dessas features: [`plans/`](../plans/)
