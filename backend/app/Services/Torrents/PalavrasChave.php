<?php

namespace App\Services\Torrents;

/**
 * Extrai e compara as palavras-chave significativas de um título.
 *
 * É a régua compartilhada da relevância. O stream direto a usa para decidir se
 * uma página é *sobre* o título buscado; a montagem final a usa para descartar
 * releases que nada têm a ver com o que se buscou. Centralizar aqui evita que as
 * duas pontas divirjam na definição de "palavra-chave": no dia em que uma lista
 * de ligação nova entrar, entra para as duas de uma vez.
 *
 * A regra é a mesma dos dois lados: as palavras de ligação ("de", "the") e a
 * numeração do episódio ("S01E01", "1x01") saem, e o que resta é comparado por
 * substring sobre o texto normalizado (minúsculo, sem acento).
 */
final class PalavrasChave
{
    /**
     * Artigos, preposições e conjunções que aparecem em quase todo título e não
     * distinguem nada. A lista cobre o português e o inglês, porque o título
     * original entra como alternativa na busca.
     *
     * @var array<int, string>
     */
    public const LIGACAO = [
        'a', 'o', 'as', 'os', 'de', 'da', 'do', 'das', 'dos', 'e', 'em', 'no',
        'na', 'nos', 'nas', 'um', 'uma', 'uns', 'umas', 'para', 'por', 'com',
        'sem', 'ao', 'aos', 'à', 'às', 'que', 'se', 'the', 'of', 'and', 'in',
        'on', 'at', 'to', 'for', 'with', 'a', 'an', 'is', 'it', 'its', 'by',
        'from', 'as', 'or',
    ];

    /**
     * Normaliza um texto para a comparação por substring.
     *
     * Minúsculas e sem acento, para que "História" case com "historia" — o
     * endereço de um site raramente preserva o acento do título, e a comparação
     * literal perderia a página por causa de um acento.
     */
    public static function normalizar(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');

        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }

    /**
     * Extrai as palavras-chave de um título.
     *
     * O título é quebrado em palavras, cada uma normalizada, e as de ligação e
     * as puramente numéricas saem. A numeração do episódio ("S01E01", "1x01")
     * também sai: ela diz *qual* episódio, não *qual* obra, e exigi-la deixaria
     * de fora o release ou a página que cobre a série inteira.
     *
     * Títulos não-latinos (ex.: um original em chinês ou japonês) não rendem
     * palavra nenhuma — o separador só enxerga `[a-z0-9]` —, e é isso que faz a
     * chamada devolver `[]` quando não há nada a comparar.
     *
     * @return array<int, string>
     */
    public static function extrair(string $titulo): array
    {
        $normalizado = self::normalizar($titulo);

        /*
         * A numeração do episódio sai antes da quebra em palavras. Ela não é
         * puramente numérica — "s01e01" tem letras —, então o filtro de
         * `is_numeric()` não a pegaria, e um nome com "s01e01" passaria como se
         * fosse sobre o título. Os padrões cobrem as mesmas grafias que o
         * gerador de termos usa ("1x01", "S01E01", "Temporada 1 Episódio 1").
         */
        $normalizado = preg_replace([
            '/\b\d{1,2}x\d{1,3}\b/u',
            '/\bs\d{1,2}[\s._-]*e\d{1,3}\b/u',
            '/\btemporada\s*\d{1,2}\s*episodio\s*\d{1,3}\b/u',
            '/\b\d{1,2}a\s*temporada\s*episodio\s*\d{1,3}\b/u',
        ], ' ', $normalizado) ?? $normalizado;

        $brutas = preg_split('/[^a-z0-9]+/', $normalizado) ?: [];

        $palavras = [];

        foreach ($brutas as $bruta) {
            if ($bruta === '' || is_numeric($bruta)) {
                continue;
            }

            if (in_array($bruta, self::LIGACAO, true)) {
                continue;
            }

            // Palavras de uma letra não distinguem nada e casariam com qualquer
            // endereço; o mesmo vale para a numeração de episódio já removida.
            if (strlen($bruta) < 2) {
                continue;
            }

            $palavras[$bruta] = true;
        }

        return array_keys($palavras);
    }

    /**
     * Quantas das palavras-chave aparecem no texto normalizado.
     *
     * A comparação é por substring sobre o texto sem acento: "historia" casa com
     * "historia-sem-fim", que é como os nomes de release e os endereços chegam.
     *
     * @param  array<int, string>  $palavras
     */
    public static function presentes(string $texto, array $palavras): int
    {
        $alvo = self::normalizar($texto);

        $encontradas = 0;

        foreach ($palavras as $palavra) {
            if (str_contains($alvo, $palavra)) {
                $encontradas++;
            }
        }

        return $encontradas;
    }

    /**
     * Une as palavras-chave de vários títulos, sem repetir.
     *
     * A montagem julga o release contra o traduzido **e** o original ao mesmo
     * tempo: o acervo nacional publica por um nome, o internacional pelo outro, e
     * os dois valem como pista de relevância.
     *
     * @param  array<int, string|null>  $titulos
     * @return array<int, string>
     */
    public static function deTitulos(array $titulos): array
    {
        $palavras = [];

        foreach ($titulos as $titulo) {
            foreach (self::extrair((string) $titulo) as $palavra) {
                $palavras[$palavra] = true;
            }
        }

        return array_keys($palavras);
    }
}
