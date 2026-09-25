import WebTorrent from 'webtorrent'
import path from 'node:path'
import fs from 'node:fs'
import os from 'node:os'
import { Transform } from 'node:stream'
import { v4 as uuid } from 'uuid'

import {
  analisarArquivo,
  iniciarConversao,
  aguardarBufferInicial,
  localizarMoov,
  mapearCaixas,
  somarDuracaoDaPlaylist,
} from './hls.js'
import { logger } from '../utils/logger.js'

/**
 * Gerenciador de sessões de reprodução.
 *
 * Cada sessão representa uma tentativa de assistir a um filme: ela conecta o
 * torrent, escolhe o arquivo de vídeo, decide o modo de conversão e publica o
 * HLS que o player consome. As sessões vivem em memória porque são efêmeras —
 * morrem quando o usuário fecha o player.
 *
 * O cliente WebTorrent é único e compartilhado: abrir um cliente por sessão
 * multiplicaria as conexões de rede sem necessidade.
 */

/*
 * O transporte uTP (utp-native) provoca segfault neste ambiente de container:
 * o processo morre com SIGSEGV sem chance de tratar o erro, derrubando todas
 * as sessões. Desligamos apenas o uTP e mantemos TCP, DHT e trackers, que são
 * suficientes para montar a malha e baixar o filme.
 */
const cliente = new WebTorrent({ utp: false })

/** Mapa `sessao_id → sessão`. */
const sessoes = new Map()

/** Extensões de vídeo que consideramos ao escolher o arquivo do torrent. */
const EXTENSOES_VIDEO = ['.mkv', '.mp4', '.avi', '.mov', '.m4v', '.webm']

/**
 * Tempo máximo aguardando o primeiro byte de dados, em ms.
 *
 * O evento `ready` do torrent só garante que os metadados do magnet foram
 * lidos — não que exista algum peer disposto a enviar dados. Uma fonte sem
 * peers ficava presa em `analisarComEspera` por 120 s, enquanto o frontend já
 * havia desistido aos 90 s. Este limite falha rápido para o frontend seguir
 * para a próxima fonte, e fica abaixo do `TIMEOUT_FONTE_MS` do frontend para
 * que o backend seja o primeiro a desistir.
 */
const TIMEOUT_DADOS_MS = 30000

/**
 * Quantidade de bytes do começo do filme que precisa estar em disco antes de
 * uma conversão ler direto do arquivo.
 *
 * Ler um arquivo parcialmente baixado direto do disco devolve zeros nas partes
 * que ainda não chegaram — diferente do fluxo do WebTorrent, que fica esperando
 * cada pedaço. Com zeros no lugar do começo, o FFmpeg atravessa o trecho
 * inválido e passa a decodificar onde os dados aparecem: o filme "começa" no
 * meio, e o relógio fica em 00:00 porque o muxer HLS ancora a saída em zero.
 * Este é o trecho contíguo desde o byte 0 que exigimos antes de converter do
 * disco.
 */
const BYTES_INICIAIS = 4 * 1024 * 1024

/*
 * A janela de leitura existe porque selecionar o arquivo inteiro (prioridade 1)
 * deixava o *picker* do WebTorrent livre para buscar o mais raro primeiro: o
 * download se espalhava pelo filme e a faixa logo à frente do leitor ficava
 * cheia de buracos — a conversão morria de inanição depois dos primeiros
 * segmentos. Aqui mantemos quente só o que vai ser lido agora.
 *
 * A antecedência é medida em segundos de mídia, não em bytes fixos: numa fonte
 * lenta uma faixa curta não cobriria nem alguns segundos de vídeo, numa fonte
 * rápida seria desperdício. Convertemos segundos em bytes pela vazão medida.
 */
const ANTECEDENCIA_SEGUNDOS = 150
const ANTECEDENCIA_MINIMA_BYTES = 24 * 1024 * 1024
const ANTECEDENCIA_MAXIMA_BYTES = 256 * 1024 * 1024

/** Quanto da cauda já reproduzida fica selecionada, para um seek curto para trás. */
const CAUDA_SELECIONADA_BYTES = 8 * 1024 * 1024

/** Faixa imediata: o que o FFmpeg consome nos próximos instantes. */
const TAMANHO_IMEDIATO_BYTES = 4 * 1024 * 1024

/** Prioridades: o maior valor vence. O índice (`moov`) usa 10 e continua no topo. */
const PRIORIDADE_JANELA = 6
const PRIORIDADE_IMEDIATA = 8

/** Cadência com que a janela e o freio de leitura são reavaliados. */
const PASSO_LEITURA_MS = 700

/*
 * Freio da leitura no caminho de disco: congelamos a conversão quando a distância
 * entre a fronteira contígua do download e a posição já publicada na playlist cai
 * abaixo da margem, e só soltamos quando o download recupera folga suficiente.
 */
const MARGEM_LEITURA_SEGUNDOS = 30
const PASSO_RETOMADA_SEGUNDOS = 20
const MARGEM_LEITURA_BYTES = 32 * 1024 * 1024
const PASSO_RETOMADA_BYTES = 16 * 1024 * 1024

/** Erro interno: a sessão morreu e o preparo deve parar no próximo ponto. */
class SessaoCancelada extends Error {
  constructor() {
    super('Sessão encerrada pelo cliente.')
    this.name = 'SessaoCancelada'
  }
}

/**
 * Interrompe o preparo quando a sessão já não existe mais.
 *
 * Chamado depois de cada espera: o preparo é longo (conectar, baixar o índice,
 * analisar o cabeçalho), e sem esta checagem um player fechado no meio deixaria
 * um FFmpeg escrevendo numa pasta já apagada e um torrent preso no cliente
 * compartilhado.
 */
function conferirSessao(sessao) {
  if (sessao.cancelada) {
    throw new SessaoCancelada()
  }
}

/**
 * Cria uma sessão e começa a preparar a reprodução.
 *
 * A função devolve o id imediatamente: a conexão do torrent e a conversão
 * acontecem em segundo plano, e o frontend acompanha o andamento pelo status.
 *
 * @param {object} opcoes
 * @param {string} opcoes.magnet link magnet da fonte escolhida
 * @param {number|string} opcoes.filmeId identificador do filme (para log)
 * @returns {{sessao_id: string, status: string}}
 */
export function criarSessao({ magnet, filmeId }) {
  const id = uuid()
  const diretorio = path.join(os.tmpdir(), `stretor-${id}`)

  const sessao = {
    id,
    filmeId,
    magnet,
    status: 'conectando',
    mensagem: 'Conectando à fonte...',
    diretorio,
    torrent: null,
    comando: null,
    fluxo: null,
    playlist: null,
    erro: null,
    progresso: null,
    download: null,
    duracao: null,
    // Idioma real da faixa de áudio, lido do contêiner pelo ffprobe; ver
    // `prepararSessao`. É o que confirma (ou desmente) a dublagem prometida
    // pelo nome do arquivo.
    idiomaAudio: null,
    idiomaAudioRotulo: null,
    idiomasAudio: [],
    // Byte corrente da leitura (alimentado pelo contador no caminho de pipe) e o
    // contador em si, para fechar o pipe no encerramento.
    posicaoLeitura: 0,
    contador: null,
    // Janela móvel de seleção de pedaços; ver `iniciarJanela`.
    janela: null,
    // Bandeira de vida da sessão: encerrar muda o valor e o preparo desiste nos
    // pontos de espera em vez de continuar trabalhando para um player fechado.
    cancelada: false,
    criadaEm: Date.now(),
  }

  sessoes.set(id, sessao)

  // Não aguardamos: o frontend consulta o status enquanto isso roda.
  prepararSessao(sessao).catch((erro) => {
    // Um preparo cancelado é o desfecho esperado de quem fechou o player: não
    // é falha e não deve marcar a sessão como erro.
    if (erro instanceof SessaoCancelada || sessao.cancelada) {
      logger.info(`[sessao ${id}] preparo interrompido: a sessão foi encerrada`)
      return
    }

    logger.error(`[sessao ${id}] falha ao preparar:`, erro.message)
    sessao.status = 'erro'
    sessao.erro = erro.message
  })

  return { sessao_id: id, status: sessao.status }
}

