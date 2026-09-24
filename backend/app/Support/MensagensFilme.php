<?php

namespace App\Support;

/**
 * Textos de fallback do contrato de filme.
 *
 * O frontend espera sempre uma string nos campos de exibição — nunca null nem
 * vazio. Concentrar esses rótulos aqui evita que a mesma frase seja reescrita
 * em pontos diferentes do serviço e saia de sincronia.
 */
final class MensagensFilme
{
    public const TITULO_INDISPONIVEL = 'Título indisponível';

    public const SINOPSE_INDISPONIVEL = 'Sinopse não disponível para este título.';

    public const GENERO_NAO_INFORMADO = 'Gênero não informado';

    public const NAO_CLASSIFICADA = 'Não classificada';

    /** Quantidade de nomes do elenco exibidos no modal. */
    public const LIMITE_ELENCO = 5;
}
