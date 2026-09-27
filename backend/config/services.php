<?php

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
     * públicos PT-BR, mais os acervos amplos (APIBay, Torrentio e BT4G). É aqui
     * que o sistema faz por conta própria o trabalho; nenhum serviço externo
     * precisa estar de pé para achar um release dublado.
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

        // BT4G varre a rede DHT inteira: costuma achar o release PT-BR que os
        // trackers indexados não têm. A lista de espelhos é separada por
        // vírgula; o domínio principal sai do ar com frequência.
        'bt4g_urls' => env('TORRENTS_BT4G_URLS', 'https://bt4gprx.com'),

        /*
         * Teto de páginas de detalhe abertas em paralelo por busca. Cada detalhe
         * é uma requisição no tracker, então um termo genérico ("Homem-Aranha")
         * pode devolver dezenas de candidatos — sem teto, a resposta da API
         * estoura o tempo do usuário.
         */
        'max_detalhes_busca' => (int) env('TORRENTS_MAX_DETALHES_BUSCA', 16),

        // Tempo limite, em segundos, de cada requisição HTTP a um provedor.
        'tempo_limite' => (int) env('TORRENTS_TEMPO_LIMITE', 15),

        // Vários trackers respondem 403 para cliente sem User-Agent de navegador.
        'user_agent' => env(
            'TORRENTS_USER_AGENT',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
        ),

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

        // --- Degrau 3: YTS (reserva em inglês) ---

        // O domínio principal do YTS (yts.mx) é bloqueado por DNS em vários
        // provedores de hospedagem. O espelho yts.gg responde com o mesmo
        // contrato da API v2, então é ele que fica como padrão.
        'base_url' => env('TORRENTS_BASE_URL', 'https://yts.gg'),
        'cache_ttl' => (int) env('TORRENTS_CACHE_TTL', 1800),

        /*
         * Enquanto o player só lida com áudio em PT-BR, manter releases em
         * outros idiomas na lista só faz o frontend perder tempo tentando uma
         * fonte que não vai servir. Com isto ligado, a ordenação descarta tudo
         * que não seja dublado ou dual áudio — a lista fica curta e o teste,
         * rápido. Desligue quando o suporte a legendado/original entrar.
         */
        'apenas_pt_br' => (bool) env('TORRENTS_APENAS_PT_BR', true),
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
         * Ids das definições Cardigann que viram indexador. A lista é curta de
         * propósito: indexador morto não melhora a busca, só gasta uma consulta
         * por termo — e a cascata pergunta quatro termos por episódio.
         *
         *   - `1337x`: definição oficial, embutida no Prowlarr. Tracker público
         *     estável, acervo amplo e o único que hoje devolve release marcado
         *     como dublado. Vive atrás do CloudFlare, então depende do proxy
         *     configurado abaixo.
         *
         * `torrentdosfilmes` saiu daqui depois que o domínio foi sequestrado. A
         * definição continua versionada em docker/prowlarr/Definitions/Custom
         * para o dia em que o site voltar, mas **fora do provisionamento**: não
         * faz sentido pagar uma consulta por termo num endereço que hoje serve
         * site de apostas, nem ressuscitar no painel o que já foi removido à
         * mão. Para reativar, basta devolvê-la a esta lista.
         */
        'indexadores' => ['1337x'],

        /*
         * Proxy para os trackers que o CloudFlare barra. O Prowlarr recusa o
         * cadastro enquanto o teste de busca falha, e o 1337x responde
         * justamente com "blocked by CloudFlare Protection". O FlareSolverr sobe
         * junto com o stack, o backend o cadastra como proxy e associa os
         * indexadores abaixo por tag — depois disso o teste passa e o indexador
         * nasce ativo.
         *
         * `proxy_ativo` em false volta ao comportamento antigo: o 1337x fica
         * cadastrado, porém inativo.
         */
        'flaresolverr_url' => env('FLARESOLVERR_URL', 'http://flaresolverr:8191'),
        'proxy_ativo' => (bool) env('PROWLARR_PROXY_ATIVO', true),
        'proxy_nome' => env('PROWLARR_PROXY_NOME', 'FlareSolverr'),
        'proxy_tag' => env('PROWLARR_PROXY_TAG', 'flaresolverr'),
        'proxy_indexadores' => ['1337x'],
    ],
];