/** Converte bytes para MB — só para deixar o log legível. */
function emMB(bytes) {
  return `${(bytes / (1024 * 1024)).toFixed(1)}MB`
}

/**
 * Vazão média do torrent, em bytes por segundo.
 *
 * `downloadSpeed` é uma média móvel do WebTorrent e é a régua para traduzir
 * "segundos de mídia" em bytes. Quando ela ainda não foi medida, estimamos pela
 * duração: um filme de 2h em 4GB dá cerca de 560KB/s.
 */
function bytesPorSegundo(sessao, arquivo) {
  const velocidade = sessao.torrent?.downloadSpeed ?? 0

  if (velocidade > 0) return velocidade

  return sessao.duracao ? arquivo.length / sessao.duracao : 0
}

function bytesDoTempo(sessao, arquivo, segundos) {
  const vazao = bytesPorSegundo(sessao, arquivo)

  return vazao ? Math.round(vazao * segundos) : null
}

/** Tamanho da antecedência da janela, entre o mínimo e o máximo. */
function tamanhoDaJanela(sessao, arquivo) {
  const alvo = bytesDoTempo(sessao, arquivo, ANTECEDENCIA_SEGUNDOS) ?? ANTECEDENCIA_MINIMA_BYTES

  return Math.min(Math.max(alvo, ANTECEDENCIA_MINIMA_BYTES), ANTECEDENCIA_MAXIMA_BYTES)
}

/** Margem de folga mínima antes de frear a leitura (bytes). */
function margemDeLeitura(sessao, arquivo) {
  return Math.max(bytesDoTempo(sessao, arquivo, MARGEM_LEITURA_SEGUNDOS) ?? MARGEM_LEITURA_BYTES, MARGEM_LEITURA_BYTES)
}

/** Folga que o download precisa recuperar para soltar a leitura (bytes). */
function passoDeRetomada(sessao, arquivo) {
  return Math.max(bytesDoTempo(sessao, arquivo, PASSO_RETOMADA_SEGUNDOS) ?? PASSO_RETOMADA_BYTES, PASSO_RETOMADA_BYTES)
}

/**
 * Duração de mídia já publicada na playlist.
 *
 * É a posição confiável da conversão no caminho de disco: o `progress` do FFmpeg
 * tem resolução de segundos e chega devagar, enquanto cada `#EXTINF` é escrito
 * quando um segmento fecha. Fica no máximo um segmento atrás da leitura real.
 */
function tempoPublicado(sessao) {
  try {
    const conteudo = fs.readFileSync(path.join(sessao.diretorio, 'playlist.m3u8'), 'utf8')

    return somarDuracaoDaPlaylist(conteudo)
  } catch {
    // A playlist ainda não existe (conversão recém-iniciada).
    return 0
  }
}

/**
 * Mede a fronteira contígua do arquivo: quantos bytes seguidos já estão em disco.
 *
 * É o inverso de `faixaPresente` — aqui não interessa se um trecho específico
 * chegou, e sim onde o download parou. O índice da peça é guardado como marca
 * d'água que só avança, então cada chamada varre apenas o que há de novo.
 */
function medirFronteira(janela, torrent, arquivo) {
  const tamanhoPeca = torrent?.pieceLength

  if (!tamanhoPeca) return null

  const base = arquivo.offset ?? 0
  const ultimaPeca = Math.ceil((base + arquivo.length) / tamanhoPeca)
  let indice = janela.fronteiraIndice

  while (indice < ultimaPeca) {
    const presente = pecaPresente(torrent, indice)

    // Sem o mapa de pedaços não há medida possível.
    if (presente === null) return null
    if (!presente) break

    indice += 1
  }

  janela.fronteiraIndice = indice

  return Math.min(Math.max(indice * tamanhoPeca - base, 0), arquivo.length)
}

/**
 * Mantém uma janela móvel de pedaços selecionados logo à frente da leitura.
 *
 * Selecionar o arquivo inteiro com uma prioridade única deixa o *picker* do
 * WebTorrent buscar o mais raro primeiro: o download se espalha e a faixa à
 * frente do leitor fica cheia de buracos — foi assim que a reprodução parou
 * depois dos primeiros segmentos. Aqui priorizamos só o que vai ser lido agora e
 * soltamos a cauda já reproduzida, para a banda atacar o que interessa.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {() => number} posicao devolve o byte corrente da leitura
 * @returns {{parar: () => void, usarPosicao: (fn: () => number) => void, segurarLeitura: (comando: object) => void}}
 */
function iniciarJanela(sessao, arquivo, posicao) {
  const torrent = sessao.torrent
  let fontePosicao = posicao

  const janela = {
    selecionada: null,
    fronteiraIndice: Math.floor((arquivo.offset ?? 0) / (torrent?.pieceLength || 1)),
    retomada: null,
    comando: null,
  }

  /*
   * `deselect` só existe nas versões mais recentes do WebTorrent. Sem ele a
   * janela continua funcionando — apenas não solta a cauda, e o download fica
   * mais espalhado do que o ideal. Nada quebra.
   */
  const podeSoltar = typeof arquivo.deselect === 'function'

  const lerPosicao = () => Math.max(0, Math.min(fontePosicao() || 0, arquivo.length))

  const deslizar = () => {
    const lida = lerPosicao()
    const tamanho = tamanhoDaJanela(sessao, arquivo)

    const de = Math.max(0, lida - CAUDA_SELECIONADA_BYTES)
    const ate = Math.min(arquivo.length, lida + tamanho)
    const anterior = janela.selecionada

    if (anterior && podeSoltar && de > anterior[0]) {
      try {
        arquivo.deselect(anterior[0], Math.min(de, anterior[1]))
      } catch (erro) {
        logger.warn(`[sessao ${sessao.id}] falha ao soltar a cauda da janela:`, erro.message)
      }
    }

    arquivo.select(PRIORIDADE_JANELA, de, ate)

    // A faixa imediata vai com prioridade maior: é o que o FFmpeg lê agora.
    arquivo.select(PRIORIDADE_IMEDIATA, lida, Math.min(ate, lida + TAMANHO_IMEDIATO_BYTES))

    janela.selecionada = [de, ate]
  }

  /*
   * Freio da leitura, usado no caminho de disco: ali o FFmpeg lê em velocidade de
   * CPU, muito à frente do download, e um arquivo esparso devolve zeros no que
   * ainda não chegou — sem erro nenhum. Ele atravessaria o buraco e "concluiria"
   * o filme num ponto arbitrário. Quando a folga entre a fronteira contígua e a
   * posição já publicada encolhe, congelamos o processo e o soltamos depois que o
   * download recupera espaço.
   */
  const conferirLeitura = () => {
    if (!janela.comando) return

    const fronteira = medirFronteira(janela, torrent, arquivo)

    if (fronteira === null) return

    const folga = fronteira - lerPosicao()

    if (!janela.retomada) {
      const minima = margemDeLeitura(sessao, arquivo)

      if (folga >= minima) return

      janela.retomada = minima + passoDeRetomada(sessao, arquivo)
      janela.comando.pausar?.()
      logger.info(
        `[sessao ${sessao.id}] leitura colada no download (folga de ${emMB(Math.max(folga, 0))}) — pausando a conversão`
      )
      return
    }

    if (folga >= janela.retomada) {
      janela.retomada = null
      janela.comando.retomar?.()
      logger.info(`[sessao ${sessao.id}] download recuperou a folga (${emMB(folga)}) — retomando a conversão`)
    }
  }

  const conferir = () => {
    try {
      deslizar()
      conferirLeitura()
    } catch (erro) {
      logger.warn(`[sessao ${sessao.id}] falha ao ajustar a janela de leitura:`, erro.message)
    }
  }

  conferir()

  const temporizador = setInterval(conferir, PASSO_LEITURA_MS)

  // A janela não deve, sozinha, manter o processo acordado.
  temporizador.unref?.()

  return {
    usarPosicao: (fn) => {
      fontePosicao = fn
    },
    segurarLeitura: (comando) => {
      janela.comando = comando
    },
    parar: () => {
      clearInterval(temporizador)

      // Um processo congelado precisa ser solto antes de ser morto.
      if (janela.retomada) {
        janela.comando?.retomar?.()
        janela.retomada = null
      }

      janela.comando = null
    },
  }
}

