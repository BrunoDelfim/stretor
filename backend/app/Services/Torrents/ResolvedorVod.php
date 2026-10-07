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
 * Uma URL de `.mp4`/`.m3u8` e o idioma. O idioma é o que o espelho **declarar** na
 * resposta; sem declaração, vale o contrato do acervo (`IDIOMA_DO_ACERVO`, hoje
 * `pt-BR`) — a mesma marca que o [`IdiomaFonte`] traduz para "Dublado". A URL
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
     * Idioma que o acervo deste provedor entrega quando ele não declara nada.
     *
     * É uma **declaração do espelho**, não um chute: o que os hosts desta família
     * publicam é a versão brasileira do título, e é por isso que `pt-BR` sempre foi
     * o valor devolvido. O que muda agora é a forma: quando a resposta traz um
     * campo de idioma (`idioma`, `language`, `audio`, `audio_language` ou os
     * booleanos `dublado`/`legendado`), é ele que vale — e um espelho que declarar
     * legendado entrega `legendado`, que o crivo de idioma da busca descarta como
     * qualquer outra fonte sem áudio PT-BR provado. Sem campo nenhum, vale o
     * contrato do acervo; a constante existe para essa escolha ficar escrita num
     * lugar só, em vez de espalhada em literais.
     */
    private const IDIOMA_DO_ACERVO = 'pt-BR';

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

    /**
     * Status que significam "não há arquivo servido atrás desta URL".
     *
     * O `404`/`410` são definitivos: o servidor diz que o caminho não existe.
     * O `503` entra porque é o que o CDN do provedor devolve quando a origem
     * atrás dele está fora — e ele, diferente dos outros, muda de ideia, por isso
     * leva uma segunda leitura antes de condenar (ver [`arquivoVivo()`]).
     *
     * De propósito não estão aqui `403`, `405` nem `500`: um host que recusa o
     * `HEAD` ou bota a CDN em manutenção continuaria apto a entregar o arquivo
     * por `GET`, e condenar nesses casos perderia fonte boa — o preço do falso
     * negativo (busca sem o título) é maior que o do falso positivo (o FFmpeg
     * reclama adiante, como reclamava antes da sonda).
     *
     * @var array<int, int>
     */
    private const STATUS_DE_ARQUIVO_MORTO = [404, 410, 503];

    /** Espera antes da segunda leitura de um `503`, em microssegundos. */
    private const PAUSA_NO_503_MICROSSEGUNDOS = 400000;

    /**
     * Teto da sonda, em segundos.
     *
     * Um `HEAD` bem-sucedido volta em menos de um segundo; o teto existe para o
     * caso ruim — CDN que segura a conexão. É o `ClienteHttp` que encolhe este
     * valor para o que resta do orçamento da busca, então uma sonda começada no
     * fim do prazo custa o que sobra e não mais que isso.
     */
    private const TEMPO_DA_SONDA_SEGUNDOS = 4;

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

        /*
         * Sonda de existência. O espelho afirma que o arquivo está lá; quem sabe
         * é o CDN. Sem isto, a API entrega `success: true` para um episódio que o
         * servidor nem tem, a busca dispensa a cascata por causa de um link morto
         * e o FFmpeg só reclama minutos depois, dentro da conversão — com o
         * agregador seguinte (e o `plenoflu.com` por trás dele) nunca ter sido
         * perguntado. Recusado aqui, o retorno é `null` e o degrau seguinte
         * acontece como se o passo zero não existisse.
         */
        if (! $this->arquivoVivo($url, $host)) {
            return null;
        }

        /*
         * O idioma sai do que o espelho declarou, e não de um literal fixo: um
         * host que anota a faixa na resposta é ouvido. Sem declaração nenhuma,
         * vale o contrato do acervo (ver `IDIOMA_DO_ACERVO`).
         */
        $idioma = $this->idiomaDeclarado($dados) ?? self::IDIOMA_DO_ACERVO;

        Log::debug('Stream direto: VOD resolvido pelo id.', [
            'host' => $host,
            'url' => $url,
            'idioma' => $idioma,
        ]);

        return ['url' => $url, 'idioma' => $idioma];
    }

    /**
     * Lê o idioma que o provedor declarou na resposta, quando ele declara algum.
     *
     * A leitura é defensiva porque o formato é de terceiro: os campos podem
     * aparecer com qualquer um dos nomes usados aqui, vir preenchidos com o
     * código de áudio, o nome do idioma ou a palavra que a comunidade usa
     * ("Dublado", "Legendado", "Dual Áudio"), e a maioria dos espelhos não traz
     * campo nenhum. O primeiro valor com conteúdo vence; a ausência devolve
     * `null`, e aí o chamador cai para o contrato do acervo.
     *
     * O texto sai cru de propósito: quem sabe traduzir rótulo e código para o
     * [`IdiomaFonte`] é o `NormalizaFonte::montarFonteDireta()`, e repetir essa
     * classificação aqui faria dois lugares divergirem com o tempo.
     *
     * @param  array<string, mixed>  $dados
     */
    private function idiomaDeclarado(array $dados): ?string
    {
        foreach (['idioma', 'language', 'audio', 'audio_language', 'audio_lang'] as $campo) {
            $valor = $dados[$campo] ?? null;

            if (is_string($valor) && trim($valor) !== '') {
                return trim($valor);
            }
        }

        foreach (['dublado', 'legendado', 'dual'] as $campo) {
            if (($dados[$campo] ?? null) === true) {
                return $campo;
            }
        }

        return null;
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
     * Diz se o CDN está servindo o arquivo por trás da URL, ou se a API mentiu.
     *
     * Duas leituras no máximo: a primeira, e uma segunda só quando o status é
     * `503` — o único que oscila (a origem atrás do CDN pode estar piscando).
     * O `404` é resposta de quem sabe que o caminho não existe, e repetir pergunta
     * a mesma certeza; o `null` (sonda que não completou) não condena ninguém,
     * porque uma falha de caminho não é prova de que o arquivo não exista.
     *
     * Desligada pela configuração, devolve `true` de cara — é o comportamento de
     * antes da sonda, para poder desligar este passo sem desligar o passo zero.
     *
     * @param  string  $host  espelho consultado, só para o log apontar quem mentiu
     */
    private function arquivoVivo(string $url, string $host): bool
    {
        if (! (bool) config('services.torrents.stream_direto_sondar_url', true)) {
            return true;
        }

        $status = $this->cliente->sondarArquivo($url, self::TEMPO_DA_SONDA_SEGUNDOS);
        $sondagens = 1;

        if ($status === 503 && $this->temOrcamento()) {
            usleep(self::PAUSA_NO_503_MICROSSEGUNDOS);

            $status = $this->cliente->sondarArquivo($url, self::TEMPO_DA_SONDA_SEGUNDOS);
            $sondagens = 2;
        }

        if ($status !== null && in_array($status, self::STATUS_DE_ARQUIVO_MORTO, true)) {
            Log::debug('Stream direto: VOD apontou para um arquivo morto; a busca segue adiante.', [
                'host' => $host,
                'url' => $url,
                'status' => $status,
                'sondagens' => $sondagens,
            ]);

            return false;
        }

        return true;
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
