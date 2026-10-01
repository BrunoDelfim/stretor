<?php

namespace App\Support;

/**
 * Barreira de conteúdo impróprio do scraper de stream direto.
 *
 * O buscador web é uma caixa preta: ele ranqueia por autoridade e relevância, e
 * para um título conhecido pode devolver, no meio dos agregadores de vídeo, um
 * link de site adulto que apenas compartilha uma palavra do nome. Foi o que
 * aconteceu com "Donas de Casa Desesperadas": o domínio `xvideos-cdn.com` entrou
 * na lista de candidatos e, sem uma barreira própria, teria sido aberto e
 * raspado como qualquer outra página.
 *
 * A defesa é em duas camadas, e as duas rodam **antes** de gastar orçamento:
 *
 * 1. **Domínio.** Uma lista negra de hosts adultos conhecidos. O casamento é por
 *    sufixo de host com o ponto à frente, para barrar `www.xvideos.com` e
 *    `cdn.xvideos-cdn.com` sem barrar um hipotético `naoxvideos.com`.
 * 2. **Texto.** Palavras-chave proibidas na URL e no título da página. É a rede
 *    de segurança para os domínios que ainda não estão na lista: um link cujo
 *    caminho ou título carrega um termo adulto é descartado mesmo que o host
 *    seja desconhecido.
 *
 * A classe é estática e sem estado de propósito: ela é consultada no meio de
 * laços de rede, onde instanciar serviço a cada checagem só somaria custo. As
 * listas são constantes — mudá-las é uma decisão de código, não de ambiente.
 */
