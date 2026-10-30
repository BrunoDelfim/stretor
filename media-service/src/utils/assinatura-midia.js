/**
 * Diz se um trecho lido tem cara de mídia, e não de página de erro.
 *
 * A régua nasceu no backend (`ClienteHttp::pareceVideoServido()`) e foi trazida
 * para cá quando a verificação passou a provar bytes: o media-service é quem lê
 * o começo do arquivo, então é ele quem precisa reconhecer o que leu.
 *
 * São duas provas independentes: o `Content-Type` declarado e a assinatura dos
 * primeiros bytes (`ftyp` de MP4/MOV, `#EXTM3U` de HLS, `ID3` de MP3). A
 * segunda existe porque há CDN que serve MP4 como `application/octet-stream` e
 * playlist como `text/plain`; a primeira cobre o caso contrário, de um MP4
 * fragmentado cujo cabeçalho não começa com `ftyp`.
 *
 * O caso que originou a régua: `pobreflixtv.democrat` publicava
 * `/manifest.webm`, que respondia `301` para a home, a home vinha
 * `200 text/html` e a sonda antiga aprovava — o `ffprobe` recusava só na hora
 * de tocar, com o usuário já esperando.
 *
 * @param {Buffer} corpo bytes lidos do começo da fonte
 * @param {string} tipo `Content-Type` declarado (pode vir vazio)
 */
export function pareceMidia(corpo, tipo = '') {
  const tipoNormalizado = String(tipo ?? '').toLowerCase()

  if (
    tipoNormalizado.startsWith('video/') ||
    tipoNormalizado.includes('mpegurl') ||
    tipoNormalizado.includes('octet-stream')
  ) {
    return true
  }

  const inicio = corpo.subarray(0, 64).toString('latin1')

  return inicio.includes('ftyp') || /#EXTM3U/i.test(inicio) || inicio.startsWith('ID3')
}
