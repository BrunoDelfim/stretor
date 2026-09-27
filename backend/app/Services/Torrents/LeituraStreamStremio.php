<?php

namespace App\Services\Torrents;

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
     * Converte um stream do addon no contrato do sistema.
     *
     * @param  array<string, mixed>  $stream
     * @return array<string, mixed>|null
     */
    protected function normalizarStream(array $stream, string $titulo): ?array
    {
        $hash = strtolower(trim((string) ($stream['infoHash'] ?? '')));

        if ($hash === '') {
            return null;
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
        if (stripos($nome, 'brazil') !== false
            || stripos($nome, 'portuguese') !== false
            || stripos($nome, 'português') !== false
            || stripos($nome, 'portugues') !== false) {
            return 'portuguese';
        }

        return '';
    }
}
