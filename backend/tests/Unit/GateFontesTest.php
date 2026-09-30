<?php

namespace Tests\Unit;

use App\Services\Torrents\CatalogoProvedores;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Trava o gate de temporada da cascata para o pack compactado.
 *
 * O sintoma que originou estes testes: um pack de série antiga — muitas vezes
 * publicado como "A Série Completa Dublado", sem numeração de temporada no nome —
 * era barrado em `CatalogoProvedores::aproveitaveis()` logo depois de ter sido
 * reconhecido como pacote por `marcarPacks()`, e a busca saía com `na_lista: 0`.
 * O gate agora perdoa o que carrega a marca `pack`, deixando a defesa contra a
 * temporada errada com `TorrentService::packDaTemporadaErrada()`.
 *
 * `aproveitaveis()` é privado e não toca em rede nem em banco: os testes o
 * chamam por reflexão sobre uma instância sem construtor, evitando montar as
 * dependências pesadas do serviço.
 */
class GateFontesTest extends TestCase
{
    /**
     * Invoca o gate privado sobre uma lista de fontes.
     *
     * @param  array<int, array<string, mixed>>  $fontes
     * @return array<int, array<string, mixed>>
     */
    private function aproveitaveis(array $fontes, ?int $temporada, ?int $episodio): array
    {
        $servico = (new ReflectionClass(CatalogoProvedores::class))->newInstanceWithoutConstructor();

        $metodo = new ReflectionMethod(CatalogoProvedores::class, 'aproveitaveis');
        $metodo->setAccessible(true);

        return $metodo->invoke($servico, $fontes, $temporada, $episodio);
    }

    /**
     * O caso do bloqueio: o pack etiquetado que não declara temporada precisa
     * sobreviver ao gate. Sem esta exceção, era ele o `na_lista: 0`.
     */
    public function test_pack_marcado_sem_temporada_declarada_sobrevive_ao_gate(): void
    {
        $fonte = [
            'id' => 'pack_sem_temporada',
            'titulo' => 'American Horror Story - A Série Completa Dublado 1080p',
            'release' => 'American Horror Story - A Série Completa Dublado 1080p',
            'idioma' => 'pt-BR',
            'pack' => true,
        ];

        $resultado = $this->aproveitaveis([$fonte], 1, 1);

        $this->assertCount(1, $resultado, 'O pack etiquetado sem temporada declarada não pode ser barrado pelo gate.');
    }

    /**
     * O controle do teste anterior: a **mesma** fonte sem a marca `pack` é
     * barrada. É isso que prova que a exceção é o que a salvou, e não um furo
     * genérico no gate.
     */
    public function test_mesma_fonte_sem_marca_de_pack_e_barrada(): void
    {
        $fonte = [
            'id' => 'sem_marca',
            'titulo' => 'American Horror Story - A Série Completa Dublado 1080p',
            'release' => 'American Horror Story - A Série Completa Dublado 1080p',
            'idioma' => 'pt-BR',
        ];

        $resultado = $this->aproveitaveis([$fonte], 1, 1);

        $this->assertCount(0, $resultado, 'Sem a marca de pack, o nome sem temporada continua barrado.');
    }

    /**
     * A homônima que não é pacote continua barrada — a exceção do pack não pode
     * reabrir a porta para a temporada avulsa que nada declara ("Freak Show").
     */
    public function test_fonte_sem_pack_e_sem_temporada_continua_barrada(): void
    {
        $fonte = [
            'id' => 'homonima',
            'titulo' => 'American Horror Story Freak Show Dublado 720p',
            'release' => 'American Horror Story Freak Show Dublado 720p',
            'idioma' => 'pt-BR',
        ];

        $resultado = $this->aproveitaveis([$fonte], 1, 1);

        $this->assertCount(0, $resultado, 'A temporada avulsa que não declara número continua barrada.');
    }

    /**
     * O episódio com numeração segue o caminho de sempre e passa intacto.
     */
    public function test_episodio_com_numeracao_passa_pelo_gate(): void
    {
        $fonte = [
            'id' => 'episodio',
            'titulo' => 'American.Horror.Story.S01E01.720p',
            'release' => 'American.Horror.Story.S01E01.720p',
            'idioma' => 'pt-BR',
        ];

        $resultado = $this->aproveitaveis([$fonte], 1, 1);

        $this->assertCount(1, $resultado, 'O episódio da temporada certa passa pelo gate.');
    }

    /**
     * A exceção do pack não afrouxa a temporada errada: um pack que **declara**
     * outra temporada é barrado antes de o gate ser consultado, garantindo que a
     * etiqueta não vire passe livre.
     */
    public function test_pack_marcado_que_declara_temporada_errada_continua_barrado(): void
    {
        $fonte = [
            'id' => 'pack_2a',
            'titulo' => 'American Horror Story 2ª Temporada Dual Áudio 1080p',
            'release' => 'American Horror Story 2ª Temporada Dual Áudio 1080p',
            'idioma' => 'dual',
            'pack' => true,
        ];

        $resultado = $this->aproveitaveis([$fonte], 1, 1);

        $this->assertCount(0, $resultado, 'O pack que declara a temporada errada não pode passar pelo gate.');
    }
}
