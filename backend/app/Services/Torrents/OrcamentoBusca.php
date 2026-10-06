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
 * ## Os métodos e os canais
 *
 * O relógio tem um canal por método de indexação. A cascata de torrents e o
 * stream direto têm custos muito diferentes: um provedor de torrent responde em
 * milissegundos, enquanto o stream direto paga uma renderização de navegador
 * (FlareSolverr) por página, de 10 a 15 s cada. Cada canal tem o seu próprio
 * orçamento, dimensionado para o custo real do método.
 *
 * O canal `torrents` mantém o orçamento global; o canal `stream_direto` tem um
 * orçamento maior, dimensionado para o FlareSolverr. Quando os dois métodos rodam
 * na mesma busca — o stream direto voltou vazio e a cascata entrou —, os dois
 * orçamentos **somam** para o usuário, e é por isso que existe o teto global
 * descrito abaixo.
 *
 * O canal ativo é uma propriedade do objeto, e não um parâmetro espalhado por
 * toda a assinatura. O [`ClienteHttp`] e o trait [`ConsultaComOrcamento`] leem o
 * relógio sem saber de qual canal se trata — quem troca o canal é o
 * [`CatalogoProvedores`], no ponto exato em que aciona cada método.
 *
 * ## O teto global
 *
 * Como os dois métodos podem rodar na mesma busca, os prazos dos canais
 * deixariam de ser um limite e virariam uma soma: dois orçamentos de 45 s dariam
 * 90 s de pior caso, acima dos ~60 s que o frontend espera. O teto global é o
 * prazo absoluto que **nenhum** canal pode ultrapassar: o [`TorrentService`] o
 * define no início da busca e `abrir()` corta cada prazo nele. Assim o primeiro
 * método pode gastar o seu orçamento à vontade, mas o segundo só enxerga o que
 * sobrou do teto — e a soma nunca passa do que o frontend tolera.
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
     * Prazo absoluto, em marca de tempo, que nenhum canal pode ultrapassar.
     *
     * Fica `null` enquanto o [`TorrentService`] não o define no início da busca;
     * sem teto, cada canal vale só pelo próprio orçamento.
     */
    private ?float $tetoGlobal = null;

    /**
     * Define o teto absoluto da busca inteira, contado a partir de agora.
     *
     * Enquanto ele estiver de pé, todo `abrir()` corta o prazo do canal no teto —
     * um canal jamais é esticado para além dele. Sem o teto, dois métodos que
     * rodam na mesma busca somariam os seus orçamentos; com ele, o segundo método
     * só enxerga o tempo que sobrou da busca.
     */
    public function definirTetoGlobal(int $segundos): void
    {
        $this->tetoGlobal = microtime(true) + max(1, $segundos);
    }

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
        $prazo = microtime(true) + max(1, $segundos);

        // O teto da busca inteira nunca é esticado por um canal: o stream direto
        // pode pedir 45 s, mas se só restarem 10 s do teto, são 10 s.
        if ($this->tetoGlobal !== null) {
            $prazo = min($prazo, $this->tetoGlobal);
        }

        $this->prazos[$canal ?? $this->canalAtivo] = $prazo;
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
        $this->tetoGlobal = null;
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
