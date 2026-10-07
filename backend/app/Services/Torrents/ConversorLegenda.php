<?php

namespace App\Services\Torrents;

/**
 * Conversor de legenda SRT para WebVTT.
 *
 * As fontes de legenda publicam `.srt`, mas o navegador só aceita WebVTT no
 * `<track>` — SRT simplesmente não é lido pela tag, e a legenda nunca aparece.
 * Como o frontend recebe a legenda do nosso backend já convertida, a conversão
 * mora aqui: é uma tradução de formato, não regra de negócio.
 *
 * A diferença prática entre os dois formatos é mínima e este conversor só mexe
 * no necessário:
 *
 * 1. uma linha `WEBVTT` encabeça o arquivo;
 * 2. o número do bloco (a linha solitária com o contador) some — o VTT usa o
 *    próprio horário como separador;
 * 3. a vírgula da marca de tempo (`00:00:01,000`) vira ponto (`00:00:01.000`),
 *    que é o separador decimal exigido pelo WebVTT.
 *
 * O texto da legenda é preservado byte a byte: converter o conteúdo (tags de
 * estilo, acentuação) só traria risco de estragar a legenda.
 */
final class ConversorLegenda
{
    /**
     * Converte um conteúdo SRT em WebVTT.
     *
     * Devolve a mesma string quando ela já é WebVTT — o `WEBVTT` na primeira
     * linha é a assinatura do formato —, para o chamador poder alimentar o
     * conversor sem se preocupar com a origem.
     */
    public static function srtParaVtt(string $conteudo): string
    {
        // O BOM (quando existe) atrapalha o reconhecimento de blocos e do
        // cabeçalho, e as quebras precisam ser normalizadas para `\n`.
        $conteudo = ltrim($conteudo, "\xEF\xBB\xBF");
        $conteudo = str_replace(["\r\n", "\r"], "\n", $conteudo);

        if (str_starts_with(ltrim($conteudo), 'WEBVTT')) {
            return $conteudo;
        }

        $blocos = preg_split('/\n{2,}/', trim($conteudo)) ?: [];
        $saida = ['WEBVTT', ''];

        foreach ($blocos as $bloco) {
            $linhas = explode("\n", trim($bloco));

            // O contador do bloco (`1`, `2`, ...) não existe no WebVTT.
            if ($linhas !== [] && ctype_digit(trim($linhas[0]))) {
                array_shift($linhas);
            }

            // Um bloco sem marca de tempo (sobra de parágrafo) é descartado.
            if ($linhas === [] || ! str_contains($linhas[0], '-->')) {
                continue;
            }

            $tempo = preg_replace('/(\d),(\d)/', '$1.$2', trim($linhas[0]));

            $saida[] = $tempo;

            foreach (array_slice($linhas, 1) as $linha) {
                $saida[] = $linha;
            }

            $saida[] = '';
        }

        return implode("\n", $saida);
    }
}
