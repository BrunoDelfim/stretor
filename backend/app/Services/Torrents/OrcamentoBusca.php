<?php

namespace App\Services\Torrents;

/**
 * Orçamento de tempo compartilhado por uma busca de torrents.
 *
 * A cascata tem vários provedores, cada um com o seu próprio teto de requisição,
 * mas a **soma** deles não tinha limite: um tracker bloqueado gasta o
 * `tempo_limite` na tentativa direta e mais o `proxy_nativo_timeout` no
 * FlareSolverr, e isso se repete por termo. Com dez termos de episódio e quatro
 * provedores por nome, o pior caso passa de vários minutos — muito acima dos 60 s
 * que o frontend espera, e a requisição é cancelada antes de a lista chegar.
 *
 * Este objeto é o relógio único da busca. Ele é registrado como singleton no
 * container, então o [`CatalogoProvedores`] e o [`ClienteHttp`] enxergam o mesmo
 * prazo: o catálogo decide quando parar de perguntar, e o cliente HTTP encurta o
 * socorro pelo FlareSolverr para não começar uma espera de 70 s que já não cabe
 * no orçamento. Sem o compartilhamento, o FlareSolverr continuaria estourando o
 * tempo sozinho, por mais que a cascata soubesse que o prazo acabou.
 */
class OrcamentoBusca
{
    /**
     * Marca de tempo absoluta em que a busca precisa parar, ou `null` quando
     * nenhuma busca está em curso.
     */
    private ?float $prazo = null;

    /**
     * Abre um novo orçamento, ancorado no instante da chamada.
     *
     * O prazo é ancorado aqui, e não na construção do serviço: o Laravel resolve
     * o container antes de qualquer trabalho, e medir a partir dali descontaria o
     * tempo gasto na autenticação e na montagem da resposta.
     */
    public function abrir(int $segundos): void
    {
        $this->prazo = microtime(true) + max(1, $segundos);
    }

    /**
     * Abre o orçamento só se ainda não houver um em curso.
     *
     * O orçamento é um singleton compartilhado, mas cada canal da busca chamava
     * `abrir()` com o seu próprio valor — e cada chamada **reiniciava** o relógio.
     * O stream direto abria 12 s, fechava, e a cascata de torrents abria 45 s do
     * zero: os dois orçamentos somavam e a busca inteira podia passar de 57 s,
     * estourando o limite do frontend. Com a abertura idempotente, o primeiro
     * canal a chegar ancora o prazo e os demais o respeitam — o relógio passa a
     * ser um só, de verdade.
     *
     * Devolve `true` quando foi esta chamada que abriu o orçamento, para quem
     * abriu saber que também é quem deve fechá-lo.
     */
    public function abrirSeFechado(int $segundos): bool
    {
        if ($this->prazo !== null) {
            return false;
        }

        $this->abrir($segundos);

        return true;
    }

    /** Há uma busca em curso com o relógio de pé? */
    public function emCurso(): bool
    {
        return $this->prazo !== null;
    }

    /**
     * Fecha o orçamento ao fim da busca.
     *
     * Sem isto, uma busca encerrada deixaria o prazo de pé e a próxima — que
     * reabre o orçamento logo no início — herdaria um relógio já vencido se
     * alguma chamada acontecesse antes do `abrir()`.
     */
    public function fechar(): void
    {
        $this->prazo = null;
    }

    /** O orçamento da busca acabou? */
    public function esgotado(): bool
    {
        return $this->prazo !== null && microtime(true) >= $this->prazo;
    }

    /**
     * Quanto sobra do orçamento, em segundos.
     *
     * Devolve `null` quando não há busca em curso — nesse caso quem chama usa o
     * seu próprio teto, porque não há prazo global para respeitar.
     */
    public function restante(): ?int
    {
        if ($this->prazo === null) {
            return null;
        }

        return max(0, (int) ceil($this->prazo - microtime(true)));
    }
}
