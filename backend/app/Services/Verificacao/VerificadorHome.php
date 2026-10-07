<?php

namespace App\Services\Verificacao;

use App\Enums\OrigemVerificacao;
use App\Enums\StatusVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Models\VerificacaoExecucao;
use App\Models\VerificacaoHome;
use App\Services\TmdbService;
use Throwable;

/**
 * Rotina que verifica, página a página, o que a Home pode exibir.
 *
 * O trabalho é sempre o mesmo: pega a página de tendências do dia no TMDB, testa
 * os títulos que ainda não têm fonte e grava a lista verificada (só o que tem
 * fonte, na ordem do TMDB) em `verificacoes_home`. Enquanto isso, um ritmo furtivo
 * — uma requisição por vez, com pausa entre itens e entre páginas — tira a carga
 * das costas dos trackers.
 *
 * A rotina é idempotente no ponto que importa: título que já tem fonte é pulado,
 * então rodar de novo no mesmo dia não gera requisição nova para o que já está
 * resolvido. Só o que segue sem fonte volta à fila, e apenas depois que a prova
 * vence.
 */
class VerificadorHome
{
    public function __construct(
        private readonly TmdbService $tmdb,
        private readonly VerificadorFontes $verificador,
    ) {
    }

    /**
     * Percorre as páginas e verifica o que falta.
     *
     * @param  callable(string):void|null  $log  Recebe linhas de acompanhamento.
     * @param  int|null  $limitePaginas  Teto de páginas; em branco usa a config.
     * @param  int|null  $limiteTitulos  Teto de títulos a testar nesta rodada.
     * @param  bool  $pausas  Desligar só faz sentido em teste.
     */
    public function executar(
        ?callable $log = null,
        ?int $limitePaginas = null,
        ?int $limiteTitulos = null,
        bool $pausas = true,
    ): VerificacaoExecucao {
        $registrar = $log ?? static function (string $linha): void {
            // Sem observador, a rotina segue em silêncio.
        };

        $paginas = max(1, $limitePaginas ?? (int) config('services.verificacao.paginas', 25));

        $execucao = VerificacaoExecucao::create([
            'origem' => OrigemVerificacao::COMANDO->value,
            'status' => VerificacaoExecucao::STATUS_EM_ANDAMENTO,
            'iniciada_em' => now(),
        ]);

        $testados = 0;
        $achados = 0;
        $paginasProcessadas = 0;

        try {
            for ($pagina = 1; $pagina <= $paginas; $pagina++) {
                if ($limiteTitulos !== null && $testados >= $limiteTitulos) {
                    $registrar("Limite de {$limiteTitulos} títulos atingido — encerrando.");
                    break;
                }

                $resultado = $this->tmdb->tendenciasDoDia($pagina);
                $itens = $resultado['resultados'];

                // Página vazia é fim de catálogo: não há o que verificar daqui
                // para frente, então encerramos em vez de varrer o vazio.
                if ($itens === []) {
                    $registrar("Página {$pagina}: vazia — fim do catálogo.");
                    break;
                }

                $registrar(sprintf('Página %d: %d itens no TMDB.', $pagina, count($itens)));

                $pendentes = $this->itensPendentes($itens);
                $completa = true;

                // O teto de títulos pode cortar esta página no meio. Quando corta,
                // ela é processada só em parte e **não** pode ser marcada como
                // pronta: a Home continua servindo o TMDB cru até uma rodada
                // inteira passar por ela. Sem este cuidado, um teste com `--limite`
                // fecharia a página com dois itens e esconderia os outros dezessete.
                if ($limiteTitulos !== null && count($pendentes) > max(0, $limiteTitulos - $testados)) {
                    $pendentes = array_slice($pendentes, 0, max(0, $limiteTitulos - $testados));
                    $completa = false;
                }

                if ($completa) {
                    $this->marcarEmAndamento($pagina);
                }

                $itensComFonte = $this->verificarLista($pendentes, $testados, $achados, $registrar, $pausas, $itens);

                if ($completa) {
                    $this->persistirPagina($pagina, $itensComFonte, count($itens), (int) $resultado['total_paginas']);
                    $paginasProcessadas++;
                    $registrar(sprintf('Página %d: %d itens com fonte.', $pagina, count($itensComFonte)));
                } else {
                    $registrar(sprintf('Página %d: parcial — teto de títulos atingido; estado preservado.', $pagina));
                }

                if ($pausas && $pagina < $paginas) {
                    $this->dormir((int) config('services.verificacao.pausa_paginas_ms', 45000), amplo: true);
                }
            }

            $execucao->fill([
                'status' => VerificacaoExecucao::STATUS_OK,
                'finalizada_em' => now(),
                'paginas_processadas' => $paginasProcessadas,
                'titulos_testados' => $testados,
                'titulos_com_fonte' => $achados,
            ])->save();
        } catch (Throwable $excecao) {
            $execucao->fill([
                'status' => VerificacaoExecucao::STATUS_FALHA,
                'finalizada_em' => now(),
                'paginas_processadas' => $paginasProcessadas,
                'titulos_testados' => $testados,
                'titulos_com_fonte' => $achados,
                'detalhe' => mb_substr($excecao->getMessage(), 0, 1000),
            ])->save();

            throw $excecao;
        }

        return $execucao;
    }

    /**
     * Os itens da página que ainda precisam ser testados.
     *
     * O corte é feito **antes** de qualquer requisição: título com fonte já
     * provada nunca entra na lista, e título sem fonte só volta depois de a prova
     * vencer. Assim o orçamento de títulos vale exato e o ritmo furtivo não é
     * gasto à toa com o que já está resolvido.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<int, array<string, mixed>>
     */
    private function itensPendentes(array $itens): array
    {
        $pendentes = [];

        foreach ($itens as $item) {
            if ($this->precisaVerificar($this->buscarDisponibilidade($item))) {
                $pendentes[] = $item;
            }
        }

        return $pendentes;
    }

