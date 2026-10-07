<?php

namespace Tests\Unit;

use App\Services\Torrents\ConversorLegenda;
use PHPUnit\Framework\TestCase;

/**
 * Trava a conversão SRT → WebVTT.
 *
 * O navegador não lê SRT num `<track>`; sem esta conversão a legenda do fallback
 * simplesmente não apareceria. Os casos cobrem o essencial: a marca de tempo com
 * vírgula vira ponto, o contador de bloco some e o texto da legenda é preservado.
 */
class ConversorLegendaTest extends TestCase
{
    public function test_converte_um_bloco_srt(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:04,000\nOlá, mundo\n\n2\n00:00:05,500 --> 00:00:07,000\nSegunda linha\n";

        $vtt = ConversorLegenda::srtParaVtt($srt);

        $this->assertStringStartsWith("WEBVTT\n\n", $vtt);
        $this->assertStringContainsString('00:00:01.000 --> 00:00:04.000', $vtt);
        $this->assertStringContainsString('00:00:05.500 --> 00:00:07.000', $vtt);
        $this->assertStringContainsString('Olá, mundo', $vtt);
        // O contador do bloco (`1`, `2`) não existe no WebVTT.
        $this->assertStringNotContainsString("\n1\n00:00:01", $vtt);
    }

    public function test_normaliza_bom_e_crlf(): void
    {
        $srt = "\xEF\xBB\xBF1\r\n00:00:02,000 --> 00:00:03,000\r\nTexto\r\n";

        $vtt = ConversorLegenda::srtParaVtt($srt);

        $this->assertStringStartsWith('WEBVTT', $vtt);
        $this->assertStringNotContainsString("\r", $vtt);
        $this->assertStringContainsString('00:00:02.000 --> 00:00:03.000', $vtt);
    }

    public function test_conteudo_que_ja_e_vtt_e_preservado(): void
    {
        $vtt = "WEBVTT\n\n00:00:01.000 --> 00:00:02.000\nOi\n";

        $this->assertSame($vtt, ConversorLegenda::srtParaVtt($vtt));
    }

    public function test_virgula_no_texto_da_legenda_nao_e_tocada(): void
    {
        $srt = "1\n00:00:01,000 --> 00:00:02,000\nOlá, mundo; tudo bem?\n";

        $vtt = ConversorLegenda::srtParaVtt($srt);

        $this->assertStringContainsString('Olá, mundo; tudo bem?', $vtt);
    }
}