/**
 * Conecta o torrent e inicia a conversão.
 *
 * Há dois caminhos, decididos pela posição do índice do contêiner:
 *
 * - **MKV/WebM e MP4 *faststart*** trazem o índice no começo e fluem por um
 *   pipe. Abrimos um fluxo do arquivo e o entregamos ao FFmpeg, que publica os
 *   segmentos conforme os bytes chegam — o player começa antes do fim do
 *   download.
 * - **MP4/MOV com o `moov` no fim** não fluem por pipe: o FFmpeg lê o MP4
 *   sequencialmente e não consegue buscar o índice depois de atravessar o
 *   `mdat`. Reordenar o fluxo também não serve, porque as tabelas de amostras
 *   do `moov` guardam offsets absolutos do arquivo original. Nesses casos
 *   aguardamos o download completo e convertemos do disco.
 *
 * @param {object} sessao
 */
async function prepararSessao(sessao) {
  const torrent = await adicionarTorrent(sessao.magnet)

  /*
   * A sessão pode ter morrido enquanto o magnet conectava (o usuário desistiu
   * da fonte ou fechou o player). O torrent acabou de entrar no cliente
   * compartilhado sem que ninguém o registrasse na sessão, e ficaria vivo para
   * sempre — sendo reaproveitado pela próxima abertura do mesmo filme. Removemos
   * aqui mesmo e abandonamos o preparo.
   */
  if (sessao.cancelada) {
    removerTorrent(sessao, torrent)
    throw new SessaoCancelada()
  }

  sessao.torrent = torrent

  sessao.status = 'aguardando'
  sessao.mensagem = 'Aguardando dados da fonte...'

  const arquivo = escolherArquivoDeVideo(torrent)

  if (!arquivo) {
    throw new Error('A fonte não contém um arquivo de vídeo reconhecido.')
  }

  // `arquivo.path` é relativo à pasta do torrent (ex.: "Filme (1999)/filme.mp4"),
  // não ao sistema de arquivos. Sem juntar com `torrent.path`, o FFmpeg procura
  // o arquivo no diretório de trabalho do processo e falha com "No such file".
  const caminho = path.join(torrent.path, arquivo.path)

  /*
   * O download passa a ser guiado por uma janela móvel, não pela seleção do
   * arquivo inteiro: no começo o leitor ainda não tem posição — o caminho de
   * disco a descobre pela playlist, o pipe pelo contador de bytes — e a janela se
   * reajusta sozinha em segundo plano. Queremos a faixa à frente da leitura
   * sempre quente, em vez de um download espalhado pelo filme inteiro.
   */
  sessao.janela = iniciarJanela(sessao, arquivo, () => sessao.posicaoLeitura)

  /*
   * O download corre junto com a conversão; só registramos o andamento para
   * dar contexto no overlay. Não bloqueia.
   */
  acompanharDownload(sessao, torrent)

  /*
   * Antes de qualquer análise, confirmamos que a fonte realmente envia dados. O
   * `ready` do torrent só diz que os metadados foram lidos; sem este passo, uma
   * fonte sem peers ficava 120 s em `analisarComEspera` enquanto o frontend já
   * tinha desistido. Falhamos rápido para o frontend tentar a próxima fonte.
   */
  await aguardarDados(sessao, torrent)
  conferirSessao(sessao)

  /*
   * Se o índice ainda não estiver visível, é quase certo que ele esteja no fim
   * do arquivo — o formato usual dos lançamentos em MP4. Pedimos a cauda já
   * agora, antes de esperar o cabeçalho: a ordem natural do download é do
   * começo para o fim, e sem isso a espera pelo índice viraria a espera pelo
   * filme inteiro.
   */
  await anteciparCauda(sessao, arquivo, caminho)
  conferirSessao(sessao)

  /*
   * A leitura do cabeçalho vem ANTES da decisão do caminho de conversão, e essa
   * ordem é o que torna a decisão confiável. Para um MP4 com o `moov` no fim, o
   * índice só passa a existir em disco depois desta espera — decidir antes disso
   * levava ao pipe, onde o FFmpeg não consegue voltar para o `mdat` e acaba
   * decodificando a partir de um ponto arbitrário, com o relógio em 00:00.
   */
  const analise = await analisarComEspera(sessao, caminho, arquivo)
  conferirSessao(sessao)

  /*
   * Guardamos a duração assim que o ffprobe a lê. O Plyr não consegue deduzi-la
   * de uma playlist `EVENT` em crescimento, então o frontend a usa como fonte
   * de verdade enquanto a conversão não termina.
   */
  sessao.duracao = analise.duracao ?? null

  /*
   * Registramos o idioma real da faixa de áudio lida do contêiner. O nome do
   * arquivo no torrent promete dublagem, mas quem diz o que está lá dentro é a
   * faixa — e essa informação sobe no status para o usuário não descobrir um
   * áudio trocado só depois de o filme começar.
   */
  sessao.idiomasAudio = analise.idiomasAudio ?? []
  sessao.idiomaAudio = analise.idiomaAudio ?? null
  sessao.idiomaAudioRotulo = analise.idiomaAudioRotulo ?? null

  // Agora a resposta é definitiva: com o cabeçalho lido, o `moov` de um arquivo
  // com índice no fim já chegou ao disco.
  const indiceNoFim = await indiceEstaNoFim(sessao, arquivo, caminho)
  conferirSessao(sessao)

  sessao.status = 'convertendo'
  sessao.mensagem = mensagemDoModo(analise.modo)

  /*
   * Guardamos o essencial para reposicionar a conversão mais tarde (seek além do
   * trecho convertido): o arquivo escolhido, o caminho em disco, o modo decidido
   * e por qual caminho a leitura flui. Sem isso, reposicionar exigiria refazer
   * toda a análise do arquivo.
   */
  sessao.arquivo = arquivo
  sessao.caminho = caminho
  sessao.modo = analise.modo
  sessao.indiceNoFim = indiceNoFim
  sessao.tempoBase = 0

  /*
   * Este log é a leitura do caso: caminho escolhido, codecs, duração e o
   * `start_time` da fonte. O `start_time` alto denuncia um arquivo cuja timeline
   * não começa em zero — junto com o cabeçalho (`ftyp>moov` é *faststart*,
   * `ftyp>mdat` é índice no fim), dá para saber o que o FFmpeg recebeu sem
   * adivinhar pelo comportamento do player.
   */
  logger.info(
    `[sessao ${sessao.id}] caminho=${indiceNoFim ? 'disco' : 'pipe'} modo=${analise.modo} video=${analise.videoCodec} audio=${analise.audioCodec} audioIdioma=${sessao.idiomaAudioRotulo ?? sessao.idiomaAudio ?? '?'} faixasAudio=${sessao.idiomasAudio.length} keyframes=${analise.intervaloKeyframes?.toFixed(2) ?? '?'}s duracao=${sessao.duracao ?? '?'} inicioFonte=${analise.inicioFonte ?? '?'}s cabecalho=${descreverCabecalho(caminho)}`
  )

  if (indiceNoFim) {
    /*
     * Sem pipe: o FFmpeg lê o MP4 sequencialmente e não busca o `moov` no fim.
     * A saída é converter do disco, que é buscável — o arquivo permite voltar e
     * ler as amostras na ordem que for necessária.
     *
     * Antes de abrir a conversão garantimos que o começo do filme esteja em
     * disco: o índice pode estar completo com o `mdat` ainda cheio de buracos, e
     * a leitura direta devolve zeros nesses buracos. O FFmpeg atravessaria o
     * começo inválido e passaria a decodificar no primeiro ponto com dados —
     * que é justamente o filme "começando no meio".
     */
    sessao.mensagem = 'Baixando o índice do filme...'
    await priorizarIndice(sessao, arquivo, caminho)
    conferirSessao(sessao)

    sessao.mensagem = 'Convertendo o filme...'
    await aguardarInicio(sessao, arquivo)
    conferirSessao(sessao)

    const comando = iniciarConversao({
      caminho,
      extensao: path.extname(arquivo.name),
      diretorio: sessao.diretorio,
      modo: analise.modo,
      duracaoEsperada: sessao.duracao,
      aoProgredir: (progresso) => {
        sessao.progresso = progresso
      },
    })

    registrarConversao(sessao, comando)

    /*
     * No disco a leitura é rápida demais para o download, então ligamos o freio.
     * A posição da leitura passa a vir do que a playlist já publicou: cada
     * `#EXTINF` marca até onde a conversão realmente chegou, o que é seguro
     * demais para frear (no máximo um segmento de atraso).
     */
    if (bytesPorSegundo(sessao, arquivo)) {
      sessao.janela?.usarPosicao(() => bytesDoTempo(sessao, arquivo, tempoPublicado(sessao)) ?? 0)
      sessao.janela?.segurarLeitura(comando)
    } else {
      // Sem uma régua para estimar a vazão não dá para frear com segurança:
      // voltamos à seleção cheia, que mantém o download ativo pelo filme todo.
      logger.warn(`[sessao ${sessao.id}] sem vazão estimada — convertendo do disco sem freio de leitura`)
      sessao.janela?.parar()
      sessao.janela = null
      arquivo.select(1)
    }
  } else {
    /*
     * O fluxo é criado agora e fica aberto até o fim do download. O FFmpeg o
     * consome na medida em que os bytes chegam, publicando os segmentos. Além
     * de começar antes, o fluxo entrega os pedaços na ordem do arquivo e
     * **espera** cada um — ele nunca produz buracos, que é a garantia que a
     * leitura direta do disco não dá.
     */
    /*
     * O contador entre o fluxo e o FFmpeg é o que dá à janela a posição exata da
     * leitura: cada pedaço que passa avança o byte corrente, e é a partir dele
     * que o trecho seguinte é priorizado. Sem isso a janela só poderia adivinhar
     * pelo relógio do FFmpeg, que é grosseiro e chega atrasado.
     */
    const contador = new Transform({
      transform(pedaco, _codificacao, concluir) {
        sessao.posicaoLeitura += pedaco.length
        concluir(null, pedaco)
      },
    })

    // O erro já é tratado no fluxo; sem um consumidor o `pipe` derrubaria o processo.
    contador.on('error', () => {})

    const leitura = arquivo.createReadStream()

    leitura.on('error', (erro) => {
      logger.warn(`[sessao ${sessao.id}] erro no fluxo do torrent:`, erro.message)
      contador.destroy(erro)
    })

    leitura.pipe(contador)

    sessao.fluxo = leitura
    sessao.contador = contador

    registrarConversao(
      sessao,
      iniciarConversao({
        fluxo: contador,
        extensao: path.extname(arquivo.name),
        diretorio: sessao.diretorio,
        modo: analise.modo,
        duracaoEsperada: sessao.duracao,
        aoProgredir: (progresso) => {
          sessao.progresso = progresso
        },
      })
    )
  }

  // Só liberamos o player quando há segmentos suficientes para tocar sem
  // travar — é o buffer que protege conexões lentas.
  await aguardarBufferInicial(sessao.diretorio)
  conferirSessao(sessao)

  // Registra como a playlist saiu (sequência, primeiro `#EXTINF`, quantidade de
  // segmentos). É o que permite confirmar pelo log se a conversão começou do
  // zero, sem depender da leitura do player.
  registrarDiagnostico(sessao)

  sessao.status = 'pronto'
  sessao.mensagem = 'Pronto para reproduzir'
  sessao.playlist = path.join(sessao.diretorio, 'playlist.m3u8')
}

