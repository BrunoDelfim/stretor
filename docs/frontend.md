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
[`iniciarPlayer()`](../frontend/src/components/PlayerOverlay.vue:247):

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
5. O `play()` **não** é chamado na criação do Plyr. O `autoplay` fica desligado
   de propósito: ligado, o Plyr tentava tocar no instante em que era montado —
   antes de o HLS existir — e a rejeição era descartada. Quem conduz a
   reprodução é o vigia descrito abaixo, que só age quando existe buffer.

O `hls.js` é criado com **`autoStartLoad: false`**, **`liveDurationInfinity:
false`** e **`backBufferLength: 90`**. Enquanto a conversão corre, a playlist é
`#EXT-X-PLAYLIST-TYPE:EVENT` e ainda não tem `#EXT-X-ENDLIST`; nesse cenário o
`hls.js` marca `details.live = true` — o `live` só vira `false` quando o
`#EXT-X-ENDLIST` aparece. Nesse modo ele deriva da playlist crescente uma
"borda" (a posição de sincronia, calculada a partir do último segmento já
publicado) e puxa o playhead para perto dela em três momentos: ao escolher o
trecho inicial (`getInitialLiveFragment()`), ao recomeçar a carga depois de um
erro de trecho (`resetStartWhenNotLoaded()`) e ao detectar que a reprodução se
afastou da borda (`synchronizeToLiveEdge()`). Como a playlist cresce junto com a
conversão, essa borda vive andando. O sintoma era o filme começar de um ponto
diferente a cada carregamento — o salto de `segmento-26` para `segmento-318`
(~20 min) — e a duração `Infinity`.

Configurar `startPosition` **não** resolve, porque o valor é ignorado no caminho
"ao vivo", e o `startLoad(0)` no evento `MANIFEST_PARSED` cobre apenas a partida:
ele define a posição inicial, mas qualquer recuo posterior devolve o playhead à
borda. A correção decisiva está em
[`fixarBordaAoPlayhead()`](../frontend/src/components/PlayerOverlay.vue:614):
`liveSyncPosition` é um getter da classe `Hls` e não há configuração para
substituí-lo, então o componente define uma versão própria na instância,
devolvendo o `media.currentTime` atual. Com a borda presa ao playhead, os três
caminhos acima passam a apontar para onde o usuário já está — no começo o playhead
é zero e o filme abre do zero; durante a reprodução o ajuste vira inócuo; e
depois de uma busca a referência passa a ser o ponto buscado. O
`liveDurationInfinity: false` (padrão) evita que a duração vire `Infinity`, e o
`backBufferLength` alto preserva o que já foi assistido, permitindo voltar na
barra sem rebuscar tudo.

Esse mesmo arranjo é o que sustenta a **reprodução progressiva**: o media-service
libera a playlist assim que há 8 segmentos em disco, mesmo com o download em
andamento. O `hls.js` acompanha a playlist que cresce e pede cada novo segmento
conforme o playhead avança — enquanto um trecho toca, o próximo é baixado e
convertido, sem interrupção.

### Escolha do motor de HLS

A ordem dos testes importa. O código perguntava primeiro ao navegador se ele
tocava HLS nativo (`canPlayType('application/vnd.apple.mpegurl')`) e só depois
recorria ao `hls.js`. O problema é que o Chrome, o Edge e o Firefox respondem
`"maybe"` a esse tipo — não só o Safari. Como `"maybe"` é uma string verdadeira,
esses navegadores entravam pelo caminho do Safari: o `<video>` recebia a playlist
como `src` e ficava parado, porque nenhum deles decodifica HLS por conta própria.

