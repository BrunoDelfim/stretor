<?php

namespace App\Services\Torrents;

use App\Support\FiltroConteudoAdulto;
use Illuminate\Support\Facades\Log;

/**
 * Busca direta nos agregadores de vídeo — o caminho que dispensa o motor web.
 *
 * O fallback de stream direto nasceu apoiado num motor de busca aberto (o
 * SearXNG interno), que resolve o título em páginas candidatas. O motor, porém,
 * é a peça mais frágil da corrente: quando os motores grandes (Google,
 * DuckDuckGo, Brave) suspendem o IP do container — o que acontece com
 * frequência —, sobra o Bing, que devolve só plataforma legal. Para o conteúdo
 * raro, o resultado é uma busca que volta vazia **antes** de abrir qualquer
 * página.
 *
 * Este serviço fecha essa brecha perguntando direto a quem tem o conteúdo: os
 * agregadores que hospedam o vídeo costumam ter **busca própria**, e o
 * resultado dela já é a página do título — sem depender de um terceiro indexar
 * a página certa. É o mesmo caminho do usuário: em vez de googlar "assistir X",
 * ele digita X na busca do próprio site.
 *
 * A lista é curta e por conhecimento de causa: cada agregador declara onde a
 * busca mora (`busca`) e como reconhecer o link de conteúdo na resposta
 * (`conteudo`). O domínio serve de âncora — só links do próprio site entram, o
 * que descarta menu, rodapé e link patrocinado.
 *
 * A saída é uma lista de URLs de página, do mesmo tipo que o
 * [`MotorBuscaWeb::procurar()`] devolveria: o provedor a processa com a mesma
 * prova de relevância, de mídia e de extração. Este serviço só troca a origem
 * do link, não o contrato.
 */
class BuscaAgregadores
{
    use ConsultaComOrcamento;
    use RelevanciaTitulo;

    /**
     * Agregadores com busca própria e o padrão que identifica o link de conteúdo.
     *
     * O padrão é sobre o **caminho** da URL, não o host: cada agregador separa o
     * conteúdo num prefixo próprio (`/series/`) e é por ele que a página de
     * resultado se distingue da navegação. O `verpobreflix.net` devolve a ficha
     * da série e o provedor desce dali para a página do episódio, pelo caminho
     * que já existia.
     *
     * O `tokyvideo.com` chegou a estar aqui e saiu: a busca dele é montada por
     * JavaScript, então o HTML estático de `/search?q=` só traz a lista de vídeos
     * populares da barra lateral — nenhuma pista do termo pedido, e zero
     * ocorrência do título nos bytes baixados. A regra passa a ser essa: só entra
     * agregador cujo resultado de busca esteja no HTML que se baixa sem navegador.
     *
     * @var array<string, array{busca: string, conteudo: string}>
     */
    private const AGREGADORES = [
        'verpobreflix.net' => [
            'busca' => 'https://www.verpobreflix.net/search?q={termo}',
            'conteudo' => '#^/series/#',
        ],
    ];

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {
    }

    /**
     * Procura, em cada agregador, a página do título.
     *
     * Os títulos são tentados em ordem (o principal primeiro). Para cada
     * agregador, o primeiro título que devolver resultado encerra a busca
     * naquele site — a alternativa serve de rede de segurança para quando o
     * acervo está cadastrado pelo nome de origem.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, string>
     */
    public function buscar(array $titulos): array
    {
        if (! (bool) config('services.torrents.stream_direto_busca_direta', true)) {
            return [];
        }

        $titulos = $this->titulosLimpos($titulos);

        if ($titulos === []) {
            return [];
        }

        $encontradas = [];

        foreach (self::AGREGADORES as $dominio => $agregador) {
            foreach ($titulos as $titulo) {
                if (! $this->temOrcamento()) {
                    break 2;
                }

                $paginas = $this->consultar($dominio, $agregador, $titulo);

                if ($paginas !== []) {
                    foreach ($paginas as $pagina) {
                        $encontradas[$pagina] = true;
                    }

                    break;
                }
            }
        }

        return array_keys($encontradas);
    }

