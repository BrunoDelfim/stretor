<?php

namespace App\Console\Commands;

use App\Services\ProwlarrService;
use Illuminate\Console\Command;

/**
 * Deixa o Prowlarr pronto na primeira subida, sem nenhum passo manual.
 *
 * O entrypoint do container do backend chama este comando e aproveita a última
 * linha da saída (PROWLARR_API_KEY=...) para gravar a chave no .env. Assim o
 * degrau 2 (Torznab/Prowlarr) funciona "de fábrica".
 */
class ProvisionarProwlarr extends Command
{
    protected $signature = 'prowlarr:provisionar
                            {--apenas-chave : Apenas descobre a chave da API, sem cadastrar indexadores}';

    protected $description = 'Configura o Prowlarr sozinho: descobre a chave da API e cadastra os indexadores públicos PT-BR, de forma idempotente.';

    public function handle(ProwlarrService $prowlarr): int
    {
        $resultado = $prowlarr->provisionar((bool) $this->option('apenas-chave'));

        foreach ($resultado['mensagens'] as $mensagem) {
            $this->line($mensagem);
        }

        // Esta linha é o "contrato" com o entrypoint: ele a extrai para
        // injetar TORRENTS_TORZNAB_KEY no ambiente do backend. Emitimos mesmo
        // quando nada foi cadastrado, porque a chave sozinha já destrava o
        // degrau 2.
        if ($resultado['chave'] !== null) {
            $this->line('PROWLARR_API_KEY='.$resultado['chave']);
        }

        // Nunca retornamos erro: uma falha de provisionamento não pode
        // impedir o backend de subir (a busca nativa e o YTS continuam valendo).
        return self::SUCCESS;
    }
}