Agora o `hls.js` tem prioridade sempre que
[`Hls.isSupported()`](../frontend/src/components/PlayerOverlay.vue:375) for
verdadeiro, e o caminho nativo fica como alternativa apenas para o Safari de
verdade. O vigia de reprodução também deixou de ser decidido por `canPlayType`:
ele é iniciado quando **não** existe instância do `hls.js`
([`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:661)), o que
cobre o caminho nativo sem depender de um teste que mente.

### Arranque da reprodução

O `play()` disparado no evento `MANIFEST_PARSED` era o ponto frágil: o manifesto
apenas foi interpretado, e ainda não há um byte do filme no MediaSource. Um
`play()` nesse instante esbarra num buffer vazio — o vídeo fica parado, o loading
some e o Plyr é liberado, mas o filme não começa.

O arranque agora espera o sinal certo: o evento **`FRAG_BUFFERED`**, que só
dispara com o primeiro trecho carregado **e anexado** ao buffer. É a partir daí
que o elemento tem o que tocar. O handler chama
[`vigiarReproducao()`](../frontend/src/components/PlayerOverlay.vue:817), que
insiste em `play()` a cada 1 s (até 30 tentativas) enquanto o vídeo continuar
pausado. O vigia é idempotente — o `FRAG_BUFFERED` dispara a cada trecho e
reiniciar a contagem não faria sentido — e se encerra sozinho assim que o vídeo
toca, seja pelo vigia ou pelo clique do usuário.

#### O vigia só age no arranque

O vigia existe para vencer a política de autoplay, não para reimpor a
reprodução. Como o `FRAG_BUFFERED` dispara a cada trecho — e a conversão publica
um a cada poucos segundos —, a checagem de "está pausado" sozinha fazia o
`play()` voltar **toda vez que o usuário pausava**. A flag `usuarioPausou`,
alimentada pelos eventos `pause`/`play` do elemento, separa "ainda não começou"
de "o usuário pausou": o `FRAG_BUFFERED` só aciona o vigia quando ela for falsa,
e o próprio vigia sai de imediato quando ela for verdadeira.

O `pause` só conta como intenção do usuário quando **não** há busca em
andamento — durante uma busca o elemento pausa sozinho enquanto o alvo carrega,
e isso não é uma pausa. Pelo mesmo motivo o vigia também não tenta `play()`
enquanto `alvoDeSeek` estiver definido: um `play()` ali tocaria a partir do
buffer antigo, desfazendo a busca.

A recusa por autoplay é tratada em
[`tentarReproduzir()`](../frontend/src/components/PlayerOverlay.vue:692): quando o
navegador devolve `NotAllowedError` (a interação do usuário se perde no meio das
requisições assíncronas), o player é silenciado e o `play()` é repetido — mutado
o autoplay é sempre permitido, o filme começa de verdade e o usuário só precisa
subir o volume.

Como o `MANIFEST_PARSED` é o último ponto em que se sabe que a playlist foi lida,
um alarme de 5 s confere se algum trecho chegou ao buffer; se a carga não andou
(uma playlist ao vivo descartada pelo `startLoad` deixaria a tela parada sem
nenhum erro visível), ele retoma com `startLoad(0)` e registra o aviso.

Quando a reprodução não anda, [`registrarErroDeMidia()`](../frontend/src/components/PlayerOverlay.vue:665)
imprime no console o `MediaError` do elemento, o `readyState`, o estado de pausa
e a quantidade de faixas em buffer. Sem isso, `NotAllowedError` (autoplay
bloqueado), `NotSupportedError` (faixa que o MSE não decodifica) e um simples
buffer vazio produzem a mesma tela parada.

### Âncora do playhead no primeiro trecho

Prender a borda ao playhead resolve os recuos, mas havia um segundo caminho para
o filme abrir no meio: o `hls.js` pode escolher como trecho inicial um fragmento
bem à frente do começo. Para isso existe um ajuste de uma única vez no evento
`FRAG_LOADED` ([`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:497)):
quando o **primeiro** trecho carregado começa depois de ~0,5 s, o componente
volta o `media.currentTime` para zero; se o playhead já andou sozinho, ele também
é ancorado em zero. Uma flag (`primeiroTrechoAncorado`) garante que isso aconteça
só uma vez, para não interferir numa busca do usuário logo depois.

Esse ajuste **não** chama `startLoad()`. A primeira versão recarregava a partir
do zero nesse ponto, e o efeito era o inverso do pretendido: `startLoad` descarta
o buffer já anexado e reinicia o ciclo de carga, então o `FRAG_BUFFERED`
seguinte encontrava o buffer vazio outra vez e o `play()` nunca chegava a valer.
Com trechos de ~10 s, a carga reiniciava a cada trecho e o filme ficava
eternamente no primeiro quadro — a tela preta com a duração certa e o player
liberado. Como a borda "ao vivo" já está presa ao playhead, mover o cursor basta:
a carga seguinte parte de zero sozinha, sem descartar o que está em buffer.

