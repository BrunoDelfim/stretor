<?php

namespace App\Contracts;

/**
 * Provedor que aceita vários termos numa única rodada.
 *
 * A cascata pergunta termo a termo — é assim que ela mede o acumulado e decide
 * quando parar. Só que alguns provedores pagam uma ida e volta de rede por termo,
 * e a soma disso é o que estoura o tempo que o frontend espera: o Knaben, por
 * exemplo, fazia oito POSTs em série e gastava dez segundos só nele.
 *
 * Quem implementa este contrato declara que sabe buscar vários termos de uma vez,
 * disparando as requisições em paralelo. O catálogo continua dono da decisão de
 * quando parar: ele entrega o lote, recebe tudo de volta e só então contabiliza.
 * O provedor não decide nada sobre a cascata — apenas troca a espera sequencial
 * pela espera da resposta mais lenta.
 */
interface ProvedorPorLote
{
    /**
     * Busca vários termos de uma vez, devolvendo as fontes de todos eles.
     *
     * O retorno é a lista já mesclada e sem repetição, no mesmo contrato de
     * [`ProvedorTorrents::buscar()`]. Termo que falhar simplesmente não contribui;
     * um provedor só é considerado fora do ar quando nenhum termo respondeu.
     *
     * @param  array<int, string>  $termos
     * @return array<int, array<string, mixed>>
     */
    public function buscarVarios(
        array $termos,
        ?int $ano = null,
        ?string $imdbId = null,
        ?int $temporada = null,
        ?int $episodio = null,
    ): array;
}
