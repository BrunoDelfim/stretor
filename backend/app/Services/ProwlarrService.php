<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Prepara o Prowlarr sozinho, na subida do stack.
 *
 * O Prowlarr é o degrau 2 da busca de torrents: é ele quem transforma os
 * trackers públicos em uma API Torznab única. Só que ele nasce "cru" — o
 * backend não conhece a chave da API e não existe nenhum indexador cadastrado.
 * Este serviço elimina essa configuração manual em três movimentos:
 *
 *   1. descobre a chave da API lendo o config.xml que o próprio Prowlarr grava
 *      no volume compartilhado (nada de copiar e colar chave);
 *   2. espera o Prowlarr responder, porque na primeira subida ele passa alguns
 *      segundos criando o banco interno antes de aceitar requisições;
 *   3. cadastra os indexadores PT-BR versionados em
 *      docker/prowlarr/Definitions/Custom, sem duplicar o que já existe.
 *
 * Nada aqui é fatal: se o Prowlarr não estiver de pé, o backend continua
 * servindo o degrau 1 (busca nativa) e o degrau 3 (YTS).
 */
class ProwlarrService
{
    /**
     * Campo do schema da API que identifica a definição Cardigann por trás de
     * um indexador. É por ele que sabemos se o torrentdosfilmes já foi
     * cadastrado ou não.
     */
    private const CAMPO_DEFINICAO = 'definitionFile';

    public function url(): string
    {
        $url = trim((string) config('services.prowlarr.url', ''));

        // Rede de segurança: um cache de config criado antes da chave
        // `prowlarr` existir devolveria vazio. Nesse caso reaproveitamos a URL
        // do Torznab, que aponta para o mesmo serviço.
        if ($url === '') {
            $url = trim((string) config('services.torrents.torznab_url', ''));
        }

        return rtrim($url, '/');
    }

    public function caminhoConfig(): string
    {
        $caminho = trim((string) config('services.prowlarr.config_path', ''));

        // Mesma proteção contra cache antigo: o ambiente do container é a
        // fonte da verdade enquanto a config não é recarregada.
        if ($caminho === '') {
            $caminho = trim((string) (getenv('PROWLARR_CONFIG_PATH') ?: ''));
        }

        return $caminho;
    }

    /**
     * Definições Cardigann (ids dos arquivos .yml customizados) que devem
     * existir como indexadores no Prowlarr.
     *
     * @return list<string>
     */
    public function definicoes(): array
    {
        $definicoes = config('services.prowlarr.indexadores', []);

        // Sem definições (cache de config antigo), usamos a definição PT-BR
        // versionada no projeto. Isso evita um provisionamento que não faz nada.
        if (! is_array($definicoes) || $definicoes === []) {
            return ['torrentdosfilmes'];
        }

        return array_values(array_filter(array_map(
            static fn ($valor) => trim((string) $valor),
            $definicoes
        )));
    }

    public function tempoLimite(): int
    {
        return max(5, (int) config('services.prowlarr.tempo_limite', 20));
    }

    /**
     * Chave da API do Prowlarr.
     *
     * A variável de ambiente tem prioridade para permitir apontar o backend
     * para um Prowlarr externo. Fora isso, lemos direto do config.xml.
     */
    public function chave(): ?string
    {
        $doAmbiente = trim((string) config('services.torrents.torznab_key', ''));

        if ($doAmbiente !== '') {
            return $doAmbiente;
        }

        $caminho = $this->caminhoConfig();

        if ($caminho === '' || ! is_file($caminho) || ! is_readable($caminho)) {
            return null;
        }

        $conteudo = @file_get_contents($caminho);

        if (! is_string($conteudo) || $conteudo === '') {
            return null;
        }

        if (preg_match('#<ApiKey>\s*([^<]+?)\s*</ApiKey>#i', $conteudo, $achados) !== 1) {
            return null;
        }

        $chave = trim(html_entity_decode($achados[1]));

        return $chave !== '' ? $chave : null;
    }

    /**
     * Aguarda o Prowlarr ficar utilizável. O critério é o endpoint de status
     * responder 200 — o que só acontece depois do banco interno estar pronto e
     * da chave existir no config.xml.
     */
    public function disponivel(int $tentativas = 15, int $intervaloMs = 1000): bool
    {
        if ($this->url() === '') {
            return false;
        }

        for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
            $chave = $this->chave();

            if ($chave !== null) {
                try {
                    if ($this->cliente($chave)->get('/api/v1/system/status')->successful()) {
                        return true;
                    }
                } catch (Throwable) {
                    // Prowlarr ainda inicializando: seguimos tentando.
                }
            }

            if ($tentativa < $tentativas) {
                usleep($intervaloMs * 1000);
            }
        }