/**
 * Pede a cauda do arquivo quando o índice ainda não está visível.
 *
 * Em MP4 com o `moov` no fim, a ordem natural do download (começo → fim) deixa o
 * índice para o último momento, e é justamente dele que a análise depende. Os
 * tamanhos dos átomos ficam nos cabeçalhos, então conseguimos ler onde o `mdat`
 * termina mesmo com o arquivo pela metade: o que vem depois dele é o índice (às
 * vezes com um `free`/`wide` no meio, que a faixa pedida também cobre).
 *
 * Devolve `false` quando não há o que antecipar — contêiner que não é ISO BMFF,
 * índice já visível ou arquivo cujo `mdat` é a última caixa (aí o índice está
 * antes dele e já seria visível).
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {string} caminho caminho do arquivo em disco
 * @returns {Promise<boolean>}
 */
async function anteciparCauda(sessao, arquivo, caminho) {
  const extensao = path.extname(arquivo.name).toLowerCase()

  if (!['.mp4', '.m4v', '.mov'].includes(extensao)) {
    return false
  }

  if (localizarMoov(caminho, arquivo.length)) {
    return false
  }

  const mdat = mapearCaixas(caminho, arquivo.length).find((caixa) => caixa.tipo === 'mdat')

  if (!mdat || mdat.fim >= arquivo.length) {
    return false
  }

  arquivo.select(10, mdat.fim, arquivo.length)

  logger.info(
    `[sessao ${sessao.id}] índice ainda não visível; antecipando a cauda a partir de ${mdat.fim}`
  )

  return true
}

/**
 * Resume o layout do contêiner a partir dos cabeçalhos dos átomos.
 *
 * `ftyp>moov>mdat` é o arranjo *faststart* (índice no começo, flui pelo pipe);
 * `ftyp>mdat` indica o índice no fim. Se os primeiros bytes não formarem átomos
 * coerentes, a extensão não corresponde ao conteúdo — o que explica uma
 * conversão que falha ou sai do lugar.
 *
 * @param {string} caminho
 * @returns {string}
 */
function descreverCabecalho(caminho) {
  try {
    const tamanho = fs.statSync(caminho).size
    const caixas = mapearCaixas(caminho, tamanho, 4)

    return caixas.length ? caixas.map((caixa) => caixa.tipo).join('>') : 'desconhecido'
  } catch {
    return 'ilegível'
  }
}

/**
 * Registra no log como a playlist publicada começa.
 *
 * Sequência de mídia, primeiro `#EXTINF` e quantidade de segmentos: se o
 * primeiro trecho tiver duração coerente e a sequência for zero, a conversão
 * partiu do início do arquivo. É a evidência que faltava para separar um
 * problema de conversão de um problema de player.
 *
 * @param {object} sessao
 */
