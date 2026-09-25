<?php

namespace App\Services\Torrents;

use App\Enums\IdiomaFonte;
use App\Support\MensagensTorrent;

/**
 * Normalização comum a todos os provedores.
 *
 * Cada provedor devolve campos diferentes (o APIBay dá `info_hash` e `seeders`,
 * o Torrentio dá `infoHash` e o número de seeds embutido no texto, os HTML dão
 * o link do magnet). O que **não** pode variar é o contrato consumido pelo
 * frontend. Esta trait concentra a tradução e as regras compartilhadas, para não
 * existirem cinco versões da mesma dedução de qualidade.
 */
trait NormalizaFonte
{
    /**
     * Piso de seeds para os provedores que não publicam a contagem de peers.
     *
     * Ao contrário do que acontece com os indexadores, os trackers raspados por
     * HTML raramente informam seeds. Zerar essas fontes faria o catálogo
     * descartá-las como mortas (a regra é "sem seeds, sem dados") — e o alvo do
     * sistema é justamente o release dublado que só esse tracker tem. O piso de 1
     * mantém a fonte na lista e a validação real de peers acontece na prática,
     * quando o media-service tenta conectar; se ela estiver morta, o player
     * simplesmente avança para a próxima.
     */
    protected const SEEDS_NAO_MEDIDOS = 1;

    /**
     * Monta uma fonte já no contrato do frontend.
     *
     * @param  array<string, mixed>  $dados  Campos crus do provedor
     */
    protected function montarFonte(array $dados, string $provedor, string $rotuloProvedor): array
    {
        $titulo = $this->limparTexto((string) ($dados['titulo'] ?? ''));
        $magnet = $this->completarMagnet(trim((string) ($dados['magnet'] ?? '')));

        /*
         * O campo de idioma do provedor, quando existe, é informação melhor que
         * a tag do título — só recorremos à dedução pelo nome quando ele não vem
         * ou não é reconhecido.
         */
        $idioma = IdiomaFonte::deduzirDoIdioma((string) ($dados['idioma'] ?? ''))
            ?? IdiomaFonte::deduzirDoTitulo($titulo);

        $id = trim((string) ($dados['id'] ?? ''));

        if ($id === '') {
            $id = $this->idDoMagnet($magnet);
        }

        return [
            'id' => $id,
            'titulo' => $titulo,
            'qualidade' => $this->deduzirQualidade($titulo),
            'idioma' => $idioma->value,
            'idioma_rotulo' => $idioma->rotulo(),
            'tamanho' => $this->formatarTamanho($dados['tamanho_bytes'] ?? null),
            'seeds' => (int) ($dados['seeds'] ?? 0),
            'peers' => (int) ($dados['peers'] ?? 0),
            'magnet' => $magnet,
            'provedor' => $provedor,
            'provedor_rotulo' => $rotuloProvedor,
        ];
    }

    /**
     * Monta o magnet a partir de um infohash.
     *
     * É o caminho de todos os provedores que informam só o hash (APIBay,
     * Torrentio). O nome entra no `dn` porque alguns clientes o usam como nome
     * amigável e alguns trackers exigem o parâmetro.
     */
    protected function magnetDoHash(string $hash, string $nome = ''): string
    {
        $hash = strtolower(trim($hash));

        if ($hash === '') {
            return '';
        }

        $magnet = 'magnet:?xt=urn:btih:'.$hash;

        if ($nome !== '') {
            $magnet .= '&dn='.rawurlencode($nome);
        }

        return $this->completarMagnet($magnet);
    }

