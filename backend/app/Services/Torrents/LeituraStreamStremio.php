<?php

namespace App\Services\Torrents;

use App\Support\IndiciosPtBr;
use Illuminate\Support\Facades\Log;

/**
 * Leitura dos streams do protocolo Stremio.
 *
 * O Torrentio e os demais addons hospedados falam o mesmo dialeto: devolvem um
 * `streams[]` em que o hash vem isolado em `infoHash` e **todo o resto** (nome do
 * release, seeds, tamanho e, às vezes, o idioma) vem embutido no rótulo `title`,
 * com emojis separando os campos. Como o formato é idêntico entre os addons — o
 * contrato é do Stremio, não de um provedor —, a leitura vive aqui em vez de ser
 * reescrita em cada provedor. É a versão "protocolo" do que a [`NormalizaFonte`]
 * faz para os provedores de campo próprio.
 *
 * Quem usa esta trait precisa também usar [`NormalizaFonte`] — é de lá que vêm
 * `montarFonte()`, `completarMagnet()`, `limparTexto()` e `tamanhoEmBytes()` — e
 * implementar `identificador()` e `rotulo()`.
 */
trait LeituraStreamStremio
{
    /**
     * Extensões que o media-service aceita como fonte direta.
     *
     * É a mesma lista do [`ResolvedorVod::EXTENSOES`] e do `EXTENSOES_DIRETAS`
     * do serviço de mídia. O `.mpd` fica de fora: o media-service o conhece, mas
     * o caminho da fonte direta já o mediu como não tocável, e oferecer um link
     * que morre na primeira checagem é pior que descartá-lo aqui.
     *
     * @var array<int, string>
     */
    private const EXTENSOES_DIRETAS = ['.mp4', '.m4v', '.webm', '.mkv', '.mov', '.m3u8'];

    /**
     * Quantos links diretos, por consulta, passam pela sonda de arquivo.
     *
     * O addon pode devolver vários mirrors do mesmo vídeo. O primeiro punhado já
     * prova se a família de links está viva; sondar o resto gastaria o orçamento
     * da busca com o que o player resolveria em uma tentativa.
     */
    private const MAX_SONDAS = 3;

    /**
     * Converte um stream do addon no contrato do sistema.
     *
     * @param  array<string, mixed>  $stream
     * @return array<string, mixed>|null
     */
    protected function normalizarStream(array $stream, string $titulo): ?array
    {
        $hash = strtolower(trim((string) ($stream['infoHash'] ?? '')));

        /*
         * Nem todo stream do protocolo é um torrent. O `url` é o campo que os
         * addons usam para entregar **arquivo de vídeo pronto** — o `.mp4` ou o
         * `.m3u8` de um CDN, sem passar por malha. É o formato que apps como o
         * Stremio reproduzem direto, e o mesmo que o contrato da fonte direta
         * aceita; por isso ele é lido aqui, e não descartado.
         *
         * O link pago fica de fora por construção: `real-debrid.com/d/...` e
         * proxies do gênero não terminam numa extensão de vídeo e são recusados
         * por [`urlDeVideo()`]. O Stretor não depende de serviço pago para
         * entregar conteúdo.
         */
        if ($hash === '') {
            return $this->normalizarStreamDireto($stream, $titulo);
        }

        $rotulo = (string) ($stream['title'] ?? '');
        $nomeDoStream = (string) ($stream['name'] ?? '');

        /*
         * O nome do release vive na primeira linha do rótulo, não no campo
         * `name` do stream. O `name` traz apenas o provedor e a qualidade
         * ("Torrentio\n720p"), então usá-lo como título apagava a numeração
         * ("S01E01") e as tags de idioma ("PORTUGUÊS BR", "DUAL") — era por isso
         * que a validação de episódio e a classificação de idioma falhavam e
         * todas as fontes do Torrentio eram descartadas.
         *
         * O `filename` do `behaviorHints`, quando existe, é o nome do arquivo
         * dentro do torrent e é a fonte mais confiável; o rótulo cobre os casos
         * em que ele não vem (a maioria dos streams nacionais) e o nome do
         * provedor no `name` fecha como último recurso.
         */
        /*
         * São dois nomes, e eles não se substituem. O nome do **arquivo**
         * (`behaviorHints.filename`) é o melhor título para exibir e para conferir
         * a numeração do episódio. Já o nome do **torrent** — a primeira linha do
         * rótulo — é a única prova de que o release é um pacote de temporada
         * ("American Horror Story S01 1080p AMZN"), porque o arquivo aponta para
         * um episódio específico e esconde que o torrent inteiro é a temporada.
         * Guardamos o arquivo em `titulo` e o pacote em `release`; é o `release`
         * que a cascata lê para marcar o pack e libertá-lo do corte de idioma.
         */
        $arquivo = (string) ($stream['behaviorHints']['filename'] ?? '');
        $release = $this->nomeDoRotulo($rotulo, $nomeDoStream);

        $nome = $arquivo ?: $release ?: $nomeDoStream ?: $titulo;
        $nome = $this->limparTexto($nome);

        $magnet = $this->magnetComFontes($hash, $nome, (array) ($stream['sources'] ?? []));

        /*
         * A tag de idioma nem sempre está no nome do arquivo: um episódio dentro
         * de um pacote "DUAL ÁUDIO" costuma se chamar apenas "S01E01.mkv". Por
         * isso a dedução de idioma olha para os dois nomes — o do arquivo, que
         * identifica o episódio, e o do torrent, que carrega a tag do release.
         */
        $textoIdioma = trim($nome.' '.$release);

        $fonte = $this->montarFonte([
            'id' => $hash,
            'titulo' => $nome,
            'magnet' => $magnet,
            'tamanho_bytes' => $this->tamanhoDoRotulo($rotulo),
            'seeds' => $this->seedsDoRotulo($rotulo),
            'peers' => 0,
            'idioma' => $this->idiomaDoNome($textoIdioma),
            'idioma_titulo' => $textoIdioma,
        ], $this->identificador(), $this->rotulo());

        // O nome do torrent só é acrescentado quando difere do arquivo: repetir o
        // mesmo texto não traz informação nenhuma para a detecção do pack.
        $release = $this->limparTexto($release);

        if ($release !== '' && $release !== $nome) {
            $fonte['release'] = $release;
        }

        return $fonte;
    }

