<?php

namespace Tests\Unit;

use App\Services\Torrents\NormalizaFonte;
use PHPUnit\Framework\TestCase;

/**
 * O campo de idioma do provedor e a tag do título discordam em um caso concreto:
 * o indexador diz "Portuguese" e o nome do release diz "PORTUGUESE.SUBtitles".
 * O campo fala do release inteiro; o título, da legenda.
 */
class NormalizaFonteTest extends TestCase
{
    private function normalizador(): object
    {
        return new class
        {
            use NormalizaFonte;

            /** @param array<string, mixed> $dados */
            public function montar(array $dados): array
            {
                return $this->montarFonte($dados, 'teste', 'Teste');
            }
        };
    }

    private const HASH = '1a2e148a240726281bd224be858c09176b9abbec';

    /** @param array<string, mixed> $dados */
    private function fonte(array $dados, string $titulo): array
    {
        return $this->normalizador()->montar([
            'id' => self::HASH,
            'titulo' => $titulo,
            'magnet' => 'magnet:?xt=urn:btih:'.self::HASH,
            'seeds' => 3,
            'peers' => 5,
            ...$dados,
        ]);
    }

    /**
     * O caso que motiva a regra: o indexador preenche o idioma do release e o
     * título anuncia legenda em português. Sem a ressalva, a fonte entrava na
     * lista como dublada e o player entregava áudio original.
     */
    public function test_campo_portuguese_com_titulo_de_legenda_vira_legendado(): void
    {
        $fonte = $this->fonte(
            ['idioma' => 'Portuguese'],
            'Desperate.Housewives.S06E13.[ON/OFF].PORTUGUESE.SUBtitles.iCAPx3'
        );

        $this->assertSame('legendado', $fonte['idioma']);
        $this->assertSame('Legendado', $fonte['idioma_rotulo']);
    }

    /** O título com marca explícita de dublagem continua vencendo o campo. */
    public function test_campo_portuguese_com_titulo_dublado_continua_dublado(): void
    {
        $fonte = $this->fonte(['idioma' => 'Portuguese'], 'Filme Dublado 1080p');

        $this->assertSame('pt-BR', $fonte['idioma']);
    }

    /** Sem marca no título, o campo do indexador manda — como antes. */
    public function test_campo_portuguese_com_titulo_sem_marca_segue_o_campo(): void
    {
        $fonte = $this->fonte(['idioma' => 'Portuguese'], 'Some.Movie.2024.1080p.WEB-DL');

        $this->assertSame('pt-BR', $fonte['idioma']);
    }

    /** Sem campo, a dedução sai do título, como sempre foi. */
    public function test_sem_campo_a_deducao_sai_do_titulo(): void
    {
        $this->assertSame('pt-BR', $this->fonte([], 'Filme Dublado 1080p')['idioma']);
        $this->assertSame('legendado', $this->fonte([], 'Filme Legendado 1080p')['idioma']);
        $this->assertSame('original', $this->fonte([], 'Generic Movie 1080p')['idioma']);
    }
}
