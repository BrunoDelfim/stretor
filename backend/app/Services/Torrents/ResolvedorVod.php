<?php

namespace App\Services\Torrents;

use Illuminate\Support\Facades\Log;

/**
 * Provedor de VOD endereçável pelo id do TMDB.
 *
 * O fallback de stream direto pergunta a duas famílias de fonte. O motor de busca
 * e os agregadores precisam **descobrir** a página do título: alguém tem que
 * indexar a URL certa, e para o conteúdo raro essa descoberta é a parte frágil —
 * quando o motor devolve só plataforma legal, a busca volta vazia **antes** de
 * abrir qualquer página.
 *
 * Este resolvedor é a terceira família, e a mais direta de todas: o provedor
 * aceita o **id do TMDB** e devolve o arquivo de vídeo já pronto, sem busca, sem
 * página intermediária e sem embed. Como o id está em mãos desde o começo da
 * requisição — ele é a chave da rota `/fontes/{id}` —, a fonte certa é obtida sem
 * adivinhar nada: o host recebe `type` (`episode`/`movie`), `tmdb` e, quando é
 * série, `season`/`episode`, e responde com um MP4 assinado num CDN.
 *
 * Por isso ele entra como **passo zero** do [`ProvedorStreamDireto`]: é a única
 * fonte que não custa uma varredura de páginas para chegar ao arquivo. Quando ele
 * resolve, o resto da cascata nem é acionado; quando devolve "sem fonte" (o
 * título não está no acervo), o provedor segue para o motor e os agregadores como
 * sempre. A escolha do host é por lista porque os espelhos trocam de endereço com
 * frequência e nem sempre carregam o mesmo acervo — o primeiro que resolver
 * encerra a tentativa.
 *
 * ## O que sai daqui
 *
 * Uma URL de `.mp4`/`.m3u8` e o idioma. O acervo é PT-BR, então o idioma sai
 * como `pt-BR` — a mesma marca que o [`IdiomaFonte`] traduz para "Dublado". A URL
 * só é devolvida quando o provedor a marca como `native`: quando ele responde
 * `embed`, está apontando para um agregador de terceiro, e esse caminho é do
 * [`ResolvedorEmbed`], não deste — oferecer o embed cru entregaria ao usuário uma
 * página, não um vídeo.
 */
class ResolvedorVod
{
    use ConsultaComOrcamento;

    /**
     * Espelhos do provedor, em ordem de tentativa.
     *
     * Os domínios que usam este plugin giram com frequência e nem todos carregam
     * o mesmo acervo — um espelho pode responder `success: false` para um id que
     * o outro entrega. Tentar mais de um é o que evita depender de qual espelho
     * está saudável na hora da busca. A lista é configurável
     * (`stream_direto_vod_hosts`) para acompanhar a rotação sem mexer no código.
     *
     * @var array<int, string>
     */
    private const HOSTS_PADRAO = [
        'https://vizer.autos',
    ];

    /**
     * Endpoint do player na API do provedor.
     *
     * É uma rota do WordPress (`wp-json`), montada pelo plugin próprio do site.
     * Lê o corpo em JSON — que é o que o [`ClienteHttp::post()`] envia — e
     * responde no mesmo formato.
     */
    private const ROTA_DO_PLAYER = '/wp-json/api/v1/player';

    /**
     * Formatos que o media-service aceita como fonte direta.
     *
     * É a mesma lista do `EXTENSOES_DIRETAS` do serviço de mídia. Conferir aqui
     * evita oferecer ao usuário uma URL que morreria na primeira checagem — o
     * provedor anuncia `.mpd` de vez em quando e o player só trabalha com
     * MP4/HLS.
     *
     * @var array<int, string>
     */
    private const EXTENSOES = ['.mp4', '.m4v', '.webm', '.mkv', '.mov', '.m3u8'];

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {
    }

    /**
     * O resolvedor está ligado.
     *
     * Como no resolvedor de embeds, a chave permite desligar só este passo sem
     * desligar o fallback inteiro.
     */
    public function disponivel(): bool
    {
        return (bool) config('services.torrents.stream_direto_resolver_vod', true);
    }

