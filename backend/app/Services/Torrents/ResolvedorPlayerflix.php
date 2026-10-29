<?php

namespace App\Services\Torrents;

use Illuminate\Support\Facades\Log;

/**
 * Catálogo de streams endereçável pelo id do TMDB — o `playerflix`.
 *
 * É a segunda fonte do **passo zero**, ao lado do [`ResolvedorVod`]. A ideia é a
 * mesma: quando o id do TMDB já está em mãos, não há por que descobrir página
 * nenhuma. A diferença está no que o provedor devolve. O VOD entrega o arquivo
 * direto (`native`); este entrega **o catálogo de embeds do título**, já
 * etiquetados por idioma, e quem sabe transformar cada embed em arquivo é uma
 * receita de player — o [`ResolvedorEmbed`] faz isso para os agregadores, e aqui
 * a receita é aplicada sobre o embed escolhido.
 *
 * ## Por que ele vale a pena
 *
 * A descoberta é o elo mais frágil do fallback: motor de busca bloqueado, site
 * que troca de domínio, acervo que não indexa o título. Este provedor não
 * descobre nada — ele recebe `type` (`movie`/`tv`), `id` e, quando é série,
 * `season`/`episode`, e responde com a lista de players daquele título. Para o
 * conteúdo raro, que era justamente quem sobrava para a cascata de torrents,
 * isso encurta o caminho para uma única consulta JSON.
 *
 * ## O que sai daqui
 *
 * Uma URL de `.m3u8`/`.mp4` e o idioma **declarado pelo player** (o watchplay
 * publica `Dublado`). A regra de ouro é a do resto do provedor: nenhuma URL sai
 * sem passar por duas provas — extensão aceita e sonda de existência. Um embed
 * cujo player não tem receita é descartado com log em `debug`; o usuário nunca
 * recebe uma página disfarçada de vídeo.
 *
 * ## O que fica de fora, de propósito
 *
 * O catálogo costuma oferecer `superflixapi.fun` e `embedplayabyss.top` em
 * português. Nenhum dos dois tem receita aqui, e o SuperFlix é o mesmo agregador
 * que o [`BuscaAgregadores`] já consulta pelo caminho normal. Tentá-los por este
 * atalho só duplicaria a consulta — e o passo zero não é lugar de adivinhação.
 */
class ResolvedorPlayerflix
{
    use ConsultaComOrcamento;

    /**
     * Endereço do catálogo.
     *
     * Os domínios desta família giram; o host é configurável
     * (`stream_direto_playerflix_host`) para acompanhar a troca sem alterar
     * código. Vazio, vale o padrão daqui.
     */
    private const HOST_PADRAO = 'https://playerflix.ink';

    /**
     * Rota que responde à consulta por id.
     *
     * É o mesmo endpoint que a página do player usa por XHR: exige o cabeçalho
     * `X-Requested-With`, senão devolve a casca HTML do site.
     */
    private const ROTA_DO_CATALOGO = '/inc/Ajax.php';

    /**
     * Formatos que o media-service aceita como fonte direta.
     *
     * Mesma lista do [`ResolvedorVod`]: conferir aqui evita oferecer ao player
     * uma URL que morreria na primeira checagem do serviço de mídia.
     *
     * @var array<int, string>
     */
    private const EXTENSOES = ['.mp4', '.m4v', '.webm', '.mkv', '.mov', '.m3u8'];

    /**
     * Teto da sonda de existência, em segundos.
     *
     * O `ClienteHttp` encolhe este valor para o que resta do orçamento, então
     * uma sonda começada no fim do prazo custa o que sobra e não mais que isso.
     */
    private const TEMPO_DA_SONDA_SEGUNDOS = 4;

    /**
     * Players de embed que já sabemos percorrer até o arquivo.
     *
     * O catálogo devolve o embed de terceiro, não o vídeo: cada entrada aqui é a
     * receita de um player. O `watchplay.shop` monta a página com a chamada
     * `createMyPlayer({... url:"...m3u8" ...})` em texto puro — sem ofuscação,
     * sem XHR —, e por isso ele é o único da lista hoje. Os demais
     * (`embedplayer2.xyz`, `superflixapi.fun`, `embedplayabyss.top`) aparecem no
     * catálogo em português, mas não têm receita: são descartados com log em vez
     * de virarem fonte quebrada.
     *
     * @var array<int, string>
     */
    private const PLAYERS_CONHECIDOS = [
        'watchplay.shop',
    ];

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {}

    /**
     * O resolvedor está ligado.
     *
     * Como no VOD, a chave permite desligar só esta fonte sem desligar o passo
     * zero inteiro.
     */
    public function disponivel(): bool
    {
        return (bool) config('services.torrents.stream_direto_resolver_playerflix', true);
    }

