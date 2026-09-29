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
}
