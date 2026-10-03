<?php

namespace App\Services\Torrents;

/**
 * Orçamento de tempo compartilhado por uma busca de torrents.
 *
 * A cascata tem vários provedores, cada um com o seu próprio teto de requisição,
 * mas a **soma** deles não tinha limite: um tracker bloqueado gasta o
 * `tempo_limite` na tentativa direta e mais o `proxy_nativo_timeout` no
 * FlareSolverr, e isso se repete por termo. Com dez termos de episódio e quatro
 * provedores por nome, o pior caso passa de vários minutos — muito acima dos 60 s
 * que o frontend espera, e a requisição é cancelada antes de a lista chegar.
 *
 * Este objeto é o relógio da busca. Ele é registrado como singleton no container,
 * então o [`CatalogoProvedores`] e o [`ClienteHttp`] enxergam o mesmo prazo: o
 * catálogo decide quando parar de perguntar, e o cliente HTTP encurta o socorro
 * pelo FlareSolverr para não começar uma espera de 70 s que já não cabe no
 * orçamento. Sem o compartilhamento, o FlareSolverr continuaria estourando o
 * tempo sozinho, por mais que a cascata soubesse que o prazo acabou.
 *
 * ## Os canais
 *
 * O relógio deixou de ser um só. A cascata de torrents e o stream direto têm
 * custos muito diferentes: um provedor de torrent responde em milissegundos,
 * enquanto o stream direto paga uma renderização de navegador (FlareSolverr) por
 * página, de 10 a 15 s cada. Com um prazo único de 45 s, a cascata de torrents
 * consumia o orçamento inteiro e o stream direto — que só entra depois, como
 * fallback — nascia sem tempo nenhum. Era esse o sintoma: o FlareSolverr
 * respondia 200 com a página do episódio quando chamado à mão, mas o provedor
 * desistia antes de chamá-lo, porque `restante()` já devolvia zero.
 *
 * Cada canal tem o seu próprio relógio, nomeado. O canal `torrents` mantém o
 * orçamento global; o canal `stream_direto` tem um orçamento maior, dimensionado
 * para o custo real do FlareSolverr. Os dois **não somam** para o usuário: o
 * stream direto só roda quando a cascata de torrents falhou, então o tempo dele
 * é o tempo da resposta, não uma adição ao da cascata.
 *
 * O canal ativo é uma propriedade do objeto, e não um parâmetro espalhado por
 * toda a assinatura. O [`ClienteHttp`] e o trait [`ConsultaComOrcamento`] leem o
 * relógio sem saber de qual canal se trata — quem troca o canal é o
 * [`CatalogoProvedores`], no ponto exato em que aciona cada metade da busca.
 */
class OrcamentoBusca
{
    /**
     * Canal padrão, usado quando nenhum foi selecionado explicitamente. É o
     * relógio da cascata de torrents — o caminho mais comum.
     */
    public const CANAL_PADRAO = 'torrents';

    /** Canal do scraper de stream direto, que paga o custo do FlareSolverr. */
    public const CANAL_STREAM_DIRETO = 'stream_direto';

    /**
     * Prazos absolutos por canal, em marcas de tempo. Um canal ausente do mapa
     * não tem busca em curso.
     *
     * @var array<string, float>
     */
    private array $prazos = [];

    /**
     * Canal cujo relógio as leituras (`restante()`, `esgotado()`) consultam.
     */
    private string $canalAtivo = self::CANAL_PADRAO;

    /**
     * Seleciona o canal cujo relógio passa a valer para as leituras seguintes.
     *
     * A troca é feita pelo [`CatalogoProvedores`] antes de acionar cada metade da
     * busca. Não abre nem fecha nada: só aponta qual prazo as leituras devem
     * consultar. Um canal sem prazo aberto se comporta como "sem busca em curso",
     * e quem lê usa o próprio teto.
     */
    public function usarCanal(string $canal): void
    {
        $this->canalAtivo = $canal;
    }

    /** O canal cujo relógio está ativo agora. */
    public function canalAtivo(): string
    {
        return $this->canalAtivo;
    }

    /**
     * Abre um novo orçamento para um canal, ancorado no instante da chamada.
     *
     * O prazo é ancorado aqui, e não na construção do serviço: o Laravel resolve
     * o container antes de qualquer trabalho, e medir a partir dali descontaria o
     * tempo gasto na autenticação e na montagem da resposta.
     */
    public function abrir(int $segundos, ?string $canal = null): void
    {
        $this->prazos[$canal ?? $this->canalAtivo] = microtime(true) + max(1, $segundos);
    }

    /**
     * Abre o orçamento de um canal só se ele ainda não estiver de pé.
     *
     * O orçamento é um singleton compartilhado, mas cada canal da busca chamava
     * `abrir()` com o seu próprio valor — e cada chamada **reiniciava** o relógio.
     * O stream direto abria 12 s, fechava, e a cascata de torrents abria 45 s do
     * zero: os dois orçamentos somavam e a busca inteira podia passar de 57 s,
     * estourando o limite do frontend. Com a abertura idempotente, o primeiro
     * canal a chegar ancora o prazo e os demais o respeitam.
     *
     * Devolve `true` quando foi esta chamada que abriu o orçamento, para quem
     * abriu saber que também é quem deve fechá-lo.
     */
    public function abrirSeFechado(int $segundos, ?string $canal = null): bool
    {
        $canal ??= $this->canalAtivo;

        if (isset($this->prazos[$canal])) {
            return false;
        }

        $this->abrir($segundos, $canal);

        return true;
    }

    /** Há uma busca em curso no canal ativo com o relógio de pé? */
    public function emCurso(): bool
    {
        return isset($this->prazos[$this->canalAtivo]);
    }

    /**
     * Fecha o orçamento do canal ativo ao fim da busca.
     *
     * Sem isto, uma busca encerrada deixaria o prazo de pé e a próxima — que
     * reabre o orçamento logo no início — herdaria um relógio já vencido se
     * alguma chamada acontecesse antes do `abrir()`.
     */
    public function fechar(?string $canal = null): void
    {
        unset($this->prazos[$canal ?? $this->canalAtivo]);
    }

    /**
     * Fecha os orçamentos de todos os canais.
     *
     * É o encerramento da busca inteira: a cascata de torrents e o stream direto
     * são duas metades da mesma resposta, e nenhuma delas fecha o relógio sozinha.
     * O [`TorrentService`] chama isto uma única vez, quando a busca acabou de
     * verdade, para que a próxima não herde um prazo vencido.
     */
    public function fecharTudo(): void
    {
        $this->prazos = [];
    }

    /** O orçamento do canal ativo acabou? */
    public function esgotado(?string $canal = null): bool
    {
        $prazo = $this->prazos[$canal ?? $this->canalAtivo] ?? null;

        return $prazo !== null && microtime(true) >= $prazo;
    }

    /**
     * Quanto sobra do orçamento do canal ativo, em segundos.
     *
     * Devolve `null` quando não há busca em curso naquele canal — nesse caso quem
     * chama usa o seu próprio teto, porque não há prazo global para respeitar.
     */
    public function restante(?string $canal = null): ?int
    {
        $prazo = $this->prazos[$canal ?? $this->canalAtivo] ?? null;

        if ($prazo === null) {
            return null;
        }

        return max(0, (int) ceil($prazo - microtime(true)));
    }
}