function registrarDiagnostico(sessao) {
  try {
    const conteudo = fs.readFileSync(path.join(sessao.diretorio, 'playlist.m3u8'), 'utf8')
    const linhas = conteudo
      .split('\n')
      .map((linha) => linha.trim())
      .filter(Boolean)

    const sequencia = linhas.find((linha) => linha.startsWith('#EXT-X-MEDIA-SEQUENCE'))
    const primeiro = linhas.find((linha) => linha.startsWith('#EXTINF'))
    const segmentos = linhas.filter((linha) => linha.endsWith('.ts')).length

    logger.info(
      `[sessao ${sessao.id}] playlist: ${sequencia ?? 'sem sequência'} | ${primeiro ?? 'sem EXTINF'} | segmentos=${segmentos}`
    )
  } catch (erro) {
    logger.warn(`[sessao ${sessao.id}] falha ao inspecionar a playlist:`, erro.message)
  }
}

/**
 * Verifica se o índice do contêiner está no fim do arquivo.
 *
 * Para MKV/WebM e MP4 *faststart* o índice fica no começo e o fluxo progressivo
 * funciona. Para MP4/MOV com o `moov` no fim, o pipe não serve e é preciso
 * esperar o download completo.
 *
 * Chamada depois do cabeçalho ter sido lido, a resposta aqui é definitiva: num
 * MP4 com o `moov` no fim, o índice já chegou ao disco — foi exatamente por ele
 * que a análise esperou. Quando nem assim o `moov` aparece, o arquivo não é um
 * ISO BMFF que saibamos planejar, e o pipe continua sendo o caminho seguro.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {string} caminho caminho do arquivo em disco
 * @returns {Promise<boolean>}
 */
async function indiceEstaNoFim(sessao, arquivo, caminho) {
  const extensao = path.extname(arquivo.name).toLowerCase()

  // Só MP4/MOV têm o `moov` no fim; os demais contêineres trazem o índice no
  // começo e fluem pelo pipe sem preparação.
  if (!['.mp4', '.m4v', '.mov'].includes(extensao)) {
    return false
  }

  const moov = localizarMoov(caminho, arquivo.length)

  if (!moov) {
    return false
  }

  // Um `moov` nos primeiros megabytes é o caso *faststart*: flui pelo pipe.
  if (moov.inicio < 1024 * 1024) {
    return false
  }

  logger.info(
    `[sessao ${sessao.id}] índice no fim (offset ${moov.inicio}); a conversão lerá do disco`
  )

  return true
}

/**
 * Baixa o trecho do índice (`moov`) antes do resto do arquivo.
 *
 * No caminho não progressivo (MP4 com `moov` no fim) o FFmpeg precisa do índice
 * em disco para montar a timeline. Em vez de esperar o download inteiro,
 * pedimos ao WebTorrent o intervalo exato do `moov` com prioridade máxima e
 * aguardamos só esse trecho. Com o índice presente, a conversão começa do disco
 * e o FFmpeg lê o `mdat` conforme os bytes chegam — o arquivo em disco é
 * buscável, então ele consegue esperar/relê-lo.
 *
 * Se o `moov` não puder ser localizado (arquivo truncado ou que não é ISO
 * BMFF), caímos para a espera do download completo: sem o mapa dos pedaços não
 * há como provar que o índice chegou.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {string} caminho caminho do arquivo em disco
 */
async function priorizarIndice(sessao, arquivo, caminho) {
  const moov = localizarMoov(caminho, arquivo.length)

  if (!moov) {
    // Sem o índice localizável não há como priorizar: esperamos o download
    // completo, como antes.
    logger.info(`[sessao ${sessao.id}] moov não localizado; aguardando o download completo`)
    await aguardarDownloadCompleto(sessao, arquivo)
    return
  }

  /*
   * `select(prioridade, inicio, fim)` pede ao WebTorrent apenas o intervalo do
   * índice. A prioridade 10 (acima da do arquivo inteiro, que é 1) garante que
   * esses bytes sejam pedidos antes de tudo.
   */
  arquivo.select(10, moov.inicio, moov.fim)

  logger.info(
    `[sessao ${sessao.id}] priorizando o moov (${moov.inicio}–${moov.fim}); convertendo do disco`
  )

  await aguardarPecas(sessao, arquivo, moov.inicio, moov.fim)
}

/**
 * Diz se um pedaço do torrent já está presente.
 *
 * O WebTorrent expõe o mapa de pedaços de formas diferentes conforme a versão e
 * a loja (`bitfield` ou o vetor `pieces`). Quando nenhuma delas responde,
 * devolvemos `null` — "não sei" — para o chamador não esperar para sempre por um
 * sinal que nunca virá.
 */
function pecaPresente(torrent, indice) {
  if (torrent.bitfield?.get) {
    return torrent.bitfield.get(indice)
  }

  if (Array.isArray(torrent.pieces)) {
    return Boolean(torrent.pieces[indice])
  }

  return null
}

/**
 * Diz se a faixa `[de, ate]` do arquivo está inteiramente presente.
 *
 * `arquivo.progress` é uma média: diz quanto do filme foi baixado, não *onde*.
 * Para saber se um trecho específico chegou é preciso olhar os pedaços que o
 * cobrem — é a diferença entre "baixei 90% espalhados" e "o começo está aqui".
 */
function faixaPresente(torrent, arquivo, de, ate) {
  const tamanhoPeca = torrent?.pieceLength

  if (!tamanhoPeca) return null

  const base = arquivo.offset ?? 0
  const primeiro = Math.floor((base + de) / tamanhoPeca)
  const ultimo = Math.ceil((base + ate) / tamanhoPeca)

  for (let indice = primeiro; indice < ultimo; indice += 1) {
    const presente = pecaPresente(torrent, indice)

    if (presente === null) return null
    if (!presente) return false
  }

  return true
}

/**
 * Aguarda uma faixa do arquivo ficar presente, com prazo.
 *
 * Usada tanto para o índice (`moov`) quanto para o começo do filme. O download
 * corre em paralelo; aqui só esperamos o pedaço do arquivo que interessa.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {number} de byte inicial da faixa
 * @param {number} ate byte final da faixa
 * @param {number} timeoutMs tempo máximo de espera
 */
function aguardarPecas(sessao, arquivo, de, ate, timeoutMs = 120000) {
  const inicio = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      if (sessao.cancelada) return reject(new SessaoCancelada())

      // Arquivo completo é a resposta mais barata e cobre todos os casos.
      if (arquivo.progress >= 1) return resolve()

      const presente = sessao.torrent ? faixaPresente(sessao.torrent, arquivo, de, ate) : null

      if (presente === true) return resolve()

      // Sem o mapa de pedaços, resta a fração baixada — imprecisa, mas ainda
      // evita abrir a conversão com o arquivo praticamente vazio.
      if (presente === null && arquivo.progress * arquivo.length >= ate) {
        return resolve()
      }

      if (Date.now() - inicio > timeoutMs) {
        return reject(new Error('Tempo esgotado aguardando o trecho do vídeo.'))
      }

      setTimeout(verificar, 500)
    }

    verificar()
  })
}

/**
 * Aguarda o começo do filme estar contíguo em disco.
 *
 * Existe por causa de uma armadilha silenciosa: o arquivo em disco é esparso, e
 * a parte ainda não baixada é lida como zeros em vez de erro. Um FFmpeg apontado
 * para esse arquivo atravessa o começo inválido e passa a decodificar no
 * primeiro ponto com dados — o filme abre no meio, com o relógio em 00:00 e sem
 * nenhuma mensagem de erro. Exigir os primeiros megabytes é o que garante que a
 * conversão começa onde o filme começa.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {number} timeoutMs tempo máximo de espera
 */
function aguardarInicio(sessao, arquivo, timeoutMs = 180000) {
  return aguardarPecas(sessao, arquivo, 0, Math.min(BYTES_INICIAIS, arquivo.length), timeoutMs)
}

/**
 * Aguarda o download completo do arquivo.
 *
 * Reserva do caminho não progressivo, usada quando o `moov` não pôde ser
 * localizado. Tem um teto de tempo para não deixar a sessão presa para sempre
 * caso o download estagne.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {number} timeoutMs tempo máximo de espera
 */
