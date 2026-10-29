<?php

namespace App\Support;

/**
 * Plataformas de streaming por assinatura — o que nunca entrega o arquivo.
 *
 * A busca web não distingue "onde assistir" de "onde baixar": o título do filme
 * aparece na ficha do MUBI, da Netflix, do Prime Video, e o buscador devolve
 * essas páginas como candidatas. Visitá-las custa orçamento e, pior, rende
 * **trailer**: a ficha de um filme do MUBI publica os `splash-videos` do site
 * (um vídeo de fundo, sem som, igual para todo título), e o extrator pode
 * confundi-lo com o arquivo do filme. A fonte entra na lista, o player abre e
 * nada toca — não porque o link morreu, mas porque ele nunca foi o filme.
 *
 * O bloqueio é por **domínio de registro**, com o ponto à frente: `mubi.com`
 * precisa barrar `www.mubi.com`, mas não um hipotético `naomubi.com`. A lista
 * inclui os CDNs de cada plataforma, porque o vídeo quase sempre mora num host
 * diferente do portal (`assets.mubicdn.net`, `ipv4-c001.nflxvideo.net`).
 *
 * Não há contorno possível do lado do servidor: o conteúdo é protegido por DRM
 * (Widevine/PlayReady) e o manifesto só abre com licença de assinante. O que
 * esta classe faz é o que resolve de verdade — não oferecer a fonte falsa.
 *
 * Como [`FiltroConteudoAdulto`], é estática e sem estado: é consultada dentro de
 * laços de rede, onde instanciar serviço a cada checagem só somaria custo.
 */
final class PlataformasPagas
{
    /**
     * Portais de assinatura e os CDNs que eles usam para servir o vídeo.
     *
     * @var array<int, string>
     */
    public const DOMINIOS = [
        // MUBI — foi o caso que abriu esta lista ("Cara-de-Barro"/Clayface).
        'mubi.com',
        'mubicdn.net',
        // Netflix.
        'netflix.com',
        'nflxvideo.net',
        'nflximg.net',
        'nflxext.com',
        'nflxso.net',
        // Prime Video.
        'primevideo.com',
        'aiv-cdn.net',
        'media-amazon.com',
        // Disney+ e Star+.
        'disneyplus.com',
        'disneystreaming.com',
        'dssott.com',
        'bamgrid.com',
        'starplus.com',
        // Max / HBO.
        'max.com',
        'hbomax.com',
        'hbo.com',
        // Paramount+.
        'paramountplus.com',
        'pplus-cdn.com',
        // Globo (Globoplay).
        'globoplay.globo.com',
        'globoplay.com',
        // Crunchyroll.
        'crunchyroll.com',
        'crunchyrollsvc.com',
        'vrv.co',
        // Looke e Telecine.
        'looke.com.br',
        'looke.com',
        'telecine.com.br',
        // Hulu, Peacock e Starz.
        'hulu.com',
        'huluim.com',
        'peacocktv.com',
        'starz.com',
        // Apple TV.
        'tv.apple.com',
        'appletv.com',
        // Pluto TV: gratuito, mas também não publica o arquivo do título.
        'pluto.tv',
        // Catálogos de "onde assistir" — a mesma ficha sem player.
        'filmelier.com',
        'thestreamable.com',
        'whentostream.com',
    ];

    /** A URL pertence a uma plataforma de assinatura? */
    public static function bloqueiaUrl(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $host !== '' && self::bloqueiaHost($host);
    }

    /**
     * O host pertence a uma plataforma de assinatura?
     *
     * O casamento é por sufixo de domínio com o ponto à frente, e não por
     * substring: `mubi.com` barra `www.mubi.com` e `assets.mubicdn.net` barra
     * `x.assets.mubicdn.net`, mas nenhum dos dois barraria `naomubi.com`.
     */
    public static function bloqueiaHost(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === '') {
            return false;
        }

        foreach (self::DOMINIOS as $dominio) {
            if ($host === $dominio || str_ends_with($host, '.'.$dominio)) {
                return true;
            }
        }

        return false;
    }
}
