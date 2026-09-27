<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use App\Enums\IdiomaFonte;
use App\Services\Torrents\TermosBusca;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Registro e cascata dos provedores de torrent.
 *
 * O sistema tem seis caminhos para achar um filme, em três degraus:
 *
 * 1. **Busca nativa no backend** (principal) — [`ProvedorTrackersBr`],
 *    [`ProvedorApibay`], [`ProvedorTorrentio`] e [`ProvedorBt4g`]. É aqui que o
 *    sistema faz por conta própria o que antes era delegado ao Prowlarr.
 * 2. **Indexador Torznab/Prowlarr** ([`ProvedorTorznab`]) — o socorro quando a
 *    busca nativa não devolveu fonte dublada. Ele agrega os mesmos trackers, com
 *    um raspador mantido por terceiros, o que cobre o caso de o nosso HTML
 *    parser ficar para trás.
 * 3. **YTS** ([`ProvedorYts`]) — a rede de segurança em inglês, quando nem o
 *    indexador achou algo em PT-BR.
 *
 * A cascata só avança de degrau quando o degrau atual **não devolveu nenhuma
 * fonte dublada válida**. Um filme que tem release nacional nunca chega a
 * consultar o YTS, e o contrário também vale: sem chave do indexador, a busca
 * nativa segue funcionando e o fluxo não para.
 *
 * Cada provedor é chamado isolado e cacheado individualmente. O cache é por
 * provedor (e não do resultado final) para que a queda de um site não invalide o
 * trabalho dos outros, e para que acrescentar um provedor novo não exija mexer
 * na chave de cache de ninguém.
 */
class CatalogoProvedores
{
    /**
     * Sal de versão da chave de cache dos provedores.
     *
     * Sem ele, "corrigi o código" e "não fiz nada" ficam indistinguíveis por até
     * um TTL inteiro: a chave é a mesma antes e depois da correção, então o
     * resultado antigo — inclusive os zeros falsos que a rota errada do Torznab
     * produzia — continua sendo servido. Suba este número junto com qualquer
     * mudança de comportamento de um provedor para a reconsulta valer já na
     * próxima busca, sem depender de limpar o Redis à mão.
     */
    private const VERSAO_CACHE = 4;

    /**
     * Provedores do primeiro degrau que buscam **por nome**.
     *
     * A ordem dentro do degrau é a ordem de consulta: o tracker PT-BR vem
     * primeiro porque é o único que existe *por causa* do dublado; o APIBay
     * amplia o alcance; o BT4G fecha com o acervo de DHT.
     *
     * O Torrentio fica de fora desta lista de propósito: ele busca por
     * identificador (imdb_id) e ignora o termo de busca. Se entrasse aqui, seria
     * consultado uma vez por variação de termo — e como o termo de episódio agora
     * rende quatro variações (puro + três dubladas), seriam quatro requisições
     * idênticas ao provedor mais lento da cascata, todas devolvendo o mesmo
     * resultado.
     *
     * @var array<int, ProvedorTorrents>
     */
    private array $primarios;

    /**
     * Provedores que buscam por identificador (imdb_id).
     *
     * São consultados uma única vez, com o termo puro, porque o termo não
     * influencia a resposta — só o identificador importa.
     *
     * @var array<int, ProvedorTorrents>
     */
    private array $porIdentificador;

    public function __construct(
        ProvedorTrackersBr $trackersBr,
        ProvedorApibay $apibay,
        ProvedorTorrentio $torrentio,
        ProvedorBt4g $bt4g,
        private readonly ProvedorTorznab $torznab,
        private readonly ProvedorYts $yts,
    ) {
        $this->primarios = [$trackersBr, $apibay, $bt4g];
        $this->porIdentificador = [$torrentio];
    }

