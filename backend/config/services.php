<?php

/*
 * Listas separadas por vírgula vindas do ambiente. Vivem aqui, e não dentro do
 * serviço, porque as duas listas do Prowlarr (os indexadores e os que dependem
 * do proxy) partilham a mesma leitura: espaços aparados e entradas vazias fora.
 * O valor padrão entra quando a variável está ausente ou em branco.
 */
$lista = static function (?string $valor, array $padrao): array {
    $texto = trim((string) $valor);

    if ($texto === '') {
        return $padrao;
    }

    return array_values(array_filter(
        array_map('trim', explode(',', $texto)),
        static fn (string $item): bool => $item !== ''
    ));
};

return [
    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],
    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],
    'media_service' => [
        'url' => env('MEDIA_SERVICE_URL', 'http://media-service:3000'),
    ],

    /*
     * Integração com o catálogo mundial de filmes (TMDB).
     * A chave fica exclusivamente no backend para não expor o token no browser.
     * O idioma e a região padrão priorizam conteúdo em PT-BR / Brasil.
     */
    'tmdb' => [
        'key' => env('TMDB_API_KEY'),
        'base_url' => env('TMDB_BASE_URL', 'https://api.themoviedb.org/3'),
        'image_url' => env('TMDB_IMAGE_URL', 'https://image.tmdb.org/t/p'),
        'language' => env('TMDB_LANGUAGE', 'pt-BR'),
        'region' => env('TMDB_REGION', 'BR'),
        'cache_ttl' => (int) env('TMDB_CACHE_TTL', 3600),
        // Teto de páginas da rolagem infinita. O TMDB reporta ~1000 páginas em
        // populares; carregar tudo desperdiça banda e sobrecarrega o DOM. Quem
        // procura algo específico usa a busca, que consulta o catálogo inteiro.
        'max_pages' => (int) env('TMDB_MAX_PAGES', 25),
    ],

    /*
     * Busca de fontes para reprodução, organizada em três degraus.
     *
     * O primeiro degrau é a busca nativa do backend: HTTP direto nos trackers
     * públicos PT-BR, mais os acervos amplos (APIBay, Knaben, Torrentio, addons
     * Stremio hospedados e BT4G). É aqui que o sistema faz por conta própria o
     * trabalho; nenhum serviço externo precisa estar de pé para achar um release
     * dublado.
     *
     * O segundo degrau é o indexador Torznab (Prowlarr), e o terceiro é o YTS.
     * A cascata só desce um degrau quando o anterior não devolveu fonte dublada
     * válida — detalhes da política em [`CatalogoProvedores`].
     *
     * Toda credencial aqui é opcional: faltando uma chave, o provedor é pulado e
     * a busca segue. Nenhuma configuração ausente derruba o fluxo.
     */
    'torrents' => [
        // --- Degrau 1: busca nativa no backend ---

        /*
         * Trackers públicos PT-BR lidos direto por HTTP. Nasce vazia porque os
         * dois domínios que a ocupavam morreram — veja docs/integracoes.md. Um
         * endereço morto só paga erro a cada termo; preencha para reativar.
         */
        'trackers_br_urls' => env(
            'TORRENTS_TRACKERS_BR_URLS',
            ''
        ),
        // Caminho da página de busca dentro desses trackers.
        'trackers_br_busca' => env('TORRENTS_TRACKERS_BR_BUSCA', 'index.php'),

        // APIBay é a API pública do Pirate Bay: JSON limpo, sem chave, e com o
        // campo `imdb` — que serve para descartar homônimos na hora.
        'apibay_url' => env('TORRENTS_APIBAY_URL', 'https://apibay.org'),

        // Torrentio resolve streams a partir do imdb_id, então é o único
        // provedor nativo que consegue buscar por identificador e não por nome.
        'torrentio_url' => env('TORRENTS_TORRENTIO_URL', 'https://torrentio.strem.fun'),

        /*
         * Idiomas pedidos ao Torrentio, separados por vírgula. O Torrentio
         * aceita essa configuração embutida na URL e, sem ela, responde com o
         * catálogo padrão — quase todo em inglês. Com "portuguese" ele passa a
         * incluir os provedores que publicam releases nacionais (Comando, BluDV,
         * ThePirateBay com faixa PT), que é de onde saem os lançamentos
         * "Dublado"/"Dual Áudio". Vazio desliga o filtro e volta ao padrão.
         */
        'torrentio_idiomas' => env('TORRENTS_TORRENTIO_IDIOMAS', 'portuguese'),

        /*
         * Consulta ampla do Torrentio para séries: além da busca filtrada por
         * idioma, repete a mesma rota **sem** o segmento `language=`. O filtro é
         * um corte na origem e esconde packs que não declaram o idioma do jeito
         * que ele reconhece ("multi áudio" mal grafado, "legendado"); sem o
         * filtro, o parser interno classifica pelo nome do release. Filme não
         * entra nessa segunda consulta. Desligue para voltar à consulta única.
         */
        'torrentio_busca_ampla' => (bool) env('TORRENTS_TORRENTIO_BUSCA_AMPLA', true),

        // BT4G varre a rede DHT inteira: costuma achar o release PT-BR que os
        // trackers indexados não têm. A lista de espelhos é separada por
        // vírgula; o domínio principal sai do ar com frequência.
        'bt4g_urls' => env('TORRENTS_BT4G_URLS', 'https://bt4gprx.com'),

        /*
         * Knaben — meta-buscador dos indexadores públicos, por HTTP JSON.
         *
         * Cobre trackers que os provedores nativos não varrem e é de onde saem
         * os packs nacionais das séries antigas (nos testes, o release "S01
         * Completa Legendado PT-BR"). A chave liga/desliga existe porque ele
         * bate num único host externo; se ele cair ou passar a limitar
         * requisições, dá para desligá-lo sem tocar no código.
         *
         * Atenção ao contrato: ele só busca de verdade com `search_type=100%`
         * (ver [`ProvedorKnaben`]).
         */
        'knaben_habilitado' => (bool) env('TORRENTS_KNABEN_HABILITADO', true),
        'knaben_url' => env('TORRENTS_KNABEN_URL', 'https://api.knaben.org/v1'),
        // Quantos resultados pedir por termo. Curto de propósito: cada termo é
        // uma requisição, e o corte de idioma da cascata descarta o resto.
        'knaben_limite' => (int) env('TORRENTS_KNABEN_LIMITE', 20),

        /*
         * Addons Stremio hospedados, separados por vírgula. O provedor genérico
         * consulta cada endereço pelo protocolo `/stream/{type}/{id}.json` e
         * reaproveita a leitura do Torrentio. Cada entrada pode trazer o caminho
         * de configuração junto do host (ex.: `.../language=portuguese`).
         *
         * O Torrentio tem provedor próprio e não precisa estar aqui; a lista é
         * para os demais — hoje, o TPB+.
         */
        'stremio_addons' => $lista(
            env('TORRENTS_STREMIO_ADDONS'),
            ['https://thepiratebay-plus.strem.fun']
        ),

        /*
         * Teto de páginas de detalhe abertas em paralelo por busca. Cada detalhe
         * é uma requisição no tracker, então um termo genérico ("Homem-Aranha")
         * pode devolver dezenas de candidatos — sem teto, a resposta da API
         * estoura o tempo do usuário.
         */
        'max_detalhes_busca' => (int) env('TORRENTS_MAX_DETALHES_BUSCA', 16),

        // Tempo limite, em segundos, de cada requisição HTTP a um provedor.
        'tempo_limite' => (int) env('TORRENTS_TEMPO_LIMITE', 15),

        /*
         * Teto próprio dos provedores de acervo mundial (BT4G e APIBay).
         *
         * Eles varrem a rede DHT inteira e são os mais lentos da cascata: nos
         * testes, o BT4G gastou 30 s (dois espelhos) para devolver zero fontes
         * PT-BR, e o APIBay 19 fontes em 351 ms. Como a rodada de abertura é
         * sequencial, um BT4G lento consumia o orçamento inteiro e os provedores
         * por identificador (Torrentio, addons Stremio) ficavam `nao_consultado`
         * — o inverso do problema que a rodada de abertura veio resolver.
         *
         * O teto curto não os descarta: eles continuam sendo perguntados, só não
         * podem segurar a rodada sozinhos. O Knaben, que é quem traz os packs
         * nacionais, mantém o teto cheio (`tempo_limite`).
         */
        'acervo_mundial_tempo_limite' => (int) env('TORRENTS_ACERVO_MUNDIAL_TEMPO_LIMITE', 8),

        /*
         * Socorro pelo FlareSolverr na busca nativa (degrau 1).
         *
         * O FlareSolverr sempre esteve no stack, mas só servia ao Prowlarr: os
         * provedores nativos falavam HTTP direto e batiam de frente no
         * Cloudflare. Foi assim que o BT4G passou a responder 403 e os trackers
         * PT-BR sumiram. Com isto ligado, o [`ClienteHttp`] tenta a requisição
         * direta e, quando ela vem bloqueada (403/429/503 ou a página "Just a
         * moment..."), reenvia pelo FlareSolverr — que abre um navegador e
         * resolve o desafio. É o mesmo caminho que o Stremio usa por baixo.
         *
         * Desligado, os provedores voltam ao HTTP direto de antes. Sem
         * `FLARESOLVERR_URL` configurada, o socorro simplesmente não acontece e
         * nada quebra.
         */
        'proxy_nativo' => (bool) env('TORRENTS_PROXY_NATIVO', true),

        /*
         * Teto, em segundos, da chamada ao FlareSolverr. Ele abre um Chromium e
         * resolve o desafio, o que leva bem mais que uma requisição comum; o
         * valor fica acima do `maxTimeout` que enviamos a ele, para que o erro
         * venha dele (com diagnóstico) e não de um corte nosso.
         */
        'proxy_nativo_timeout' => (int) env('TORRENTS_PROXY_NATIVO_TIMEOUT', 70),

        // Vários trackers respondem 403 para cliente sem User-Agent de navegador.
        'user_agent' => env(
            'TORRENTS_USER_AGENT',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
        ),

        /*
         * Orçamento de tempo, em segundos, para a busca inteira — os três degraus
         * somados.
         *
         * Cada degrau já tinha o seu próprio teto, mas os tetos não conversavam:
         * um provedor bloqueado custa o `tempo_limite` da tentativa direta mais o
         * `proxy_nativo_timeout` do socorro pelo FlareSolverr, e isso se repete a
         * cada termo do episódio. Como a cascata é sequencial, o pior caso é a
         * soma de tudo — e essa soma não tinha limite nenhum. O resultado era o
         * `/fontes` passar dos 60 s que o frontend espera
         * (`TIMEOUT_REQUISICAO_MS`) e a requisição ser cancelada antes de a lista
         * chegar à tela.
         *
         * Este é o único relógio que a busca inteira enxerga: o
         * [`CatalogoProvedores`] o abre no início e o fecha na saída, e o
         * [`ClienteHttp`] o consulta para não iniciar um socorro de 70 s a poucos
         * segundos do fim. Ao estourar, a cascata para onde está e devolve o que
         * já recolheu — uma lista parcial é melhor do que nenhuma.
         */
        'orcamento_busca' => (int) env('TORRENTS_ORCAMENTO_BUSCA', 45),

        /*
         * Orçamento de tempo, em segundos, só para o canal do stream direto.
         *
         * Ele é separado do global porque o custo é de outra ordem. Um provedor de
         * torrent responde em milissegundos; o stream direto paga uma renderização
         * de navegador (FlareSolverr) por página, de 10 a 15 s cada, e precisa de
         * duas páginas no mínimo — a listagem da série e a do episódio. Com o
         * prazo único de 45 s, a cascata de torrents consumia o orçamento inteiro
         * e o stream direto, que só entra depois como fallback, nascia sem tempo:
         * o FlareSolverr respondia 200 com a página do episódio quando chamado à
         * mão, mas o provedor desistia antes de chamá-lo, porque `restante()` já
         * devolvia zero.
         *
         * Os dois orçamentos **não somam** para o usuário: o stream direto só roda
         * quando a cascata de torrents falhou, então o tempo dele é o tempo da
         * resposta, não uma adição ao da cascata. O valor padrão cobre a descida
         * (série → episódio) com folga para o FlareSolverr de cada página, sem
         * deixar a resposta passar de um minuto.
         */
        'stream_direto_orcamento' => (int) env('TORRENTS_STREAM_DIRETO_ORCAMENTO', 45),

        // --- Degrau 2: indexador Torznab (Prowlarr) ---

        /*
         * Sem chave, o degrau é simplesmente pulado e a busca desce para o YTS.
         * A `torznab_url` aponta para a raiz do indexador.
         */
        'torznab_url' => env('TORRENTS_TORZNAB_URL', ''),
        'torznab_key' => env('TORRENTS_TORZNAB_KEY', ''),
        // Categoria Torznab de filmes (2000 = Movies).
        'torznab_categoria' => env('TORRENTS_TORZNAB_CATEGORIA', '2000'),
        /*
         * Categoria Torznab de séries (5000 = TV). É uma chave separada porque
         * o Prowlarr filtra por categoria: pedir um episódio com `cat=2000`
         * (filmes) devolve zero resultados, por mais que o release exista. Era
         * por isso que uma série só vinha vazia mesmo com o indexador saudável.
         */
        'torznab_categoria_serie' => env('TORRENTS_TORZNAB_CATEGORIA_SERIE', '5000'),

        /*
         * Orçamento de tempo, em segundos, para o degrau inteiro em uma busca.
         *
         * O custo deste degrau é um produto de três fatores que crescem sem
         * combinar entre si: os termos do episódio, as duas consultas por termo e
         * os indexadores cadastrados no Prowlarr. A varredura é sequencial, e
         * cada indexador pode gastar quatro tentativas só para descobrir a rota
         * certa — os que ficam atrás do CloudFlare ainda respondem pelo
         * FlareSolverr, com vários segundos por consulta. Enquanto havia um
         * indexador isso cabia; com o ThePirateBay e o TorrentGalaxy na lista, o
         * `/fontes` de um episódio passou dos 60 s que o frontend espera
         * (`TIMEOUT_REQUISICAO_MS`) e a requisição era cancelada antes de a lista
         * chegar à tela. Ao estourar o orçamento, o degrau para e devolve o que
         * já recolheu: perder um indexador é melhor do que perder a busca.
         */
        'torznab_orcamento' => (int) env('TORRENTS_TORZNAB_ORCAMENTO', 8),

        // --- Degrau 3: YTS (reserva em inglês) ---

        // O domínio principal do YTS (yts.mx) é bloqueado por DNS em vários
        // provedores de hospedagem. O espelho yts.gg responde com o mesmo
        // contrato da API v2, então é ele que fica como padrão.
        'base_url' => env('TORRENTS_BASE_URL', 'https://yts.gg'),
        'cache_ttl' => (int) env('TORRENTS_CACHE_TTL', 1800),

        /*
         * --- Fallback: stream direto (MP4/HLS) ---
         *
         * O último recurso para conteúdo antigo ou raro em PT-BR, onde os
         * torrents já morreram. Quando a cascata inteira termina sem nenhuma
         * fonte aproveitável, o [`ProvedorStreamDireto`] **raspa a web** atrás de
         * um link de vídeo tocável (MP4 ou HLS) e devolve uma fonte com
         * `tipo=direto` — o player a reproduz pelo media-service, que faz o
         * proxy/remux, sem WebTorrent.
         *
         * A descoberta é autônoma: o provedor monta a query, consulta um motor de
         * busca leve e extrai o vídeo do HTML das páginas. Não há mais dependência
         * de uma API externa estática.
         *
         * É opt-in: desligado, o comportamento é exatamente o de antes e nenhuma
         * requisição extra acontece.
         */
        'stream_direto_habilitado' => (bool) env('TORRENTS_STREAM_DIRETO_HABILITADO', false),

        /*
         * Motores de busca usados para resolver o termo em páginas candidatas,
         * separados por vírgula. Com mais de um endereço, o provedor tenta o
         * próximo quando o primeiro falha ou devolve zero resultados — a busca
         * degrada, não quebra.
         *
         * O padrão é o **SearXNG interno** do compose
         * (`http://searxng:8080/search`), e não uma instância pública nem um
         * buscador comercial. O SearXNG que sobe junto com o stack tem cota
         * própria, devolve JSON limpo (`format=json`) e não depende de terceiros.
         * O DuckDuckGo foi removido do projeto: além de bloquear o IP dos
         * containers com status 202, exigia parsing de HTML e detecção de captcha
         * que só existiam para contornar o bloqueio. Se um dia for preciso
         * redundância, ela deve vir de outra instância SearXNG — nunca de um
         * buscador comercial.
         *
         * O tipo de cada motor é inferido do endereço (`searx` → SearXNG,
         * `brave` → Brave) ou forçado com o prefixo `tipo:url`. O host interno
         * `searxng` contém `searx`, então é inferido como SearXNG sem precisar de
         * prefixo. O endereço é só o endpoint de busca — a query (`q`) e o
         * `format=json` são acrescentados na hora da requisição.
         */
        'stream_direto_motores' => $lista(
            env('TORRENTS_STREAM_DIRETO_MOTORES'),
            [
                'http://searxng:8080/search',
            ]
        ),

        /*
         * Chave da API oficial do Brave Search. Sem ela, um motor do tipo `brave`
         * é descartado da lista antes de qualquer requisição — a API responde 401
         * e o endereço só gastaria uma volta do laço.
         */
        'stream_direto_brave_key' => env('TORRENTS_STREAM_DIRETO_BRAVE_KEY', ''),

        /*
         * Termos de intenção de streaming, separados por vírgula. São colados ao
         * título (e à numeração do episódio) para formar a query. "assistir
         * online dublado" é o que os sites brasileiros usam no `<title>`;
         * "legendado" entra como reserva. Vazio usa o padrão embutido.
         *
         * A query é **natural**: não há mais operador `site:` amarrando a busca a
         * um domínio fixo. Quem decide se o resultado serve é a prova de mídia na
         * extração, não o domínio — assim, derrubar um provedor não derruba a
         * busca.
         */
        'stream_direto_termos' => $lista(env('TORRENTS_STREAM_DIRETO_TERMOS'), []),

        /*
         * Teto de termos consultados por busca. Cada termo é uma requisição ao
         * motor de busca, e buscadores bloqueiam quem dispara em rajada: um
         * episódio gera dezenas de termos (4 grafias de numeração × 5 intenções,
         * mais as variações sem intenção e o título original), e consultar todos
         * de uma vez é o caminho mais curto para o rate limit. O corte mantém os
         * termos mais precisos — que são os primeiros da lista — e descarta a
         * cauda.
         *
         * O teto caiu de 8 para 5: com a lista de domínios de vídeo enxugada e a
         * validação estrita do vídeo, os termos da cauda quase nunca acrescentam
         * candidato novo — só gastam orçamento. Os cinco primeiros já cobrem as
         * grafias de numeração e as intenções principais.
         */
        'stream_direto_max_termos' => (int) env('TORRENTS_STREAM_DIRETO_MAX_TERMOS', 5),

        /*
         * Intervalo, em milissegundos, entre duas consultas ao motor de busca. O
         * atraso é sorteado entre o mínimo e o máximo a cada consulta, para que a
         * cadência não seja um relógio fixo — que também é padrão de bot. Zero
         * desliga a espera (útil em teste).
         *
         * O intervalo caiu (de 800–2200 para 400–1200): o teto de termos menor já
         * reduz o número de consultas, e a espera entre elas pode ser mais curta
         * sem virar rajada. O sorteio continua, para a cadência não ser fixa.
         */
        'stream_direto_intervalo_min' => (int) env('TORRENTS_STREAM_DIRETO_INTERVALO_MIN', 400),
        'stream_direto_intervalo_max' => (int) env('TORRENTS_STREAM_DIRETO_INTERVALO_MAX', 1200),

        /*
         * Teto de páginas abertas por busca. Cada página é uma requisição, e o
         * fallback é socorro, não catálogo: uma vez que há links tocáveis, gastar
         * o orçamento em mais páginas só atrasa a resposta.
         *
         * O teto voltou de 10 para 6. O valor alto fazia sentido quando a lista
         * de domínios de vídeo estava inflada com clones mortos: era preciso
         * abrir muitas páginas para, por sorte, alcançar o agregador certo. Com a
         * lista enxuta (só quem tem player nativo) e a validação estrita do
         * vídeo, as primeiras páginas já são as boas — e o teto menor evita que a
         * busca gaste 40 s abrindo lixo quando não há nada a achar.
         */
        'stream_direto_max_paginas' => (int) env('TORRENTS_STREAM_DIRETO_MAX_PAGINAS', 6),

        /*
         * Alvo de fontes distintas que encerra a varredura. É diferente do teto de
         * páginas: uma página pode render várias fontes, e o que o usuário escolhe
         * é a fonte. Assim que há este número de fontes na mão, o laço para — não
         * vale gastar o orçamento restante atrás de mais opções quando já há o
         * suficiente para escolher.
         *
         * Zero ou negativo desliga o corte: aí o laço só para pelo teto de páginas
         * ou pelo orçamento.
         */
        'stream_direto_max_fontes' => (int) env('TORRENTS_STREAM_DIRETO_MAX_FONTES', 2),

        /*
         * Teto, em segundos, de cada requisição do fallback (motor de busca ou
         * página de streaming). Curto de propósito: o fallback roda depois do
         * orçamento principal e não pode empurrar a resposta além dos 60 s que o
         * frontend espera.
         *
         * O teto caiu de 10 para 8. Com a lista de domínios enxuta e o descarte
         * de site morto antes da extração, a página que não responde rápido
         * dificilmente é a boa — e 8 s ainda cobrem a renderização do FlareSolverr
         * nos sites que montam o player por JavaScript.
         */
        'stream_direto_tempo_limite' => (int) env('TORRENTS_STREAM_DIRETO_TEMPO_LIMITE', 8),

        /*
         * Barreira de conteúdo impróprio do scraper de stream direto.
         *
         * O buscador web é uma caixa preta: para um título conhecido, ele pode
         * devolver, no meio dos agregadores de vídeo, um link de site adulto que
         * apenas compartilha uma palavra do nome. Foi o que aconteceu com "Donas
         * de Casa Desesperadas", cujo resultado incluiu o domínio
         * `xvideos-cdn.com`. Com esta chave ligada, o [`MotorBuscaWeb`] descarta
         * a URL na origem e o [`ProvedorStreamDireto`] revalida a página e cada
         * link de vídeo antes de virar fonte — a lista negra vive em
         * [`FiltroConteudoAdulto`].
         *
         * Desligar só faz sentido para depurar a própria barreira: sem ela, o
         * scraper volta a abrir qualquer página que o motor devolver.
         */
        'stream_direto_filtro_adulto' => (bool) env('TORRENTS_STREAM_DIRETO_FILTRO_ADULTO', true),

        /*
         * Relevância da página pelo título, no scraper de stream direto.
         *
         * A prova de mídia responde "aqui tem player?", mas não "o vídeo é o
         * pedido?". Uma página de fandom *sobre* a série embute o trailer e passa
         * na prova de mídia; um agregador devolve um episódio qualquer de outro
         * programa quando o título pedido não está no acervo. Foi assim que
         * "American Horror Story" abriu um episódio aleatório vindo do
         * `dramatotal.fandom.com` e um `historia-4` do Tokyvideo.
         *
         * Com esta chave, a página só é aceita se o endereço ou o `<title>`
         * trouxerem ao menos este número de palavras-chave distintas do título
         * (sem as palavras de ligação e sem a numeração do episódio). O padrão é
         * 2: um título composto ("American Horror Story") precisa de duas
         * palavras ("american" + "story") para provar relevância — uma só
         * ("american") casaria com `americanas.com.br` e `americansportshop.com.br`,
         * que apareceram no log. Zero desliga a checagem e volta ao critério
         * antigo, só a prova de mídia.
         */
        'stream_direto_min_palavras_chave' => (int) env('TORRENTS_STREAM_DIRETO_MIN_PALAVRAS_CHAVE', 2),

        /*
         * Busca direta nos agregadores, antes do motor de busca web.
         *
         * O motor aberto (SearXNG) é a peça frágil do stream direto: quando os
         * motores grandes suspendem o IP do container, sobra o Bing, que devolve
         * só plataforma legal — e a lista negra descarta tudo, deixando a busca
         * vazia **antes** de abrir qualquer página. Esta chave liga o atalho que
         * contorna o problema: em vez de perguntar ao motor onde o episódio mora,
         * o provedor pergunta direto à busca interna dos agregadores de vídeo
         * ([`BuscaAgregadores`]), cujo resultado já é a página do título.
         *
         * É complementar, não substituto: as páginas que voltam daqui passam
         * pelo mesmo crivo (relevância, prova de mídia, extração) das que vêm do
         * motor, e o motor continua rodando em seguida se a busca direta não der
         * em nada. Desligar restaura o fluxo antigo, só pelo motor web.
         */
        'stream_direto_busca_direta' => (bool) env('TORRENTS_STREAM_DIRETO_BUSCA_DIRETA', true),

        /*
         * Travessia de players de embed, depois da extração do HTML.
         *
         * Há página em que o player não está na página: o agregador embute um
         * iframe de um serviço de terceiro (o `plenoflu.com`, no caso do
         * `verpobreflix.net`) e é lá dentro que o vídeo mora. A extração comum só
         * enxerga o iframe — que serve de prova de mídia e nada mais —, então a
         * página era descartada como "sem arquivo de vídeo" com o episódio a uma
         * chamada de distância. Com esta chave ligada, o [`ResolvedorEmbed`]
         * percorre a cadeia conhecida até o master.m3u8 assinado.
         *
         * Só cadeias abertas entram: os provedores atrás de Cloudflare Turnstile
         * ou de cifra dentro do navegador continuam fora de alcance, com o motivo
         * documentado em `docs/integracoes.md`. Desligar restaura o
         * comportamento anterior, de aceitar apenas o que a página entrega pronta
         * em texto claro.
         */
        'stream_direto_resolver_embeds' => (bool) env('TORRENTS_STREAM_DIRETO_RESOLVER_EMBEDS', true),

        /*
         * Estratégia de roteamento por idade da série.
         *
         * Uma série recente tem release fresco nos indexadores de torrent e o
         * Torrentio responde em segundos. Uma série antiga é o contrário: os
         * indexadores devolvem pouco ou nada, e a cascata gasta o orçamento
         * inteiro antes de o fallback de stream direto — que é quem acha o
         * conteúdo raro — sequer começar. Com esta chave ligada, série fora do
         * limiar começa pelo stream direto e só cai nos torrents se o scraper
         * falhar (fallback cruzado).
         *
         * Desligar devolve o fluxo antigo: torrents primeiro, sempre.
         */
        'busca_por_idade_habilitada' => (bool) env('TORRENTS_BUSCA_POR_IDADE_HABILITADA', true),

        /*
         * Limiar, em anos, que separa série recente de série antiga. O critério é
         * o ano de lançamento da **série**, não o da temporada: uma série de 2004
         * continua sendo de 2004 na décima temporada, e é isso que se quer — o
         * catálogo de torrents envelhece junto com a série.
         *
         * O limiar é inclusivo: com 2, uma série de dois anos ainda é recente e
         * uma de três já é antiga. Zero ou negativo desliga o corte na prática.
         */
        'busca_idade_limite_anos' => (int) env('TORRENTS_BUSCA_IDADE_LIMITE_ANOS', 2),

        /*
         * Bypass do cache de consultas aos provedores. Com isto ligado, a busca
         * ignora a **leitura** do cache e reconsulta o provedor; o resultado novo
         * é gravado por cima, então as chamadas seguintes já veem o valor fresco.
         * Serve para tirar da frente um resultado limitado que ficou preso no
         * cache antes de uma correção, sem esperar o TTL nem limpar o Redis.
         */
        'cache_bypass' => (bool) env('TORRENTS_CACHE_BYPASS', false),

        /*
         * Com isto ligado, a montagem final entrega só a pilha boa — dublado e
         * dual áudio provados — quando ela sozinha já alcança o mínimo. Se o
         * PT-BR não chega lá, a reserva completa a lista, para o player ter
         * alternativa. Os packs de idioma não provado não contam para o mínimo:
         * entram pela reserva, no fim da lista. Desligue quando o suporte a
         * legendado/original passar a ser o padrão.
         */
        'apenas_pt_br' => (bool) env('TORRENTS_APENAS_PT_BR', true),

        /*
         * Corte duro de idioma: só áudio PT-BR provado na lista final.
         *
         * O `apenas_pt_br` acima decide *quanto* da reserva entra para completar
         * o mínimo — mas a reserva ainda carrega original e legendado, o que
         * enche a lista de releases que o usuário brasileiro não aproveita. Com
         * esta chave ligada, a montagem final descarta de vez tudo que não seja
         * áudio PT-BR provado: sobram dublado e dual áudio. O original em inglês
         * e o legendado (áudio original com legenda PT-BR) somem, mesmo que a
         * lista fique menor que o mínimo.
         *
         * É um corte mais duro que o `apenas_pt_br`: aquele é sobre *quanto* de
         * reserva entra, este é sobre *se* a reserva entra. Desligue quando
         * quiser a lista completa de volta, com a reserva no fim.
         */
        'somente_pt_br_ou_legendado' => (bool) env('TORRENTS_SOMENTE_PT_BR_OU_LEGENDADO', true),

        /*
         * Busca de packs de temporada no fim da cascata de episódio.
         *
         * Um episódio isolado de série antiga raramente tem seeds; o pack da
         * temporada segue vivo porque é o que a comunidade ainda procura. Com
         * isto ligado, quando nenhum termo de episódio achou fonte dublada, a
         * busca tenta "S01 completa" / "Temporada 1 completa". O media-service
         * baixa só o arquivo do episódio pedido de dentro do pack.
         *
         * Fica atrás de configuração para poder desligar sem reverter código.
         */
        'packs_habilitados' => (bool) env('TORRENTS_PACKS_HABILITADOS', true),

        /*
         * Exceção de idioma só para pack de temporada.
         *
         * O corte de `apenas_pt_br` existe porque o player não aproveita áudio em
         * outro idioma — mas o pack é o último recurso de uma série antiga, e a
         * esmagadora maioria deles não vem marcada como dublado. Sem a exceção, o
         * pack é encontrado e descartado, e a lista volta vazia (foi o caso de
         * American Horror Story). Com isto ligado, só a fonte **marcada como
         * pack** sobrevive ao corte sem ser dublada; episódios e filmes continuam
         * sujeitos ao corte normal. O pack não é áudio PT-BR provado: ele não
         * conta para o mínimo e vai para a reserva, depois dos episódios de
         * qualquer idioma. É o que mantém o pack do Torrentio atrás dos episódios
         * do Knaben, do TPB+ e do APIBay.
         */
        'packs_qualquer_idioma' => (bool) env('TORRENTS_PACKS_QUALQUER_IDIOMA', true),

        /*
         * Termos de série no fim da cascata de episódio.
         *
         * Os buscadores por nome casam todas as palavras do termo, e os termos
         * que já existem carregam ruído que zera o recall justamente nos packs
         * nacionais das séries antigas: "S01E01" e "completa" não aparecem no
         * nome de "1ª 2ª 3ª Temporadas Dublado e Legendado". Com isto ligado, a
         * busca acrescenta termos sem numeração e sem "completa" ("... dublado",
         * "... temporada 1") depois de tudo o mais; as fontes que vêm deles são
         * julgadas pelo gate de temporada do CatalogoProvedores, que descarta o
         * que não declarar a temporada pedida.
         */
        'termos_serie_habilitados' => (bool) env('TORRENTS_TERMOS_SERIE_HABILITADO', true),

        /*
         * Piso de fontes boas que dispensa a reserva.
         *
         * A cascata junta o que acha em PT-BR provado (dublado e dual áudio) e o
         * resto vira reserva. Quando o PT-BR sozinho alcança este piso, a lista
         * final é só ele. Quando não alcança, a reserva completa até o teto de
         * fontes — é o caso de um filme que só tem release em inglês, em que a
         * alternativa evita uma lista de um item só. O piso **não** é o tamanho
         * máximo da lista: quem limita é o teto de vinte.
         */
        'minimo_fontes' => (int) env('TORRENTS_MINIMO_FONTES', 15),

        /*
         * Meta de fontes PT-BR que a coleta tenta alcançar antes de parar.
         *
         * Antes, a cascata parava no primeiro termo que trouxesse uma dublada e
         * completava o resto com original — o que enchia a lista de releases
         * errados. Agora ela segue termo a termo até juntar esta meta ou esgotar
         * os degraus; o que passar disso é reserva.
         *
         * Com `buscar_todos` ligado (o padrão), esta meta deixa de encerrar a
         * busca: ela só marca o ponto em que não vale mais insistir nos termos
         * restantes do degrau atual. Quem encerra é o orçamento.
         */
        'meta_pt_br_coleta' => (int) env('TORRENTS_META_PT_BR', 6),

        /*
         * Segunda tentativa pelo título original.
         *
         * A busca pergunta primeiro pelo título traduzido — é o que os trackers
         * brasileiros publicam. Quando essa primeira fase não junta PT-BR
         * suficiente, vale repetir a busca pelo título original: algumas
         * traduções ficam curtas demais para o buscador do site ("Homem-Aranha"
         * devolve o desenho, "Spider-Man" devolve o filme). Desligada, a busca
         * fica só com o título traduzido e economiza orçamento — ao custo de
         * perder o release que só aparece pelo nome internacional.
         */
        'titulo_original_segunda_tentativa' => (bool) env('TORRENTS_TITULO_ORIGINAL_SEGUNDA_TENTATIVA', true),

        /*
         * Buscar em todos os provedores, sem encerrar na primeira meta atingida.
         *
         * Ligada (padrão), a cascata percorre o registro inteiro: cada provedor
         * recebe os termos até render `fontes_suficientes_por_provedor` fontes
         * PT-BR e então é deixado de lado — não por ter falhado, mas por já ter
         * dado o que tinha —, e a busca segue para o próximo. Um provedor sem
         * fonte ou indisponível é pulado na hora. O efeito é uma lista montada
         * com o que **cada** provedor oferece, em vez de parar no primeiro que
         * bate a meta.
         *
         * O preço é latência: como a busca não encerra cedo, o orçamento global
         * (`orcamento_busca`) passa a ser o único freio. Desligue só se precisar
         * do comportamento antigo, de resposta rápida com o primeiro acerto.
         */
        'buscar_todos' => (bool) env('TORRENTS_BUSCAR_TODOS', true),

        /*
         * Cobertura completa: descer todos os degraus mesmo com a meta batida.
         *
         * Desligada (padrão), a cascata encerra assim que junta `meta_pt_br_coleta`
         * fontes dubladas — rápida, mas os degraus de baixo nunca são consultados e
         * o relatório de cobertura os mostra como `nao_consultado`. Ligada, a meta
         * só encerra a repetição de termos do degrau atual e a cascata desce até o
         * último, uma consulta por degrau, para que "perguntamos a todos?" tenha
         * resposta verificável no relatório. O preço é a latência do indexador;
         * ligue quando precisar de certeza, não no fluxo comum.
         */
        'cobertura_completa' => (bool) env('TORRENTS_COBERTURA_COMPLETA', false),

        /*
         * Fontes aproveitadas de um provedor a partir das quais ele deixa de ser
         * consultado nos termos seguintes.
         *
         * Cada termo da cascata é uma nova rodada de requisições contra todos os
         * degraus; numa série nova os termos passam de vinte e a soma estoura o
         * tempo que o frontend espera. Este teto por provedor corta a repetição:
         * quem já entregou este tanto de fontes que passaram pelo gate não é
         * perguntado de novo. Não afeta a ordem final da lista e não vale para os
         * provedores por identificador, que são consultados uma única vez. Em `0`
         * o corte é desligado.
         */
        'fontes_suficientes_por_provedor' => (int) env('TORRENTS_FONTES_SUFICIENTES_POR_PROVEDOR', 4),

        /*
         * Teto de packs inspecionados por busca.
         *
         * O nome do pack quase sempre prova o idioma; só quando ele não prova é
         * que o backend abre o conteúdo pelo media-service — caro e lento. Este
         * teto limita quantos packs podem pagar esse preço em uma mesma busca.
         */
        'inspecao_packs_limite' => (int) env('TORRENTS_INSPECAO_PACKS_LIMITE', 6),

        /*
         * Tempo limite, em segundos, da inspeção de um pack no media-service.
         *
         * A leitura dos metadados depende de achar peers para o magnet; sem
         * teto, um pack morto seguraria a busca inteira. Ao estourar, o backend
         * desiste e joga o pack na reserva.
         */
        'inspecao_timeout' => (int) env('TORRENTS_INSPECAO_TIMEOUT', 12),

        /*
         * Tempo de cache do veredito da inspeção, em segundos (padrão 24h).
         *
         * Só respostas definitivas entram no cache: o resultado de uma inspeção
         * que estourou o tempo não vira "não é dublado" permanente.
         */
        'inspecao_cache_ttl' => (int) env('TORRENTS_INSPECAO_CACHE_TTL', 86400),
    ],

    // --- Provisionamento do Prowlarr (degrau 2) ---

    /*
     * O Prowlarr sobe junto com o stack, mas nasce sem chave conhecida pelo
     * backend e sem indexador cadastrado. O comando `prowlarr:provisionar`,
     * chamado pelo entrypoint do backend, lê a chave da API no config.xml do
     * volume compartilhado e cadastra os indexadores PT-BR abaixo.
     *
     * `config_path` vazio desativa o provisionamento: nesse caso o backend
     * espera que TORRENTS_TORZNAB_KEY venha do ambiente (Prowlarr externo).
     */
    'prowlarr' => [
        'url' => env('PROWLARR_URL', env('TORRENTS_TORZNAB_URL', 'http://prowlarr:9696')),
        'config_path' => env('PROWLARR_CONFIG_PATH', ''),
        'tempo_limite' => (int) env('PROWLARR_TEMPO_LIMITE', 20),

        // Nome exibido no Prowlarr caso o schema não traga um.
        'rotulo_padrao' => env('PROWLARR_ROTULO_PADRAO', 'Índice público PT-BR'),

        /*
         * Ids das definições Cardigann que viram indexador, separados por
         * vírgula. O id é o `definitionFile` como o Prowlarr o expõe, sem a
         * extensão `.yml`; a lista sai do ambiente para poder mudar sem tocar no
         * código.
         *
         *   - `1337x`: definição oficial, embutida no Prowlarr. Tracker público
         *     estável, acervo amplo e um dos que devolvem release marcado como
         *     dublado. Vive atrás do CloudFlare, então depende do proxy abaixo.
         *   - `thepiratebay`: cena PT-BR com "Dublado"/"Dual Áudio" e acesso
         *     livre; não passa pelo CloudFlare, logo fica no caminho direto.
         *   - `torrentgalaxy`: mesmo tipo de acervo dublado e de acesso livre,
         *     porém atrás do CloudFlare — por isso entra também no proxy.
         *
         * A lista é curta de propósito: indexador morto não melhora a busca, só
         * gasta uma consulta por termo — e a cascata pergunta quatro termos por
         * episódio. Um id a mais só entra quando provar que traz fonte PT-BR.
         *
         * `torrentdosfilmes` saiu daqui depois que o domínio foi sequestrado. A
         * definição continua versionada em docker/prowlarr/Definitions/Custom
         * para o dia em que o site voltar, mas **fora do provisionamento**: não
         * faz sentido pagar uma consulta por termo num endereço que hoje serve
         * site de apostas, nem ressuscitar no painel o que já foi removido à
         * mão. Para reativar, basta devolvê-la a esta lista.
         */
        'indexadores' => $lista(
            env('PROWLARR_INDEXADORES'),
            ['1337x', 'thepiratebay', 'torrentgalaxy']
        ),

        /*
         * Subconjunto de `indexadores` que o CloudFlare barra, separado por
         * vírgula. O Prowlarr recusa o cadastro enquanto o teste de busca falha,
         * e é justamente com "blocked by CloudFlare Protection" que o 1337x e o
         * TorrentGalaxy respondem. O FlareSolverr sobe junto com o stack, o
         * backend o cadastra como proxy e associa estes indexadores por tag —
         * depois disso o teste passa e o indexador nasce ativo.
         *
         * Os que não estão aqui (hoje, o ThePirateBay) são testados direto.
         * `proxy_ativo` em false volta ao comportamento antigo: os protegidos
         * ficam cadastrados, porém inativos.
         */
        'flaresolverr_url' => env('FLARESOLVERR_URL', 'http://flaresolverr:8191'),
        'proxy_ativo' => (bool) env('PROWLARR_PROXY_ATIVO', true),
        'proxy_nome' => env('PROWLARR_PROXY_NOME', 'FlareSolverr'),
        'proxy_tag' => env('PROWLARR_PROXY_TAG', 'flaresolverr'),
        'proxy_indexadores' => $lista(
            env('PROWLARR_PROXY_INDEXADORES'),
            ['1337x', 'torrentgalaxy']
        ),
    ],
];
