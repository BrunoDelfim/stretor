<?php

namespace App\Services\Torrents;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A conferência do passe: o que o backend recebe quando pede a página, agora.
 *
 * O passe guardado não prova nada sozinho. Ele vale para o par **IP + agente**
 * que venceu o widget, e o host recusa o par trocado devolvendo exatamente a
 * mesma tela de verificação de quem não apresentou nada — sem uma linha
 * explicando o motivo. Três causas se parecem por fora e são idênticas por
 * dentro:
 *
 * 1. o que foi colado não é o que o host espera — veio só o cookie, faltou o
 *    `cfv`, ou o que se colou foi o token do widget;
 * 2. o agente guardado não é o da aba que venceu o widget (o passe vive do par);
 * 3. a aba que venceu sai por outro IP que não o do backend.
 *
 * Nenhuma delas se distingue pela resposta, e todas terminam na mesma pergunta:
 * *o backend abriu a página ou continua na verificação?* É essa resposta que
 * este serviço busca — com uma requisição de verdade, feita na hora e assinada
 * pelo passe em mãos —, junto do IP por onde o backend sai, que é justamente a
 * metade da equação que a bancada não tem como medir sozinha.
 */
class ConferenciaDoPasse
{
    /**
     * O endereço que devolve o IP de saída de quem pede.
     *
     * O `cdn-cgi/trace` do Cloudflare é a forma mais curta de perguntar "por onde
     * este pedido saiu?", e é o mesmo valor que a aba do usuário vê ao abrir o
     * endereço no navegador — a comparação é direta, linha `ip=` com linha `ip=`.
     */
    private const ENDERECO_DO_IP = 'https://www.cloudflare.com/cdn-cgi/trace';

    /**
     * Quanto a conferência espera pelo host.
     *
     * É um teste, não uma busca: o host do desafio responde a tela de verificação
     * em menos de um segundo e a página liberada em poucos, então o teto existe
     * só para não pendurar a interface quando o site não responde nada.
     */
    private const PRAZO_SEGUNDOS = 20;

    /**
     * Quanto espera pela resposta do IP de saída, que é uma linha de texto.
     */
    private const PRAZO_DO_IP_SEGUNDOS = 8;

    public function __construct(
        private readonly ClienteHttp $cliente,
        private readonly PasseCloudflare $passe,
    ) {}

    /**
     * Testa o passe em mãos contra um endereço do host e relata o que voltou.
     *
     * O caminho é o mesmo da busca de verdade — [`ClienteHttp::get()`], que já
     * anexa o cookie e o agente e reconhece a tela de verificação —, porque uma
     * conferência com caminho próprio mediria outra coisa.
     *
     * @return array<string, mixed>
     */
    public function executar(string $url): array
    {
        $inicio = microtime(true);

        $resposta = $this->cliente->get($url, [], null, self::PRAZO_SEGUNDOS);
        $corpo = (string) ($resposta?->body() ?? '');
        $situacao = $this->situacao($resposta, $corpo);
        $ip = $this->ipDeSaida();

        $disponivel = $this->passe->disponivel();
        $clearance = $this->passe->clearance();
        $carimbo = $this->passe->carimboDoValor($clearance);
        $cookies = $this->passe->nomesDeCookies($clearance);

        return [
            'situacao' => $situacao,
            'url' => $url,
            'status' => $resposta?->status(),
            'titulo' => $this->titulo($corpo),
            'tamanho' => strlen($corpo),
            'ms' => (int) round((microtime(true) - $inicio) * 1000),
            'captcha' => $this->cabecalho($resposta, 'x-cloudflare-captcha'),
            'ttl_minutos' => $this->cabecalho($resposta, 'x-cloudflare-captcha-ttl-minutes'),
            'passe' => $disponivel,
            'agente' => $this->passe->agente(),
            'carimbo' => $carimbo,
            'cookies_do_passe' => $cookies,
            'ip_de_saida' => $ip,
            'familia_de_saida' => $this->familiaDe($ip),
            'veredito' => $this->veredito($situacao, $disponivel, $carimbo, $cookies),
        ];
    }

    /**
     * Lê o que a resposta é: a tela de verificação, a página ou coisa nenhuma.
     *
     * `sem_resposta` é distinto de propósito: sem corpo, o host não recusou o
     * passe — não houve host. Confundir os dois faria procurar defeito no par
     * IP+agente por causa de um DNS que não resolveu.
     */
    private function situacao(?Response $resposta, string $corpo): string
    {
        if ($resposta === null) {
            return 'sem_resposta';
        }

        return $this->passe->paginaDeDesafio($corpo) ? 'desafio' : 'liberado';
    }

    /**
     * O `<title>` da resposta — o rótulo mais curto do que voltou.
     *
     * A tela de verificação se anuncia como `Verificação`, e a página liberada
     * traz o nome do episódio: é a diferença mais fácil de ler de relance.
     */
    private function titulo(string $corpo): ?string
    {
        if (preg_match('#<title[^>]*>(.*?)</title>#is', $corpo, $achado) !== 1) {
            return null;
        }

        $titulo = trim(str_replace("\u{a0}", ' ', strip_tags($achado[1])));

        return $titulo === '' ? null : mb_substr($titulo, 0, 120);
    }

