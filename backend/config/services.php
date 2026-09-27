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
        'torznab_orcamento' => (int) env('TORRENTS_TORZNAB_ORCAMENTO', 20),

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
         * pack** atravessa o corte sem ser dublada; episódios e filmes continuam
         * sujeitos ao corte normal. O pack entra no fim da lista, atrás do
         * dublado, quando ele existe.
         */
        'packs_qualquer_idioma' => (bool) env('TORRENTS_PACKS_QUALQUER_IDIOMA', true),
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
