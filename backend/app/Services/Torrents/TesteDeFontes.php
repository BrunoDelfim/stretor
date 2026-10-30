<?php

namespace App\Services\Torrents;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Confere, na malha real, se uma fonte de torrent ainda conecta.
 *
 * O `seeds` que o provedor informa é estático e envelhece: um release com
 * "12 seeds" pode estar morto há meses. Esta etapa mede a malha de verdade — o
 * media-service abre o magnet, observa peers e tráfego e devolve um veredito —,
 * e só então a fonte é oferecida ao player ou serve de prova na verificação.
 *
 * O caso que originou isto: "Super Psychic Policeman Chojo" aparecia como
 * disponível (o release existia, com legenda anexada), mas os dois torrents
 * tinham 0–1 peers e nunca entregaram metadados — o player ficava tentando
 * conectar e passava para a próxima, ou não tocava nada. A busca achava; a
 * malha não sustentava.
 *
 * ## Fail-open, sempre
 *
 * Só uma resposta **definitiva** reprova. Se o media-service estiver fora do ar,
 * se a URL não estiver configurada, se a requisição estourar o prazo ou voltar
 * malformada, a fonte é **mantida**: não há prova de que ela esteja morta, e
 * descartar por dúvida seria trocar um falso-positivo por um falso-negativo —
 * pior, porque tira do usuário uma fonte que poderia muito bem tocar.
 *
 * A fonte **direta** (MP4/HLS) não entra no teste: ela não tem malha para medir,
 * e quem a valida é ter uma URL que o media-service consiga abrir.
 *
 * ## Custo
 *
 * A medição acontece no fim da busca, quando a lista já está montada e reduzida
 * ao teto de fontes, então no máximo um punhado de magnetes é sondado por vez.
 * O conjunto vai em paralelo e o prazo de cada um é limitado pelo que sobra do
 * orçamento da busca — quando não sobra nada, o teste é dispensado e a lista
 * segue como veio.
 */
class TesteDeFontes
{
    /**
     * Remove da lista as fontes cuja malha não confirmou conexão.
     *
     * @param  array<int, array<string, mixed>>  $fontes  Lista já montada e ordenada
     * @param  int|null  $tempoLimite  Segundos disponíveis (o que sobra do orçamento); `null` usa o teto próprio
     * @return array<int, array<string, mixed>>
     */
    public function filtrar(array $fontes, ?int $tempoLimite = null): array
    {
        if (! config('services.torrents.liveness_fontes', true) || $fontes === []) {
            return array_values($fontes);
        }

        $base = rtrim((string) config('services.media_service.url', ''), '/');

        if ($base === '') {
            return array_values($fontes);
        }

        // Separa o que dá para medir (torrent com magnet) do que não dá. A fonte
        // direta e a que não tem magnet ficam: não há malha para sondar.
        $max = max(1, (int) config('services.torrents.liveness_max_fontes', 4));
        $mantidas = [];
        $pendentes = [];

        foreach ($fontes as $indice => $fonte) {
            $mensuravel = ($fonte['tipo'] ?? 'torrent') !== 'direto' && ($fonte['magnet'] ?? '') !== '';

            if (! $mensuravel || count($pendentes) >= $max) {
                $mantidas[$indice] = $fonte;

                continue;
            }

            $pendentes[$indice] = (string) $fonte['magnet'];
        }

        if ($pendentes === []) {
            return array_values($mantidas);
        }

        $teto = (int) config('services.torrents.liveness_tempo_limite', 9);

        if ($tempoLimite !== null) {
            $teto = min($teto, $tempoLimite);
        }

        // Orçamento esgotado: não há tempo para uma medição confiável, e o certo
        // é servir o que veio em vez de arriscar um corte por pressa.
        if ($teto <= 0) {
            return array_values($fontes);
        }

        $esperaMs = (int) config('services.torrents.liveness_espera_ms', 6000);
        // A espera pedida ao media-service fica abaixo do tempo limite do HTTP
        // para que ele responda primeiro, como na inspeção de pack.
        $esperaMs = max(1000, min($esperaMs, ($teto - 1) * 1000));
        $conexao = max(1, min(3, $teto));

        try {
            $respostas = Http::pool(fn ($pool) => array_map(
                fn (int $indice) => $pool->as((string) $indice)
                    ->connectTimeout($conexao)
                    ->timeout($teto)
                    ->acceptJson()
                    ->post($base.'/api/media/verificar', [
                        'magnet' => $pendentes[$indice],
                        'espera_ms' => $esperaMs,
                    ]),
                array_keys($pendentes)
            ));
        } catch (\Throwable $excecao) {
            // Falha no pool inteiro (media-service fora do ar): sem prova, a
            // lista fica como está.
            report($excecao);

            return array_values($fontes);
        }

        foreach ($pendentes as $indice => $magnet) {
            // Só o veredito definitivo (`ok` booleano) decide. Qualquer outra
            // saída é "não sei" e mantém a fonte.
            if ($this->veredito($respostas[(string) $indice] ?? null) === false) {
                Log::info('Fonte descartada: a malha não confirmou conexão.', [
                    'id' => $fontes[$indice]['id'] ?? null,
                    'titulo' => $fontes[$indice]['titulo'] ?? null,
                ]);

                continue;
            }

            $mantidas[$indice] = $fontes[$indice];
        }

        // Preserva a ordem original da lista após remover as reprovadas.
        ksort($mantidas);

        return array_values($mantidas);
    }