    private function cabecalho(?Response $resposta, string $nome): ?string
    {
        $valor = trim((string) ($resposta?->header($nome) ?? ''));

        return $valor === '' ? null : $valor;
    }

    /**
     * O que a resposta significa, em uma frase, para quem está olhando a bancada.
     *
     * O veredito é escrito para separar as causas — passe ausente, colagem sem
     * `cf_clearance`, par IP+agente trocado — em vez de repetir "não funcionou",
     * que é o que o host já diz calado.
     *
     * @param  array<int, string>  $cookies
     */
    private function veredito(string $situacao, bool $disponivel, ?int $carimbo, array $cookies): string
    {
        return match ($situacao) {
            'liberado' => 'O backend abriu a página com o passe em mãos: ele vale para este par IP + agente.',
            'desafio' => $this->vereditoDaVerificacao($disponivel, $carimbo, $cookies),
            default => 'O host não respondeu: rede, DNS ou prazo. Repita antes de concluir qualquer coisa.',
        };
    }

    /**
     * O veredito do desfecho em que o host devolveu a verificação.
     *
     * São três causas com a mesma cara e três consertos diferentes: não colar
     * nada, colar outra coisa, e colar um passe legítimo que não vale para este
     * par IP + agente. O host responde igual às três, então é aqui que elas se
     * separam.
     *
     * @param  array<int, string>  $cookies
     */
    private function vereditoDaVerificacao(bool $disponivel, ?int $carimbo, array $cookies): string
    {
        if (! $disponivel) {
            return 'Não há passe em mãos: a tela de verificação era o esperado. Cole o passe e confira de novo.';
        }

        if ($carimbo === null) {
            $traz = $cookies === []
                ? 'o que está guardado não traz nome de cookie nenhum'
                : 'o que está guardado traz `'.implode('`, `', $cookies).'` e nenhum `cf_clearance`';

            /*
             * Este é o caso da colagem pelo console: o host marca o `cf_clearance`
             * como `HttpOnly`, e `document.cookie` não o enxerga — a lista colada
             * vem com os irmãos e sem ele. Vale dizer onde ele aparece e onde não,
             * porque o campo fica com cara de preenchido e a tela não muda.
             */
            return 'A conferência recebeu a tela de verificação e '.$traz.' — sem `cf_clearance` não há passe. '
                .'Ele aparece em *Application → Cookies* e no cabeçalho `cookie:` do pedido do episódio '
                .'(*Network*, e o atalho é *botão direito no pedido → Copy as cURL*), mas **não** em '
                .'`document.cookie` quando o host o marca como `HttpOnly`. Copie do cabeçalho e cole de novo.';
        }

        return 'O backend recebeu a tela de verificação mesmo com o passe em mãos. O primeiro suspeito é o IP: '
            .'o passe vale para o IP que o conquistou, e basta a família mudar — a aba sai por IPv6 e o '
            .'container por IPv4 — para o host recusá-lo como se não houvesse nada. Compare o endereço e a '
            .'família abaixo com o `ip=` que a sua aba mostra em https://cloudflare.com/cdn-cgi/trace. '
            .'Depois vêm o agente guardado (precisa ser o da aba que venceu), o nascimento do passe (o carimbo '
            .'é de quando ele foi conquistado: muito antes da última tentativa é passe velho) e uma colagem '
            .'incompleta (o `cf_clearance` sozinho, sem os outros cookies da sessão e sem o `cfv`).';
    }

    /**
     * O IP por onde o backend sai, do ponto de vista do Cloudflare.
     */
    private function ipDeSaida(): ?string
    {
        try {
            $resposta = Http::connectTimeout(3)
                ->timeout(self::PRAZO_DO_IP_SEGUNDOS)
                ->get(self::ENDERECO_DO_IP);
        } catch (Throwable) {
            return null;
        }

        if (! $resposta->successful()) {
            return null;
        }

        if (preg_match('#^ip=(.+)$#mi', $resposta->body(), $achado) !== 1) {
            return null;
        }

        return trim($achado[1]);
    }

    /**
     * A família do IP de saída — `ipv4` ou `ipv6`.
     *
     * Existe porque a comparação que importa não é só de endereço: o passe vale
     * para o IP que o conquistou, e **a família é parte disso**. Medido nesta
     * máquina, o navegador sai por IPv6 (`2804:7f0:...`) e o container por IPv4
     * (`201.43.197.80`), porque a rede do Docker não tem IPv6 — o passe da aba
     * nunca vale para o backend, por mais fresco e bem colado que esteja, e a
     * resposta do host é idêntica à de quem não apresentou nada. Dizer a família
     * junto do endereço é o que transforma "não funcionou" em "sai por outra
     * família", que é uma coisa que se conserta.
     */
    private function familiaDe(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        return str_contains($ip, ':') ? 'ipv6' : 'ipv4';
    }
}