Com o manifesto já parseado, o log `[player] manifesto:` informa `live`,
`startSN`, `endSN`, a quantidade de trechos e o `start` do primeiro — é o que
permite ver se o problema está na playlist (servidor) ou na escolha do trecho
(cliente).

### Teardown e concorrência

Fechar o player precisa encerrar **tudo** o que sobrou do filme anterior,
inclusive o torrent e o FFmpeg no servidor — senão a próxima reprodução encontra
o mesmo arquivo pela metade, herdando o estado da anterior. Do lado do
[`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:127),
`limparSessao()` faz, nesta ordem:

1. Marca `cancelado = true`.
2. Incrementa um **contador de geração** (`geracao`).
3. Cancela os timers de polling.
4. Guarda o id da sessão numa variável local, **zera `sessaoId` antes do
   `await`** e só então chama o servidor.

O contador é o que evita as corridas: as funções assíncronas do fluxo
(`tentarFontes`, `aguardarFonte`, `iniciarPlayer`) recebem a geração do momento
em que começaram e comparam com a atual em cada ponto de retomada. Se o usuário
fechou o player — ou recomeçou — no meio de uma requisição, a resposta que chega
depois é descartada em vez de ressuscitar a interface antiga.

A captura do `sessaoId` antes do `await` atende ao mesmo fim: sem ela, o passo de
encerrar usava o id que uma tentativa posterior já havia substituído, e o
servidor recebia um id que não era o da sessão a encerrar.
[`limparSessaoAtual()`](../frontend/src/components/PlayerOverlay.vue:1071) faz o
mesmo para o caso de uma fonte descartada no meio da fila.

Do lado do servidor, [`encerrarSessao`](../media-service/src/services/sessoes.js:1002)
marca a sessão como cancelada antes de qualquer espera, mata o FFmpeg e remove o
torrent do cliente compartilhado — ver
[Integrações](integracoes.md#ajustes-obrigatórios-no-media-service).

### Busca (seek)

A busca é decidida em
[`executarSeek()`](../frontend/src/components/PlayerOverlay.vue:976) por três
caminhos, na ordem:

1. **Alvo já em buffer** — basta escrever `midia.currentTime`. Aqui **não** se
   chama `startLoad`: a versão anterior chamava em qualquer busca dentro do
   convertido, e `startLoad` descarta o buffer e reinicia o ciclo de carga — o
   `FRAG_LOADED` seguinte encontrava o playhead em zero e reancorava o filme no
   começo. Era a origem do "arrasto a barra e volto ao início".
2. **Fora do buffer, mas dentro do convertido** — o trecho existe no servidor, só
   não está carregado. Aí `startLoad(alvo)` é legítimo (não há buffer daquele
   ponto para preservar) e a borda presa ao alvo faz a carga seguir para o ponto
   buscado.
3. **Além do convertido** — o trecho ainda não existe: a conversão publica os
   segmentos na ordem do filme, então o pedido volta 404 e o `hls.js` emite um
   erro fatal depois de esgotar as próprias tentativas — embora a fonte esteja
   saudável. O handler de `ERROR` em
   [`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:482)
   reconhece esse caso (`NETWORK_ERROR` + `FRAG_LOAD_ERROR`) e **retoma a carga do
   ponto buscado** em intervalos de 2 s com `startLoad(media.currentTime)`, até o
   segmento aparecer. Cada trecho que chega (`FRAG_LOADED`) zera a contagem, e o
   limite de tentativas evita insistir num fluxo realmente quebrado.

Toda busca marca `usuarioBuscou`, que desliga a âncora do início pelo resto da
reprodução (ver "Âncora do playhead no primeiro trecho"). Sem essa marca, uma
busca feita antes de o primeiro trecho chegar seria desfeita pelo `FRAG_LOADED`
seguinte — a intenção do usuário sempre vence a âncora, em qualquer ordem de
eventos.

#### O Plyr não pode escrever no elemento

O Plyr tem o próprio handler de `seeking`, que escreve `media.currentTime` com o
valor da barra. Como o elemento clampeia esse valor ao fim do `seekable` — que,
numa playlist `EVENT`, cobre só o trecho convertido —, o Plyr acabava sendo o
autor do "arrasto a barra e volto ao início". Por isso
[`ligarControleDeSeek()`](../frontend/src/components/PlayerOverlay.vue:952)
interrompe a propagação no `change` (`preventDefault` +
`stopImmediatePropagation`): quem escreve no elemento é só o `executarSeek()`.

