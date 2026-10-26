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
 * Traduz o `format_name` do ffprobe na extensão que o FFmpeg espera.
 *
 * O ffprobe devolve uma lista de contêineres compatíveis entre si
 * (`mov,mp4,m4a,3gp,3g2,mj2`) ou o nome do formato de transporte (`hls`,
 * `mpegts`, `matroska,webm`). A ordem das checagens importa: `matroska,webm`
 * contém `webm`, e um `.webm` é lido como Matroska pelo FFmpeg de qualquer
 * forma — por isso o Matroska vem antes.
 *
 * @param {string|null|undefined} formato
 * @returns {string} extensão reconhecida, ou `''`
 */
export function extensaoPeloFormato(formato) {
  const nome = String(formato ?? '').toLowerCase()

  if (nome === '') return ''
  if (nome.includes('hls')) return '.m3u8'
  if (nome.includes('matroska') || nome.includes('webm')) return '.mkv'
  if (nome.includes('mov') || nome.includes('mp4') || nome.includes('3gp')) return '.mp4'
  if (nome.includes('mpegts')) return '.mp4'

  return ''
}