function aguardarDownloadCompleto(sessao, arquivo, timeoutMs = 10 * 60 * 1000) {
  const inicio = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      if (sessao.cancelada) return reject(new SessaoCancelada())

      if (arquivo.progress >= 1) {
        return resolve()
      }

      if (Date.now() - inicio > timeoutMs) {
        return reject(new Error('Tempo esgotado aguardando o download do filme.'))
      }

      setTimeout(verificar, 1000)
    }

    verificar()
  })
}

/**
 * Adiciona o magnet ao cliente e resolve quando o torrent estiver pronto.
 *
 * Se o mesmo torrent já estiver no cliente — o usuário reabriu o filme sem que
 * a sessão anterior tivesse sido encerrada — reaproveitamos a instância em vez
 * de tentar adicionar de novo. O `cliente.add` recusa duplicatas com "Cannot add
 * duplicate torrent", o que derrubava a segunda tentativa de assistir.
 *
 * No WebTorrent 2.x o `cliente.get()` devolve uma Promise, então a função é
 * assíncrona para poder aguardá-la.
 *
 * @param {string} magnet
 * @returns {Promise<import('webtorrent').Torrent>}
 */
async function adicionarTorrent(magnet) {
  const existente = await cliente.get(magnet)

  if (existente) {
    if (existente.ready) {
      return existente
    }

    return new Promise((resolve, reject) => {
      existente.once('ready', () => resolve(existente))
      existente.once('error', reject)
    })
  }

  return new Promise((resolve, reject) => {
    const timeout = setTimeout(() => {
      reject(new Error('Tempo esgotado ao conectar na fonte.'))
    }, 45000)

    const torrent = cliente.add(magnet, { path: os.tmpdir() })

    torrent.on('ready', () => {
      clearTimeout(timeout)
      resolve(torrent)
    })

    torrent.on('error', (erro) => {
      clearTimeout(timeout)
      reject(erro)
    })
  })
}

/**
 * Escolhe o maior arquivo de vídeo do torrent.
 *
 * Torrents de filme costumam trazer amostras e arquivos extras; o maior arquivo
 * com extensão de vídeo é quase sempre o filme em si.
 *
 * @param {import('webtorrent').Torrent} torrent
 */
function escolherArquivoDeVideo(torrent) {
  const videos = torrent.files.filter((arquivo) =>
    EXTENSOES_VIDEO.includes(path.extname(arquivo.name).toLowerCase())
  )

  if (videos.length === 0) {
    return null
  }

  return videos.sort((a, b) => b.length - a.length)[0]
}

/**
 * Acompanha o download em segundo plano, sem bloquear a conversão.
 *
 * Diferente da versão anterior, que esperava o arquivo inteiro, aqui só
 * registramos o andamento. O download corre junto com a conversão, então o
 * percentual serve de contexto no overlay enquanto os segmentos são gerados.
 *
 * Além do percentual, expomos a velocidade e a contagem de peers: sem eles o
 * overlay não distingue uma fonte morta (0 peers) de uma fonte apenas lenta,
 * e o usuário fica sem saber se deve esperar ou trocar de fonte.
 *
 * @param {object} sessao
 * @param {import('webtorrent').Torrent} torrent
 */
function acompanharDownload(sessao, torrent) {
  const atualizar = () => {
    sessao.download = {
      percentual: Math.round(torrent.progress * 100),
      velocidade: torrent.downloadSpeed ?? 0,
      peers: torrent.numPeers ?? 0,
      baixado: torrent.downloaded ?? 0,
    }
  }

  const concluir = () => {
    torrent.off('download', atualizar)
    torrent.off('done', concluir)

    sessao.download = {
      percentual: 100,
      velocidade: 0,
      peers: torrent.numPeers ?? 0,
      baixado: torrent.downloaded ?? 0,
    }
  }

  if (torrent.progress >= 1) {
    concluir()
    return
  }

  torrent.on('download', atualizar)
  torrent.on('done', concluir)

  atualizar()
}

/**
 * Aguarda o primeiro byte de dados, falhando rápido se a fonte estiver morta.
 *
 * O evento `ready` do torrent só confirma que os metadados do magnet foram
 * lidos — não que exista peer enviando dados. Sem esta espera, uma fonte sem
 * peers prendia a sessão em `analisarComEspera` por 120 s, muito além do
 * `TIMEOUT_FONTE_MS` do frontend (90 s), deixando a sessão órfã no backend.
 *
 * Resolve assim que qualquer byte chega; rejeita se o tempo esgotar sem
 * tráfego. O frontend então recebe `status: 'erro'` e tenta a próxima fonte.
 *
 * @param {object} sessao
 * @param {import('webtorrent').Torrent} torrent
 */
function aguardarDados(sessao, torrent) {
  return new Promise((resolve, reject) => {
    // Um torrent já completo (ou com dados em disco) não precisa esperar.
    if (torrent.downloaded > 0 || torrent.progress >= 1) {
      resolve()
      return
    }

    let concluido = false

    const finalizar = () => {
      if (concluido) return
      concluido = true
      clearTimeout(temporizador)
      torrent.off('download', aoBaixar)
      resolve()
    }

    const aoBaixar = () => {
      if (torrent.downloaded > 0) {
        finalizar()
      }
    }

    const temporizador = setTimeout(() => {
      if (concluido) return
      concluido = true
      torrent.off('download', aoBaixar)

      reject(
        new Error(
          `A fonte não enviou dados em ${TIMEOUT_DADOS_MS / 1000}s (peers: ${torrent.numPeers ?? 0}).`
        )
      )
    }, TIMEOUT_DADOS_MS)

    torrent.on('download', aoBaixar)

    // O evento `download` só dispara quando chegam bytes; uma verificação
    // imediata cobre o caso de os dados já terem começado antes do listener.
    aoBaixar()
  })
}

/**
 * Analisa o arquivo insistindo até o cabeçalho ficar legível.
 *
 * Alguns MP4 trazem o átomo `moov` no fim do arquivo, então o ffprobe só
 * consegue ler os metadados depois que o torrent baixou aquele trecho. Como
 * não dá para saber de antemão quanto falta, tentamos de novo a cada segundo
 * até conseguir — o download segue em paralelo.
 *
 * @param {object} sessao
 * @param {string} caminho caminho absoluto do arquivo no disco
 * @param {import('webtorrent').TorrentFile} arquivo arquivo do torrent
 */
