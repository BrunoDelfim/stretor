<?php

namespace App\Services\Torrents;

/**
 * Confere se uma página candidata tem relação com o título que se procura.
 *
 * A prova de mídia ([`ExtratorVideo::temMidia()`]) responde "aqui tem player?".
 * Ela é necessária, mas não suficiente: uma página de fandom *sobre* a série
 * embute o trailer no YouTube e passa no teste, e um agregador devolve um
 * episódio qualquer de outro programa quando o título buscado não está no
 * acervo. Nos dois casos o player existe, mas o vídeo não é o pedido — foi
 * assim que "American Horror Story" abriu um episódio aleatório vindo do
 * `dramatotal.fandom.com` e um `historia-4` do Tokyvideo.
 *
 * Esta trait fecha essa lacuna com uma pergunta diferente: "esta página fala
 * do título?". A resposta é dada pelas **palavras-chave** do título — os termos
 * que carregam significado, sem as palavras de ligação ("de", "the", "of") e
 * sem a numeração do episódio. Uma página que não traz nenhuma delas no
 * endereço nem no `<title>` não é sobre o que se procura, por mais player que
 * tenha.
 *
 * ## Por que a URL e o título da página, e não o corpo inteiro
 *
 * O endereço e o `<title>` são o que o site escreveu para se identificar: é ali
 * que o nome do título aparece quando a página é de fato sobre ele. Varrer o
 * corpo inteiro seria frágil — uma página de fórum que apenas *menciona* a
 * série no meio de um comentário passaria, e o custo de baixar e analisar o
 * HTML completo já foi pago pela extração. A checagem aqui é barata de
 * propósito: ela roda antes de a página virar fonte, sobre dados que já estão
 * em memória.
 *
 * ## A regra das duas palavras
 *
 * Um título de uma palavra só ("Dexter", "Lost") não tem como provar relevância
 * por contagem — qualquer página que traga "dexter" no endereço passa, e isso é
 * o melhor que dá para fazer sem um catálogo. Já um título composto
 * ("American Horror Story") exige **duas** palavras-chave distintas: uma página
 * que só traga "american" pode ser sobre qualquer coisa americana, e foi
 * exatamente o caso do `americanas.com.br` e do `americansportshop.com.br` que
 * apareceram no log. Duas palavras ("american" + "story") já são um sinal forte
 * de que a página é sobre o título.
 *
 * O limiar é configurável (`stream_direto_min_palavras_chave`): zero desliga a
 * checagem, e o valor é limitado ao número de palavras-chave disponíveis — um
 * título de duas palavras não pode exigir três.
 */
trait RelevanciaTitulo
{
    /**
     * Diz se a página tem relação com o título buscado.
     *
     * A checagem junta o endereço e o `<title>` num único texto normalizado e
     * conta quantas palavras-chave distintas do título aparecem nele. O limiar
     * vem da configuração e é limitado ao que o título oferece: um título de uma
     * palavra só nunca vai exigir duas.
     *
     * @param  string  $pagina  Endereço da página candidata.
     * @param  string  $tituloPagina  Conteúdo do `<title>`, se houver.
     * @param  string  $titulo  Título procurado (o principal, em PT-BR).
     */
    public function paginaRelevante(string $pagina, string $tituloPagina, string $titulo): bool
    {
        $limiar = $this->limiarDePalavrasChave();

        // Zero desliga a checagem: a página passa e a decisão volta a ser só a
        // prova de mídia, que é o comportamento antigo.
        if ($limiar <= 0) {
            return true;
        }

        $palavras = $this->palavrasChave($titulo);

        if ($palavras === []) {
            // Título sem palavra aproveitável (só ligação, ou vazio): não há como
            // provar relevância, e bloquear tudo seria pior que não bloquear nada.
            return true;
        }

        $alvo = $this->normalizarParaBusca($pagina.' '.$tituloPagina);

        $encontradas = 0;

        foreach ($palavras as $palavra) {
            if (str_contains($alvo, $palavra)) {
                $encontradas++;
            }
        }

        return $encontradas >= min($limiar, count($palavras));
    }