    /**
     * Percorre a cascata e devolve todas as fontes encontradas, sem repetir.
     *
     * @param  array<int, string>  $titulos  Títulos candidatos, em ordem de
     *                                       preferência (traduzido e original)
     * @param  int|null  $temporada  Temporada do episódio, quando a busca é de série
     * @param  int|null  $episodio   Episódio procurado, quando a busca é de série
     * @return array<int, array<string, mixed>>
     */
    public function buscar(
        array $titulos,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        $fontes = [];

        /*
         * Os provedores por identificador (Torrentio) são consultados uma única
         * vez, com o termo puro. O termo não muda a resposta deles — só o
         * `imdb_id` importa —, então repetir a chamada para cada variação dublada
         * seria gastar o provedor mais lento da cascata à toa.
         *
         * O que ele devolve entra na lista, mas **não** decide a parada. Antes,
         * esta fonte era mesclada ao acumulado e o `temDublado()` do laço de
         * termos a enxergava: o Torrentio respondia uma única fonte rotulada
         * "Dublado" (S01E01 720p PORTUGUÊS BR) e a cascata retornava logo no
         * primeiro termo — que é o termo puro, sem a tag. As variações dubladas
         * montadas pelo TorrentService e o degrau 2 inteiro nunca rodavam, e a
         * busca de episódio terminava com uma fonte só, mesmo havendo mais.
         */
        $fontes = $this->mesclar(
            $fontes,
            $this->buscarGrupo($this->porIdentificador, $titulos[0] ?? '', $ano, $imdbId, $temporada, $episodio)
        );

        /*
         * O título traduzido é o que os trackers brasileiros usam, mas o título
         * original ajuda quando a tradução abreviou demais ("Homem-Aranha" versus
         * "Spider-Man"). Tentamos os dois no degrau nativo antes de descer para o
         * indexador — é mais barato insistir no caminho principal do que delegar.
         *
         * Cada título já chega com as variações dubladas montadas pelo
         * TorrentService, então o `temDublado()` encerra a cascata assim que o
         * release nacional aparece. O julgamento é sobre o que **esta etapa**
         * devolveu, não sobre a lista acumulada: uma fonte promissora do degrau
         * anterior (ou do grupo por identificador) não pode calar a busca que
         * existe justamente para achar o dublado.
         */
        foreach ($titulos as $titulo) {
            $desteTermo = $this->buscarGrupo($this->primarios, $titulo, $ano, $imdbId, $temporada, $episodio);
            $fontes = $this->mesclar($fontes, $desteTermo);

            $this->registrarEtapa('nativos', $titulo, $desteTermo);

            if ($this->temDublado($desteTermo)) {
                return $fontes;
            }
        }

        // Degrau 2: indexador. Só é consultado se a busca nativa não achou dublado.
        foreach ($titulos as $titulo) {
            $desteTermo = $this->buscarGrupo([$this->torznab], $titulo, $ano, $imdbId, $temporada, $episodio);
            $fontes = $this->mesclar($fontes, $desteTermo);

            $this->registrarEtapa('indexador', $titulo, $desteTermo);

            if ($this->temDublado($desteTermo)) {
                return $fontes;
            }
        }

        // Degrau 3: reserva em inglês. Entra sempre que nada dublado apareceu.
        // Em episódio o YTS se abstém sozinho (é catálogo só de filmes), então a
        // chamada é inofensiva e mantém a cascata com um formato único.
        $fontes = $this->mesclar(
            $fontes,
            $this->buscarGrupo([$this->yts], $titulos[0] ?? '', $ano, $imdbId, $temporada, $episodio)
        );

        return $fontes;
    }