#### O alvo fica guardado até o playhead chegar nele

`alvoDeSeek` não é liberado só porque o trecho que cobre o alvo chegou — só
quando `midia.currentTime` realmente alcançou o alvo. Enquanto ele estiver
definido, o handler de `seeked` reaplica o alvo se o `currentTime` tiver sido
reescrito para longe (o clamp do browser, por exemplo). Sem essa guarda, uma
reescrita logo depois da busca ficava sem quem a corrigisse.

O servidor também deixou de publicar segmentos pela metade: os `-hls_flags` de
[`iniciarConversao()`](../media-service/src/services/hls.js:324) ganharam
`temp_file`, que escreve `segmento-N.ts.tmp` e só renomeia ao fechar o arquivo.
Antes, o nome entrava na playlist antes de o arquivo terminar de ser escrito e um
pedido logo em seguida lia um trecho incompleto — o que abortava a carga e, na
janela inicial, devolvia o início à borda.

Para o trecho buscado descer mais rápido, o backend ainda precisa priorizar o
byte-range correspondente no WebTorrent; está no backlog do plano.

### Duração e barra de progresso

Enquanto a playlist é `EVENT`, o `hls.js` a enxerga como transmissão ao vivo: a
duração total fica `Infinity`, a barra não anda e o tempo decorrido sai errado.
A correção principal é no servidor: ao concluir a conversão, a playlist vira
`#EXT-X-PLAYLIST-TYPE:VOD` com `#EXT-X-ENDLIST`, e o Plyr passa a ler a duração
real sozinho.

Durante a conversão, porém, a playlist ainda é `EVENT` e o `hls.js` só conhece
os segmentos já publicados — a duração que ele calcula é a do trecho convertido,
não a do filme. O ffprobe já leu a duração total no media-service e ela chega
pelo status da sessão (`duracao`); o overlay a guarda em `duracaoTotal` e a
repassa ao Plyr em [`aplicarDuracaoReal()`](../frontend/src/components/PlayerOverlay.vue:644).

A via usada é a opção **`config.duration`** do Plyr, a duração "de fachada": o
getter interno de `duration` devolve esse número no lugar do `media.duration`
quando ele existe, então a barra representa o filme inteiro desde o primeiro
instante. É bem mais estável do que sobrescrever `duration` no `<video>` — uma
propriedade somente-leitura do `HTMLMediaElement`, que exige
`Object.defineProperty` e ainda depende de o Plyr reler o atributo. Como o Plyr
só redesenha os mostradores de tempo nos eventos `durationchange loadeddata
loadedmetadata`, disparamos um `durationchange` logo depois de trocar o valor. A
função é chamada em `MANIFEST_PARSED` e a cada `LEVEL_UPDATED`, para a barra não
encolher quando o `hls.js` recalcula a duração a partir dos `#EXTINF` já
publicados.

O CSS do componente força `.plyr` e `.plyr__video-wrapper` a ocuparem 100% da
altura do container com `aspect-video`; sem isso o wrapper não herda a área e o
vídeo colapsa. Falhas fatais do `hls.js` são capturadas e levam o overlay ao
estado de erro, em vez de deixar a tela preta sem aviso — exceto o trecho buscado
ainda não convertido, que é tratado com novas tentativas (ver "Busca (seek)").

### Buffer para redes lentas

Os padrões do `hls.js` (`maxBufferLength: 30`, `maxBufferSize: 60 MB`) assumem
banda confortável. Numa conexão abaixo de 1 Mbps o buffer enche e esvazia no
mesmo ritmo, e o player entra em `mediaError/bufferStalledError` a cada poucos
segundos — o vídeo trava, retoma, trava de novo. A instância em
[`PlayerOverlay.vue`](../frontend/src/components/PlayerOverlay.vue:375) sobe os
limites para dar folga a esse cenário:

- `maxBufferLength: 120` e `maxMaxBufferLength: 600` — o alvo de buffer vai a
  2 min e pode esticar até 10 min quando a rede permite;
