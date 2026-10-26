<?php

namespace App\Services\Torrents;

use Illuminate\Support\Facades\Log;

/**
 * Percorre o player do "videobox" (Invision Community) até o arquivo de vídeo.
 *
 * O `pobreflixtv.democrat` não entrega `<video src>` nem guarda a URL do vídeo
 * num `data-*`: a página do título chega com um `<div id="view" data-video-id>`
 * vazio, e o JavaScript monta o player depois. Quem lê o HTML estático vê uma
 * página sem prova de mídia — a mesma casca que fez o acervo do Pobreflix
 * parecer vazio por várias rodadas.
 *
 * O que o script consulta é um endpoint do próprio site
 * (`?app=videobox&module=video&controller=view&do=playerData&id=...`), que
 * devolve os **servidores** daquele vídeo num JSON:
 *
 *     {"players":[{"label":"StreamTape","url":"https://streamtape.com/e/"}],
 *      "servers_dub":"mixdrop=...&streamtape=...&byse=...&doodstream=...",
 *      "servers_leg":"...", "current_audio":"Dublado"}
 *
 * O arquivo mora do outro lado do servidor: o StreamTape publica um
 * `get_video` com expiração e token, cujo token **não** está no HTML — está numa
 * concatenação de JavaScript com `substring()`, e os valores soltos nos `div`s
 * são iscas. Este leitor monta a expressão de verdade, confirma que a origem
 * devolve vídeo e só então entrega a URL. Um servidor que não sai do papel é
 * registrado no log e o próximo é tentado; sem nenhum, a página segue como
 * "sem arquivo", exatamente como antes.
 *
 * Só o áudio **dublado** é entregue: o contrato da busca é fonte em PT-BR, e o
 * `servers_leg` descreve o áudio original com legenda — oferecê-lo como se fosse
 * dublado seria trocar o idioma do usuário sem avisá-lo.
 */
class LeitorVideobox
{
    use ConsultaComOrcamento;

    /**
     * Servidores cuja cadeia o projeto sabe percorrer, na ordem de preferência.
     *
     * O StreamTape vem primeiro porque é o mais direto: o `get_video` responde a
     * um `GET` com `Range` e o token sai de uma concatenação no HTML. Ele,
     * porém, é o espelho que o site mais deixa apodrecer — episódios antigos
     * respondem "Video not found". O MixDrop entra logo depois, com o endereço
     * publicado num script empacotado que este leitor desempacota.
     *
     * DoodStream (token de sessão em `pass_md5`) e Byse (SPA que só monta o
     * player no navegador) continuam fora — aparecem no log como vistos e
     * recusados, para a próxima investigação saber onde parou.
     *
     * @var array<int, string>
     */
    private const SERVIDORES = ['streamtape', 'mixdrop'];

    /**
     * Teto, em segundos, da leitura que confirma o arquivo.
     *
     * A confirmação pede dois kilobytes, mas o servidor pode ignorar o `Range` e
     * começar a mandar o filme: o teto curto é o que impede a sonda de virar
     * download.
     */
    private const TETO_DA_AMOSTRA = 6;

    /**
     * Quantos tokens candidatos do StreamTape valem uma tentativa.
     *
     * A expressão do `robotlink` publica um token e os `div`s publicam falsos. A
     * montagem exata da concatenação resolve o caso normal; este teto cobre a
     * variante em que a concatenação muda de forma e só a sonda separa o token
     * bom do falso.
     */
    private const TETO_DE_TOKENS = 3;

    public function __construct(
        private readonly OrcamentoBusca $orcamento,
        private readonly ClienteHttp $cliente,
    ) {}