    /**
     * Consulta a busca interna de um agregador e devolve os links de conteúdo.
     *
     * @param  array{busca: string, conteudo: string}  $agregador
     * @return array<int, string>
     */
    private function consultar(string $dominio, array $agregador, string $titulo): array
    {
        $url = str_replace('{termo}', rawurlencode($titulo), $agregador['busca']);
        $teto = (int) config('services.torrents.stream_direto_tempo_limite', 8);

        try {
            $resposta = $this->cliente->get($url, [], $this->navegador(), $teto);
        } catch (\Throwable $excecao) {
            Log::warning('Stream direto: falha na busca direta do agregador.', [
                'dominio' => $dominio,
                'titulo' => $titulo,
                'erro' => $excecao->getMessage(),
            ]);

            return [];
        }

        if ($resposta === null || $resposta->failed()) {
            Log::debug('Stream direto: agregador não respondeu à busca direta.', [
                'dominio' => $dominio,
                'titulo' => $titulo,
            ]);

            return [];
        }

        $links = $this->links((string) $resposta->body(), $url, $dominio, $agregador['conteudo']);

        /*
         * A relevância pelo título é conferida **aqui**, e não só lá na frente,
         * para o ruído não gastar orçamento. A página de resultado de um
         * agregador costuma vir salpicada de "veja também", e cada link desses
         * custaria uma requisição e uma vaga do teto de páginas antes de ser
         * descartado. Filtrar o slug agora é barato e deixa o crivo final (título
         * da página e URL do vídeo) exatamente onde já estava.
         */
        return array_values(array_filter(
            $links,
            fn (string $link): bool => $this->paginaRelevante($link, '', $titulo),
        ));
    }

    /**
     * Extrai os links de conteúdo de uma página de resultados.
     *
     * Só entram links do próprio agregador (âncora pelo host) que carreguem o
     * prefixo de conteúdo. É a mesma ideia da lista branca do motor web, mas
     * pelo host do resultado em vez do domínio de busca: aqui já se sabe em qual
     * site se está.
     *
     * @return array<int, string>
     */
    private function links(string $html, string $base, string $dominio, string $padraoConteudo): array
    {
        if ($html === '') {
            return [];
        }

        if (! preg_match_all('#href\s*=\s*["\']([^"\']+)["\']#i', $html, $casamentos)) {
            return [];
        }

        $links = [];

        foreach ($casamentos[1] as $bruto) {
            $endereco = html_entity_decode($bruto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $absoluto = $this->absolutizar($endereco, $base);

            if ($absoluto === null) {
                continue;
            }

            $host = strtolower((string) parse_url($absoluto, PHP_URL_HOST));

            if ($host !== $dominio && ! str_ends_with($host, '.'.$dominio)) {
                continue;
            }

            $caminho = strtolower((string) parse_url($absoluto, PHP_URL_PATH));

            if (! preg_match($padraoConteudo, $caminho)) {
                continue;
            }

            if ($this->filtroAdultoAtivo() && FiltroConteudoAdulto::urlBloqueada($absoluto)) {
                continue;
            }

            $links[$absoluto] = true;
        }

        return array_keys($links);
    }

    /**
     * Transforma um endereço relativo à raiz num absoluto.
     *
     * As buscas dos agregadores devolvem links das duas formas: absoluto
     * (`https://www.tokyvideo.com/video/x`) e relativo à raiz (`/series/x`). O
     * relativo resolve contra o esquema e o host da página de busca. Um relativo
     * ao caminho não é resolvido — não aparece em página de resultado e evitá-lo
     * mantém a regra simples.
     */
    private function absolutizar(string $endereco, string $base): ?string
    {
        $endereco = trim($endereco);

        if ($endereco === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $endereco)) {
            return $endereco;
        }

        $partes = parse_url($base);

        if ($partes === false || ! isset($partes['scheme'], $partes['host'])) {
            return null;
        }

        $raiz = $partes['scheme'].'://'.$partes['host'];

        if (isset($partes['port'])) {
            $raiz .= ':'.$partes['port'];
        }

        if (str_starts_with($endereco, '//')) {
            return $partes['scheme'].':'.$endereco;
        }

        if (str_starts_with($endereco, '/')) {
            return $raiz.$endereco;
        }

        return null;
    }

    /**
     * Normaliza a lista de títulos, descartando vazios e repetidos.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, string>
     */
    private function titulosLimpos(array $titulos): array
    {
        $limpos = [];

        foreach ($titulos as $titulo) {
            $titulo = trim((string) $titulo);

            if ($titulo !== '') {
                $limpos[] = $titulo;
            }
        }

        return array_values(array_unique($limpos));
    }

    /**
     * Agente de navegador das requisições, no mesmo padrão do [`MotorBuscaWeb`].
     */
    private function navegador(): string
    {
        $agente = (string) config('services.torrents.user_agent', '');

        return $agente !== ''
            ? $agente
            : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    }

    /**
     * Diz se a barreira de conteúdo impróprio está ligada.
     */
    private function filtroAdultoAtivo(): bool
    {
        return (bool) config('services.torrents.stream_direto_filtro_adulto', true);
    }
}

