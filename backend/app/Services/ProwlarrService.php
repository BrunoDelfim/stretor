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
 *      docker/prowlarr/Definitions/Custom, sem duplicar o que já existe;
 *   4. para os trackers que o CloudFlare barra, cadastra o FlareSolverr como
 *      proxy e liga os indexadores a ele por tag — sem isso o teste de busca
 *      falha, o cadastro é recusado e o indexador nunca sai de inativo.
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

    /**
     * Motivo da última falha de cadastro, para virar mensagem útil no comando
     * em vez de um genérico "falha ao cadastrar".
     */
    private ?string $ultimoErro = null;

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

        // Os trackers atrás do CloudFlare só passam no teste de busca saindo por
        // um proxy, e o Prowlarr roda esse teste no momento em que grava o
        // indexador. Ou seja: o proxy precisa existir antes do cadastro.
        $tagProxy = null;

        if ($this->proxyAtivo()) {
            $tagProxy = $this->provisionarProxy($chave);

            if ($tagProxy === null) {
                $detalhe = $this->ultimoErro !== null ? ' '.$this->ultimoErro : '';
                $mensagens[] = '[prowlarr] Proxy para CloudFlare indisponível; os indexadores protegidos ficarão inativos.'.$detalhe;
            } else {
                $mensagens[] = sprintf('[prowlarr] Proxy "%s" apontando para %s.', $this->nomeDoProxy(), $this->flaresolverrUrl());
            }
        }

        $cadastrados = [];

        foreach ($this->definicoes() as $definicao) {
            $comProxy = $tagProxy !== null && in_array($definicao, $this->indexadoresComProxy(), true);

            if (in_array($definicao, $existentes, true)) {
                // Quem subiu o stack antes do FlareSolverr tem o indexador
                // gravado desabilitado — e o provisionamento, que não duplica o
                // que já existe, jamais o tentaria de novo. Aqui ele ganha a tag
                // do proxy e volta a ser testado, agora por dentro dele.
                if ($comProxy) {
                    if ($this->ajustarIndexadorComProxy($chave, $definicao, $tagProxy)) {
                        $mensagens[] = sprintf('[prowlarr] Indexador "%s" associado ao proxy.', $definicao);
                    } else {
                        $detalhe = $this->ultimoErro !== null ? ' '.$this->ultimoErro : '';
                        $mensagens[] = sprintf(
                            '[prowlarr] Indexador "%s" segue inativo: falha ao aplicar o proxy.%s',
                            $definicao,
                            $detalhe
                        );
                    }

                    continue;
                }

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

            if ($this->cadastrarIndexador($chave, $modelo, $comProxy ? $tagProxy : null)) {
                $cadastrados[] = $definicao;
                $mensagens[] = sprintf('[prowlarr] Indexador "%s" cadastrado.', $definicao);
            } else {
                $detalhe = $this->ultimoErro !== null ? ' '.$this->ultimoErro : '';
                $mensagens[] = sprintf('[prowlarr] Falha ao cadastrar o indexador "%s".%s', $definicao, $detalhe);
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

    /**
     * @param  int|null  $segundos  Tempo limite específico. As gravações de
     *                              indexador rodam o teste de busca, que pode
     *                              sair pelo FlareSolverr e demorar bem mais que
     *                              o limite padrão — para elas passamos
     *                              TEMPO_LIMITE_TESTE.
     */
    private function cliente(string $chave, ?int $segundos = null): PendingRequest
    {
        return Http::baseUrl($this->url())
            ->withHeaders([
                'X-Api-Key' => $chave,
                'Accept' => 'application/json',
            ])
            ->timeout($segundos ?? $this->tempoLimite())
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
     * Campos que o schema devolve mas que o Prowlarr calcula sozinho. Reenviá-los
     * no POST faz a API responder 400 ("Read-only field"), que era o motivo de o
     * cadastro falhar mesmo com a definição presente.
     */
    private const CAMPOS_SOMENTE_LEITURA = [
        'infoLink',
        'capabilities',
        'indexerUrls',
        'legacyUrls',
        'description',
        'language',
        'encoding',
        'protocol',
        'privacy',
        'supportsRss',
        'supportsSearch',
        'supportsRedirect',
        'supportsPagination',
        'definitionFile',
        'added',
        'sortName',
    ];

    /**
     * Implementação do proxy no Prowlarr. O `Host` do FlareSolverr é exposto
     * pela própria API como um proxy de indexador — não é um indexador, e por
     * isso não aparece na listagem nem no schema de `/indexer`.
     */
    private const IMPLEMENTACAO_PROXY = 'FlareSolverr';

    /**
     * Mesma ideia do bloco acima, agora para o proxy: o schema devolve campos
     * derivados que, reenviados no POST, fazem a API responder 400.
     */
    private const CAMPOS_PROXY_SOMENTE_LEITURA = [
        'infoLink',
        'message',
    ];

    /**
     * Teto para as chamadas que salvam indexador, porque elas disparam o teste de
     * busca. Sem proxy o teste falha rápido; com o FlareSolverr, ele abre um
     * navegador e resolve o desafio antes de responder — o que não cabe no
     * timeout padrão. O valor fica acima do teto do próprio FlareSolverr (60s)
     * para que o erro venha dele, e não de um corte nosso.
     */
    private const TEMPO_LIMITE_TESTE = 90;

    /**
     * O schema devolve o modelo completo de cada definição; reenviamos apenas os
     * campos editáveis, ajustando os que controlam identidade e habilitação.
     *
     * O cadastro acontece em duas tentativas porque o Prowlarr insiste em rodar
     * um teste de busca ao gravar um indexador habilitado. Quando o tracker está
     * fora do ar (domínio sequestrado, Cloudflare, bloqueio judicial) esse teste
     * devolve 400 e o indexador nunca é persistido — mesmo com a definição
     * correta. A saída é gravar primeiro DESABILITADO, que pula a validação, e
     * só então habilitar. O indexador fica pronto e volta a responder sozinho
     * quando o site reaparecer.
     *
     * @param  array<string, mixed>  $modelo
     * @param  int|null  $tagProxy  Tag do proxy a associar. Sem ela o Prowlarr
     *                              testa o indexador direto e recusa quando há
     *                              CloudFlare no caminho.
     */
    private function cadastrarIndexador(string $chave, array $modelo, ?int $tagProxy = null): bool
    {
        $corpo = array_diff_key($modelo, array_flip(self::CAMPOS_SOMENTE_LEITURA));

        $corpo['id'] = 0;
        $corpo['priority'] = 25;
        $corpo['appProfileId'] = 1;
        $corpo['tags'] = $tagProxy !== null ? [$tagProxy] : [];

        if (empty($corpo['name'])) {
            $corpo['name'] = (string) config('services.prowlarr.rotulo_padrao', 'Índice PT-BR');
        }

        // O Prowlarr exige que cada campo do schema venha com `name` e `value`.
        // O schema já entrega nesse formato; garantimos a lista como array.
        if (! isset($corpo['fields']) || ! is_array($corpo['fields'])) {
            $corpo['fields'] = [];
        }

        // Primeira tentativa: habilitado e com `forceSave`, que é o caminho
        // normal quando o tracker responde. Se o Prowlarr honrar o force, o
        // indexador já nasce ativo e não há segundo passo.
        $corpo['enable'] = true;
        $corpo['forceSave'] = true;

        $resposta = $this->enviarCadastro($chave, $corpo);

        if ($resposta !== null && $resposta->successful()) {
            return true;
        }

        // Segunda tentativa: grava desabilitado. Sem `enable`, o Prowlarr não
        // dispara o teste de busca e aceita a definição mesmo com o site fora.
        $corpo['enable'] = false;
        unset($corpo['forceSave']);

        $resposta = $this->enviarCadastro($chave, $corpo);

        if ($resposta === null || ! $resposta->successful()) {
            $this->ultimoErro = $resposta === null
                ? 'sem resposta do Prowlarr'
                : sprintf('HTTP %d: %s', $resposta->status(), trim((string) $resposta->body()));

            return false;
        }

        // Gravado desabilitado: agora habilita. Se a habilitação falhar, o
        // indexador continua cadastrado (só inativo), o que ainda é melhor do
        // que perder a definição — por isso não tratamos como erro fatal.
        $id = (int) ($resposta->json('id') ?? 0);

        if ($id > 0) {
            $this->habilitarIndexador($chave, $id);
        }

        return true;
    }

    /**
     * POST de cadastro isolado, para as duas tentativas compartilharem o mesmo
     * tratamento de exceção.
     *
     * @param  array<string, mixed>  $corpo
     */
    private function enviarCadastro(string $chave, array $corpo): ?\Illuminate\Http\Client\Response
    {
        try {
            return $this->cliente($chave, self::TEMPO_LIMITE_TESTE)->post('/api/v1/indexer', $corpo);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reabilita um indexador já gravado. O Prowlarr aceita o PUT com o corpo
     * completo; buscamos o objeto atual para não perder os campos que ele
     * preencheu sozinho (capabilities, indexerUrls etc.).
     */
    private function habilitarIndexador(string $chave, int $id): void
    {
        try {
            $atual = $this->cliente($chave)->get(sprintf('/api/v1/indexer/%d', $id));

            if (! $atual->successful()) {
                return;
            }

            $corpo = $atual->json();

            if (! is_array($corpo)) {
                return;
            }

            $corpo['enable'] = true;

            // O PUT reexecuta o teste de busca; por isso a folga de tempo.
            $this->cliente($chave, self::TEMPO_LIMITE_TESTE)
                ->put(sprintf('/api/v1/indexer/%d', $id), $corpo);
        } catch (Throwable) {
            // Silencioso de propósito: o indexador já está cadastrado; falhar a
            // habilitação não invalida o provisionamento.
        }
    }

    /**
     * O proxy só faz sentido quando há para onde apontar: sem o endereço do
     * FlareSolverr, o provisionamento segue o caminho antigo.
     */
    public function proxyAtivo(): bool
    {
        return (bool) config('services.prowlarr.proxy_ativo', true)
            && $this->flaresolverrUrl() !== '';
    }

    public function flaresolverrUrl(): string
    {
        return trim((string) config('services.prowlarr.flaresolverr_url', ''));
    }

    private function nomeDoProxy(): string
    {
        return (string) config('services.prowlarr.proxy_nome', 'FlareSolverr');
    }

    /**
     * Definições que dependem do proxy para passar no teste de busca.
     *
     * @return list<string>
     */
    private function indexadoresComProxy(): array
    {
        $definicoes = config('services.prowlarr.proxy_indexadores', []);

        if (! is_array($definicoes)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn ($valor) => trim((string) $valor),
            $definicoes
        )));
    }

    /**
     * Cadastra (ou atualiza) o proxy no Prowlarr e devolve a tag que liga os
     * indexadores a ele. `null` significa que o proxy não pôde ser preparado —
     * e nesse caso os indexadores protegidos seguem o caminho antigo, gravados
     * desabilitados.
     */
    private function provisionarProxy(string $chave): ?int
    {
        $this->ultimoErro = null;

        $tag = $this->garantirTag($chave, (string) config('services.prowlarr.proxy_tag', 'flaresolverr'));

        if ($tag === null) {
            $this->ultimoErro = 'Não foi possível garantir a tag do proxy.';

            return null;
        }

        $existente = $this->proxyExistente($chave);
        $endereco = $this->flaresolverrUrl();

        // O Prowlarr valida a conexão toda vez que grava o proxy. Reenviar um
        // registro que já aponta para o mesmo endereço é um teste de graça: se o
        // FlareSolverr ainda estiver terminando de subir, a gravação falha e
        // derruba o provisionamento inteiro. Se nada mudou, o que já está lá
        // serve — e o provisionamento fica imune a uma queda momentânea.
        if ($existente !== null) {
            $hostAtual = $this->valorDoCampo($existente['fields'] ?? null, 'host');

            // Algumas versões devolvem o proxy sem os `fields` na listagem. Nesse
            // caso não há como comparar: tratamos como inalterado, porque o custo
            // de um reenvio desnecessário — a validação de conexão — é maior que
            // o de um endereço trocado que só voltaria a valer no próximo
            // provisionamento.
            if ($hostAtual === null || $hostAtual === $endereco) {
                return $tag;
            }
        }

        $modelo = $this->modeloDoProxy($chave);

        if ($modelo === null) {
            $this->ultimoErro = 'Schema do proxy indisponível.';

            return null;
        }

        $corpo = array_diff_key($modelo, array_flip(self::CAMPOS_PROXY_SOMENTE_LEITURA));

        $corpo['id'] = 0;
        $corpo['name'] = $this->nomeDoProxy();
        $corpo['tags'] = [$tag];
        $corpo['fields'] = $this->definirCampo(
            is_array($corpo['fields'] ?? null) ? $corpo['fields'] : [],
            'host',
            $endereco
        );

        try {
            // Atualizar um proxy existente também dispara a validação antes de
            // gravar. Apagar e recriar reaproveita o caminho do primeiro
            // cadastro — que é justamente o que já funcionava.
            if ($existente !== null) {
                $id = (int) ($existente['id'] ?? 0);

                if ($id > 0) {
                    $this->cliente($chave)->delete(sprintf('/api/v1/indexerproxy/%d', $id));
                }
            }

            $resposta = $this->cliente($chave, self::TEMPO_LIMITE_TESTE)
                ->post('/api/v1/indexerproxy', $corpo);

            if ($resposta->successful()) {
                return $tag;
            }

            $this->ultimoErro = sprintf('HTTP %d: %s', $resposta->status(), $resposta->body());

            return null;
        } catch (Throwable $erro) {
            $this->ultimoErro = $erro->getMessage();

            return null;
        }
    }

    /**
     * Modelo do proxy que o próprio Prowlarr oferece. É daqui que vem o
     * contrato dos campos, para não inventarmos nomes que a API recusaria.
     *
     * @return array<string, mixed>|null
     */
    private function modeloDoProxy(string $chave): ?array
    {
        try {
            $resposta = $this->cliente($chave)->get('/api/v1/indexerproxy/schema');
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

        foreach ($schema as $entrada) {
            if (is_array($entrada) && ($entrada['implementation'] ?? '') === self::IMPLEMENTACAO_PROXY) {
                return $entrada;
            }
        }

        return null;
    }

    /**
     * Proxy já cadastrado, com os campos que o Prowlarr guardou. Devolver o
     * registro inteiro — e não apenas o id — permite comparar o endereço antes
     * de regravar e distinguir uma troca real de endereço de um simples reenvio.
     *
     * @return array<string, mixed>|null
     */
    private function proxyExistente(string $chave): ?array
    {
        try {
            $resposta = $this->cliente($chave)->get('/api/v1/indexerproxy');
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

        foreach ($lista as $proxy) {
            if (! is_array($proxy) || ($proxy['implementation'] ?? '') !== self::IMPLEMENTACAO_PROXY) {
                continue;
            }

            return $proxy;
        }

        return null;
    }

    /**
     * Valor de um campo nomeado dentro do bloco `fields` de um recurso do
     * Prowlarr. O endereço do proxy mora lá, e não no nível de cima.
     *
     * @param  mixed  $campos
     */
    private function valorDoCampo(mixed $campos, string $nome): ?string
    {
        if (! is_array($campos)) {
            return null;
        }

        foreach ($campos as $campo) {
            if (! is_array($campo) || ($campo['name'] ?? '') !== $nome) {
                continue;
            }

            $valor = $campo['value'] ?? null;

            return is_scalar($valor) ? trim((string) $valor) : null;
        }

        return null;
    }

    /**
     * A tag é o que costura proxy e indexador: o Prowlarr roteia pelo proxy todo
     * indexador que carregue a mesma tag. Criamos uma vez e reaproveitamos.
     */
    private function garantirTag(string $chave, string $rotulo): ?int
    {
        $rotulo = trim($rotulo);

        if ($rotulo === '') {
            return null;
        }

        try {
            $listagem = $this->cliente($chave)->get('/api/v1/tag');

            if ($listagem->successful()) {
                $tags = $listagem->json();

                if (is_array($tags)) {
                    foreach ($tags as $tag) {
                        if (! is_array($tag)) {
                            continue;
                        }

                        $existente = mb_strtolower(trim((string) ($tag['label'] ?? '')));

                        if ($existente !== mb_strtolower($rotulo)) {
                            continue;
                        }

                        $id = (int) ($tag['id'] ?? 0);

                        if ($id > 0) {
                            return $id;
                        }
                    }
                }
            }

            $criada = $this->cliente($chave)->post('/api/v1/tag', ['label' => $rotulo]);

            if (! $criada->successful()) {
                return null;
            }

            $id = (int) ($criada->json('id') ?? 0);

            return $id > 0 ? $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Substitui o valor de um campo do schema. Campo desconhecido é adicionado,
     * porque a API exige que todo campo traga `name` e `value`.
     *
     * @param  array<int, mixed>  $campos
     * @return array<int, mixed>
     */
    private function definirCampo(array $campos, string $nome, mixed $valor): array
    {
        foreach ($campos as $indice => $campo) {
            if (is_array($campo) && ($campo['name'] ?? '') === $nome) {
                $campos[$indice]['value'] = $valor;

                return $campos;
            }
        }

        $campos[] = ['name' => $nome, 'value' => $valor];

        return $campos;
    }

    /**
     * Indexador já cadastrado ganha a tag do proxy e volta a ficar ativo. É o
     * caminho de quem subiu o stack antes do FlareSolverr existir: o 1337x foi
     * gravado desabilitado e, sem isto, nunca seria testado de novo.
     *
     * A tag, aqui, é vínculo e não rótulo: o Prowlarr só encaminha pelo proxy os
     * indexadores que a carregam. Como o usuário pode ter tags próprias no painel,
     * a nossa é somada às existentes — trocar a lista apagaria as dele a cada
     * provisionamento, e uma edição feita à mão derrubaria o proxy no sentido
     * oposto. Quando tudo já está no lugar, saímos sem gravar: o PUT reexecuta o
     * teste de busca, e o desafio do CloudFlare pode consumir o tempo limite
     * inteiro para não mudar nada.
     *
     * A listagem de `/indexer` devolve um objeto enxuto; o PUT exige o recurso
     * completo, com os `fields` que o Prowlarr preencheu (capabilities,
     * indexerUrls etc.). Por isso relemos o item pelo id antes de gravar — a
     * mesma precaução que o habilitar toma. As falhas viram `ultimoErro`, senão
     * um PUT recusado some sem deixar rastro.
     */
    private function ajustarIndexadorComProxy(string $chave, string $definicao, int $tag): bool
    {
        $this->ultimoErro = null;

        try {
            $listagem = $this->cliente($chave)->get('/api/v1/indexer');
        } catch (Throwable) {
            $this->ultimoErro = 'sem resposta do Prowlarr ao listar os indexadores';

            return false;
        }

        if (! $listagem->successful()) {
            $this->ultimoErro = sprintf('HTTP %d ao listar os indexadores', $listagem->status());

            return false;
        }

        $lista = $listagem->json();

        if (! is_array($lista)) {
            $this->ultimoErro = 'resposta inesperada ao listar os indexadores';

            return false;
        }

        $id = 0;

        foreach ($lista as $indexador) {
            if (is_array($indexador) && $this->definicaoDoIndexador($indexador) === $definicao) {
                $id = (int) ($indexador['id'] ?? 0);

                break;
            }
        }

        if ($id <= 0) {
            $this->ultimoErro = sprintf('indexador "%s" não encontrado na listagem', $definicao);

            return false;
        }

        try {
            $atual = $this->cliente($chave)->get(sprintf('/api/v1/indexer/%d', $id));
        } catch (Throwable) {
            $this->ultimoErro = 'sem resposta do Prowlarr ao ler o indexador';

            return false;
        }

        if (! $atual->successful()) {
            $this->ultimoErro = sprintf('HTTP %d ao ler o indexador', $atual->status());

            return false;
        }

        $corpo = $atual->json();

        if (! is_array($corpo)) {
            $this->ultimoErro = 'resposta inesperada ao ler o indexador';

            return false;
        }

        $tags = array_map(
            static fn ($valor) => (int) $valor,
            is_array($corpo['tags'] ?? null) ? $corpo['tags'] : []
        );

        if (in_array($tag, $tags, true) && (bool) ($corpo['enable'] ?? false)) {
            return true;
        }

        $tags[] = $tag;

        $corpo['tags'] = array_values(array_unique(array_filter($tags)));
        $corpo['enable'] = true;

        try {
            // A gravação reexecuta o teste de busca, agora dentro do proxy:
            // pode levar o tempo de o FlareSolverr vencer o desafio.
            $resposta = $this->cliente($chave, self::TEMPO_LIMITE_TESTE)
                ->put(sprintf('/api/v1/indexer/%d', $id), $corpo);
        } catch (Throwable) {
            $this->ultimoErro = 'sem resposta do Prowlarr ao gravar o indexador';

            return false;
        }

        if (! $resposta->successful()) {
            $this->ultimoErro = sprintf('HTTP %d: %s', $resposta->status(), trim((string) $resposta->body()));

            return false;
        }

        return true;
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