    /**
     * Resolve a fonte pelo id do TMDB, tentando os espelhos em ordem.
     *
     * Devolve `null` quando o resolvedor está desligado, quando não há id válido
     * (a rota exige um inteiro) ou quando nenhum espelho entrega um arquivo
     * nativo. Nunca devolve uma URL que não tenha passado pela conferência de
     * modo e de extensão.
     *
     * O `$teto` é o tempo desta consulta, já descontado do orçamento global por
     * quem chama — a mesma disciplina do resolvedor de embeds.
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

        foreach ($this->hosts() as $host) {
            $resolvido = $this->consultar($host, $id, $temporada, $episodio, $deSerie, $teto);

            if ($resolvido !== null) {
                return $resolvido;
            }

            /*
             * Um espelho que não resolveu já cobrou o próprio tempo; se o que
             * resta não cobre outra consulta, o próximo nasceria perdido.
             */
            if (! $this->temTempoParaConsulta($teto)) {
                break;
            }
        }

        return null;
    }

    /**
     * Pergunta a um espelho específico pelo id.
     *
     * A leitura é defensiva de propósito: um espelho pode responder HTML (o
     * `success` não vem), responder com erro (`success: false`) ou entregar um
     * `embed` de terceiro em vez de um arquivo. Nos três casos o retorno é `null`
     * e a busca segue para o próximo espelho ou para o resto da cascata — nunca
     * sobe uma exceção nem devolve uma URL duvidosa.
     *
     * @return array{url: string, idioma: string}|null
     */
    private function consultar(string $host, string $id, ?int $temporada, ?int $episodio, bool $deSerie, int $teto): ?array
    {
        $corpo = $deSerie
            ? ['type' => 'episode', 'tmdb' => $id, 'season' => $temporada, 'episode' => $episodio]
            : ['type' => 'movie', 'tmdb' => $id];

        $resposta = $this->cliente->post($host.self::ROTA_DO_PLAYER, $corpo, null, $teto, [
            'Referer' => $host.'/',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        if ($resposta === null || ! $resposta->ok()) {
            Log::debug('Stream direto: VOD sem resposta.', [
                'host' => $host,
                'status' => $resposta?->status(),
            ]);

            return null;
        }

        $dados = $resposta->json();

        if (! is_array($dados) || ($dados['success'] ?? false) !== true) {
            Log::debug('Stream direto: VOD sem fonte para o id.', [
                'host' => $host,
                'mensagem' => is_array($dados) ? ($dados['message'] ?? 'sem mensagem') : 'resposta não é JSON',
            ]);

            return null;
        }

        $modo = (string) ($dados['mode'] ?? '');
        $url = trim((string) ($dados['url'] ?? ''));

        if ($modo !== 'native' || $url === '' || ! $this->extensaoValida($url)) {
            Log::debug('Stream direto: VOD não entregou arquivo nativo.', [
                'host' => $host,
                'modo' => $modo,
                'url' => $url,
            ]);

            return null;
        }

        Log::debug('Stream direto: VOD resolvido pelo id.', [
            'host' => $host,
            'url' => $url,
        ]);

        return ['url' => $url, 'idioma' => 'pt-BR'];
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
     * Hosts configurados, normalizados (com esquema e sem barra final).
     *
     * Vazia, a lista cai no espelho padrão. Um host sem esquema recebe `https://`
     * porque é assim que a configuração costuma ser escrita (`vizer.autos`).
     *
     * @return array<int, string>
     */
    private function hosts(): array
    {
        $configurados = config('services.torrents.stream_direto_vod_hosts', []);

        if (! is_array($configurados) || $configurados === []) {
            return self::HOSTS_PADRAO;
        }

        $hosts = [];

        foreach ($configurados as $host) {
            $host = rtrim(trim((string) $host), '/');

            if ($host === '') {
                continue;
            }

            if (! preg_match('#^https?://#i', $host)) {
                $host = 'https://'.$host;
            }

            $hosts[] = $host;
        }

        return $hosts === [] ? self::HOSTS_PADRAO : array_values(array_unique($hosts));
    }
}
