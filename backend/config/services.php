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
         * Trackers públicos PT-BR lidos direto por HTTP. São a razão de existir
         * da busca nativa: só eles publicam release com áudio nacional. Lista
         * separada por vírgula, tentada na ordem.
         */
        'trackers_br_urls' => env(
            'TORRENTS_TRACKERS_BR_URLS',
            'https://torrentdosfilmes.tv,https://torrentsfilmeshd.net'
        ),
        // Caminho da página de busca dentro desses trackers.
        'trackers_br_busca' => env('TORRENTS_TRACKERS_BR_BUSCA', 'index.php'),

        // APIBay é a API pública do Pirate Bay: JSON limpo, sem chave, e com o
        // campo `imdb` — que serve para descartar homônimos na hora.
        'apibay_url' => env('TORRENTS_APIBAY_URL', 'https://apibay.org'),

        // Torrentio resolve streams a partir do imdb_id, então é o único
        // provedor nativo que consegue buscar por identificador e não por nome.
        'torrentio_url' => env('TORRENTS_TORRENTIO_URL', 'https://torrentio.strem.fun'),

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

        // --- Degrau 3: YTS (reserva em inglês) ---

        // O domínio principal do YTS (yts.mx) é bloqueado por DNS em vários
        // provedores de hospedagem. O espelho yts.gg responde com o mesmo
        // contrato da API v2, então é ele que fica como padrão.
        'base_url' => env('TORRENTS_BASE_URL', 'https://yts.gg'),
        'cache_ttl' => (int) env('TORRENTS_CACHE_TTL', 1800),
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

        // Ids das definições Cardigann versionadas em
        // docker/prowlarr/Definitions/Custom. Cada uma vira um indexador.
        'indexadores' => ['torrentdosfilmes'],
    ],
];
