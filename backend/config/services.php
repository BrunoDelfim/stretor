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
     * Provedor de torrents usado na busca de fontes para reprodução.
     * O provedor fica isolado no TorrentService — trocar a API significa mexer
     * apenas na normalização, sem afetar o contrato consumido pelo frontend.
     */
    'torrents' => [
        // O domínio principal do YTS (yts.mx) é bloqueado por DNS em vários
        // provedores de hospedagem. O espelho yts.gg responde com o mesmo
        // contrato da API v2, então é ele que fica como padrão.
        'base_url' => env('TORRENTS_BASE_URL', 'https://yts.gg'),
        'cache_ttl' => (int) env('TORRENTS_CACHE_TTL', 1800),
    ],
];