    /**
     * Testa uma lista de itens e devolve a página verificada.
     *
     * @param  array<int, array<string, mixed>>  $lista  A testar nesta rodada.
     * @param  array<int, array<string, mixed>>  $itens  A página inteira, para remontar a saída.
     * @return array<int, array<string, mixed>>  Itens com fonte, na ordem do TMDB.
     */
    private function verificarLista(
        array $lista,
        int &$testados,
        int &$achados,
        callable $registrar,
        bool $pausas,
        array $itens,
    ): array {
        foreach ($lista as $item) {
            try {
                $disponibilidade = $this->verificador->verificar($item, OrigemVerificacao::COMANDO);
            } catch (Throwable $excecao) {
                // Um título problemático não pode derrubar a rodada inteira: a
                // página é grande e perder as outras custa mais do que pular uma.
                // O erro fica no log para investigação.
                $registrar(sprintf('  ! %s — falha: %s', $item['titulo'] ?? '?', $excecao->getMessage()));
                $testados++;

                continue;
            }

            $testados++;

            if ($disponibilidade->tem_fonte) {
                $achados++;
                $registrar(sprintf(
                    '  ✓ %s — fonte: %s',
                    $item['titulo'] ?? '?',
                    $disponibilidade->fonte_idioma ?? '—',
                ));
            } elseif ($disponibilidade->inconclusivo) {
                // Nem fonte, nem veredito: a busca não teve como perguntar a
                // todos. O título volta à fila já na próxima rodada.
                $registrar(sprintf('  … %s — inconclusivo (re-tenta)', $item['titulo'] ?? '?'));
            } else {
                $registrar(sprintf('  · %s — sem fonte', $item['titulo'] ?? '?'));
            }

            if ($pausas) {
                $this->dormir((int) config('services.verificacao.pausa_itens_ms', 6000));
            }
        }

        // A lista final relê o índice: pode conter itens verificados agora e itens
        // que já tinham fonte de rodadas anteriores — os dois entram na Home.
        $comFonte = [];

        foreach ($itens as $item) {
            if ($this->buscarDisponibilidade($item)?->tem_fonte === true) {
                $comFonte[] = $item;
            }
        }

        return $comFonte;
    }

    /**
     * Este título precisa ser testado hoje?
     *
     * Título com fonte já provada **nunca** é re-testado pela rotina — não faz
     * sentido gastar requisição com o que já está resolvido. Título sem fonte só
     * volta à fila depois que a prova vence, porque o acervo dos trackers muda
     * com o tempo e um item que estava órfão pode reaparecer.
     *
     * A exceção é o veredito **inconclusivo**: ele não é "sem fonte" e sim "não
     * deu para concluir" — a busca rodou sem como perguntar a todos. Esse título
     * volta à fila **já**, sem esperar a validade, porque a tentativa que o
     * marcou não valeu como prova.
     */
    private function precisaVerificar(?Disponibilidade $disponibilidade): bool
    {
        if ($disponibilidade === null) {
            return true;
        }

        if ($disponibilidade->tem_fonte) {
            return false;
        }

        if ($disponibilidade->inconclusivo) {
            return true;
        }

        return $disponibilidade->expirado();
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function buscarDisponibilidade(array $item): ?Disponibilidade
    {
        $tipo = TipoMidia::doTmdb($item['tipo'] ?? null);
        $tmdbId = (int) ($item['id'] ?? 0);

        if ($tipo === null || $tmdbId === 0) {
            return null;
        }

        return Disponibilidade::query()
            ->where('tipo', $tipo->value)
            ->where('tmdb_id', $tmdbId)
            ->first();
    }

    /** Marca a página como "em processamento", sem tocar na lista já gravada. */
    private function marcarEmAndamento(int $pagina): void
    {
        VerificacaoHome::updateOrCreate(
            ['pagina' => $pagina],
            ['status' => StatusVerificacao::EM_ANDAMENTO->value],
        );
    }

    /**
     * Grava a lista verificada da página e a marca como pronta.
     *
     * @param  array<int, array<string, mixed>>  $itens  Na ordem de tendência do TMDB.
     */
    private function persistirPagina(int $pagina, array $itens, int $itensTmdb, int $totalPaginas): void
    {
        VerificacaoHome::updateOrCreate(
            ['pagina' => $pagina],
            [
                'status' => StatusVerificacao::OK->value,
                'itens' => $itens,
                'itens_tmdb' => $itensTmdb,
                'total_paginas' => $totalPaginas,
                'verificada_em' => now(),
            ],
        );
    }

    /**
     * Dorme a pausa de furtividade, com jitter.
     *
     * A pausa não é só espera: é o que separa tráfego humano de um script. E o
     * jitter existe porque um compasso exato — "espera N, pede, espera N" — é tão
     * delator quanto a frequência alta. O intervalo varia a cada iteração para o
     * rastro não ter assinatura.
     */
    private function dormir(int $baseMs, bool $amplo = false): void
    {
        if ($baseMs <= 0) {
            return;
        }

        // Jitter amplo (entre páginas) balança mais: são as paradas maiores, onde
        // a regularidade mais chamaria atenção.
        $teto = $amplo ? 40 : 60;
        $jitter = (int) round($baseMs * (random_int(0, $teto) / 100));

        usleep(($baseMs + $jitter) * 1000);
    }
}
