<?php

namespace App\Services\Verificacao;

use App\Enums\OrigemVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Services\TorrentService;
use InvalidArgumentException;

/**
 * Prova se um item do catálogo tem fonte.
 *
 * É a menor unidade da verificação: recebe um item normalizado do TMDB, pergunta
 * à cascata de fontes se há algo tocável e grava o veredito em `disponibilidades`.
 * A inteligência de "achar" mora inteira no [`TorrentService`]; aqui só decidimos
 * o que conta como resposta e como registrar o resultado.
 */
class VerificadorFontes
{
    /**
     * Sonda de episódio para séries.
     *
     * O stream direto — o canal que hospeda os agregadores (Pobreflix, entre
     * outros) — monta a rota do vídeo a partir da ficha da série **mais** a
     * temporada e o episódio. Faltando esses dois números, o agregador só devolve
     * a ficha, nunca a página do episódio, e a série era gravada como "sem fonte"
     * mesmo estando no acervo. Perguntar pelo primeiro episódio da primeira
     * temporada é a amostra mais barata que responde "esta série tem fonte?".
     */
    private const TEMPORADA_SONDA = 1;

    private const EPISODIO_SONDA = 1;

    public function __construct(private readonly TorrentService $torrents)
    {
    }

    /**
     * Verifica um item e persiste o veredito.
     *
     * @param  array<string, mixed>  $item  Item no formato normalizado do TMDB.
     *
     * @throws InvalidArgumentException  Quando o item não é filme nem série.
     */
    public function verificar(array $item, OrigemVerificacao $origem): Disponibilidade
    {
        $tipo = TipoMidia::doTmdb($item['tipo'] ?? null);
        $tmdbId = (int) ($item['id'] ?? 0);

        // Item sem tipo reconhecido (pessoa, item quebrado) não entra no índice:
        // sem tipo e id não há como identificá-lo de volta depois, e uma linha
        // dessas só serviria para poluir a contagem.
        if ($tipo === null || $tmdbId === 0) {
            throw new InvalidArgumentException('Item sem tipo de mídia reconhecido.');
        }

        // A série é sondada por um episódio concreto — é o que faz o agregador
        // responder; o filme não tem essa dimensão e segue só com o título.
        $sondandoEpisodio = $tipo === TipoMidia::SERIE;

        $fontes = $this->torrents->fontes(
            titulo: (string) ($item['titulo'] ?? ''),
            ano: $this->ano($item),
            imdbId: $item['imdb_id'] ?? null,
            tituloOriginal: $item['titulo_original'] ?? null,
            temporada: $sondandoEpisodio ? self::TEMPORADA_SONDA : null,
            episodio: $sondandoEpisodio ? self::EPISODIO_SONDA : null,
            tmdbId: (string) $tmdbId,
            // O teto cobre o custo real do stream direto — duas páginas (a ficha
            // da série e a do episódio), de 10 a 15 s cada. Um teto curto demais
            // cortava o agregador no meio e gravava "sem fonte" para um título que
            // só o acervo web tinha.
            tempoTotal: (int) config('services.verificacao.orcamento_titulo', 30),
        );

        // A lista já vem ordenada, com a PT-BR na frente. Guardamos o perfil da
        // melhor fonte para o status e para entender de onde veio a prova.
        $principal = $fontes[0] ?? null;

        return Disponibilidade::updateOrCreate(
            [
                'tipo' => $tipo->value,
                'tmdb_id' => $tmdbId,
            ],
            [
                'tem_fonte' => $fontes !== [],
                'fonte_idioma' => $principal['idioma'] ?? null,
                'fonte_tipo' => $principal['tipo'] ?? null,
                'origem' => $origem->value,
                'verificado_em' => now(),
            ],
        );
    }

    /** O ano vem como inteiro ou string, a depender de onde o item foi montado. */
    private function ano(array $item): ?int
    {
        return is_numeric($item['ano'] ?? null) ? (int) $item['ano'] : null;
    }
}