async function analisarComEspera(sessao, caminho, arquivo, timeoutMs = 120000) {
  const inicio = Date.now()
  let ultimoErro = null

  while (Date.now() - inicio < timeoutMs) {
    // A leitura do cabeçalho pode demorar; se a sessão morrer nesse meio-tempo,
    // não faz sentido seguir esperando por um arquivo que já foi descartado.
    conferirSessao(sessao)

    try {
      const analise = await analisarArquivo(caminho)

      // Um cabeçalho lido pela metade devolve os streams sem os codecs. Aceitar
      // isso faria o FFmpeg "concluir" sem gerar segmento nenhum, então só
      // consideramos a análise válida quando vídeo e áudio foram identificados.
      if (analise.videoCodec && analise.audioCodec) {
        return analise
      }

      ultimoErro = new Error('cabeçalho incompleto')
    } catch (erro) {
      ultimoErro = erro
    }

    // Se o torrent já terminou e ainda assim não lemos o cabeçalho, não há
    // mais o que esperar: o arquivo está corrompido ou não é um vídeo válido.
    if (arquivo.progress >= 1) {
      break
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  throw new Error(
    `Não foi possível ler o cabeçalho do vídeo: ${ultimoErro?.message || 'formato desconhecido'}`
  )
}

/** Mensagem exibida no overlay conforme o modo de conversão. */
function mensagemDoModo(modo) {
  if (modo === 'remux') return 'Ajustando o contêiner do vídeo...'
  if (modo === 'audio') return 'Convertendo o áudio...'

  return 'Convertendo o vídeo...'
}

/** Devolve o estado atual de uma sessão. */
export function obterSessao(id) {
  const sessao = sessoes.get(id)

  if (!sessao) {
    return null
  }

  return {
    sessao_id: sessao.id,
    status: sessao.status,
    mensagem: sessao.mensagem,
    erro: sessao.erro,
    progresso: sessao.progresso ?? null,
    /*
     * O download corre em paralelo à conversão. Além do percentual, expomos a
     * velocidade e a contagem de peers para o overlay distinguir uma fonte
     * morta (0 peers) de uma apenas lenta.
     */
    download: sessao.download ?? null,
    /*
     * Duração total do filme, lida pelo ffprobe. O Plyr não consegue deduzir a
     * duração de uma playlist `EVENT` ainda em crescimento (o hls.js a trata
     * como ao vivo e reporta `Infinity`), então o frontend usa este valor como
     * fonte de verdade enquanto a conversão não termina.
     */
    duracao: sessao.duracao ?? null,
    /*
     * Idioma real da faixa de áudio, lido do ffprobe. O frontend usa isto para
     * confirmar a dublagem na tela em vez de confiar apenas no nome do
     * arquivo — um lançamento pode prometer PT-BR e entregar outra faixa.
     */
    idioma_audio: sessao.idiomaAudio ?? null,
    idioma_audio_rotulo: sessao.idiomaAudioRotulo ?? null,
    idiomas_audio: sessao.idiomasAudio ?? [],
    /*
     * Tempo, em segundos, onde começa a timeline atual. Vale zero na reprodução
     * normal; depois de um seek remoto vale o ponto buscado, porque a conversão
     * é reiniciada ali e a playlist recomeça em zero. O player soma este valor
     * para exibir a posição real no filme.
     */
    tempo_base: sessao.tempoBase ?? 0,
    // A URL só é exposta quando a playlist está pronta para ser consumida.
    playlist: sessao.status === 'pronto' ? `/api/media/sessao/${sessao.id}/playlist.m3u8` : null,
  }
}

/** Caminho da playlist de uma sessão pronta. */
export function caminhoPlaylist(id) {
  const sessao = sessoes.get(id)

  if (!sessao || sessao.status !== 'pronto') {
    return null
  }

  return sessao.playlist
}

/** Diretório onde os segmentos de uma sessão são escritos. */
export function diretorioSessao(id) {
  return sessoes.get(id)?.diretorio ?? null
}

/**
 * Guarda o comando da conversão na sessão, reagindo a um cancelamento tardio.
 *
 * Existe uma janela entre criar o FFmpeg e registrá-lo: se a sessão for
 * encerrada exatamente nesse intervalo, o processo recém-criado ficaria órfão
 * escrevendo numa pasta que acabou de ser apagada. Aqui a bandeira é conferida
 * de novo e, se a sessão morreu, o comando é morto na hora.
 *
 * @param {object} sessao
 * @param {{parar: () => void}} comando
 */
function registrarConversao(sessao, comando) {
  if (sessao.cancelada) {
    comando.parar()
    throw new SessaoCancelada()
  }

  sessao.comando = comando
}

/** Para a conversão em andamento e fecha o fluxo do torrent, se houver. */
function pararConversao(sessao) {
  /*
   * A janela vem primeiro: se a conversão estava congelada pelo freio, é ela
   * quem envia o SIGCONT antes de o processo ser morto — um processo parado não
   * responderia bem a um encerramento abrupto.
   */
  sessao.janela?.parar()
  sessao.janela = null

  try {
    sessao.comando?.parar()
  } catch (erro) {
    logger.warn(`[sessao ${sessao.id}] falha ao encerrar a conversão:`, erro.message)
  }

  sessao.comando = null

  // O fluxo alimentava o FFmpeg a partir do torrent; sem consumidor ele ficaria
  // segurando peças do download sem motivo.
  if (sessao.fluxo) {
    try {
      sessao.fluxo.destroy()
    } catch {
      // O fluxo já pode ter fechado sozinho.
    }

    sessao.fluxo = null
  }

  // O contador fica entre o fluxo e o FFmpeg; destruí-lo libera o pipe inteiro.
  if (sessao.contador) {
    try {
      sessao.contador.destroy()
    } catch {
      // Já pode ter sido destruído pelo erro do fluxo.
    }

    sessao.contador = null
  }
}

/**
 * Apaga playlist e segmentos de uma sessão para recomeçar a conversão do zero.
 *
 * Ao reposicionar, os segmentos antigos não representam mais o trecho que o
 * player vai consumir; mantê-los no mesmo diretório misturaria duas timelines na
 * playlist recém-gerada.
 */
function limparSegmentos(sessao) {
  let entradas = []

  try {
    entradas = fs.readdirSync(sessao.diretorio)
  } catch {
    // Diretório já removido (sessão encerrada no meio do reposicionamento).
    return
  }

  for (const nome of entradas) {
    /*
     * O nome carrega o carimbo da execução (`segmento-<carimbo>-N.ts`), mas
     * aceitamos também o formato antigo sem carimbo para não deixar resíduo de
     * uma execução anterior no disco.
     */
    if (nome === 'playlist.m3u8' || /^segmento-(?:\d+-)?\d+\.ts(\.tmp)?$/.test(nome)) {
      try {
        fs.rmSync(path.join(sessao.diretorio, nome), { force: true })
      } catch (erro) {
        logger.warn(`[sessao ${sessao.id}] falha ao limpar ${nome}:`, erro.message)
      }
    }
  }
}

/**
 * Reposiciona a conversão de uma sessão para um tempo alvo.
 *
 * Usado quando o usuário busca um ponto além do já convertido. Na ordem natural,
 * a conversão publica os segmentos do começo para o fim: um salto para o meio de
 * um filme de duas horas exigiria esperar a conversão das duas horas. Aqui
 * paramos a conversão, apagamos os segmentos e reiniciamos a partir do alvo com
 * `-ss`, de modo que o trecho buscado fica disponível em segundos.
 *
 * A timeline resultante é local: a playlist nova começa em zero e representa o
 * trecho de `tempo` em diante. O `tempo_base` devolvido informa ao player onde
 * esse zero está no filme, para ele ajustar a duração exibida.
 *
 * @param {string} id
 * @param {number} tempo tempo alvo, em segundos
 * @returns {{sessao_id: string, status: string, tempo_base?: number, erro?: string}|null}
 */
export function reposicionarSessao(id, tempo) {
  const sessao = sessoes.get(id)

  if (!sessao) return null

  if (!Number.isFinite(tempo) || tempo < 0) {
    return { sessao_id: id, status: sessao.status, erro: 'Tempo de busca inválido.' }
  }

  if (!sessao.arquivo || !sessao.caminho) {
    return { sessao_id: id, status: sessao.status, erro: 'A sessão ainda não permite reposicionar.' }
  }

  // Outro reposicionamento em curso: devolvemos o estado atual em vez de
  // disparar um segundo trabalho concorrente sobre o mesmo diretório.
  if (sessao.status === 'reposicionando') {
    return { sessao_id: id, status: sessao.status, tempo_base: sessao.tempoBase ?? 0 }
  }

  sessao.status = 'reposicionando'
  sessao.mensagem = 'Buscando o trecho...'

  reposicionarEmSegundoPlano(sessao, tempo).catch((erro) => {
    if (erro instanceof SessaoCancelada || sessao.cancelada) {
      logger.info(`[sessao ${id}] reposicionamento interrompido: a sessão foi encerrada`)
      return
    }

    logger.error(`[sessao ${id}] falha ao reposicionar:`, erro.message)
    sessao.status = 'erro'
    sessao.erro = erro.message
  })

  return { sessao_id: id, status: sessao.status, tempo_base: sessao.tempoBase ?? 0 }
}

/** Reinicia a conversão a partir do tempo alvo, depois de limpar a saída antiga. */
async function reposicionarEmSegundoPlano(sessao, tempo) {
  const arquivo = sessao.arquivo
  const caminho = sessao.caminho

  // A duração que resta do filme é o que a nova conversão vai cobrir; passá-la
  // ao finalizador evita que ele compare com a duração cheia e conclua errado.
  const duracaoRestante = sessao.duracao ? Math.max(sessao.duracao - tempo, 0) : null

  pararConversao(sessao)
  conferirSessao(sessao)

  limparSegmentos(sessao)

  sessao.tempoBase = tempo
  sessao.progresso = null

  /*
   * A janela nova parte do ponto buscado. O byte alvo é estimado pela vazão do
   * torrent; a partir dele o download volta a priorizar a faixa à frente.
   */
  sessao.posicaoLeitura = bytesDoTempo(sessao, arquivo, tempo) ?? 0
  sessao.janela = iniciarJanela(sessao, arquivo, () => sessao.posicaoLeitura)

  if (sessao.indiceNoFim) {
    /*
     * Caminho de disco: o arquivo é buscável e o `-ss` salta direto para o alvo.
     */
    await aguardarInicio(sessao, arquivo)
    conferirSessao(sessao)

    registrarConversao(
      sessao,
      iniciarConversao({
        caminho,
        extensao: path.extname(arquivo.name),
        diretorio: sessao.diretorio,
        modo: sessao.modo,
        duracaoEsperada: duracaoRestante,
        tempoInicial: tempo,
        aoProgredir: (progresso) => {
          sessao.progresso = progresso
        },
      })
    )

    if (bytesPorSegundo(sessao, arquivo)) {
      sessao.janela?.usarPosicao(
        () => bytesDoTempo(sessao, arquivo, tempo + tempoPublicado(sessao)) ?? 0
      )
      sessao.janela?.segurarLeitura(sessao.comando)
    } else {
      sessao.janela?.parar()
      sessao.janela = null
      arquivo.select(1)
    }
  } else {
    /*
     * Caminho de pipe: não há seek de verdade. Posicionamos o fluxo no byte
     * estimado e deixamos o `-ss` cobrir só o resíduo entre a estimativa por
     * vazão e o tempo real pedido, para não descartar minutos de leitura.
     */
    const vazao = bytesPorSegundo(sessao, arquivo)
    const tempoDoByte = vazao ? sessao.posicaoLeitura / vazao : 0
    const residual = Math.max(0, tempo - tempoDoByte)

    const contador = new Transform({
      transform(pedaco, _codificacao, concluir) {
        sessao.posicaoLeitura += pedaco.length
        concluir(null, pedaco)
      },
    })

    contador.on('error', () => {})

    const leitura = arquivo.createReadStream({ start: sessao.posicaoLeitura })

    leitura.on('error', (erro) => {
      logger.warn(`[sessao ${sessao.id}] erro no fluxo do torrent:`, erro.message)
      contador.destroy(erro)
    })

    leitura.pipe(contador)

    sessao.fluxo = leitura
    sessao.contador = contador

    registrarConversao(
      sessao,
      iniciarConversao({
        fluxo: contador,
        extensao: path.extname(arquivo.name),
        diretorio: sessao.diretorio,
        modo: sessao.modo,
        duracaoEsperada: duracaoRestante,
        tempoInicial: residual,
        aoProgredir: (progresso) => {
          sessao.progresso = progresso
        },
      })
    )
  }

  await aguardarBufferInicial(sessao.diretorio)
  conferirSessao(sessao)

  registrarDiagnostico(sessao)

  sessao.status = 'pronto'
  sessao.mensagem = 'Pronto para reproduzir'
  sessao.playlist = path.join(sessao.diretorio, 'playlist.m3u8')
}

/** Remove o torrent do cliente compartilhado, apagando os dados baixados. */
function removerTorrent(sessao, torrent) {
  try {
    cliente.remove(torrent, { destroyStore: true })
  } catch (erro) {
    logger.warn(`[sessao ${sessao.id}] falha ao remover o torrent:`, erro.message)
  }
}

/**
 * Encerra uma sessão: mata a conversão, remove o torrent e limpa o disco.
 *
 * A bandeira de cancelamento é levantada **antes** de qualquer desmontagem. O
 * preparo reage a ela em cada ponto de espera e desiste sozinho, então uma
 * sessão abandonada no meio do download não deixa FFmpeg nem torrent para trás —
 * era esse resíduo que fazia o próximo filme herdar o estado do anterior.
 *
 * @param {string} id
 */
export function encerrarSessao(id) {
  const sessao = sessoes.get(id)

  if (!sessao) {
    return false
  }

  sessao.cancelada = true

  pararConversao(sessao)

  if (sessao.torrent) {
    removerTorrent(sessao, sessao.torrent)
    sessao.torrent = null
  }

  try {
    fs.rmSync(sessao.diretorio, { recursive: true, force: true })
  } catch (erro) {
    logger.warn(`[sessao ${id}] falha ao limpar o diretório:`, erro.message)
  }

  sessoes.delete(id)

  return true
}

/**
 * Testa uma fonte sem criar sessão: conecta o magnet e mede a malha.
 *
 * O backend usa isto para descartar fontes sem peers antes de oferecê-las ao
 * usuário. O `seeds`/`peers` do provedor é estático e pode estar desatualizado;
 * aqui medimos a malha real por alguns segundos.
 *
 * O torrent é removido ao fim do teste para não deixar lixo no cliente — a
 * sessão de reprodução, se houver, adiciona o magnet de novo.
 *
 * @param {string} magnet
 * @param {number} esperaMs tempo de observação da malha
 * @returns {Promise<{ok: boolean, peers: number, seeds: number, velocidade: number}>}
 */
export async function verificarFonte(magnet, esperaMs = 8000) {
  let torrent

  try {
    torrent = await adicionarTorrent(magnet)
  } catch (erro) {
    logger.warn('[verificar] falha ao conectar a fonte:', erro.message)
    return { ok: false, peers: 0, seeds: 0, velocidade: 0 }
  }

  // Observamos a malha por alguns segundos: o número de peers sobe conforme as
  // conexões se estabelecem, então uma leitura imediata subestimaria a fonte.
  await new Promise((resolve) => setTimeout(resolve, esperaMs))

  const peers = torrent.numPeers ?? 0
  const velocidade = torrent.downloadSpeed ?? 0
  const baixou = (torrent.downloaded ?? 0) > 0

  // Consideramos a fonte viva se há peers e algum tráfego (ou se já baixou).
  const ok = peers > 0 && (velocidade > 0 || baixou)

  const resultado = { ok, peers, seeds: peers, velocidade }

  // O teste não deve deixar o torrent vivo: a sessão de reprodução o adiciona
  // de novo quando o usuário escolhe a fonte.
  try {
    cliente.remove(torrent, { destroyStore: true })
  } catch (erro) {
    logger.warn('[verificar] falha ao remover o torrent de teste:', erro.message)
  }

  return resultado
}

/**
 * Remove sessões antigas que ficaram para trás (player fechado sem aviso).
 * Roda periodicamente para não acumular torrents e processos FFmpeg órfãos.
 */
export function limparSessoesAntigas(idadeMaximaMs = 3 * 60 * 60 * 1000) {
  const agora = Date.now()

  for (const [id, sessao] of sessoes) {
    if (agora - sessao.criadaEm > idadeMaximaMs) {
      logger.info(`[sessao ${id}] removida por inatividade`)
      encerrarSessao(id)
    }
  }
}