final class FiltroConteudoAdulto
{
    /**
     * Domínios adultos conhecidos, descartados por sufixo de host.
     *
     * A lista cobre os grandes portais e os CDNs que eles usam para servir o
     * vídeo. O `xvideos-cdn.com` está aqui justamente porque foi ele que vazou:
     * o domínio de mídia é diferente do portal e passaria batido por uma lista
     * que só olhasse o nome comercial.
     *
     * @var array<int, string>
     */
    public const DOMINIOS_BLOQUEADOS = [
        // Portais adultos de grande porte.
        'xvideos.com',
        'xvideos-cdn.com',
        'xvideos.es',
        'xvideos2.com',
        'xvideos3.com',
        'xvideosbr.com',
        'xvideos-br.com',
        'pornhub.com',
        'pornhub.org',
        'pornhub.net',
        'pornhubpremium.com',
        'phncdn.com',
        'xhamster.com',
        'xhamster.desi',
        'xhamster18.desi',
        'xhamsterlive.com',
        'xhcdn.com',
        'redtube.com',
        'redtube.com.br',
        'rdtcdn.com',
        'youporn.com',
        'youporngay.com',
        'ypncdn.com',
        'spankbang.com',
        'sb-cd.com',
        'beeg.com',
        'brazzers.com',
        'brazzersnetwork.com',
        'onlyfans.com',
        'chaturbate.com',
        'stripchat.com',
        'livejasmin.com',
        'bongacams.com',
        'cam4.com',
        'myfreecams.com',
        'eporner.com',
        'tnaflix.com',
        'tube8.com',
        'txxx.com',
        'hclips.com',
        'hqporner.com',
        'porntrex.com',
        'porn300.com',
        'pornone.com',
        'pornhat.com',
        'sex.com',
        'xnxx.com',
        'xnxx-cdn.com',
        'xnnx.com',
        'motherless.com',
        'efukt.com',
        'heavy-r.com',
        'empflix.com',
        'drtuber.com',
        'sunporno.com',
        'pornhd.com',
        'porn.com',
        'pornmd.com',
        'porndig.com',
        'pornolab.net',
        'rule34.xxx',
        'rule34video.com',
        'nhentai.net',
        'hanime.tv',
        'hentaihaven.xxx',
        'literotica.com',
        'ashemaletube.com',
        'shemale6.com',
        'gaytube.com',
        'gaymaletube.com',
        'manhunt.net',
        'grindr.com',
        'fapello.com',
        'erome.com',
        'erothots.co',
        'camwhores.tv',
        'recu.me',
        'pornpics.com',
        'nudevista.com',
        'theporndude.com',
        'pornve.com',
        'pornzog.com',
        'vjav.com',
        'javhd.com',
        'javmost.com',
        'supjav.com',
        'missav.com',
        'jable.tv',
        'avgle.com',
        '91porn.com',
        '91porny.com',
        'sextb.net',
        'netfapx.com',
        'netflav.com',
        'porntube.com',
        'pornoxo.com',
        'pornrabbit.com',
        'pornburst.xxx',
        'pornflip.com',
        'pornid.xxx',
        'pornlib.com',
        'pornmaki.com',
        'pornobae.com',
        'pornodoido.com',
        'pornolandia.xxx',
        'pornomexicano.net',
        'pornotube.com',
        'pornovideoshub.com',
        'pornwatchers.com',
        'pornwhite.com',
        'pornyhd.com',
        'sexvid.xxx',
        'sexu.com',
        'sxyprn.com',
        'upornia.com',
        'vidoza.net',
        'voyeurhit.com',
        'xozilla.com',
        'xozilla.xxx',
        'xtapes.to',
        'yespornplease.com',
        'youjizz.com',
        'youjizz-cdn.com',
        'zbporn.com',
        'anon-v.com',
        'anysex.com',
        'babestation.com',
        'bangbros.com',
        'bang.com',
        'bdsmstreak.com',
        'boundhub.com',
        'camdudes.com',
        'camgasm.com',
        'camster.com',
        'camsurf.com',
        'camsoda.com',
        'cliphunter.com',
        'crazyshit.com',
        'daftsex.com',
        'definebabe.com',
        'desihoes.com',
        'desiporn.tube',
        'dirtyroulette.com',
        'dumpert.nl',
        'ebony8.com',
        'fap18.net',
        'fapality.com',
        'fapdu.com',
        'fapfappy.com',
        'fapmov.com',
        'fapster.xxx',
        'faptitans.com',
        'fapvid.com',
        'fetishshrine.com',
        'fux.com',
        'gay0day.com',
        'gaybeeg.info',
        'gayforit.eu',
        'gaypornwave.com',
        'gaysex.com',
        'gaytwinkstube.com',
        'gayxxx.com',
        'gotporn.com',
        'hairydivas.com',
        'hdzog.com',
        'hellporno.com',
        'hentai.tv',
        'hentai2read.com',
        'hentai4daily.com',
        'hentaicity.com',
        'hentaigasm.com',
        'hentaimama.io',
        'hentaiprn.com',
        'hentaistream.com',
        'hqxxx.com',
        'iceporn.com',
        'ixxx.com',
        'javbangers.com',
        'javdoe.com',
        'javfinder.com',
        'javfree.me',
        'javgg.net',
        'javhub.net',
        'javhuge.com',
        'javhihi.com',
        'javwhores.com',
        'jizzbunker.com',
        'jizzhut.com',
        'jizzonline.com',
        'kink.com',
        'kinxxx.com',
        'lesbian8.com',
        'letmejerk.com',
        'liveleak.com',
        'lubetube.com',
        'megatube.xxx',
        'milfzr.com',
        'momxxx.com',
        'mylust.com',
        'naked.com',
        'nuvid.com',
        'ok.xxx',
        'porn00.org',
        'porn4days.com',
        'porn5.com',
        'porn87.com',
        'porn93.com',
        'porndoe.com',
        'porneq.com',
        'pornfay.com',
        'pornheed.com',
        'pornhost.com',
        'pornicom.com',
        'pornkai.com',
        'pornkind.net',
        'pornleech.com',
        'pornmz.com',
        'pornn.com',
        'pornoflix.com',
        'pornohub.su',
        'pornoid.com',
        'pornproxy.com',
        'pornrewind.com',
        'pornrox.com',
        'pornsharing.com',
        'pornstarstube.com',
        'porntop.com',
        'pornult.com',
        'pornvibe.org',
        'pornvid.fun',
        'pornwild.to',
        'private.com',
        'proporn.com',
        'pussyspace.com',
        'rusex.tv',
        'sex3.com',
        'sexalarab.com',
        'sexemodel.com',
        'sexhd.pics',
        'sexix.net',
        'sexlikereal.com',
        'sexmax.com',
        'sexmex.xxx',
        'sexo.com',
        'sexogratis.com',
        'sextvx.com',
        'sextubebr.com',
        'shameless.com',
        'shooshtime.com',
        'slutload.com',
        'smutr.com',
        'spankingtube.com',
        'streamate.com',
        'superporn.com',
        'tabootube.xxx',
        'thegay.com',
        'tube2017.com',
        'tubegalore.com',
        'tubepornclassic.com',
        'videosdemadurasx.com',
        'videosxxx.com',
        'vintagepornfun.com',
        'viralporn.com',
        'vporn.com',
        'wankoz.com',
        'watchmygf.me',
        'wearehairy.com',
        'x18.eu',
        'xart.com',
        'xmoviesforyou.com',
        'xpee.com',
        'xsexporn.com',
        'xxnx.com',
        'xxx.com',
        'zoo-xnxx.com',
    ];