    /**
     * Tenta transformar a página de um player videobox num vídeo tocável.
     *
     * Devolve `null` quando a página não declara vídeo, quando o player não
     * responde ou quando nenhum servidor conhecido entrega arquivo. Nunca
     * devolve URL que não tenha sido aberta e conferida.
     *
     * Em **série**, a página que a busca devolve é a ficha
     * (`/series/online/...`), e o `playerData` dela vem sem servidor nenhum — o
     * vídeo mora na página do episódio
     * (`/episodios/online/<slug>-<T>x<E>-<id>/`). Quando o pedido traz temporada
     * e episódio, o leitor desce por `do=episodesList&season=` até a página certa
     * e continua a leitura lá.
     *
     * @return array{url: string, idioma: string}|null
     */
    public function resolver(
        string $html,
        string $pagina,
        int $teto,
        ?int $temporada = null,
        ?int $episodio = null,
    ): ?array {
        return $this->resolverNaPagina($html, $pagina, $teto, $temporada, $episodio, 0);
    }

    /**
     * O corpo da leitura, com a profundidade explícita.
     *
     * A descida é permitida **uma vez**: ficha → episódio. `$profundidade` é o
     * guarda contra o laço de uma página que se aponta para si mesma, e também
     * contra descer de novo a partir da página do episódio — lá, servidor vazio
     * significa acervo sem vídeo, não ficha de série.
     *
     * @return array{url: string, idioma: string}|null
     */
    private function resolverNaPagina(
        string $html,
        string $pagina,
        int $teto,
        ?int $temporada,
        ?int $episodio,
        int $profundidade,
    ): ?array {
        $id = $this->idDoVideo($html);

        if ($id === null) {
            return null;
        }

        if ($teto <= 0 || ! $this->temTempoParaConsulta($teto) || ! $this->temOrcamento()) {
            return null;
        }

        $dados = $this->dadosDoPlayer($pagina, $html, $id, $teto);

        if ($dados === null) {
            Log::debug('Leitor videobox: o player não devolveu os servidores.', ['pagina' => $pagina]);

            return null;
        }

        $servidores = $this->mapaDeServidores((string) ($dados['servers_dub'] ?? ''));

        if ($servidores === []) {
            return $this->descerParaEpisodio($pagina, $html, $id, $teto, $temporada, $episodio, $profundidade);
        }

        foreach (self::SERVIDORES as $servidor) {
            $idNoServidor = (string) ($servidores[$servidor] ?? '');

            if ($idNoServidor === '') {
                continue;
            }

            $base = $this->baseDoServidor($dados, $servidor);

            if ($base === null) {
                continue;
            }

            $url = $this->peloServidor($servidor, $base.$idNoServidor, $pagina, $teto);

            if ($url !== null) {
                Log::debug('Leitor videobox: vídeo encontrado.', [
                    'pagina' => $pagina,
                    'servidor' => $servidor,
                ]);

                return ['url' => $url, 'idioma' => 'dublado'];
            }
        }

        Log::debug('Leitor videobox: os servidores conhecidos não entregaram arquivo.', [
            'pagina' => $pagina,
            'servidores' => array_keys($servidores),
        ]);

        return null;
    }

    /**
     * Desce da ficha da série para a página do episódio pedido.
     *
     * A ficha (`/series/online/...`) prova que o título é aquele, mas não serve
     * vídeo: o `playerData` do id da série vem com `servers_dub` vazio, e é isso
     * que fazia a série cair na cascata de torrents mesmo tendo acervo no site.
     * O caminho até o episódio é um endpoint do próprio player
     * (`do=episodesList&id=<série>&season=<temporada>`), que devolve a lista da
     * temporada com a URL de cada episódio. A leitura recomeça lá, onde o id é o
     * do episódio e os servidores existem.
     *
     * A descida só vale quando o pedido **traz temporada e episódio** e quando a
     * página é mesmo uma ficha — a página do episódio já tem `data-temp`/`data-ep`
     * preenchidos, e nela servidor vazio significa acervo sem vídeo.
     *
     * @return array{url: string, idioma: string}|null
     */
    private function descerParaEpisodio(
        string $pagina,
        string $html,
        string $id,
        int $teto,
        ?int $temporada,
        ?int $episodio,
        int $profundidade,
    ): ?array {
        if ($profundidade > 0 || $temporada === null || $episodio === null || $this->ehPaginaDeEpisodio($html)) {
            Log::debug('Leitor videobox: página sem servidor dublado.', [
                'pagina' => $pagina,
                'audio' => 'dublado',
            ]);

            return null;
        }

        $link = $this->enderecoDoEpisodio($pagina, $html, $id, $temporada, $episodio, $teto);

        if ($link === null) {
            Log::debug('Leitor videobox: episódio não encontrado na ficha.', [
                'pagina' => $pagina,
                'temporada' => $temporada,
                'episodio' => $episodio,
            ]);

            return null;
        }

        $corpo = $this->abrir($link, $pagina, $teto);

        if ($corpo === null) {
            return null;
        }

        Log::debug('Leitor videobox: descendo para a página do episódio.', [
            'serie' => $pagina,
            'episodio' => $link,
        ]);

        return $this->resolverNaPagina($corpo, $link, $teto, $temporada, $episodio, $profundidade + 1);
    }

