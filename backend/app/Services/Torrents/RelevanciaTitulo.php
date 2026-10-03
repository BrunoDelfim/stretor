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
     * Palavras de ligação que não contam como palavra-chave.
     *
     * São os artigos, preposições e conjunções que aparecem em quase todo título
     * e não distinguem nada: "Donas **de** Casa **Desesperadas**" tem duas
     * palavras que importam e uma que não. A lista cobre o português e o inglês,
     * porque o título original entra como alternativa na busca.
     *
     * @var array<int, string>
     */
    private const PALAVRAS_DE_LIGACAO = [
        'a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'no',
        'na', 'nos', 'nas', 'um', 'uma', 'uns', 'umas', 'para', 'por', 'com',
        'sem', 'ao', 'aos', 'à', 'às', 'que', 'se', 'the', 'of', 'and', 'in',
        'on', 'at', 'to', 'for', 'with', 'a', 'an', 'is', 'it', 'its', 'by',
        'from', 'as', 'or',
    ];

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
        $normalizado = $this->normalizarParaBusca($titulo);

        /*
         * A numeração do episódio sai antes da quebra em palavras. Ela não é
         * puramente numérica — "s01e01" tem letras —, então o filtro de
         * `is_numeric()` não a pegaria, e uma página com "s01e01" no endereço
         * passaria como se fosse sobre o título. Os padrões cobrem as mesmas
         * grafias que o gerador de termos usa ("1x01", "S01E01", "Temporada 1
         * Episódio 1").
         */
        $normalizado = preg_replace([
            '/\b\d{1,2}x\d{1,3}\b/u',
            '/\bs\d{1,2}[\s._-]*e\d{1,3}\b/u',
            '/\btemporada\s*\d{1,2}\s*episodio\s*\d{1,3}\b/u',
            '/\b\d{1,2}a\s*temporada\s*episodio\s*\d{1,3}\b/u',
        ], ' ', $normalizado) ?? $normalizado;

        $brutas = preg_split('/[^a-z0-9]+/', $normalizado) ?: [];

        $palavras = [];

        foreach ($brutas as $bruta) {
            if ($bruta === '' || is_numeric($bruta)) {
                continue;
            }

            if (in_array($bruta, self::PALAVRAS_DE_LIGACAO, true)) {
                continue;
            }

            // Palavras de uma letra não distinguem nada e casariam com qualquer
            // endereço; o mesmo vale para a numeração de episódio já removida.
            if (strlen($bruta) < 2) {
                continue;
            }

            $palavras[$bruta] = true;
        }

        return array_keys($palavras);
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
        $texto = mb_strtolower($texto, 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
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
