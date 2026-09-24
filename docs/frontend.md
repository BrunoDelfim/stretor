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

## Player de trailer em tela cheia

O botão "Ver trailer" do modal não embute mais o vídeo no meio do conteúdo — o
que empurrava os botões de ação para baixo. Agora ele abre o componente
[`TrailerOverlay.vue`](../frontend/src/components/TrailerOverlay.vue:1), um
overlay em tela cheia no estilo dos streamings, com o player 16:9 centralizado,
fundo escuro e botão de fechar.

O modal de filme permanece montado por baixo, então fechar o trailer (pelo
botão, pelo `Esc` ou clicando no fundo) devolve o usuário ao modal exatamente
onde ele estava. O iframe do YouTube só é montado após o clique, evitando
carregar cookies de terceiros a cada abertura do modal.

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