    /**
     * Diz se a página é a de um episódio — e não a ficha da série.
     *
     * O player preenche `data-temp`/`data-ep` com números só na página do
     * episódio; na ficha os atributos vêm vazios ou não vêm.
     */
    private function ehPaginaDeEpisodio(string $html): bool
    {
        return (bool) preg_match('#data-temp\s*=\s*["\']\d+["\']#i', $html)
            && (bool) preg_match('#data-ep\s*=\s*["\']\d+["\']#i', $html);
    }

    /**
     * O endereço da página do episódio, lido da lista da temporada.
     *
     * O endpoint devolve `{"episodes":[{"number":"1","url":"..."}]}` — a
     * numeração é texto, e o casamento é pelo número pedido. O `season` é o
     * parâmetro que a própria ficha usa nas abas de temporada.
     */
    private function enderecoDoEpisodio(
        string $pagina,
        string $html,
        string $id,
        int $temporada,
        int $episodio,
        int $teto,
    ): ?string {
        $url = $this->origem($pagina, $html)
            .'/index.php?app=videobox&module=video&controller=view&do=episodesList&id='.rawurlencode($id)
            .'&season='.$temporada;

        $dados = $this->dadosDaApi($url, $pagina, $teto);

        if ($dados === null) {
            return null;
        }

        foreach ((array) ($dados['episodes'] ?? []) as $item) {
            if (! is_array($item) || (int) ($item['number'] ?? 0) !== $episodio) {
                continue;
            }

            $link = trim((string) ($item['url'] ?? ''));

            return preg_match('#^https?://#i', $link) ? $link : null;
        }

        return null;
    }

    /**
     * O id do vídeo que o player declara na página.
     *
     * A marca do `videobox` é exigida junto do atributo: `data-video-id` sozinho
     * aparece em tema de fórum e em widget de enquete, e aceitar qualquer um
     * faria o leitor perguntar a um endpoint que não existe.
     */
    private function idDoVideo(string $html): ?string
    {
        if (stripos($html, 'videobox') === false) {
            return null;
        }

        if (! preg_match('#data-video-id\s*=\s*["\'](\d+)["\']#i', $html, $achado)) {
            return null;
        }

        return $achado[1];
    }

