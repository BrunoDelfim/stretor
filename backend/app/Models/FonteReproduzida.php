<?php

namespace App\Models;

use App\Enums\IdiomaFonte;
use App\Enums\TipoMidia;
use Illuminate\Database\Eloquent\Model;

/**
 * Fonte que já reproduziu inteira neste título.
 *
 * É o outro lado do teste de malha: a malha diz se a fonte *deve* conectar; esta
 * linha diz que ela **conectou e serviu o título até o fim**, nas mãos do
 * usuário. A busca usa essa prova para devolver a fonte comprovada na frente da
 * fila, e o player a tenta antes das que acabaram de ser descobertas — se ela
 * não responder mais, o resto da lista continua ali e a reprodução segue.
 *
 * `reproducoes` é o desempate entre duas fontes comprovadas do mesmo título:
 * quanto mais vezes uma serviu, mais cedo ela entra.
 */
class FonteReproduzida extends Model
{
    protected $table = 'fontes_reproduzidas';

    protected $fillable = [
        'tipo',
        'tmdb_id',
        'temporada',
        'episodio',
        'fonte_id',
        'tipo_fonte',
        'titulo',
        'qualidade',
        'tamanho',
        'idioma',
        'provedor',
        'provedor_rotulo',
        'seeds',
        'peers',
        'magnet',
        'stream',
        'legendas',
        'reproducoes',
        'confirmada_em',
    ];

    protected $casts = [
        'tipo' => TipoMidia::class,
        'temporada' => 'integer',
        'episodio' => 'integer',
        'seeds' => 'integer',
        'peers' => 'integer',
        'legendas' => 'array',
        'reproducoes' => 'integer',
        'confirmada_em' => 'datetime',
    ];

    /**
     * A fonte de volta no contrato do frontend.
     *
     * Os nomes são os mesmos que os provedores montam de propósito: a fonte
     * comprovada entra na lista como qualquer outra, e nem o player nem a
     * montagem final precisam de um caminho paralelo para entendê-la.
     *
     * @return array<string, mixed>
     */
    public function paraFonte(): array
    {
        $idioma = IdiomaFonte::tryFrom((string) $this->idioma) ?? IdiomaFonte::ORIGINAL;

        return [
            'id' => (string) $this->fonte_id,
            'tipo' => (string) $this->tipo_fonte,
            'titulo' => (string) $this->titulo,
            'qualidade' => $this->qualidade,
            'idioma' => $idioma->value,
            'idioma_rotulo' => $idioma->rotulo(),
            'tamanho' => $this->tamanho,
            'seeds' => (int) $this->seeds,
            'peers' => (int) $this->peers,
            'magnet' => (string) $this->magnet,
            'stream' => (string) $this->stream,
            'provedor' => (string) $this->provedor,
            'provedor_rotulo' => (string) $this->provedor_rotulo,
            'legendas' => $this->legendas ?? [],
        ];
    }
}
