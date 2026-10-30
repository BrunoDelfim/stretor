<?php

namespace App\Services\Verificacao;

use App\Enums\OrigemVerificacao;
use App\Enums\TipoMidia;
use App\Models\Disponibilidade;
use App\Models\DisponibilidadeEpisodio;

/**
 * A prova mais forte que existe: o vídeo tocou.
 *
 * A verificação pergunta aos provedores; o playback responde de fato. Quando o
 * vídeo começa, não há mais o que provar — e o episódio deixa de gastar uma
 * busca no worker. Quando o player falha em algo que estava verde, o selo
 * precisa cair na hora: o usuário acabou de ver que não abre, e deixá-lo verde
 * até a próxima rodada é o tipo de mentira que faz o sistema parecer quebrado.
 *
 * A volta é automática: a próxima tentativa de assistir grava a prova de novo, e
 * a rotina diária revisita o que ficou sem fonte na janela curta da série.
 */
class ProvaDePlayback
{
    /**
     * Registra o desfecho de uma reprodução.
     *
     * @param  int  $tmdbId  id do título no TMDB
     * @param  int|null  $temporada  temporada do episódio (série)
     * @param  int|null  $episodio  episódio aberto (série)
     * @param  bool  $ok  `true` o vídeo começou; `false` falhou antes de tocar
     */
    public function registrar(int $tmdbId, ?int $temporada, ?int $episodio, bool $ok): void
    {
        $colunas = [
            'tem_fonte' => $ok,
            'inconclusivo' => false,
            // Quem falhou não pede nova chance à rotina: ele só volta quando
            // alguém tentar assistir de novo (que grava outra prova) ou na
            // próxima varredura da janela.
            'deve_retentar' => false,
            'fonte_tipo' => 'reproducao',
            'origem' => OrigemVerificacao::PLAY->value,
            'verificado_em' => now(),
        ];

        if ($temporada !== null && $episodio !== null) {
            DisponibilidadeEpisodio::updateOrCreate(
                [
                    'tmdb_id' => $tmdbId,
                    'temporada' => $temporada,
                    'episodio' => $episodio,
                ],
                $colunas,
            );

            return;
        }

        Disponibilidade::updateOrCreate(
            [
                'tipo' => TipoMidia::FILME->value,
                'tmdb_id' => $tmdbId,
            ],
            $colunas,
        );
    }
}