    /**
     * Acrescenta os anunciadores públicos ausentes a um magnet.
     *
     * Provedores que já vêm com trackers (BT4G, Torrentio) não perdem os deles:
     * só completamos o que falta. Sem isso, um magnet vindo com um único tracker
     * morto não monta malha e a sessão fica presa em "aguardando".
     */
    protected function completarMagnet(string $magnet): string
    {
        if ($magnet === '') {
            return '';
        }

        foreach (TrackersPublicos::LISTA as $tracker) {
            if (str_contains($magnet, rawurlencode($tracker)) || str_contains($magnet, $tracker)) {
                continue;
            }

            $magnet .= '&tr='.rawurlencode($tracker);
        }

        return $magnet;
    }

    /**
     * Extrai o infohash de um magnet, usado como identificador da fonte.
     *
     * O `id` é o que permite deduplicar a mesma release vinda de dois provedores
     * diferentes; sem ele, a mesma fonte apareceria duas vezes na lista. Quando
     * não há hash reconhecível, cai no md5 do magnet inteiro — pior, mas único.
     */
    protected function idDoMagnet(string $magnet): string
    {
        if (preg_match('/urn:btih:([a-zA-Z0-9]{32,40})/', $magnet, $achados)) {
            return strtolower($achados[1]);
        }

        return $magnet !== '' ? md5($magnet) : '';
    }

    /**
     * Deduz a qualidade a partir do título do arquivo.
     *
     * Nenhum provedor público expõe um campo de qualidade confiável; a resolução
     * vem no nome ("1080p", "2160p", "WEB-DL"). A busca é por marcador e a ordem
     * importa: "2160p" precisa ser testado antes de "1080p" para não casar com o
     * "080p" de dentro dele.
     */
    protected function deduzirQualidade(string $titulo): string
    {
        $texto = mb_strtolower($titulo);

        foreach (['2160p', '4k', '1080p', '720p', '480p', '360p'] as $marcador) {
            if (str_contains($texto, $marcador)) {
                return strtoupper($marcador);
            }
        }

        return MensagensTorrent::QUALIDADE_NAO_INFORMADA;
    }

    /** Converte bytes em rótulo legível (ex.: "2,1 GB"). */
    protected function formatarTamanho(?int $bytes): ?string
    {
        if (empty($bytes) || $bytes <= 0) {
            return null;
        }

        $unidades = ['B', 'KB', 'MB', 'GB', 'TB'];
        $indice = (int) floor(log($bytes, 1024));
        $indice = min($indice, count($unidades) - 1);
        $valor = $bytes / (1024 ** $indice);

        return number_format($valor, $indice > 1 ? 1 : 0, ',', '.').' '.$unidades[$indice];
    }

    /**
     * Interpreta o tamanho em texto ("2.1 GB", "850 MiB") para bytes.
     *
     * Os provedores de HTML publicam o tamanho já formatado; para manter o mesmo
     * contrato dos que informam bytes, convertemos de volta. Sem a conversão, a
     * coluna de tamanho sairia vazia só nos provedores raspados.
     */
    protected function tamanhoEmBytes(?string $texto): ?int
    {
        if ($texto === null || trim($texto) === '') {
            return null;
        }

        $texto = str_replace(',', '.', $texto);

        if (! preg_match('/([\d.]+)\s*([KMGT]?i?B)/i', $texto, $achados)) {
            return null;
        }

        $valor = (float) $achados[1];
        $unidade = strtoupper($achados[2][0]);

        $potencias = ['B' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4];

        if (! isset($potencias[$unidade])) {
            return null;
        }

        return (int) round($valor * (1024 ** $potencias[$unidade]));
    }

    /**
     * Limpa o texto do título vindo de HTML.
     *
     * As páginas vêm com entidades (`&`, `&#8211;`), espaços não separáveis e
     * quebras de linha. Sem isso, os títulos apareceriam truncados ou com tags de
     * idioma grudadas em caracteres invisíveis, o que quebra a dedução de idioma.
     */
    protected function limparTexto(string $texto): string
    {
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $texto = str_replace(["\xc2\xa0", "\n", "\r", "\t"], ' ', $texto);

        return trim((string) preg_replace('/\s+/', ' ', $texto));
    }
}
