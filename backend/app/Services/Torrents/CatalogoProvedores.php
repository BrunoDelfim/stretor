<?php

namespace App\Services\Torrents;

use App\Contracts\ProvedorTorrents;
use App\Enums\IdiomaFonte;
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
     * Provedores do primeiro degrau (busca nativa).
     *
     * A ordem dentro do degrau é a ordem de consulta: o tracker PT-BR vem
     * primeiro porque é o único que existe *por causa* do dublado; o APIBay e o
     * Torrentio ampliam o alcance; o BT4G fecha com o acervo de DHT.
     *
     * @var array<int, ProvedorTorrents>
     */
    private array $primarios;

    public function __construct(
        ProvedorTrackersBr $trackersBr,
        ProvedorApibay $apibay,
        ProvedorTorrentio $torrentio,
        ProvedorBt4g $bt4g,
        private readonly ProvedorTorznab $torznab,
        private readonly ProvedorYts $yts,
    ) {
        $this->primarios = [$trackersBr, $apibay, $torrentio, $bt4g];
    }

    /**
     * Percorre a cascata e devolve todas as fontes encontradas, sem repetir.
     *
     * @param  array<int, string>  $titulos  Títulos candidatos, em ordem de
     *                                       preferência (traduzido e original)
     * @return array<int, array<string, mixed>>
     */
    public function buscar(array $titulos, ?int $ano, ?string $imdbId): array
    {
        $fontes = [];

        /*
         * O título traduzido é o que os trackers brasileiros usam, mas o título
         * original ajuda quando a tradução abreviou demais ("Homem-Aranha" versus
         * "Spider-Man"). Tentamos os dois no degrau nativo antes de descer para o
         * indexador — é mais barato insistir no caminho principal do que delegar.
         */
        foreach ($titulos as $titulo) {
            $fontes = $this->mesclar($fontes, $this->buscarGrupo($this->primarios, $titulo, $ano, $imdbId));

            if ($this->temDublado($fontes)) {
                return $fontes;
            }
        }

        // Degrau 2: indexador. Só é consultado se a busca nativa não achou dublado.
        foreach ($titulos as $titulo) {
            $fontes = $this->mesclar($fontes, $this->buscarGrupo([$this->torznab], $titulo, $ano, $imdbId));

            if ($this->temDublado($fontes)) {
                return $fontes;
            }
        }

        // Degrau 3: reserva em inglês. Entra sempre que nada dublado apareceu.
        $fontes = $this->mesclar($fontes, $this->buscarGrupo([$this->yts], $titulos[0] ?? '', $ano, $imdbId));

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
     * Consulta um conjunto de provedores para um título, somando o que vier.
     *
     * @param  array<int, ProvedorTorrents>  $provedores
     * @return array<int, array<string, mixed>>
     */
    private function buscarGrupo(array $provedores, string $titulo, ?int $ano, ?string $imdbId): array
    {
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

            $fontes = $this->mesclar($fontes, $this->buscarComCache($provedor, $titulo, $ano, $imdbId));
        }

        return $fontes;
    }

    /**
     * Consulta o provedor com cache e absorve a falha.
     *
     * A resposta vazia também é cacheada: um site que não tem o filme hoje (ou
     * está fora do ar) seria martelado a cada abertura do player sem que houvesse
     * chance de mudar de resposta dentro do TTL.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buscarComCache(ProvedorTorrents $provedor, string $titulo, ?int $ano, ?string $imdbId): array
    {
        $chave = 'torrent:provedor:'.$provedor->identificador().':'
            .md5(mb_strtolower($titulo).'|'.$ano.'|'.$imdbId);

        $ttl = (int) config('services.torrents.cache_ttl', 1800);

        return Cache::remember($chave, $ttl, function () use ($provedor, $titulo, $ano, $imdbId) {
            try {
                $fontes = $provedor->buscar($titulo, $ano, $imdbId);

                Log::debug('Provedor de torrents respondeu.', [
                    'provedor' => $provedor->identificador(),
                    'titulo' => $titulo,
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
