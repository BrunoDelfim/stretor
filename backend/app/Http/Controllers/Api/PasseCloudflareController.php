<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Torrents\ConferenciaDoPasse;
use App\Services\Torrents\PasseCloudflare;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Recebe o passe do Cloudflare que o navegador do usuário obteve.
 *
 * O host que fecha a página do episódio com o widget Turnstile — o
 * `superflixapi.quest` — não é vencido por servidor nenhum: o FlareSolverr
 * devolve a mesma tela de verificação (`Challenge not detected!` no log dele) e o
 * resto é um clique humano. Quem vence é a aba do usuário, e o prêmio é um cookie
 * (`cf_clearance`) e/ou um token na query (`cfv`), com prazo anunciado pelo
 * próprio host: 45 minutos.
 *
 * Como navegador e backend saem pelo **mesmo IP**, o passe vale para os dois.
 * Este endpoint é a ponte: com ele, colar o passe não exige editar o `.env` e
 * recriar o container a cada 45 minutos — o que importa quando o assunto é
 * experimentar.
 *
 * O passe vai para o cache com o prazo do host. Nada é gravado em disco, e o
 * próprio cache o esquece quando vence — que é a única forma de o sistema não
 * insistir num passe morto.
 *
 * E há a conferência, que é o outro lado da ponte: colar o passe não prova nada,
 * porque o host recusa o par trocado devolvendo a mesma tela de verificação de
 * quem não apresentou nada. O [`ConferenciaDoPasse`] pede a página na hora, com
 * o passe anexado, e diz se voltou a verificação ou o episódio.
 */
class PasseCloudflareController extends Controller
{
    public function __construct(
        private readonly PasseCloudflare $passe,
        private readonly ConferenciaDoPasse $conferencia,
    ) {}

    /**
     * O estado do passe em mãos: quais hosts têm portão, se há passe e quando ele
     * vence. É o que a bancada mostra antes de pedir para colar outro.
     *
     * O agente vai junto porque o passe vale para o par IP+agente: sem ele, a
     * bancada não teria como dizer se o backend está se apresentando com o mesmo
     * navegador que venceu o widget — e o host recusa um passe de par trocado
     * devolvendo a mesma tela de verificação, sem explicar por quê.
     */
    public function estado(): JsonResponse
    {
        return response()->json([
            'hosts' => $this->passe->hosts(),
            'disponivel' => $this->passe->disponivel(),
            'configurado' => $this->passe->configurado(),
            'expira_em' => $this->passe->expiraEm(),
            'prazo' => $this->passe->prazo(),
            'agente' => $this->passe->agente(),
        ]);
    }

    /**
     * Confere o passe em mãos contra o host, agora, e diz o que o backend recebeu.
     *
     * É a resposta que a bancada não tem como dar sozinha. O host recusa um passe
     * de par trocado — IP ou agente — devolvendo exatamente a mesma tela de
     * verificação de quem não apresentou nada, e "o passe está guardado" não
     * distingue os dois casos. Aqui o backend pede a página de verdade, com o
     * passe anexado, e relata se voltou a verificação ou a página do episódio,
     * junto do IP por onde ele sai.
     *
     * O agente e o carimbo voltam na resposta porque são os dois lados da
     * comparação que sobra quando a conferência dá verificação: o carimbo diz há
     * quanto tempo o passe nasceu, e o agente diz em nome de qual navegador o
     * backend está se apresentando.
     */
    public function conferir(Request $requisicao): JsonResponse
    {
        $dados = $requisicao->validate([
            'url' => ['required', 'string', 'max:2048'],
        ]);

        $url = trim($dados['url']);

        /*
         * A conferência busca o endereço que lhe mandarem, então só aceita
         * endereço de host com portão, em https. Aceitar qualquer URL faria deste
         * endpoint um proxy aberto para dentro da rede — e o que interessa
         * conferir é justamente o host que fecha a página no desafio.
         */
        if (! str_starts_with(strtolower($url), 'https://') || ! $this->passe->exige($url)) {
            return response()->json([
                'erro' => 'A conferência testa só endereços https dos hosts com portão.',
                'hosts' => $this->passe->hosts(),
            ], 422);
        }

        return response()->json($this->conferencia->executar($url));
    }

    /**
     * Guarda o passe colado.
     *
     * Um dos dois precisa vir: o cookie ou o token. Sem nenhum, não há passe — e
     * aceitar o pedido vazio daria ao usuário a impressão de que o portão foi
     * resolvido.
     */
    public function registrar(Request $requisicao): JsonResponse
    {
        $dados = $requisicao->validate([
            'clearance' => ['nullable', 'string', 'max:4096'],
            'token' => ['nullable', 'string', 'max:4096'],
            'agente' => ['nullable', 'string', 'max:512'],
        ]);

        $clearance = trim((string) ($dados['clearance'] ?? ''));
        $token = trim((string) ($dados['token'] ?? ''));

        if ($clearance === '' && $token === '') {
            return response()->json([
                'erro' => 'Informe o cookie `cf_clearance` ou o token `cfv`.',
            ], 422);
        }

        $this->passe->registrar($clearance, $token, (string) ($dados['agente'] ?? ''));

        /*
         * O aviso sai junto da confirmação porque é agora que ele serve: um token
         * colado no campo do cookie não é recusado — pode ser um formato novo do
         * Cloudflare —, mas quase sempre é o grant do widget no campo errado, e a
         * única pista de que isso aconteceu seria a tela de verificação voltando
         * meia hora depois, sem explicação.
         */
        if ($this->passe->pareceTokenSolto($clearance)) {
            return response()->json([
                'disponivel' => true,
                'expira_em' => $this->passe->expiraEm(),
                'aviso' => 'O que foi colado não traz nome de cookie nem a forma de um `cf_clearance`: '
                    .'se é um token do widget, o lugar dele é o campo `cfv`.',
            ]);
        }

        return response()->json([
            'disponivel' => true,
            'expira_em' => $this->passe->expiraEm(),
        ]);
    }

    /**
     * Descarta o passe guardado. Serve para colar um novo sem esperar o prazo do
     * anterior — um passe vencido guardado é pior que nenhum, porque mascara a
     * causa da tela de verificação.
     */
    public function esquecer(): JsonResponse
    {
        $this->passe->esquecer();

        return response()->json([
            'disponivel' => $this->passe->disponivel(),
            'configurado' => $this->passe->configurado(),
        ]);
    }
}