- `maxBufferSize: 200 MB` — o teto de memória acompanha o buffer maior;
- `abrEwmaDefaultEstimate: 300 kbps` — a estimativa inicial de banda parte baixa,
  então o `hls.js` já começa escolhendo o nível mais leve em vez de tentar o
  topo e falhar;
- `maxBufferHole: 0.5` — tolera buracos maiores entre segmentos sem tratar como
  falha;
- `highBufferWatchdogPeriod: 1` e `nudgeMaxRetry: 10` — o vigia de buffer age mais
  rápido e insiste mais antes de desistir.

O custo é memória e latência de arranque maiores; em troca, a reprodução
sobrevive a oscilações de banda que antes a derrubavam.

### Mensagens de progresso

O overlay traduz o status cru do media-service em mensagens úteis via
[`mensagemDeProgresso()`](../frontend/src/components/PlayerOverlay.vue:967).
Quando a fonte conectou mas não há peers, a mensagem ganha o sufixo "(sem
peers)"; quando há tráfego, mostra a contagem de peers e a velocidade. Isso
distingue "conectando" de "baixando de verdade" — antes, uma fonte morta exibia
"Aguardando dados da fonte..." indefinidamente sem que o usuário soubesse o
motivo.

Abaixo do contador "Fonte X de Y", o overlay mostra a **origem e o idioma** da
fonte em teste a partir de `provedor_rotulo` e do idioma deduzido pelo backend
(ex.: `Indexador (Torznab) · Dublado` ou `YTS · Idioma original`), em
[`tentarFontes()`](../frontend/src/components/PlayerOverlay.vue:920). É o que
explica um áudio em inglês sem precisar caçar no log: se ali está `YTS`, a reserva
em inglês entrou em cena; se está `Indexador (Torznab)` com "Idioma original", foi
o indexador que não trouxe dublado. Ver
[Integrações](integracoes.md#de-onde-veio-a-fonte).

### Desistência de uma fonte

A fonte é considerada **vencedora assim que a playlist fica pronta no servidor**
(`status === 'pronto'` com `playlist` preenchido), em
[`aguardarFonte()`](../frontend/src/components/PlayerOverlay.vue:999). Montar o
player é um passo separado: [`iniciarPlayer()`](../frontend/src/components/PlayerOverlay.vue:247)
não devolve mais booleano e não decide mais o destino da fonte.

O media-service também falha rápido quando a fonte não envia dados: se nenhum
byte chegar em 30 s, a sessão vai para `erro` e o overlay passa para a próxima
fonte sem esperar o timeout de 90 s.

Essa separação corrigiu um problema sério: antes, condicionar o sucesso ao
retorno de `iniciarPlayer` fazia uma falha de montagem do Plyr (evento `ready`
que não dispara, `player.media` nulo) descartar uma fonte perfeitamente válida —
o fluxo queimava a lista inteira de fontes por um problema de UI.

Cada fonte tem um limite próprio (`TIMEOUT_FONTE_MS`, 90 s) em vez dos 5 minutos
anteriores: com várias fontes na fila, uma fonte morta prendia o usuário por
minutos.

Ao descartar uma fonte por falha real (timeout ou `status === 'erro'`),
[`limparSessaoAtual()`](../frontend/src/components/PlayerOverlay.vue:1166) faz
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

### Sessão que sumiu do servidor

As sessões do media-service vivem num `Map` **em memória**. Se o processo cair e
o contêiner reiniciar (`restart: unless-stopped`), todas as sessões somem — e o
`GET /sessao/<id>/status` passa a responder `404 Sessão não encontrada`. Antes,
o frontend tratava esse 404 como falha transitória de rede e continuava
consultando para sempre: o overlay ficava preso em `preparando`, sem nunca
desistir da fonte nem passar para a próxima.

Agora [`statusSessao()`](../frontend/src/services/streaming.js:84) traduz o 404
num estado próprio, `inexistente`, e os dois laços de espera o tratam como
terminal:

- [`aguardarFonte()`](../frontend/src/components/PlayerOverlay.vue:999) chama
  `limparSessaoAtual()` e devolve `'falhou'`, liberando a fila para a próxima
  fonte.
- [`aguardarReposicionamento()`](../frontend/src/components/PlayerOverlay.vue:975)
  resolve com `null`, encerrando a espera do seek.

Qualquer outro erro HTTP continua sendo relançado, para não mascarar falhas
reais de rede.

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