    /**
     * Resolve a fonte pelo id do TMDB.
     *
     * Devolve `null` quando o resolvedor está desligado, quando não há id válido
     * (a rota exige um inteiro), quando o catálogo não tem o título ou quando
     * nenhum embed em português pôde ser percorrido até o arquivo. Nunca sobe
     * exceção: o passo zero do provedor precisa ser silencioso para a busca cair
     * no motor e nos agregadores como se ele não existisse.
     *
     * @return array{url: string, idioma: string}|null
     */
    public function resolver(?string $tmdbId, ?int $temporada, ?int $episodio, int $teto): ?array
    {
        $id = trim((string) $tmdbId);

        if (! $this->disponivel() || $id === '' || ! ctype_digit($id) || $teto <= 0) {
            return null;
        }

        if (! $this->temTempoParaConsulta($teto)) {
            return null;
        }

        $deSerie = $temporada !== null && $episodio !== null;

        $consulta = [
            'type' => $deSerie ? 'tv' : 'movie',
            'id' => $id,
        ];

        if ($deSerie) {
            $consulta['season'] = $temporada;
            $consulta['episode'] = $episodio;
        }

        $host = $this->host();

        $resposta = $this->cliente->get($host.self::ROTA_DO_CATALOGO, $consulta, null, $this->tempoDeConsulta($teto), [
            'Referer' => $host.'/',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        if ($resposta === null || ! $resposta->ok()) {
            Log::debug('Stream direto: catálogo por id sem resposta.', [
                'host' => $host,
                'tipo' => $consulta['type'],
                'status' => $resposta?->status(),
            ]);

            return null;
        }

        $dados = $resposta->json();

        if (! is_array($dados) || ($dados['status'] ?? false) !== true) {
            Log::debug('Stream direto: catálogo por id não tem o título.', [
                'id' => $id,
                'temporada' => $temporada,
                'episodio' => $episodio,
            ]);

            return null;
        }

        foreach ($this->embedsEmPortugues((array) ($dados['data']['options'] ?? [])) as $embed) {
            if (! $this->temTempoParaConsulta($teto)) {
                break;
            }

            $resolvido = $this->percorrerEmbed($embed, $teto);

            if ($resolvido !== null) {
                Log::debug('Stream direto: catálogo por id entregou a fonte.', [
                    'embed' => $embed,
                    'url' => $resolvido['url'],
                ]);

                return $resolvido;
            }
        }

        return null;
    }

    /**
     * Filtra as opções em português e põe os players conhecidos na frente.
     *
     * A ordem do catálogo não serve como ordem de tentativa: ele lista primeiro
     * o player que paga mais, e não o que funciona. Como só o player com receita
     * pode render arquivo, ele vai para a frente; os demais ficam no fim apenas
     * para o log registrar que existiam.
     *
     * As opções sem `embed` válido ou com idioma diferente de `pt*` caem fora —
     * um embed em `en-us` entregaria áudio inglês, que é exatamente o que o
     * usuário não pediu.
     *
     * @param  array<int, mixed>  $opcoes
     * @return array<int, string>
     */
    private function embedsEmPortugues(array $opcoes): array
    {
        $escolhidas = [];

        foreach ($opcoes as $posicao => $opcao) {
            if (! is_array($opcao)) {
                continue;
            }

            $embed = trim((string) ($opcao['embed'] ?? ''));
            $idioma = strtolower(trim((string) ($opcao['lang'] ?? '')));

            if ($embed === '' || ! preg_match('#^https?://#i', $embed) || ! str_starts_with($idioma, 'pt')) {
                continue;
            }

            $escolhidas[$embed] = [
                'embed' => $embed,
                'posicao' => (int) $posicao,
                'conhecido' => $this->playerConhecido($embed) !== null,
            ];
        }

        $escolhidas = array_values($escolhidas);

        usort($escolhidas, function (array $a, array $b): int {
            if ($a['conhecido'] !== $b['conhecido']) {
                return $a['conhecido'] ? -1 : 1;
            }

            return $a['posicao'] <=> $b['posicao'];
        });

        return array_column($escolhidas, 'embed');
    }

    /**
     * Diz qual receita atende este embed, se alguma.
     *
     * A comparação é pelo sufixo do host, não por igualdade: o catálogo alterna
     * entre `watchplay.shop` e `v1.watchplay.shop`, mas a página que ele serve é
     * a mesma.
     */
    private function playerConhecido(string $embed): ?string
    {
        $host = strtolower((string) parse_url($embed, PHP_URL_HOST));

        if ($host === '') {
            return null;
        }

        foreach (self::PLAYERS_CONHECIDOS as $conhecido) {
            if ($host === $conhecido || str_ends_with($host, '.'.$conhecido)) {
                return $conhecido;
            }
        }

        return null;
    }

    /**
     * Percorre o embed até o arquivo, aplicando a receita do player.
     *
     * @return array{url: string, idioma: string}|null
     */
    private function percorrerEmbed(string $embed, int $teto): ?array
    {
        if ($this->playerConhecido($embed) === null) {
            Log::debug('Stream direto: embed em português sem receita conhecida.', [
                'embed' => $embed,
            ]);

            return null;
        }

        return $this->peloWatchplay($embed, $teto);
    }

    /**
     * Receita do `watchplay.shop`.
     *
     * A página é o player inteiro em HTML: um `<script>` chama
     * `createMyPlayer({...})` com a URL do HLS já assinada (o token vai na query
     * e vence em ~24 h) e `window.MyPlayerAudio` diz o áudio. Não há XHR, nem
     * ofuscação, nem segundo salto — é a leitura do HTML que resolve.
     *
     * O HLS que ele entrega usa segmentos **mascarados** (`.css`, `.js`), e o
     * media-service já sabe lidar com isso: o demuxer do FFmpeg roda com
     * `-extension_picky 0` e as extensões falsas liberadas, e o hls.js do
     * navegador só consome playlist depois do nosso proxy. Validado por sonda:
     * playlist 200, segmento com sync de MPEG-TS (`0x47`) e áudio `aac` com
     * `language=por`.
     *
     * @return array{url: string, idioma: string}|null
     */
    private function peloWatchplay(string $embed, int $teto): ?array
    {
        $pagina = $this->cliente->get($embed, [], null, $this->tempoDeConsulta($teto), [
            'Referer' => $this->host().'/',
        ]);

        if ($pagina === null || ! $pagina->ok()) {
            Log::debug('Stream direto: player do catálogo não abriu.', [
                'embed' => $embed,
                'status' => $pagina?->status(),
            ]);

            return null;
        }

        $html = (string) $pagina->body();

        $url = $this->urlDoMyPlayer($html);

        if ($url === null) {
            Log::debug('Stream direto: player do catálogo sem URL de mídia.', [
                'embed' => $embed,
            ]);

            return null;
        }

        if (! $this->extensaoValida($url)) {
            Log::debug('Stream direto: player do catálogo entregou formato não suportado.', [
                'embed' => $embed,
                'url' => $url,
            ]);

            return null;
        }

        /*
         * A prova aqui é a mesma do VOD: o catálogo pode apontar para um player
         * que só devolve página de erro ou credencial recusada. `arquivoServido`
         * aprova demais — foi ele que deixou passar um `401` do `nixplay.lat` e
         * transformou o link morto em fonte "verificada". O que entra na lista
         * precisa entregar bytes de mídia.
         */
        if (! $this->cliente->pareceVideoServido($url, self::TEMPO_DA_SONDA_SEGUNDOS)) {
            Log::debug('Stream direto: player do catálogo apontou para fonte sem arquivo.', [
                'embed' => $embed,
                'url' => $url,
            ]);

            return null;
        }

        return [
            'url' => $url,
            'idioma' => $this->idiomaDoMyPlayer($html),
        ];
    }

    /**
     * Extrai a URL de mídia da chamada `createMyPlayer({...})`.
     *
     * A URL chega escapada como JSON (`https:\/\/host\/...`), então o
     * desescapamento é parte da leitura. O `type` do objeto não é conferido
     * aqui: quem decide se o formato serve é `extensaoValida()`, que olha o
     * caminho de verdade em vez de confiar no rótulo do player.
     */
    private function urlDoMyPlayer(string $html): ?string
    {
        if (! preg_match('#createMyPlayer\(\{(.*?)\}\)#s', $html, $bloco)) {
            return null;
        }

        if (! preg_match('#url:\s*"([^"]+)"#', $bloco[1], $achado)) {
            return null;
        }

        $url = trim(html_entity_decode(str_replace('\\/', '/', $achado[1]), ENT_QUOTES));

        return $url === '' ? null : $url;
    }

    /**
     * Áudio declarado pelo player.
     *
     * O watchplay escreve `window.MyPlayerAudio="Dublado"`. Sem a declaração,
     * vale o idioma da opção que trouxe o embed (`pt-br`) — a mesma postura do
     * VOD, que cai no contrato do acervo quando o espelho não fala nada.
     */
    private function idiomaDoMyPlayer(string $html): string
    {
        if (preg_match('#window\.MyPlayerAudio="([^"]*)"#', $html, $achado)) {
            $audio = trim(html_entity_decode($achado[1], ENT_QUOTES));

            if ($audio !== '') {
                return $audio;
            }
        }

        return 'pt-BR';
    }

    /**
     * Diz se o caminho da URL termina numa extensão que a fonte direta aceita.
     */
    private function extensaoValida(string $url): bool
    {
        $caminho = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach (self::EXTENSOES as $extensao) {
            if (str_ends_with($caminho, $extensao)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Host do catálogo, normalizado (com esquema e sem barra final).
     *
     * Um host escrito sem esquema (`playerflix.ink`) recebe `https://`, que é
     * como a configuração costuma ser preenchida.
     */
    private function host(): string
    {
        $configurado = trim((string) config('services.torrents.stream_direto_playerflix_host', ''));

        if ($configurado === '') {
            return self::HOST_PADRAO;
        }

        $configurado = rtrim($configurado, '/');

        if (! preg_match('#^https?://#i', $configurado)) {
            $configurado = 'https://'.$configurado;
        }

        return $configurado;
    }
}