    /**
     * Diz se a URL de um vídeo extraído tem relação com o título buscado.
     *
     * A checagem de página ([`paginaRelevante()`]) responde "esta página é sobre
     * o título?". Ela é necessária, mas não suficiente: um agregador pode ter uma
     * página correta — com o título no endereço e no `<title>` — e ainda assim
     * embutir um vídeo de outro programa. Foi o caso do Tokyvideo, que serviu um
     * `/video/historia-4` numa página cujo título era o da série pedida: a página
     * passou, o vídeo não era o pedido.
     *
     * Esta checagem fecha a última brecha olhando para o **endereço do vídeo**,
     * não para a página. O critério é o mesmo das palavras-chave do título, mas
     * com uma diferença importante: aqui a ausência de pista **recusa**, não
     * aprova. Um slug puramente numérico (`/video/12345`) ou genérico
     * (`/video/historia-4`) não carrega nenhuma palavra do título, e é
     * exatamente o que um player de adware ou um acervo desalinhado devolve.
     *
     * ## Por que a URL do vídeo, e não o corpo da página
     *
     * O endereço do vídeo é o que o site escreveu para identificar *aquele*
     * arquivo. Um CDN de vídeo legítimo costuma carregar o slug do título
     * (`.../american-horror-story-s01e01.mp4`), e um player de embed conhecido
     * (YouTube, Vimeo) carrega o id do vídeo, não o título — por isso os hosts de
     * embed conhecidos são **isentos** da checagem: o id do YouTube não tem como
     * provar relevância, e recusá-lo derrubaria a fonte legítima.
     *
     * @param  string  $url  Endereço do vídeo extraído.
     * @param  string  $titulo  Título procurado (o principal, em PT-BR).
     */
    public function videoRelevante(string $url, string $titulo): bool
    {
        $limiar = $this->limiarDePalavrasChave();

        // Zero desliga a checagem: a URL passa e a decisão volta a ser só a prova
        // de mídia, que é o comportamento antigo.
        if ($limiar <= 0) {
            return true;
        }

        $palavras = $this->palavrasChave($titulo);

        if ($palavras === []) {
            // Título sem palavra aproveitável: não há como provar relevância, e
            // recusar tudo seria pior que não recusar nada.
            return true;
        }

        /*
         * O host de embed conhecido é isento: o id do vídeo (o `dQw4w9WgXcQ` do
         * YouTube) não carrega o título, e exigir palavra-chave nele derrubaria
         * uma fonte que é justamente a mais confiável. A isenção vale só para o
         * host — o caminho de um CDN de arquivo continua sendo checado.
         */
        if ($this->hostDeEmbedConhecido($url)) {
            return true;
        }

        $alvo = $this->normalizarParaBusca($url);

        foreach ($palavras as $palavra) {
            if (str_contains($alvo, $palavra)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A página casa com **qualquer** grafia do título?
     *
     * O mesmo título chega em duas línguas — "Donas de Casa Desesperadas" e
     * "Desperate Housewives" —, e o acervo publica em uma, na outra ou nas duas.
     * Julgar a página só pela grafia principal descartava justamente a página que
     * o motor achou pelo nome de origem: o termo "Desperate Housewives 01x01"
     * encontrava a página, e a conferência de relevância a recusava porque
     * comparava com a tradução. Quem chama passa a lista inteira de títulos.
     *
     * @param  array<int, string>  $titulos
     */
    public function paginaRelevanteParaAlgum(string $pagina, string $tituloPagina, array $titulos): bool
    {
        foreach ($this->titulosUnicos($titulos) as $titulo) {
            if ($this->paginaRelevante($pagina, $tituloPagina, $titulo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * O vídeo casa com qualquer grafia do título — ou vem do **mesmo site** da
     * página.
     *
     * A isenção por site existe para o arquivo de CDN com nome opaco. O Tokyvideo
     * serve o episódio em `cdnst73.tokyvideo.com/videos/684/684511/mp4/<sha>.mp4`:
     * não há uma palavra do título para conferir, e a checagem por palavra-chave
     * recusava o vídeo de uma página que já havia provado ser sobre o título. Como
     * o arquivo está no mesmo site da página, ele é o player daquela página — a
     * prova de relevância já foi dada antes, na abertura dela.
     *
     * A isenção é **do mesmo site**, não de qualquer host: um CDN de terceiro
     * embutido numa página de fandom continua passando pela checagem, que é o que
     * separa o player do episódio do trailer de outro programa.
     *
     * @param  array<int, string>  $titulos
     */
    public function videoRelevanteParaAlgum(string $url, array $titulos, ?string $pagina = null): bool
    {
        if ($pagina !== null && $this->mesmoSite($url, $pagina)) {
            return true;
        }

        foreach ($this->titulosUnicos($titulos) as $titulo) {
            if ($this->videoRelevante($url, $titulo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A lista de títulos sem vazios e sem repetição, preservando a ordem.
     *
     * @param  array<int, string>  $titulos
     * @return array<int, string>
     */
    private function titulosUnicos(array $titulos): array
    {
        $limpos = [];

        foreach ($titulos as $titulo) {
            $titulo = trim((string) $titulo);

            if ($titulo !== '' && ! in_array($titulo, $limpos, true)) {
                $limpos[] = $titulo;
            }
        }

        return $limpos;
    }

    /** Diz se o vídeo e a página pertencem ao mesmo site (mesmo domínio de registro). */
    private function mesmoSite(string $url, string $pagina): bool
    {
        $doVideo = $this->dominioDe($url);
        $daPagina = $this->dominioDe($pagina);

        return $doVideo !== '' && $doVideo === $daPagina;
    }

    /**
     * O domínio de registro de uma URL, sem subdomínio e sem `www.`.
     *
     * `cdnst73.tokyvideo.com` e `www.tokyvideo.com` precisam cair no mesmo
     * `tokyvideo.com` — é isso que faz o CDN do próprio site contar como o player
     * da página. Os TLDs de dois níveis mais comuns no acervo PT-BR entram na
     * conta para `www.site.com.br` não virar `com.br`.
     */
    private function dominioDe(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return '';
        }

        $host = (string) preg_replace('/^www\./', '', $host);
        $partes = explode('.', $host);

        if (count($partes) <= 2) {
            return $host;
        }

        $ultimosDois = implode('.', array_slice($partes, -2));

        $doisNiveis = ['com.br', 'net.br', 'tv.br', 'org.br', 'com.ar', 'com.mx', 'com.pt', 'co.uk'];

        if (in_array($ultimosDois, $doisNiveis, true)) {
            return implode('.', array_slice($partes, -3));
        }

        return $ultimosDois;
    }

    /**
     * Diz se a URL pertence a um host de embed conhecido, isento da checagem.
     *
     * A lista é curta e de propósito: só entram os hosts cujo endereço de vídeo é
     * um **id opaco**, sem o título. O YouTube (`youtube.com/watch?v=...`,
     * `youtu.be/...`) e o Vimeo (`vimeo.com/12345`) são os casos claros. Um CDN
     * de arquivo (`cdn.exemplo.com/american-horror-story.mp4`) **não** entra: ali
     * o slug carrega o título, e a checagem tem o que provar.
     *
     * O `plenoflu.com` chegou a entrar aqui pela medição do `verpobreflix.net`,
     * mas saiu: o host não serve vídeo nenhum, só uma página de player que
     * responde "Acesso proibido" a qualquer cliente automatizado. Isentá-lo só
     * fazia um embed inacessível passar pela checagem — e o embed, de todo modo,
     * não vira fonte (o contrato exige MP4/HLS). A isenção ficou restrita aos
     * hosts que realmente entregam um arquivo tocável.
     */
    private function hostDeEmbedConhecido(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'vimeo.com'] as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extrai as palavras-chave de um título.
     *
     * O título é quebrado em palavras, cada uma normalizada (minúscula, sem
     * acento), e as de ligação e as puramente numéricas saem. A numeração do
     * episódio ("1x01", "S01E01") não entra: ela é informação de qual episódio,
     * não de qual série, e exigi-la no endereço deixaria de fora a página que
     * cobre a série inteira.
     *
     * @return array<int, string>
     */
    private function palavrasChave(string $titulo): array
    {
        return PalavrasChave::extrair($titulo);
    }

    /**
     * Normaliza um texto para a comparação por substring.
     *
     * Minúsculas e sem acento, para que "História" case com "historia" — o
     * endereço de um site raramente preserva o acento do título, e a comparação
     * literal perderia a página por causa de um acento.
     */
    private function normalizarParaBusca(string $texto): string
    {
        return PalavrasChave::normalizar($texto);
    }

    /**
     * Lê o limiar de palavras-chave da configuração.
     *
     * O padrão é 2: um título composto precisa de duas palavras distintas para
     * provar relevância. Zero ou negativo desliga a checagem.
     */
    private function limiarDePalavrasChave(): int
    {
        return (int) config('services.torrents.stream_direto_min_palavras_chave', 2);
    }
}