    /**
     * Converte um stream com `url` numa fonte direta (MP4/HLS).
     *
     * O addon que devolve `url` está dizendo "toque este arquivo", e é o mesmo
     * contrato que o stream direto usa: o player cria a sessão pela URL e o
     * media-service converte para HLS. O que separa uma fonte útil de uma
     * armadilha é a extensão — um embed, uma página de player ou um link de
     * serviço pago não têm vídeo no fim e são recusados por [`urlDeVideo()`].
     *
     * O nome segue a mesma leitura do caminho de torrent: o arquivo
     * (`behaviorHints.filename`) é o melhor título e o mais confiável para a
     * numeração; o release vive na primeira linha do rótulo.
     *
     * @param  array<string, mixed>  $stream
     * @return array<string, mixed>|null
     */
    protected function normalizarStreamDireto(array $stream, string $titulo): ?array
    {
        $url = $this->urlDeVideo((string) ($stream['url'] ?? ''));

        if ($url === null) {
            return null;
        }

        $rotulo = (string) ($stream['title'] ?? '');
        $nomeDoStream = (string) ($stream['name'] ?? '');
        $arquivo = (string) ($stream['behaviorHints']['filename'] ?? '');
        $release = $this->nomeDoRotulo($rotulo, $nomeDoStream);

        $nome = $this->limparTexto($arquivo ?: $release ?: $nomeDoStream ?: $titulo);

        /*
         * O idioma é lido do nome do arquivo **e** do release. Um episódio dentro
         * de um pacote pode se chamar só "S01E01.mkv", com a tag ("Dublado",
         * "DUAL") no nome do torrent — o mesmo motivo que justifica os dois nomes
         * no caminho de torrent.
         */
        $textoIdioma = trim($nome.' '.$release);

        $fonte = $this->montarFonteDireta([
            'url' => $url,
            'titulo' => $nome,
            'tamanho_bytes' => $this->tamanhoDoRotulo($rotulo),
            'idioma' => $this->idiomaDoNome($textoIdioma),
            'idioma_titulo' => $textoIdioma,
        ], $this->identificador(), $this->rotulo());

        $release = $this->limparTexto($release);

        if ($release !== '' && $release !== $nome) {
            $fonte['release'] = $release;
        }

        return $fonte;
    }

