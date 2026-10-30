<?php

namespace App\Models\Concerns;

/**
 * A régua de validade de uma prova de fonte.
 *
 * A mesma pergunta vale para o título e para o episódio — "esta prova ainda
 * vale?" —, e a resposta precisa ser a mesma nos dois lugares: o selo do card,
 * o selo do episódio e a fila da rotina não podem divergir sobre quando uma
 * prova venceu.
 *
 * O prazo depende da marca, e não do tipo de mídia: quem pediu nova chance
 * (`deve_retentar`) volta na virada do dia, e o veredito definitivo só é
 * reexaminado depois da janela longa. O motivo está em
 * [`Disponibilidade::horasDeValidade()`].
 */
trait ProvaDeFonte
{
    /**
     * A prova ainda vale?
     *
     * Sem data de verificação, a linha nunca é considerada válida — é o caso de
     * um registro criado e nunca preenchido.
     */
    public function expirado(): bool
    {
        if ($this->verificado_em === null) {
            return true;
        }

        return $this->verificado_em->lte(now()->subHours($this->horasDeValidade()));
    }

    /**
     * Quantas horas a prova vale antes de a linha voltar à fila.
     *
     * A marca decide a janela: curta para quem pediu nova chance, longa para o
     * veredito definitivo. Um "sem fonte" que perguntou a todos os provedores
     * não muda de um dia para o outro, e insistir nele todo dia só gastaria o
     * ritmo furtivo da rotina com o que o acervo já respondeu.
     */
    private function horasDeValidade(): int
    {
        if ($this->deve_retentar) {
            return (int) config('services.verificacao.validade_horas', 24);
        }

        return $this->janelaLongaEmDias() * 24;
    }

    /**
     * Janela do veredito definitivo, em dias — mais curta para série.
     *
     * Fonte de episódio envelhece muito mais rápido: o pack sai do ar, o CDN
     * troca o token, a temporada nova muda a lista. Uma prova de episódio com
     * sete dias de validade esconde exatamente o que o usuário percebe primeiro
     * (o E04 não abre mais). Para filme, a janela longa continua valendo.
     *
     * O episódio não tem coluna `tipo` — é série por definição.
     */
    private function janelaLongaEmDias(): int
    {
        $ehSerie = ! array_key_exists('tipo', $this->getAttributes())
            || ($this->getAttributes()['tipo'] ?? null) === 'serie';

        if ($ehSerie) {
            return (int) config('services.verificacao.validade_dias_serie', 2);
        }

        return (int) config('services.verificacao.validade_dias_definitivo', 7);
    }
}