    /**
     * Palavras-chave proibidas, procuradas na URL e no título da página.
     *
     * São termos que, no contexto de um agregador de streaming familiar, não têm
     * uso legítimo: ou descrevem conteúdo adulto, ou são marcadores de sites que
     * só publicam esse tipo de material. A checagem é por substring em texto
     * normalizado (minúsculo, sem acento), então "Pornô" e "porno" caem na mesma
     * regra.
     *
     * Ficam de fora palavras ambíguas que apareceriam em títulos legítimos —
     * "sexo" isolado, por exemplo, aparece em sinopses de filmes dramáticos. O
     * critério é o termo que **só** faz sentido no contexto adulto.
     *
     * @var array<int, string>
     */
    public const PALAVRAS_BLOQUEADAS = [
        // Marcas e portais.
        'porn',
        'porno',
        'pornografia',
        'pornografico',
        'xvideos',
        'xnxx',
        'xhamster',
        'redtube',
        'youporn',
        'spankbang',
        'brazzers',
        'onlyfans',
        'chaturbate',
        'stripchat',
        'livejasmin',
        'bongacams',
        'myfreecams',
        'eporner',
        'tnaflix',
        'hqporner',
        'porntrex',
        'erome',
        'erothots',
        'camwhores',
        'nudevista',
        'theporndude',
        'vjav',
        'javhd',
        'javmost',
        'supjav',
        'missav',
        'jable',
        'avgle',
        '91porn',
        'sextb',
        'netfapx',
        'netflav',
        'hentai',
        'nhentai',
        'hanime',
        'hentaihaven',
        'rule34',
        'literotica',
        'ashemaletube',
        'shemale',
        'gaytube',
        'gaymaletube',
        'grindr',
        'fapello',
        'pornpics',
        'pornve',
        'pornzog',
        // Câmeras e encontros.
        'sexcam',
        'sexcams',
        'webcamsex',
        'camsex',
        'sexchat',
        'sexdating',
        'sexfinder',
        'sexhookup',
        // Termos explícitos em PT-BR.
        'sexo gratis',
        'sexo grátis',
        'sexo anal',
        'sexo oral',
        'sexo amador',
        'sexo caseiro',
        'sexo brasileiro',
        'sexo gay',
        'sexo lesbico',
        'sexo lésbico',
        'sexo grupal',
        'sexo explicito',
        'sexo explícito',
        'filme porno',
        'filme pornô',
        'filmes porno',
        'filmes pornô',
        'video porno',
        'video pornô',
        'vídeo porno',
        'vídeo pornô',
        'videos porno',
        'videos pornô',
        'vídeos porno',
        'vídeos pornô',
        'novinha',
        'novinhas',
        'gostosa',
        'gostosas',
        'pelada',
        'peladas',
        'pelado',
        'pelados',
        'nudes',
        'nudez',
        'orgia',
        'orgias',
        'suruba',
        'surubas',
        'putaria',
        'putas',
        'prostitutas',
        'prostituicao',
        'prostituição',
        'acompanhantes',
        'garotas de programa',
        'garoto de programa',
        'massagem erotica',
        'massagem erótica',
        'massagem tantrica',
        'massagem tântrica',
        'conteudo adulto',
        'conteúdo adulto',
        'conteudo explicito',
        'conteúdo explícito',
        'conteudo +18',
        'conteúdo +18',
        'adulto +18',
        'adultos +18',
        'sexo +18',
        'porno +18',
        'pornô +18',
        'caiu na net',
        'vazou na net',
        'vazados',
        // Termos explícitos em inglês.
        'xxx',
        'x-rated',
        'xrated',
        'nsfw',
        'hardcore porn',
        'softcore porn',
        'milf',
        'gilf',
        'dildo',
        'vibrador',
        'brinquedo sexual',
        'brinquedos sexuais',
        'masturbacao',
        'masturbação',
        'ejaculacao',
        'ejaculação',
        'orgasmo',
        'orgasmos',
        'fetiche',
        'fetiches',
        'bdsm',
        'bondage',
        'fetish',
        'escort',
        'escorts',
        'swinger',
        'swingers',
        'cuckold',
        'gangbang',
        'threesome',
        'creampie',
        'cumshot',
        'blowjob',
        'handjob',
        'anal sex',
        'oral sex',
        'sex tape',
        'sextape',
        'sex video',
        'sex videos',
        'sex tube',
        'sextube',
        'free porn',
        'porn video',
        'porn videos',
        'porn tube',
        'porntube',
        'porn star',
        'pornstar',
        'pornstars',
        'adult video',
        'adult videos',
        'adult tube',
        'adulttube',
        'adult site',
        'adult sites',
        'adult content',
        'adult movie',
        'adult movies',
        'adult film',
        'adult films',
        'adult webcam',
        'adult webcams',
        'adult chat',
        'adult dating',
        'adult games',
        'adult game',
        'adult comics',
        'adult comic',
        'adult manga',
        'adult anime',
        'hentai anime',
        'hentai manga',
        'hentai comics',
        'hentai comic',
        'hentai video',
        'hentai videos',
        'hentai tube',
        'hentaitube',
        'hentai porn',
        'hentai sex',
        'anime porn',
        'anime sex',
        'cartoon porn',
        'cartoon sex',
        'toon porn',
        'toonsex',
        'gay porn',
        'gay sex',
        'gay tube',
        'lesbian porn',
        'lesbian sex',
        'lesbian tube',
        'lesbiantube',
        'shemale porn',
        'shemale sex',
        'tranny porn',
        'tranny sex',
        'trans porn',
        'trans sex',
        'ebony porn',
        'ebony sex',
        'asian porn',
        'asian sex',
        'latina porn',
        'latina sex',
        'milf porn',
        'milf sex',
        'teen porn',
        'teen sex',
        'amateur porn',
        'amateur sex',
        'homemade porn',
        'homemade sex',
        'brazilian porn',
        'brazilian sex',
        'brasileirinhas',
        'brasileirinha',
    ];