    /**
     * Confirma se existe algum provedor utilizável na configuração atual.
     *
     * O controller usa isto para distinguir "nenhuma fonte encontrada" de
     * "nenhum provedor pôde ser consultado" — a segunda mensagem aponta para
     * configuração, não para o filme.
     */
    public function algumDisponivel(): bool
    {
        foreach (array_merge($this->primarios, [$this->torznab, $this->yts]) as $provedor) {
            if ($provedor->disponivel()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diz se a lista contém alguma fonte dublada ou em dual áudio.
     *
     * É o critério de parada da cascata: dublado e dual áudio atendem o usuário
     * brasileiro, então qualquer um dos dois encerra a busca pelos degraus
     * seguintes.
     *
     * Quem decide a parada entrega **o que a própria etapa devolveu**, nunca a
     * lista acumulada. A diferença não é cosmética: julgada sobre o acumulado, a
     * fonte do Torrentio (que abre o degrau 1) encerrava o laço no primeiro termo
     * e as variações dubladas ficavam sem ser perguntadas.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    public function temDublado(array $fontes): bool
    {
        foreach ($fontes as $fonte) {
            $idioma = $fonte['idioma'] ?? '';

            if ($idioma === IdiomaFonte::DUBLADO->value || $idioma === IdiomaFonte::DUAL_AUDIO->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Registra o que cada etapa da cascata devolveu, com quantas dubladas.
     *
     * Sem este retrato, "só veio uma fonte" fica indistinguível de "a etapa não
     * rodou". Foi o que a busca de episódio escondeu: o degrau nativo retornava no
     * primeiro termo por causa da fonte do Torrentio, e nada no log dizia que os
     * termos dublados e o indexador tinham ficado por executar.
     *
     * O `encerra` aqui é telemetria; quem para a cascata é o laço, com este
     * mesmo conjunto.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    private function registrarEtapa(string $degrau, string $titulo, array $fontes): void
    {
        Log::info('Etapa da cascata de torrents concluída.', [
            'degrau' => $degrau,
            'titulo' => $titulo,
            'fontes' => count($fontes),
            'dubladas' => count(array_filter(
                $fontes,
                fn (array $fonte) => in_array(
                    $fonte['idioma'] ?? '',
                    [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                    true
                )
            )),
            'encerra' => $this->temDublado($fontes),
        ]);
    }

    /**
     * Consulta um conjunto de provedores para um título, somando o que vier.
     *
     * @param  array<int, ProvedorTorrents>  $provedores
     * @return array<int, array<string, mixed>>
     */
    private function buscarGrupo(
        array $provedores,
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        if (trim($titulo) === '') {
            return [];
        }

        $fontes = [];

        foreach ($provedores as $provedor) {
            if (! $provedor->disponivel()) {
                /*
                 * Regra da credencial ausente: pular o provedor e deixar a
                 * cascata seguir. Nada de erro bloqueante — a falta de uma chave
                 * reduz o alcance, não impede a busca.
                 */
                Log::info('Provedor de torrents pulado por falta de configuração.', [
                    'provedor' => $provedor->identificador(),
                ]);

                continue;
            }

            $fontes = $this->mesclar(
                $fontes,
                $this->buscarComCache($provedor, $titulo, $ano, $imdbId, $temporada, $episodio)
            );
        }

        return $this->aproveitaveis($fontes, $temporada, $episodio);
    }

    /**
     * Descarta, ainda dentro da cascata, o que não serve para o pedido.
     *
     * O critério de parada da cascata é `temDublado()`, e ele precisa enxergar
     * apenas fontes que realmente atendem ao pedido. Sem este corte, um dual
     * áudio de outra temporada — que o Torrentio mistura na resposta da série —
     * encerrava a busca no degrau 1 e, descartado depois na ordenação, deixava a
     * lista vazia sem que os degraus seguintes fossem tentados.
     *
     * São dois cortes, na ordem em que importam:
     *
     * 1. **Numeração** — releases que declaram uma temporada/episódio diferente
     *    da pedida. Releases sem numeração passam, para não apagar packs e nomes
     *    nacionais legítimos.
     * 2. **Idioma** — quando `apenas_pt_br` está ligado, só dublado e dual áudio
     *    seguem. É o mesmo corte que a ordenação faz, mas aplicado cedo o
     *    bastante para a cascata reagir a ele.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function aproveitaveis(array $fontes, ?int $temporada, ?int $episodio): array
    {
        $apenasPtBr = (bool) config('services.torrents.apenas_pt_br', true);

        return array_values(array_filter(
            $fontes,
            function (array $fonte) use ($temporada, $episodio, $apenasPtBr): bool {
                if ($temporada !== null && $episodio !== null
                    && ! TermosBusca::correspondeAoEpisodio((string) ($fonte['titulo'] ?? ''), $temporada, $episodio)) {
                    return false;
                }

                if ($apenasPtBr && ! in_array(
                    $fonte['idioma'] ?? '',
                    [IdiomaFonte::DUBLADO->value, IdiomaFonte::DUAL_AUDIO->value],
                    true
                )) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * Consulta o provedor com cache e absorve a falha.
     *
     * A resposta vazia também é cacheada: um site que não tem o filme hoje (ou
     * está fora do ar) seria martelado a cada abertura do player sem que houvesse
     * chance de mudar de resposta dentro do TTL.
     *
     * A numeração do episódio entra na chave porque o mesmo provedor responde
     * coisas diferentes para cada episódio da série: sem ela, o resultado do
     * S01E01 seria servido para o S01E02 durante todo o TTL.
     *
     * A [`VERSAO_CACHE`] encabeça a chave para isolar as entradas gravadas pelo
     * código antigo: assim uma correção de comportamento passa a valer na próxima
     * busca, em vez de ficar presa atrás do resultado velho.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buscarComCache(
        ProvedorTorrents $provedor,
        string $titulo,
        ?int $ano,
        ?string $imdbId,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array {
        $chave = 'torrent:provedor:'.self::VERSAO_CACHE.':'.$provedor->identificador().':'
            .md5(mb_strtolower($titulo).'|'.$ano.'|'.$imdbId.'|'.$temporada.'|'.$episodio);

        $ttl = (int) config('services.torrents.cache_ttl', 1800);

        return Cache::remember($chave, $ttl, function () use ($provedor, $titulo, $ano, $imdbId, $temporada, $episodio) {
            try {
                $fontes = $provedor->buscar($titulo, $ano, $imdbId, $temporada, $episodio);

                Log::debug('Provedor de torrents respondeu.', [
                    'provedor' => $provedor->identificador(),
                    'titulo' => $titulo,
                    'temporada' => $temporada,
                    'episodio' => $episodio,
                    'fontes' => count($fontes),
                ]);

                return $fontes;
            } catch (\Throwable $excecao) {
                report($excecao);

                return [];
            }
        });
    }

    /**
     * Junta listas de fontes sem repetir a mesma release.
     *
     * A primeira ocorrência vence. Como a ordem de chamada põe o tracker PT-BR na
     * frente, um mesmo torrent que apareça depois em outro provedor mantém a
     * classificação de idioma que veio da busca nacional.
     *
     * @param  array<int, array<string, mixed>>  ...$listas
     * @return array<int, array<string, mixed>>
     */
    private function mesclar(array ...$listas): array
    {
        $fontes = [];
        $vistos = [];

        foreach ($listas as $lista) {
            foreach ($lista as $fonte) {
                $chave = (string) ($fonte['id'] ?? '');

                if ($chave === '' || isset($vistos[$chave])) {
                    continue;
                }

                $vistos[$chave] = true;
                $fontes[] = $fonte;
            }
        }

        return $fontes;
    }
}