    /**
     * Valida a URL de vídeo do addon e devolve a forma canônica.
     *
     * A extensão é lida **do caminho**, não da URL inteira: um `.mp4` com
     * `?token=...` na query continua sendo um arquivo, e olhar a URL completa
     * faria o token esconder a extensão.
     *
     * Devolve `null` para o que não é arquivo de vídeo — página de embed,
     * redirecionador, link de serviço de entrega paga. O serviço de mídia exige
     * uma extensão em `EXTENSOES_DIRETAS`; aceitar o resto aqui só produziria uma
     * sessão que morre na primeira checagem.
     */
    protected function urlDeVideo(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || ! preg_match('#^https?://#i', $url)) {
            return null;
        }

        $caminho = strtolower((string) parse_url($url, PHP_URL_PATH));

        foreach (self::EXTENSOES_DIRETAS as $extensao) {
            if (str_ends_with($caminho, $extensao)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Extrai o nome do release da primeira linha do rótulo do stream.
     *
     * O rótulo vem em várias linhas: a primeira é o nome do release, a segunda
     * traz os metadados ("👤 0 💾 1.62 GB ⚙️ ThePirateBay"), a terceira as faixas
     * de áudio e as bandeiras. Devolve vazio quando a primeira linha é só o nome
     * do provedor — o rótulo do próprio addon (`rotulo()`) ou o `name` do stream
     * (ex.: "Torrentio\n1080p", "TPB+") —, para não repetir o que o `name` já dá
     * nem usar o nome do agregador como se fosse o título do release.
     */
    private function nomeDoRotulo(string $rotulo, string $label = ''): string
    {
        $linhas = preg_split('/\r\n|\r|\n/', trim($rotulo)) ?: [];
        $primeira = trim((string) ($linhas[0] ?? ''));

        if ($primeira === '') {
            return '';
        }

        $linhasLabel = preg_split('/\r\n|\r|\n/', trim($label)) ?: [];
        $label = trim((string) ($linhasLabel[0] ?? ''));

        foreach (array_filter([$this->rotulo(), $label]) as $rotuloConhecido) {
            if (stripos($primeira, $rotuloConhecido) === 0) {
                return '';
            }
        }

        return $primeira;
    }

    /**
     * Monta o magnet preservando os anunciadores que o addon já informou.
     *
     * Os `sources` vêm no formato `tracker:udp://...` ou `dht:...`. Repassamos os
     * que são anunciadores de verdade e deixamos o DHT de fora (ele é implícito
     * no protocolo e não é endereço de tracker). Quem não traz `sources` — o caso
     * do TPB+ — recebe a lista pública em `completarMagnet()`.
     *
     * @param  array<int, string>  $fontes
     */
    private function magnetComFontes(string $hash, string $nome, array $fontes): string
    {
        $magnet = 'magnet:?xt=urn:btih:'.$hash.'&dn='.rawurlencode($nome);

        foreach ($fontes as $fonte) {
            if (is_string($fonte) && str_starts_with($fonte, 'tracker:')) {
                $magnet .= '&tr='.rawurlencode(substr($fonte, strlen('tracker:')));
            }
        }

        return $this->completarMagnet($magnet);
    }

    /**
     * Lê a contagem de seeds do rótulo (ex.: "👤 123 💾 1.4 GB").
     *
     * O piso é 1, nunca 0. O Torrentio usa `👤 0` tanto para "não há peers" quanto
     * para "não consegui medir" — e o segundo caso é o mais comum em releases
     * nacionais, que ele indexa sem passar pelo rastreador de peers. Como o
     * catálogo descarta toda fonte com 0 seeds, tratar esse 0 como definitivo
     * apagava dubladas legítimas: foi o que aconteceu com o release
     * "Dual Áudio 720p By-LuanHarper" de "Grey's Anatomy", que vinha com `👤 0` e
     * era removido antes de chegar à interface. Quem confirma se a fonte vive é o
     * media-service, que mede os peers na prática antes de abrir a reprodução.
     */
    private function seedsDoRotulo(string $rotulo): int
    {
        if (preg_match('/👤\s*(\d+)/u', $rotulo, $achados)) {
            return max(1, (int) $achados[1]);
        }

        if (preg_match('/(\d+)\s*(?:seeds|seeders)/i', $rotulo, $achados)) {
            return max(1, (int) $achados[1]);
        }

        return 1;
    }

    /** Lê o tamanho do rótulo (ex.: "💾 1.4 GB"). */
    private function tamanhoDoRotulo(string $rotulo): ?int
    {
        if (preg_match('/💾\s*([\d.,]+\s*[KMGT]?i?B)/u', $rotulo, $achados)) {
            return $this->tamanhoEmBytes($achados[1]);
        }

        return null;
    }

    /**
     * Deduz o idioma pelo **nome do release**, nunca pelo rótulo do addon.
     *
     * A busca do Torrentio pode ir configurada com `language=portuguese` para o
     * indexador incluir os provedores nacionais, mas esse filtro faz a resposta
     * inteira vir anotada com a bandeira de português. Ler o rótulo completo
     * classificava *todas* as fontes como "Dublado" — inclusive um release
     * americano do EZTV ou um WEB-DL "ENG/ITA" — e, como o idioma do provedor tem
     * precedência no `NormalizaFonte::montarFonte()`, a tag do próprio nome do
     * arquivo nunca era consultada. O player então tocava em inglês uma fonte
     * prometida como dublada, que foi exatamente o caso do "Lanterns".
     *
     * A bandeira e o texto do rótulo são promessa do indexador; o nome do release
     * é o que a fonte de fato declara. Por isso só olhamos para ele aqui — e o
     * que ele não provar, o `IdiomaFonte::deduzirDoTitulo()` decide a partir das
     * tags do próprio nome ("DUAL", "DUBLADO", "PT-BR", "NACIONAL").
     *
     * O valor devolvido é o código que `IdiomaFonte::deduzirDoIdioma()` entende
     * ("portuguese"); devolver vazio deixa a classificação para a tag do nome.
     */
    private function idiomaDoNome(string $nome): string
    {
        /*
         * Legenda em português não é áudio em português. "PORTUGUESE.SUBtitles"
         * no nome descreve a legenda, e devolver `portuguese` aqui fazia a fonte
         * nascer dublada — o mesmo caso do torrent do Knaben, por outra porta.
         * Sem promessa, a classificação fica com a tag do próprio nome.
         */
        if (IndiciosPtBr::marcaLegendado($nome)) {
            return '';
        }

        if (stripos($nome, 'brazil') !== false
            || stripos($nome, 'portuguese') !== false
            || stripos($nome, 'português') !== false
            || stripos($nome, 'portugues') !== false) {
            return 'portuguese';
        }

        return '';
    }

    /**
     * Passa os links diretos pela sonda de arquivo antes de virarem oferta.
     *
     * O link de addon é a fonte mais volátil que o sistema recebe: um CDN
     * gratuito pode sumir, trocar de conta ou passar a responder uma página de
     * erro com status enganoso. É também a mais barata de conferir — um `HEAD` e,
     * quando o cabeçalho não decide, um kilobyte de corpo. Por isso a peneira
     * roda aqui, antes do cache: o link morto não é gravado e não chega ao
     * player.
     *
     * O teto de [`MAX_SONDAS`] existe para o addon que devolve dezenas de
     * mirrors do mesmo arquivo: sondar todos gastaria o orçamento da cascata com
     * o que o player resolveria em uma tentativa. O excedente passa intacto, e
     * o frontend o trata como sempre — tentar e seguir adiante.
     *
     * Desligada por `stream_direto_sondar_url`, a lista volta como veio.
     *
     * @param  array<array-key, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    protected function sondarDiretas(array $fontes): array
    {
        if (! (bool) config('services.torrents.stream_direto_sondar_url', true)) {
            return array_values($fontes);
        }

        $sondadas = 0;
        $mantidas = [];

        foreach ($fontes as $chave => $fonte) {
            if (($fonte['tipo'] ?? '') !== 'direto' || $sondadas >= self::MAX_SONDAS) {
                $mantidas[$chave] = $fonte;

                continue;
            }

            $sondadas++;

            $url = (string) ($fonte['stream'] ?? '');

            if ($url === '' || ! app(ClienteHttp::class)->arquivoServido($url)) {
                Log::info('Addon Stremio: link direto descartado pela sonda.', [
                    'provedor' => $this->identificador(),
                    'url' => $url,
                ]);

                continue;
            }

            $mantidas[$chave] = $fonte;
        }

        return array_values($mantidas);
    }
}
