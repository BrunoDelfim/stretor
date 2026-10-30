<?php

namespace App\Models;

use App\Enums\OrigemVerificacao;
use App\Models\Concerns\ProvaDeFonte;
use Illuminate\Database\Eloquent\Model;

/**
 * Veredito de disponibilidade de um episódio de série.
 *
 * O card da série responde pelo primeiro episódio; o modal responde por cada
 * linha, e é aqui que essa resposta mora. `tem_fonte` é o mesmo portão da linha
 * do título — só episódio com fonte provada ganha o selo verde —, e o resto é o
 * rastro da prova, para a rotina saber o que já resolveu e o que venceu.
 *
 * `inconclusivo` separa "não tem fonte" de "não deu para concluir", como na
 * linha do título: uma busca que não pôde perguntar a todos não é veredito.
 */
class DisponibilidadeEpisodio extends Model
{
    use ProvaDeFonte;

    protected $table = 'disponibilidades_episodios';

    protected $fillable = [
        'tmdb_id',
        'temporada',
        'episodio',
        'tem_fonte',
        'inconclusivo',
        'deve_retentar',
        'fonte_idioma',
        'fonte_tipo',
        'origem',
        'verificado_em',
    ];

    protected $casts = [
        'tmdb_id' => 'integer',
        'temporada' => 'integer',
        'episodio' => 'integer',
        'tem_fonte' => 'boolean',
        'inconclusivo' => 'boolean',
        'deve_retentar' => 'boolean',
        'origem' => OrigemVerificacao::class,
        'verificado_em' => 'datetime',
    ];

    /** Chave "T1E2", usada nos mapas de selo e nos logs. */
    public function chave(): string
    {
        return 'T'.$this->temporada.'E'.$this->episodio;
    }
}