    /**
     * Os servidores declarados no JSON do player, indexados pelo nome.
     *
     * A lista chega como query string com entidades HTML (`&amp;` no meio), e é
     * por isso que ela é decodificada antes de ser partida: sem isso, o segundo
     * servidor nasceria chamado `amp;streamtape` e nunca casaria com a receita.
     *
     * @return array<string, string>
     */
    private function mapaDeServidores(string $lista): array
    {
        $lista = html_entity_decode(trim($lista), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if ($lista === '') {
            return [];
        }

        $mapa = [];

        foreach (preg_split('/[&;]+/', $lista) ?: [] as $par) {
            [$nome, $id] = array_pad(explode('=', $par, 2), 2, '');

            $nome = strtolower(trim($nome));
            $id = trim($id);

            if ($nome !== '' && $id !== '') {
                $mapa[$nome] = $id;
            }
        }

        return $mapa;
    }

    /**
     * O endereço-base do servidor, como o próprio JSON o publica.
     *
     * O casamento é pelo rótulo sem pontuação (`StreamTape` → `streamtape`), e
     * não pelo host da URL: os domínios desses players rodam, e amarrar a receita
     * ao host faria o servidor certo deixar de ser reconhecido na primeira troca.
     *
     * @param  array<string, mixed>  $dados
     */
    private function baseDoServidor(array $dados, string $servidor): ?string
    {
        foreach ((array) ($dados['players'] ?? []) as $player) {
            if (! is_array($player)) {
                continue;
            }

            $rotulo = strtolower((string) preg_replace('/[^a-z]/i', '', (string) ($player['label'] ?? '')));
            $url = trim((string) ($player['url'] ?? ''));

            if ($rotulo === $servidor && preg_match('#^https?://#i', $url)) {
                return rtrim($url, '/').'/';
            }
        }

        return null;
    }

    /**
     * Pergunta ao endpoint do player quais são os servidores do vídeo.
     *
     * @return array<string, mixed>|null
     */
    private function dadosDoPlayer(string $pagina, string $html, string $id, int $teto): ?array
    {
        return $this->dadosDaApi($this->enderecoDoPlayerData($pagina, $html, $id), $pagina, $teto);
    }

    /**
     * Lê um endpoint JSON do player.
     *
     * Os dois endpoints que o leitor consulta — o `playerData` e o
     * `episodesList` — pedem a mesma apresentação: `X-Requested-With` (sem ele o
     * site devolve a casca da página), `Referer` da ficha e `Accept` de JSON.
     * Concentrar isso aqui evita duas cópias do mesmo trio de cabeçalhos, que é
     * justamente o tipo de detalhe que passa despercebido quando muda.
     *
     * @return array<string, mixed>|null
     */
    private function dadosDaApi(string $url, string $referer, int $teto): ?array
    {
        try {
            $resposta = $this->cliente->get($url, [], $this->navegador(), $teto, [
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => $referer,
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
            ]);
        } catch (\Throwable $excecao) {
            Log::debug('Leitor videobox: endpoint do player não respondeu.', [
                'url' => $url,
                'erro' => $excecao->getMessage(),
            ]);

            return null;
        }

        if ($resposta === null || $resposta->failed()) {
            return null;
        }

        $dados = $resposta->json();

        return is_array($dados) ? $dados : null;
    }

    /**
     * Monta o endereço do endpoint de dados do player.
     *
     * O host sai do `<base href>` da página, e não da URL pedida: o site responde
     * tanto em `www.` quanto no domínio nu, e o cookie de sessão vale para o host
     * do `<base>` — pedir ao outro devolveria uma sessão nova, sem o vídeo.
     */
    private function enderecoDoPlayerData(string $pagina, string $html, string $id): string
    {
        return $this->origem($pagina, $html)
            .'/index.php?app=videobox&module=video&controller=view&do=playerData&id='.rawurlencode($id);
    }

    /**
     * O esquema e o host da página, preferindo o que o `<base href>` declara.
     */
    private function origem(string $pagina, string $html): string
    {
        $candidatos = [];

        if (preg_match('#<base\b[^>]*\bhref\s*=\s*["\']([^"\']+)#i', $html, $achado)) {
            $candidatos[] = html_entity_decode($achado[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $candidatos[] = $pagina;

        foreach ($candidatos as $candidato) {
            $partes = parse_url($candidato);

            if (is_array($partes) && isset($partes['scheme'], $partes['host'])) {
                return $partes['scheme'].'://'.$partes['host'];
            }
        }

        return '';
    }

    /**
     * Encaminha o embed para a receita do servidor dele.
     *
     * A escolha é pelo nome que o `playerData` declarou, e não pelo host: os
     * domínios desses players rodam (`playmogo.com` já foi `dood.so`,
     * `mixdrop.top` já foi `mixdrop.co`), e a receita continua a mesma.
     */
    private function peloServidor(string $servidor, string $embed, string $referer, int $teto): ?string
    {
        return match ($servidor) {
            'streamtape' => $this->peloStreamtape($embed, $referer, $teto),
            'mixdrop' => $this->peloMixdrop($embed, $referer, $teto),
            default => null,
        };
    }

    /**
     * Atravessa o embed do MixDrop até o MP4 do CDN.
     *
     * A página publica o endereço dentro de um script empacotado pelo packer
     * clássico (`eval(function(p,a,c,k,e,d){...}('...',62,N,'...'.split('|'),0,{}))`).
     * Nada é executado: o desempacotador só troca os índices do dicionário de
     * volta pelas palavras, e o endereço aparece como
     * `//a-deliveryNN.mxcontent.net/v2/<id>.mp4?s=...&e=...`.
     *
     * O parâmetro `e=` é a expiração e a URL é servida ao IP de quem pediu — as
     * mesmas duas condições do `get_video` do StreamTape. Por isso o link é
     * entregue como está, e não o resultado de uma nova resolução: quem abre é
     * o media-service, no mesmo IP e poucos segundos depois.
     */
    private function peloMixdrop(string $embed, string $referer, int $teto): ?string
    {
        $host = strtolower((string) parse_url($embed, PHP_URL_HOST));

        if (! str_contains($host, 'mixdrop')) {
            Log::debug('Leitor videobox: servidor fora da receita conhecida.', [
                'servidor' => $host,
            ]);

            return null;
        }

        $html = $this->abrir($embed, $referer, $teto);

        if ($html === null) {
            return null;
        }

        $url = $this->enderecoDoMixdrop($html);

        if ($url === null) {
            Log::debug('Leitor videobox: o MixDrop não publicou o arquivo.', ['embed' => $embed]);

            return null;
        }

        if ($this->confirmarVideo($url, $teto)) {
            return $url;
        }

        Log::debug('Leitor videobox: o MixDrop publicou endereço que não serve vídeo.', [
            'embed' => $embed,
        ]);

        return null;
    }

    /**
     * O endereço do MP4 dentro da página do MixDrop.
     *
     * A primeira tentativa é no texto cru, para os espelhos que ainda publicam
     * `MDCore.wurl` direto. A segunda desempacota o script — e busca o `.mp4` em
     * vez de um nome de variável, porque o packer troca os nomes e o que
     * sobrevive é a extensão do arquivo.
     */
    private function enderecoDoMixdrop(string $html): ?string
    {
        $codigo = $this->desempacotar($html);

        foreach ([$codigo, $html] as $fonte) {
            if ($fonte === null) {
                continue;
            }

            if (! preg_match('#(?:https?:)?//[^"\'\s]+\.mp4[^"\'\s]*#i', $fonte, $achado)) {
                continue;
            }

            $url = html_entity_decode(trim($achado[0]), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return str_starts_with($url, '//') ? 'https:'.$url : $url;
        }

        return null;
    }

    /**
     * Desempacota o script do packer clássico (`eval(function(p,a,c,k,e,d)...)`).
     *
     * Funciona como o `p.a.c.k.e.r` original: o corpo é uma string com índices
     * (`0`, `1`, `...`, `a`, `b`...) e o dicionário é a lista de palavras
     * separada por `|`. Cada índice é trocado pela palavra correspondente — o
     * `base_convert` é necessário porque, acima de dez, o packer usa letras.
     *
     * Devolve `null` quando o formato não é o esperado. Sem essa trava, uma
     * página que apenas **mencione** `eval(function(` faria o leitor tratar lixo
     * como código e procurar um `.mp4` que não existe.
     */
    private function desempacotar(string $html): ?string
    {
        if (! preg_match(
            "#eval\\(function\\(p,a,c,k,e,d\\)\\{.*?\\}\\(\\s*'(.*?)'\\s*,\\s*(\\d+)\\s*,\\s*(\\d+)\\s*,\\s*'(.*?)'\\.split\\('\\|'\\)#s",
            $html,
            $achado
        )) {
            return null;
        }

        [, $corpo, $base, $conta, $dicionario] = $achado;

        $palavras = explode('|', (string) $dicionario);
        $codigo = (string) $corpo;

        for ($indice = (int) $conta - 1; $indice >= 0; $indice--) {
            $palavra = $palavras[$indice] ?? '';

            if ($palavra === '') {
                continue;
            }

            /*
             * O `base_convert()` do PHP para em 36 — e o packer usa 62 (dígitos,
             * minúsculas e maiúsculas). Daí o conversor próprio: sem ele, todo
             * script com mais de 35 palavras estourava com `ValueError` em vez
             * de desempacotar.
             */
            $codigo = (string) preg_replace(
                '/\b'.preg_quote($this->emBase($indice, (int) $base), '/').'\b/',
                str_replace(['\\', '$'], ['\\\\', '\\$'], $palavra),
                $codigo
            );
        }

        return $codigo;
    }

    /**
     * Converte um número para a base do packer (2 a 62).
     *
     * O alfabeto é o mesmo do `p.a.c.k.e.r`: `0-9`, depois `a-z`, depois `A-Z`.
     * É o que faz o índice 61 virar `Z` e o 10 virar `a`, exatamente como o
     * dicionário do script espera.
     */
    private function emBase(int $numero, int $base): string
    {
        $alfabeto = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $base = max(2, min(strlen($alfabeto), $base));

        if ($numero <= 0) {
            return '0';
        }

        $convertido = '';

        while ($numero > 0) {
            $convertido = $alfabeto[$numero % $base].$convertido;
            $numero = intdiv($numero, $base);
        }

        return $convertido;
    }

    /**
     * Atravessa o embed do StreamTape até o `get_video` que devolve o arquivo.
     *
     * O `Referer` importa: o embed responde 403 a quem chega sem ele. A URL
     * devolvida é a do servidor (com expiração e token), e não o redirecionamento
     * final: o token vale para o IP de quem pediu, e o endereço final muda a cada
     * consulta — guardar o do meio é o que permite ao player abrir a fonte
     * minutos depois.
     */
    private function peloStreamtape(string $embed, string $referer, int $teto): ?string
    {
        $host = strtolower((string) parse_url($embed, PHP_URL_HOST));

        if (! str_contains($host, 'streamtape')) {
            Log::debug('Leitor videobox: servidor fora da receita conhecida.', [
                'servidor' => $host,
            ]);

            return null;
        }

        $html = $this->abrir($embed, $referer, $teto);

        if ($html === null) {
            return null;
        }

        foreach ($this->candidatosDoStreamtape($html) as $candidato) {
            if ($this->confirmarVideo($candidato, $teto)) {
                return $candidato;
            }
        }

        Log::debug('Leitor videobox: o StreamTape não entregou arquivo.', ['embed' => $embed]);

        return null;
    }

    /**
     * Os endereços `get_video` candidatos da página do StreamTape.
     *
     * O caminho normal é **montar** a expressão do `robotlink` — a única que o
     * player de fato usa. Os tokens soltos nos `div`s (`botlink`, `ideoolink`)
     * são iscas: apontam para o mesmo `get_video` com um token inválido, e quem
     * os toma por bons recebe um JSON de erro do servidor.
     *
     * Como a forma da concatenação muda de tempos em tempos, a segunda leva
     * reconstrói a URL a partir dos parâmetros publicados na expressão e tenta os
     * tokens dela, até o teto. A confirmação por sonda é o que decide — nunca a
     * posição do token no HTML.
     *
     * @return array<int, string>
     */
    private function candidatosDoStreamtape(string $html): array
    {
        if (! preg_match(
            "#getElementById\\(\\s*['\"]robotlink['\"]\\s*\\)\\.innerHTML\\s*=\\s*([^;]+);#i",
            $html,
            $achado
        )) {
            return [];
        }

        $expressao = $achado[1];
        $candidatos = [];

        $montado = $this->montarEndereco($expressao);

        if ($montado !== null) {
            $candidatos[] = $montado;
        }

        if (! preg_match('#\\?id=([^\'"&]+)&expires=([^\'"&]+)&ip=([^\'"&]+)#i', $expressao, $partes)) {
            return $candidatos;
        }

        preg_match_all('#token=([A-Za-z0-9]{8,})#', $expressao, $tokens);

        foreach (array_slice(array_unique($tokens[1] ?? []), 0, self::TETO_DE_TOKENS) as $token) {
            $candidatos[] = 'https://streamtape.com/get_video?id='.$partes[1]
                .'&expires='.$partes[2]
                .'&ip='.$partes[3]
                .'&token='.$token;
        }

        return array_values(array_unique($candidatos));
    }

    /**
     * Monta a URL a partir da expressão JavaScript do `robotlink`.
     *
     * A expressão é uma soma de literais com corte:
     *
     *     '//streamtape.com/get_vid' + ('xcdeo?id=...&token=ABC').substring(2).substring(1)
     *
     * Cada literal entra inteiro, e cada `.substring(n)` que o segue é aplicado
     * em ordem — o resultado é a URL que o navegador escreveria no `innerHTML`.
     * Nada é executado: só literais e números, e o que não casar com o formato
     * devolve `null` para a segunda leva resolver por sonda.
     */
    private function montarEndereco(string $expressao): ?string
    {
        if (! preg_match_all('#([\'"])(.*?)\1#s', $expressao, $literais, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $montado = '';

        foreach ($literais[2] as [$literal, $posicao]) {
            $literal = (string) $literal;
            $cauda = substr($expressao, (int) $posicao + strlen($literal) + 1);

            if (preg_match('#^[\s\)]*((?:\.\s*substring\(\s*\d+\s*\)\s*)+)#', $cauda, $corrente)) {
                preg_match_all('#\.\s*substring\(\s*(\d+)\s*\)#', $corrente[1], $cortes);

                foreach ($cortes[1] as $corte) {
                    $literal = substr($literal, (int) $corte);
                }
            }

            $montado .= $literal;
        }

        $montado = html_entity_decode(trim($montado), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (str_starts_with($montado, '//')) {
            $montado = 'https:'.$montado;
        }

        if (! preg_match('#^https?://#i', $montado) || ! str_contains($montado, 'token=')) {
            return null;
        }

        return $montado;
    }

    /**
     * Abre o endereço e confirma que ele devolve vídeo, e não uma página de erro.
     *
     * A leitura é a mesma do [`ResolvedorVod`]: alguns bytes com `Range`, porque
     * o `get_video` responde `200` com um JSON de erro quando o token não vale e
     * `206` com o MP4 quando vale. O status sozinho não separa os dois.
     */
    private function confirmarVideo(string $url, int $teto): bool
    {
        $amostra = $this->cliente->amostrarArquivo($url, 2048, min($teto, self::TETO_DA_AMOSTRA));

        if ($amostra === null || ! in_array($amostra['status'], [200, 206], true)) {
            return false;
        }

        if (str_starts_with($amostra['tipo'], 'video/')) {
            return true;
        }

        // CDN que ignora o tipo: o `ftyp` no offset 4 é a assinatura do MP4.
        return substr($amostra['corpo'], 4, 4) === 'ftyp';
    }

    /**
     * Abre uma página da cadeia, com o `Referer` que o embed exige.
     */
    private function abrir(string $url, string $referer, int $teto): ?string
    {
        if (! $this->temOrcamento()) {
            return null;
        }

        try {
            $resposta = $this->cliente->get($url, [], $this->navegador(), $teto, ['Referer' => $referer]);
        } catch (\Throwable $excecao) {
            Log::debug('Leitor videobox: página do servidor não respondeu.', [
                'url' => $url,
                'erro' => $excecao->getMessage(),
            ]);

            return null;
        }

        if ($resposta === null || $resposta->failed()) {
            return null;
        }

        return (string) $resposta->body();
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
}