    /**
     * Diz se a URL aponta para um domínio adulto da lista negra.
     *
     * O casamento é por sufixo de host com o ponto à frente: `xvideos.com` barra
     * `www.xvideos.com` e `cdn.xvideos.com`, mas não um hipotético
     * `naoxvideos.com`. URLs sem host válido devolvem `false` — quem decide se
     * elas servem é o extrator.
     */
    public static function dominioBloqueado(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '') {
            return false;
        }

        foreach (self::DOMINIOS_BLOQUEADOS as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se o texto carrega alguma palavra-chave proibida.
     *
     * A comparação é feita sobre o texto normalizado (minúsculo e sem acento),
     * para que "Pornô" e "porno" caiam na mesma regra. O texto vazio nunca é
     * bloqueado — a ausência de título não é indício de nada.
     */
    public static function textoBloqueado(string $texto): bool
    {
        $normalizado = self::normalizar($texto);

        if ($normalizado === '') {
            return false;
        }

        foreach (self::PALAVRAS_BLOQUEADAS as $palavra) {
            if (str_contains($normalizado, self::normalizar($palavra))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se a URL é imprópria por domínio **ou** por palavra-chave.
     *
     * É a checagem de porta de entrada: roda sobre cada resultado do motor de
     * busca antes de a página ser aberta, e sobre cada link de vídeo extraído
     * antes de virar fonte. O caminho e a query entram na checagem de texto
     * porque o termo proibido pode estar no slug (`/porn-video-123`) mesmo que o
     * host seja desconhecido.
     */
    public static function urlBloqueada(string $url): bool
    {
        if (self::dominioBloqueado($url)) {
            return true;
        }

        return self::textoBloqueado($url);
    }

    /**
     * Normaliza o texto para a comparação: minúsculo e sem acento.
     *
     * O `iconv` translitera os acentos para ASCII (`pornô` → `porno`), o que faz
     * a lista de palavras-chave funcionar com uma grafia só. Quando a extensão
     * não está disponível, o texto volta como veio — a comparação continua
     * válida para os termos sem acento, que são a maioria.
     */
    private static function normalizar(string $texto): string
    {
        $texto = mb_strtolower(trim($texto));

        if ($texto === '') {
            return '';
        }

        $transliterado = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $texto);

        return $transliterado !== false ? strtolower($transliterado) : $texto;
    }
}