    /**
     * Traduz a resposta do media-service em um veredito tri-estado.
     *
     * `true` a malha confirmou conexão; `false` a fonte foi medida e está morta;
     * `null` não deu para concluir — e é o que faz a fonte sobreviver.
     */
    private function veredito(mixed $resposta): ?bool
    {
        if (! $resposta instanceof Response || ! $resposta->successful()) {
            return null;
        }

        $dados = $resposta->json();

        if (! is_array($dados) || ! is_bool($dados['ok'] ?? null)) {
            return null;
        }

        return $dados['ok'];
    }

    /**
     * Prova, no media-service, que a fonte entrega **bytes de vídeo**.
     *
     * A malha responde "há peers?"; esta prova responde "abre?". O media-service
     * escolhe o arquivo do episódio com a mesma função que o player usa e lê os
     * primeiros bytes dele — é o mais perto de executar sem transcodificar. Sem
     * isso, um pack com malha saudável e nenhum arquivo do episódio passava como
     * "fonte verificada" e o selo mentia.
     *
     * O veredito é tri-estado de propósito. Só uma recusa **definitiva** reprova:
     * `nao_e_midia` (a URL devolveu HTML), arquivo vazio, torrent sem vídeo,
     * `404`/`410`. Timeout, `403` de anti-bot e falha de rede não provam nada —
     * e nesses a fonte é mantida, como no resto das sondas.
     *
     * @param  array<string, mixed>  $fonte
     * @return bool|null `true` entrega, `false` não entrega, `null` inconclusivo
     */
    public function provar(array $fonte, ?int $temporada = null, ?int $episodio = null, ?int $tempoLimite = null): ?bool
    {
        if (! (bool) config('services.verificacao.prova_bytes', false)) {
            return null;
        }

        $base = rtrim((string) config('services.media_service.url', ''), '/');

        if ($base === '') {
            return null;
        }

        $magnet = (string) ($fonte['magnet'] ?? '');
        $url = (string) ($fonte['stream'] ?? $fonte['url'] ?? '');

        if ($magnet === '' && $url === '') {
            return null;
        }

        $teto = max(2, (int) config('services.verificacao.prova_tempo', 20));

        if ($tempoLimite !== null) {
            $teto = min($teto, max(2, $tempoLimite));
        }

        $corpo = array_filter([
            'magnet' => $magnet !== '' ? $magnet : null,
            // O `magnet` manda: quando há os dois, o torrent é o que o player
            // escolheria (a lista já vem ordenada com o torrent na frente quando
            // ele é a melhor fonte).
            'url' => $magnet === '' ? $url : null,
            'temporada' => $temporada,
            'episodio' => $episodio,
            'espera_ms' => $teto * 1000,
            'bytes_minimos' => (int) config('services.verificacao.prova_bytes_minimos', 262144),
        ], fn ($valor) => $valor !== null);

        try {
            $resposta = Http::connectTimeout(3)
                ->timeout($teto + 5)
                ->acceptJson()
                ->post($base.'/api/media/provar', $corpo);
        } catch (\Throwable $excecao) {
            report($excecao);

            return null;
        }

        if (! $resposta->successful()) {
            return null;
        }

        $dados = $resposta->json();

        if (! is_array($dados) || ! is_bool($dados['ok'] ?? null)) {
            return null;
        }

        $contexto = [
            'id' => $fonte['id'] ?? null,
            'titulo' => $fonte['titulo'] ?? null,
            'tipo' => $fonte['tipo'] ?? 'torrent',
            'motivo' => $dados['motivo'] ?? null,
            'bytes' => $dados['bytes'] ?? 0,
            'ms' => $dados['ms'] ?? null,
        ];

        if ($dados['ok'] === true) {
            Log::info('Prova de bytes: a fonte entregou vídeo.', $contexto);

            return true;
        }

        $definitivos = ['nao_e_midia', 'arquivo_vazio', 'sem_arquivo_de_video', 'http_404', 'http_410'];

        if (! in_array((string) ($dados['motivo'] ?? ''), $definitivos, true)) {
            return null;
        }

        Log::info('Prova de bytes: a fonte não entrega mídia.', $contexto);

        return false;
    }

    /**
     * Prova as melhores fontes da lista e diz se alguma entrega vídeo.
     *
     * Não adianta reprovar pelo insucesso de uma fonte só: a lista tem
     * alternativas, e o player passaria para a próxima. Só quando **todas** as
     * testadas recusam de forma definitiva é que o veredito é `false`; se
     * qualquer uma entregou bytes, a resposta é `true`; e qualquer inconclusivo
     * mantém o benefício da dúvida (`null`).
     *
     * @param  array<int, array<string, mixed>>  $fontes
     */
    public function provarMelhor(array $fontes, ?int $temporada = null, ?int $episodio = null, ?int $tempoLimite = null): ?bool
    {
        if (! (bool) config('services.verificacao.prova_bytes', false) || $fontes === []) {
            return null;
        }

        $max = max(1, (int) config('services.verificacao.prova_max_fontes', 2));
        $inconclusivo = false;

        foreach (array_slice($fontes, 0, $max) as $fonte) {
            $veredito = $this->provar($fonte, $temporada, $episodio, $tempoLimite);

            if ($veredito === true) {
                return true;
            }

            if ($veredito === null) {
                $inconclusivo = true;
            }
        }

        return $inconclusivo ? null : false;
    }
}
