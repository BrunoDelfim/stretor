<?php

namespace App\Console\Commands;

use App\Enums\TipoMidia;
use App\Services\FontesReproduzidas;
use Illuminate\Console\Command;

/**
 * Limpa o histórico de fontes comprovadas — os "links salvos".
 *
 * A busca guarda a fonte que reproduziu inteira para colocá-la na frente da fila
 * na próxima abertura do mesmo título. É um atalho bom para o dia a dia e um
 * estorvo quando a busca muda: um magnet que tocou ontem continua sendo a
 * primeira coisa oferecida mesmo depois de uma correção que passa a achar um
 * link direto, e não há prazo que expire isso — o histórico só tem teto por
 * título.
 *
 * Este comando é a saída: apaga o histórico inteiro, o de um título
 * (`--tmdb=`) ou o de um episódio (`--tmdb= --temporada= --episodio=`). A busca
 * seguinte volta a descobrir tudo do zero, e o que reproduzir é gravado de novo.
 *
 * O que ele **não** toca é o cache de resposta dos provedores (30 min, em Redis).
 * Esse tem chave própria e é liberado por `TORRENTS_CACHE_BYPASS=true` ou por
 * `make cache-clear`.
 */
class LimparFontes extends Command
{
    protected $signature = 'fontes:limpar
        {--tmdb= : Limpa só o histórico deste id do TMDB}
        {--tipo= : filme ou serie (sem isso, limpa os dois)}
        {--temporada= : Temporada do episódio (exige --tmdb e --episodio)}
        {--episodio= : Episódio a limpar (exige --tmdb e --temporada)}';

    protected $description = 'Limpa o histórico de links comprovados (fontes que já reproduziram).';

    public function handle(FontesReproduzidas $reproduzidas): int
    {
        $tipo = $this->tipoPedido();

        if ($tipo === false) {
            return self::FAILURE;
        }

        $tmdbId = $this->inteiro('tmdb');

        if ($tmdbId === false) {
            return self::FAILURE;
        }

        $temporada = $this->inteiro('temporada');
        $episodio = $this->inteiro('episodio');

        if ($temporada === false || $episodio === false) {
            return self::FAILURE;
        }

        if (($temporada !== null || $episodio !== null) && $tmdbId === null) {
            $this->error('Informe o título com --tmdb= para limpar um episódio.');

            return self::FAILURE;
        }

        if ($episodio !== null && $temporada === null) {
            $this->error('Informe a temporada junto do episódio (--temporada=).');

            return self::FAILURE;
        }

        if ($tipo === TipoMidia::FILME && ($temporada !== null || $episodio !== null)) {
            $this->error('Filme não tem temporada nem episódio. Revise o --tipo=.');

            return self::FAILURE;
        }

        $apagadas = $reproduzidas->limpar($tipo, $tmdbId, $temporada, $episodio);

        $this->info(
            'Histórico de fontes comprovadas: '.$apagadas.' registro(s) apagado(s)'
            .$this->recorte($tipo, $tmdbId, $temporada, $episodio).'.'
        );

        if (! $reproduzidas->habilitado()) {
            $this->warn('O histórico está desligado (FONTES_SALVAS=false); a busca não o usa agora.');
        }

        return self::SUCCESS;
    }

    /**
     * Traduz o `--tipo` no enum — ou `false` quando o valor não existe.
     */
    private function tipoPedido(): TipoMidia|null|false
    {
        $valor = $this->option('tipo');

        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        $tipo = TipoMidia::tryFrom(strtolower(trim((string) $valor)));

        if ($tipo === null) {
            $this->error('Tipo inválido. Use --tipo=filme ou --tipo=serie.');

            return false;
        }

        return $tipo;
    }

    /**
     * Lê uma opção numérica. Devolve `null` quando ela não veio e `false` quando
     * o valor não é um inteiro válido — a distinção evita tratar erro de digitação
     * como "sem filtro".
     */
    private function inteiro(string $opcao): int|null|false
    {
        $valor = $this->option($opcao);

        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        if (! is_numeric($valor) || (int) $valor < 0) {
            $this->error("Valor inválido em --{$opcao}: informe um número.");

            return false;
        }

        return (int) $valor;
    }

    /** O recorte aplicado, para a mensagem final dizer exatamente o que saiu. */
    private function recorte(?TipoMidia $tipo, ?int $tmdbId, ?int $temporada, ?int $episodio): string
    {
        $partes = [];

        if ($tipo !== null) {
            $partes[] = $tipo->value;
        }

        if ($tmdbId !== null) {
            $partes[] = 'tmdb '.$tmdbId;
        }

        if ($temporada !== null) {
            $partes[] = 'T'.$temporada;
        }

        if ($episodio !== null) {
            $partes[] = 'E'.$episodio;
        }

        return $partes === [] ? ' (histórico inteiro)' : ' ('.implode(' · ', $partes).')';
    }
}
