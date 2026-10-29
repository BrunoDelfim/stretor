<?php

namespace App\Services\Verificacao;

use App\Enums\TipoMidia;
use App\Models\Disponibilidade;

/**
 * Selo de verificação dos cards da Home.
 *
 * A lista da Home pode vir de dois lugares: da página verificada (só o que tem
 * fonte provada) ou do TMDB cru, quando a rodada ainda não chegou naquela
 * página. Nos dois casos o card precisa dizer a mesma coisa — se aquele título
 * **já passou pelo agendador e continua válido** —, e essa resposta só existe no
 * banco, em `disponibilidades`.
 *
 * "Válido" é `tem_fonte` verdadeiro **e** prova dentro do prazo de
 * [`Disponibilidade::expirado()`] — e o prazo é o mesmo que a rotina usa, não um
 * número inventado aqui. Com fonte, a prova vale a janela longa (padrão 7 dias):
 * a rotina não re-testa o que já resolveu, então é esse o tempo em que a prova
 * continua de pé. Um título com fonte provada há três dias segue verde; um com
 * prova além da janela, um veredito "sem fonte" e um título nunca testado
 * aparecem como não verificados.
 *
 * A consulta é em lote, uma por tipo (filme e série): o mesmo id numérico pode
 * existir nos dois catálogos do TMDB, então a chave do mapa é `tipo:id`, a mesma
 * usada no índice de classificação.
 */
class SeloVerificacao
{
    /**
     * Acrescenta `verificado` e `verificado_em` a cada item, preservando o resto.
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<int, array<string, mixed>>
     */
    public function marcar(array $itens): array
    {
        if ($itens === []) {
            return $itens;
        }

        $provados = $this->provados($itens);

        return array_map(function (array $item) use ($provados): array {
            $chave = $this->chaveDo($item);
            $prova = $chave !== null ? ($provados[$chave] ?? null) : null;

            $item['verificado'] = $prova !== null;
            $item['verificado_em'] = $prova?->verificado_em?->toIso8601String();

            return $item;
        }, $itens);
    }

    /**
     * As provas ainda válidas dos itens, indexadas por "tipo:id".
     *
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<string, Disponibilidade>
     */
    private function provados(array $itens): array
    {
        $idsPorTipo = [];

        foreach ($itens as $item) {
            $chave = $this->chaveDo($item);

            if ($chave === null) {
                continue;
            }

            [$tipo, $id] = explode(':', $chave, 2);
            $idsPorTipo[$tipo][] = (int) $id;
        }

        $provados = [];

        foreach ($idsPorTipo as $tipo => $ids) {
            $linhas = Disponibilidade::query()
                ->where('tipo', $tipo)
                ->whereIn('tmdb_id', array_values(array_unique($ids)))
                ->get(['tipo', 'tmdb_id', 'tem_fonte', 'deve_retentar', 'verificado_em']);

            foreach ($linhas as $linha) {
                // `expirado()` já resolve a janela certa para cada marca: a curta
                // de quem pediu nova chance e a longa do veredito definitivo.
                if (! $linha->tem_fonte || $linha->expirado()) {
                    continue;
                }

                $provados[$tipo.':'.$linha->tmdb_id] = $linha;
            }
        }

        return $provados;
    }

    /**
     * A chave "tipo:id" do item, no vocabulário do banco (`filme`/`serie`).
     *
     * Devolve `null` para item sem tipo reconhecido ou sem id — nesses, o selo
     * não tem o que consultar e o card fica como não verificado.
     *
     * @param  array<string, mixed>  $item
     */
    private function chaveDo(array $item): ?string
    {
        $tipo = TipoMidia::doTmdb(isset($item['tipo']) ? (string) $item['tipo'] : null);
        $id = (int) ($item['id'] ?? 0);

        if ($tipo === null || $id <= 0) {
            return null;
        }

        return $tipo->value.':'.$id;
    }
}
