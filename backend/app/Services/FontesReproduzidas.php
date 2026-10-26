<?php

namespace App\Services;

use App\Enums\TipoMidia;
use App\Models\FonteReproduzida;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

/**
 * Histórico das fontes que já reproduziram inteiras.
 *
 * O fluxo de reprodução é uma tentativa e erro cara: peneira a cascata, testa a
 * malha de cada candidata e ainda paga a conexão no media-service até uma fonte
 * entregar o arquivo. Quando uma serve o título do começo ao fim, esse trabalho
 * já foi feito e não precisa ser refeito na próxima abertura — é isso que este
 * serviço guarda, e é daqui que a busca tira as fontes que volta a oferecer
 * primeiro.
 *
 * A prova é forte, mas não é eterna: nada aqui dispensa a malha nem substitui a
 * busca. A fonte comprovada entra na frente da fila e, se não responder mais, o
 * resto da lista continua lá — o usuário não paga pela confiança que o histórico
 * depositou nela.
 */
class FontesReproduzidas
{
    /** O recurso está ligado? Desligado, a busca volta a ignorar o histórico. */
    public function habilitado(): bool
    {
        return (bool) config('services.torrents.fontes_salvas', true);
    }

    /**
     * Quantas fontes comprovadas podem ser lembradas por título.
     *
     * É o mesmo número que limita a poda e o que a busca devolve, de propósito:
     * um teto só, para não existir linha no banco que a busca nunca ofereceria.
     */
    public function limite(): int
    {
        return max(1, (int) config('services.torrents.fontes_salvas_max', 3));
    }

    /**
     * As fontes comprovadas deste título, já no contrato do frontend.
     *
     * A ordem é a da confiança: primeiro as que mais serviram, depois as mais
     * recentes. `temporada` e `episodio` valem zero no filme — é o mesmo zero que
     * a coluna guarda, para a consulta bater com o índice.
     *
     * @return array<int, array<string, mixed>>
     */
    public function preferidas(TipoMidia $tipo, ?int $tmdbId, int $temporada = 0, int $episodio = 0): array
    {
        if (! $this->habilitado() || $tmdbId === null || $tmdbId === 0) {
            return [];
        }

        return $this->doTitulo($tipo, $tmdbId, $temporada, $episodio)
            ->orderByDesc('reproducoes')
            ->orderByDesc('confirmada_em')
            ->limit($this->limite())
            ->get()
            ->map(fn (FonteReproduzida $fonte): array => $fonte->paraFonte())
            ->all();
    }

    /**
     * Registra a fonte que acabou de reproduzir inteira.
     *
     * Repetir a mesma fonte não cria linha nova: reforça a que existe, somando
     * uma reprodução e renovando a data. É esse contador que faz uma fonte
     * confiável subir na frente das que tocaram uma única vez.
     *
     * @param  array<string, mixed>  $dados  Campos já validados pelo controller.
     */
    public function registrar(
        array $dados,
        TipoMidia $tipo,
        int $tmdbId,
        int $temporada = 0,
        int $episodio = 0,
    ): ?FonteReproduzida {
        if (! $this->habilitado() || $tmdbId === 0) {
            return null;
        }

        $fonte = FonteReproduzida::query()->firstOrNew([
            'tipo' => $tipo->value,
            'tmdb_id' => $tmdbId,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'fonte_id' => (string) $dados['fonte_id'],
        ]);

        $fonte->fill([
            'tipo_fonte' => (string) $dados['tipo'],
            'titulo' => $dados['titulo'] ?? null,
            'qualidade' => $dados['qualidade'] ?? null,
            'tamanho' => $dados['tamanho'] ?? null,
            'idioma' => $dados['idioma'] ?? null,
            'provedor' => $dados['provedor'] ?? null,
            'provedor_rotulo' => $dados['provedor_rotulo'] ?? null,
            'seeds' => (int) ($dados['seeds'] ?? 0),
            'peers' => (int) ($dados['peers'] ?? 0),
            'magnet' => $dados['magnet'] ?? null,
            'stream' => $dados['stream'] ?? null,
            'legendas' => $dados['legendas'] ?? [],
        ]);

        $fonte->reproducoes = (int) $fonte->reproducoes + 1;
        $fonte->confirmada_em = now();
        $fonte->save();

        $this->podar($tipo, $tmdbId, $temporada, $episodio);

        Log::info('Fonte comprovada registrada.', [
            'tipo' => $tipo->value,
            'tmdb_id' => $tmdbId,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'fonte_id' => $fonte->fonte_id,
            'reproducoes' => $fonte->reproducoes,
        ]);

        return $fonte;
    }

    /**
     * Apaga o histórico de fontes comprovadas.
     *
     * O histórico é uma conveniência, e é isso que este método lembra: apagá-lo
     * não perde nada — a próxima busca redescobre as fontes e volta a gravar o
     * que reproduzir. O que ele resolve é o caso de um resultado antigo continuar
     * na frente da fila depois de uma correção na busca (o magnet que tocou ontem,
     * por exemplo), sem esperar prazo nenhum: o histórico não expira, só tem teto
     * por título.
     *
     * Sem filtro, apaga tudo. Com `$tipo` e `$tmdbId`, só aquele título; com
     * temporada e episódio, só aquele episódio. Devolve quantas linhas saíram,
     * para quem chama dizer o que fez.
     */
    public function limpar(
        ?TipoMidia $tipo = null,
        ?int $tmdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): int {
        $consulta = FonteReproduzida::query();

        if ($tipo !== null) {
            $consulta->where('tipo', $tipo->value);
        }

        if ($tmdbId !== null) {
            $consulta->where('tmdb_id', $tmdbId);
        }

        if ($temporada !== null) {
            $consulta->where('temporada', $temporada);
        }

        if ($episodio !== null) {
            $consulta->where('episodio', $episodio);
        }

        $apagadas = $consulta->delete();

        Log::info('Histórico de fontes comprovadas limpo.', [
            'tipo' => $tipo?->value,
            'tmdb_id' => $tmdbId,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'apagadas' => $apagadas,
        ]);

        return $apagadas;
    }

    /** Consulta base do título — o mesmo recorte usado para gravar e para podar. */
    private function doTitulo(TipoMidia $tipo, int $tmdbId, int $temporada, int $episodio): Builder
    {
        return FonteReproduzida::query()
            ->where('tipo', $tipo->value)
            ->where('tmdb_id', $tmdbId)
            ->where('temporada', $temporada)
            ->where('episodio', $episodio);
    }

    /**
     * Descarta as comprovadas que passaram do teto.
     *
     * O corte é pelo menos usada: quem sai é a fonte que serviu menos vezes (e,
     * empatada, a que tocou há mais tempo). Sem a poda, um título aberto muitas
     * vezes acumularia dezenas de linhas e a busca gastaria o teste de malha
     * sondando histórico velho em vez do achado de agora.
     */
    private function podar(TipoMidia $tipo, int $tmdbId, int $temporada, int $episodio): void
    {
        $excedentes = $this->doTitulo($tipo, $tmdbId, $temporada, $episodio)
            ->orderByDesc('reproducoes')
            ->orderByDesc('confirmada_em')
            ->skip($this->limite())
            ->pluck('id');

        if ($excedentes->isNotEmpty()) {
            FonteReproduzida::query()->whereIn('id', $excedentes)->delete();
        }
    }
}
