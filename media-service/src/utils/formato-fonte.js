/**
 * Reconhecimento do formato de uma fonte direta.
 *
 * O `media-service` recebe a URL de um vídeo já resolvido e precisa decidir como
 * o FFmpeg vai lê-la. A extensão da URL era a única pista, e ela falta nos links
 * que saem dos resolvedores de embed: o StreamTape entrega por
 * `https://streamtape.com/get_video?id=...&token=...` e redireciona para um
 * caminho de CDN com o nome do filme — nenhum dos dois termina em `.mp4`.
 *
 * Recusar essas URLs por "formato desconhecido" descartava um MP4 de 1,6 GB que
 * o FFmpeg abre sem dificuldade: o formato estava no `Content-Type`, não no
 * texto do link. Este módulo concentra a tradução (`Content-Type` → extensão) e
 * a assinatura dos primeiros bytes, para a decisão ficar testável sem rede e sem
 * subir o cliente de torrent.
 */

/**
 * Extensões de vídeo que o provedor direto pode entregar.
 *
 * Diferente do torrent, aqui não há malha nem escolha de arquivo: a URL já
 * aponta para o vídeo. A extensão serve só para decidir como o FFmpeg lê a
 * entrada — um `.m3u8` é uma playlist HLS (o FFmpeg a segue sozinho) e os
 * demais são arquivos progressivos.
 */
export const EXTENSOES_DIRETAS = ['.mp4', '.m4v', '.webm', '.mkv', '.mov', '.m3u8', '.mpd']

/**
 * Tipos de conteúdo que identificam o formato de uma fonte sem extensão.
 *
 * A lista é curta de propósito: só entra o que o FFmpeg lê como arquivo
 * progressivo ou playlist HLS. Qualquer outro tipo continua recusado, porque
 * aceitar um `text/html` de página de erro entregaria ao player uma sessão que
 * morre na abertura.
 *
 * @type {Record<string, string>}
 */
const EXTENSOES_POR_TIPO = {
  'video/mp4': '.mp4',
  'video/webm': '.webm',
  'video/quicktime': '.mov',
  'video/x-matroska': '.mkv',
  'video/x-m4v': '.m4v',
  'application/vnd.apple.mpegurl': '.m3u8',
  'application/x-mpegurl': '.m3u8',
  'audio/mpegurl': '.m3u8',
  'audio/x-mpegurl': '.m3u8',
}

/**
 * Traduz um `Content-Type` na extensão correspondente.
 *
 * A comparação ignora parâmetros (`; charset=...`) e caixa, porque cada CDN
 * publica o mesmo tipo de um jeito: `video/mp4`, `Video/MP4`,
 * `application/vnd.apple.mpegurl; charset=utf-8`.
 *
 * @param {string|null|undefined} tipo
 * @returns {string} extensão reconhecida, ou `''`
 */
export function extensaoPeloTipo(tipo) {
  const limpo = String(tipo ?? '')
    .split(';')[0]
    .trim()
    .toLowerCase()

  return EXTENSOES_POR_TIPO[limpo] ?? ''
}

/**
 * Reconhece o formato pelos primeiros bytes, quando o servidor não declara tipo.
 *
 * O `application/octet-stream` é a resposta preguiçosa de muito CDN, e nesse
 * caso só a assinatura do arquivo separa um MP4 de uma página de erro. A
 * assinatura do MP4 fica no **offset 4** (`....ftyp`), não no começo; a do WebM
 * e a do HLS são as conhecidas.
 *
 * @param {Buffer} amostra
 * @returns {string} extensão reconhecida, ou `''`
 */
export function extensaoPelaAmostra(amostra) {
  if (amostra.length >= 4
    && amostra[0] === 0x1a && amostra[1] === 0x45 && amostra[2] === 0xdf && amostra[3] === 0xa3) {
    return '.webm'
  }

  if (amostra.length >= 12 && amostra.subarray(4, 8).toString('latin1') === 'ftyp') return '.mp4'

  if (amostra.subarray(0, 7).toString('latin1') === '#EXTM3U') return '.m3u8'

  return ''
}
