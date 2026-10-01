<?php

namespace App\Services\Torrents;

/**
 * Dá aos provedores de `Http::pool` a noção do orçamento global da busca.
 *
 * O [`ClienteHttp`] já encurtava as requisições ao prazo restante, mas só quem
 * passava por ele enxergava o relógio. Os provedores que disparam o próprio
 * `Http::pool` — Knaben, APIBay, BT4G, Torrentio e os addons Stremio — ficavam
 * cegos: cada um abria o pool com o `tempo_limite` cheio (15 s por termo) sem
 * saber que a busca inteira já estava no fim. O resultado era o pior dos dois
 * mundos: o provedor gastava o tempo que restava e, quando voltava, o
 * [`CatalogoProvedores`] já tinha encerrado a rodada — ele consumia o orçamento
 * sem entregar nada.
 *
 * Com este trait, o teto de cada pool passa a ser o **menor** entre o teto
 * próprio do provedor e o que sobra do orçamento. Quando o prazo já venceu, o
 * teto vira zero e o provedor desiste antes de abrir a conexão, devolvendo a
 * vez para quem ainda tem tempo.
 *
 * O trait espera que a classe que o use tenha uma propriedade `$orcamento` do
 * tipo [`OrcamentoBusca`] — injetada pelo container, como os demais serviços.
 */
trait ConsultaComOrcamento
{
    /**
     * Teto de tempo, em segundos, para uma requisição deste provedor.
     *
     * Devolve o menor valor entre o teto pedido e o que resta do orçamento
     * global. Sem busca em curso (`restante()` devolve `null`), vale o teto
     * próprio — não há prazo global para respeitar.
     *
     * Um retorno `0` significa "não há mais tempo": quem chama deve desistir da
     * requisição em vez de abrir uma conexão que já nasce perdida.
     */
    protected function tempoDeConsulta(int $teto): int
    {
        $restante = $this->orcamento->restante();

        if ($restante === null) {
            return $teto;
        }

        return min($teto, $restante);
    }

    /**
     * Diz se ainda há orçamento para começar uma nova consulta.
     *
     * É a leitura direta do relógio, para os laços que percorrem vários termos
     * ou endereços: antes de disparar o próximo, o provedor confere se ainda
     * cabe no prazo. Sem isto, um laço de socorro pelo FlareSolverr continuaria
     * termo a termo mesmo depois de o orçamento ter acabado.
     */
    protected function temOrcamento(): bool
    {
        return ! $this->orcamento->esgotado();
    }

    /**
     * Diz se o que resta do orçamento comporta uma consulta inteira.
     *
     * `temOrcamento()` responde "o prazo ainda não venceu?", mas não diz se dá
     * para **começar** uma requisição. Uma consulta iniciada a 1 s do fim ainda
     * espera o teto cheio (o `tempoDeConsulta()` encolhe para 1 s, mas a conexão
     * já foi aberta) e, quando volta, o prazo venceu e o resultado é descartado —
     * o provedor gastou o que restava sem entregar nada.
     *
     * A leitura aqui é **proporcional**, não aditiva. Exigir o teto cheio mais
     * uma margem fixa quebrava o fallback do stream direto: ele abre 12 s de
     * orçamento e consulta com teto de 10 s, então a conta "10 + 2 = 12" fechava
     * apenas no instante zero — assim que a primeira consulta consumisse 1 s, o
     * restante caía para 11 s e nenhum termo seguinte era tentado, mesmo com
     * orçamento de sobra. O laço parava depois do primeiro termo.
     *
     * O critério correto é: o restante precisa cobrir uma **fração mínima** do
     * teto (`$fracaoMinima`, padrão metade). Assim uma consulta de 10 s só é
     * barrada quando restam menos de 5 s — tempo insuficiente para valer a
     * conexão. A margem deixa de ser um valor somado e passa a ser o piso
     * proporcional que separa "cabe" de "não vale começar".
     *
     * @param  int  $teto  Teto da consulta que se pretende iniciar, em segundos
     * @param  float  $fracaoMinima  Fração mínima do teto que precisa restar (0–1)
     */
    protected function temTempoParaConsulta(int $teto, float $fracaoMinima = 0.5): bool
    {
        $restante = $this->orcamento->restante();

        // Sem busca em curso não há prazo global: o teto próprio do provedor vale.
        if ($restante === null) {
            return true;
        }

        /*
         * O piso é proporcional ao teto, mas nunca menor que 1 s: uma consulta
         * de 10 s exige 5 s restantes; uma de 2 s exige 1 s. O `ceil` garante
         * que a fração não vire zero e libere uma consulta sem tempo nenhum.
         */
        $piso = max(1, (int) ceil($teto * $fracaoMinima));

        return $restante >= $piso;
    }
}