        return false;
    }

    /**
     * Executa o provisionamento completo.
     *
     * @return array{chave: ?string, disponivel: bool, indexadores: list<string>, mensagens: list<string>}
     */
    public function provisionar(bool $apenasChave = false): array
    {
        $url = $this->url();

        if ($url === '') {
            return $this->resultado(null, false, [], [
                '[prowlarr] Endereço não configurado; o degrau 2 fica desativado.',
            ]);
        }

        if (! $this->disponivel()) {
            return $this->resultado($this->chave(), false, [], [
                '[prowlarr] Não respondeu a tempo; a busca segue no backend nativo e no YTS.',
            ]);
        }

        $chave = $this->chave();

        if ($chave === null) {
            return $this->resultado(null, false, [], [
                sprintf('[prowlarr] Chave da API ausente em %s.', $this->caminhoConfig()),
            ]);
        }

        $mensagens = [sprintf('[prowlarr] Disponível em %s.', $url)];

        if ($apenasChave) {
            return $this->resultado($chave, true, [], $mensagens);
        }

        $existentes = $this->definicoesCadastradas($chave);

        if ($existentes === null) {
            $mensagens[] = '[prowlarr] Não foi possível listar os indexadores; nada foi alterado.';

            return $this->resultado($chave, true, [], $mensagens);
        }

        $modelos = $this->modelosDoSchema($chave);

        if ($modelos === null) {
            $mensagens[] = '[prowlarr] Schema de indexadores indisponível; nada foi alterado.';

            return $this->resultado($chave, true, [], $mensagens);
        }

        $cadastrados = [];

        foreach ($this->definicoes() as $definicao) {
            if (in_array($definicao, $existentes, true)) {
                $mensagens[] = sprintf('[prowlarr] Indexador "%s" já cadastrado.', $definicao);

                continue;
            }

            $modelo = $modelos[$definicao] ?? null;

            if ($modelo === null) {
                $mensagens[] = sprintf(
                    '[prowlarr] Definição "%s" não encontrada no Prowlarr (o volume das definições está montado?).',
                    $definicao
                );

                continue;
            }

            if ($this->cadastrarIndexador($chave, $modelo)) {
                $cadastrados[] = $definicao;
                $mensagens[] = sprintf('[prowlarr] Indexador "%s" cadastrado.', $definicao);
            } else {
                $mensagens[] = sprintf('[prowlarr] Falha ao cadastrar o indexador "%s".', $definicao);
            }
        }

        return $this->resultado($chave, true, $cadastrados, $mensagens);
    }

    /**
     * @param  list<string>  $indexadores
     * @param  list<string>  $mensagens
     * @return array{chave: ?string, disponivel: bool, indexadores: list<string>, mensagens: list<string>}
     */
    private function resultado(?string $chave, bool $disponivel, array $indexadores, array $mensagens): array
    {
        return [
            'chave' => $chave,
            'disponivel' => $disponivel,
            'indexadores' => $indexadores,
            'mensagens' => $mensagens,
        ];
    }

    private function cliente(string $chave): PendingRequest
    {
        return Http::baseUrl($this->url())
            ->withHeaders([
                'X-Api-Key' => $chave,
                'Accept' => 'application/json',
            ])
            ->timeout($this->tempoLimite())
            ->retry(2, 500, throw: false);
    }

    /**
     * Ids das definições já cadastradas como indexador. `null` indica falha na
     * consulta, o que é diferente de "lista vazia" (Prowlarr limpo).
     *
     * @return list<string>|null
     */
    private function definicoesCadastradas(string $chave): ?array
    {
        try {
            $resposta = $this->cliente($chave)->get('/api/v1/indexer');
        } catch (Throwable) {
            return null;
        }

        if (! $resposta->successful()) {
            return null;
        }

        $lista = $resposta->json();

        if (! is_array($lista)) {
            return null;
        }

        $definicoes = [];

        foreach ($lista as $indexador) {
            if (! is_array($indexador)) {
                continue;
            }

            $definicao = $this->definicaoDoIndexador($indexador);

            if ($definicao !== null) {
                $definicoes[] = $definicao;
            }
        }

        return $definicoes;
    }

    /**
     * Modelos de indexador oferecidos pelo Prowlarr, indexados pelo id da
     * definição. É daqui que tiramos o corpo pronto para o POST de cadastro.
     *
     * @return array<string, array<string, mixed>>|null
     */
    private function modelosDoSchema(string $chave): ?array
    {
        try {
            $resposta = $this->cliente($chave)->get('/api/v1/indexer/schema');
        } catch (Throwable) {
            return null;
        }

        if (! $resposta->successful()) {
            return null;
        }

        $schema = $resposta->json();

        if (! is_array($schema)) {
            return null;
        }

        $modelos = [];

        foreach ($schema as $entrada) {
            if (! is_array($entrada)) {
                continue;
            }

            $definicao = $this->definicaoDoIndexador($entrada);

            if ($definicao !== null && ! isset($modelos[$definicao])) {
                $modelos[$definicao] = $entrada;
            }
        }

        return $modelos;
    }

    /**
     * O schema devolve o modelo completo de cada definição; basta reenviá-lo
     * ajustando os campos que controlam identidade e habilitação.
     *
     * @param  array<string, mixed>  $modelo
     */
    private function cadastrarIndexador(string $chave, array $modelo): bool
    {
        $corpo = $modelo;
        $corpo['id'] = 0;
        $corpo['enable'] = true;
        $corpo['priority'] = 25;
        $corpo['appProfileId'] = 1;
        $corpo['tags'] = [];

        if (empty($corpo['name'])) {
            $corpo['name'] = (string) config('services.prowlarr.rotulo_padrao', 'Índice PT-BR');
        }

        try {
            return $this->cliente($chave)->post('/api/v1/indexer', $corpo)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Extrai o id da definição de um objeto de indexador (seja da listagem,
     * seja do schema), que sempre traz o campo `definitionFile` na lista de
     * fields.
     *
     * @param  array<string, mixed>  $indexador
     */
    private function definicaoDoIndexador(array $indexador): ?string
    {
        $campos = $indexador['fields'] ?? [];

        if (! is_array($campos)) {
            return null;
        }

        foreach ($campos as $campo) {
            if (! is_array($campo)) {
                continue;
            }

            if (($campo['name'] ?? '') !== self::CAMPO_DEFINICAO) {
                continue;
            }

            $valor = trim((string) ($campo['value'] ?? ''));

            return $valor !== '' ? $valor : null;
        }

        return null;
    }
}
