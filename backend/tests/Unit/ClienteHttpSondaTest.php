<?php

namespace Tests\Unit;

use App\Services\Torrents\ClienteHttp;
use App\Services\Torrents\OrcamentoBusca;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Trava a régua das sondas de arquivo.
 *
 * O caso que abriu esta classe foi "Ponto Sem Retorno": o VOD por id entregou
 * `nixplay.lat/movie/testelogado-vods/GwXanZ3Dj/1384216.mp4`, o host respondeu
 * `401 Credenciais inválidas.` e a sonda de cabeçalho aprovou — o status não
 * estava em nenhuma lista. A fonte virou "verificada" na Home e o player mostrou
 * "as fontes conectaram, mas não enviaram dados".
 *
 * A régua tem dois degraus: o status que prova arquivo morto e a leitura de bytes
 * que prova mídia. Um `401` morre no primeiro; um `200 text/html` morre no
 * segundo.
 */
class ClienteHttpSondaTest extends TestCase
{
    private function cliente(): ClienteHttp
    {
        return new ClienteHttp(new OrcamentoBusca());
    }

    /** `401` é recusa de credencial: nenhum player transforma isso em vídeo. */
    public function test_credencial_recusada_e_arquivo_morto(): void
    {
        Http::fake(['*' => Http::response('Credenciais inválidas.', 401)]);

        $this->assertFalse($this->cliente()->arquivoServido('https://nixplay.lat/movie/testelogado-vods/x/1384216.mp4'));
        $this->assertFalse($this->cliente()->pareceVideoServido('https://nixplay.lat/movie/testelogado-vods/x/1384216.mp4'));
    }

    /** Um `404` nunca foi fonte, mesmo antes da leitura de bytes. */
    public function test_arquivo_inexistente_e_recusado(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);

        $this->assertFalse($this->cliente()->arquivoServido('https://host.test/filme.mp4'));
    }

    /** Página de erro com `200` não é vídeo, mesmo que o cabeçalho não diga nada. */
    public function test_pagina_html_com_200_nao_e_video(): void
    {
        Http::fake([
            '*' => Http::response('<html><body>erro</body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        ]);

        $this->assertFalse($this->cliente()->pareceVideoServido('https://host.test/filme.mp4'));
    }

    /** MP4 de verdade: `ftyp` no offset 4, o mesmo que o `ffprobe` espera. */
    public function test_mp4_com_ftyp_e_video(): void
    {
        Http::fake([
            '*' => Http::response("\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom", 200, ['Content-Type' => 'video/mp4']),
        ]);

        $this->assertTrue($this->cliente()->pareceVideoServido('https://host.test/filme.mp4'));
    }

    /** Playlist HLS também é mídia: o player lê o `#EXTM3U` e segue os segmentos. */
    public function test_playlist_hls_e_video(): void
    {
        Http::fake([
            '*' => Http::response("#EXTM3U\n#EXT-X-VERSION:3\n", 200, ['Content-Type' => 'application/vnd.apple.mpegurl']),
        ]);

        $this->assertTrue($this->cliente()->pareceVideoServido('https://host.test/filme.m3u8'));
    }
}
