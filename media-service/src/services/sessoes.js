import WebTorrent from 'webtorrent'
import path from 'node:path'
import fs from 'node:fs'
import os from 'node:os'
import dns from 'node:dns'
import { Transform } from 'node:stream'
import { v4 as uuid } from 'uuid'

import {
  analisarArquivo,
  iniciarConversao,
  aguardarBufferInicial,
  comRetentativa,
  ehFalhaDeResolucao,
  ehFalhaTransitoria,
  localizarMoov,
  mapearCaixas,
  profundidadeRelevante,
  resumirErro,
  somarDuracaoDaPlaylist,
  temFaixaPortuguesa,
} from './hls.js'
import { logger } from '../utils/logger.js'
import { contemIndicioPtBr } from '../utils/idiomas.js'

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
 * Porta de escuta do WebTorrent, fixa e casada com o mapeamento do compose.
 *
 * Uma porta aleatória a cada subida nunca seria alcançável de fora: o container
 * publica uma porta específica (`MEDIA_TORRENT_PORT`), e é nela que o cliente
 * precisa escutar para aceitar conexões de entrada. Sem isso, a malha fica só
 * com as conexões de saída e a fonte aparece como "sem peers" com muito mais
 * frequência do que deveria.
 */
const PORTA_TORRENT = Number(process.env.MEDIA_TORRENT_PORT) || 51413

/*
 * O transporte uTP (utp-native) provoca segfault neste ambiente de container:
 * o processo morre com SIGSEGV sem chance de tratar o erro, derrubando todas
 * as sessões. Desligamos apenas o uTP e mantemos TCP, DHT e trackers, que são
 * suficientes para montar a malha e baixar o filme.
 *
 * O DHT fica explícito porque é ele que acha peers sem depender de tracker: se
 * um anunciador estiver fora do ar, o DHT ainda pode montar a malha. A porta
 * TCP é fixada para casar com o `ports:` do compose.
 */
const cliente = new WebTorrent({
  utp: false,
  dht: true,
  torrentPort: PORTA_TORRENT,
})

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
 *
 * O tempo mede a espera pelo **primeiro byte**, não a velocidade da fonte: uma
 * fonte com poucos peers demora a conectar e depois baixa normalmente. Por isso
 * o valor é ajustável por `MEDIA_TIMEOUT_DADOS_MS` — quem tem fontes lentas mas
 * vivas pode esticar a paciência sem mexer no código.
 */
const TIMEOUT_DADOS_MS = Number(process.env.MEDIA_TIMEOUT_DADOS_MS) || 30000

/**
 * Tempo máximo aguardando os metadados do magnet, em ms.
 *
 * É aqui que a fonte praticamente morta trava: o `cliente.add` só emite `ready`
 * quando algum peer entrega o `info` do torrent, e um lançamento com um único
 * seed offline nunca chega lá. Os 45 s anteriores consumiam metade da paciência
 * do overlay (90 s) sem gerar um byte. Vinte segundos cobrem a entrada no DHT e
 * nos trackers e devolvem o resto da espera a quem tem chance real de andar.
 *
 * Ajustável por `MEDIA_TIMEOUT_METADADOS_MS` pelo mesmo motivo do timeout de
 * dados: a entrada no DHT varia com a rede e com a saúde dos trackers.
 */
const TIMEOUT_METADADOS_MS = Number(process.env.MEDIA_TIMEOUT_METADADOS_MS) || 20000

/*
 * Prazos fixos não servem para fonte de poucos seeds.
 *
 * Um lançamento antigo com um ou dois seeds baixa devagar, mas baixa: o
 * progresso avança alguns KB por segundo, sem parar. Um prazo fixo derruba a
 * sessão no meio de um download que estava andando — foi assim que fontes
 * vivas morriam com "Tempo esgotado aguardando o trecho do vídeo".
 *
 * A partir daqui a paciência é medida por **progresso**, não por relógio: o
 * prazo só corre enquanto o download estiver parado. Cada avanço real de bytes
 * renova a espera. Assim uma fonte lenta mas viva nunca é cortada, e uma fonte
 * que estagnou de verdade é abandonada rápido.
 */

/**
 * Janela de tolerância à estagnação, em ms.
 *
 * É o tempo que aceitamos sem nenhum byte novo antes de considerar a fonte
 * parada. Não é o prazo total: enquanto houver avanço, a espera continua. O
 * valor é generoso porque um seed único pode ficar dezenas de segundos sem
 * entregar um pedaço inteiro — o pedaço só conta como baixado quando fecha.
 */
const ESTAGNACAO_PECAS_MS = Number(process.env.MEDIA_ESTAGNACAO_PECAS_MS) || 90000

/**
 * Teto absoluto de espera por um trecho, em ms.
 *
 * Mesmo com progresso, uma fonte patologicamente lenta (poucos KB/s) levaria
 * horas para entregar o começo do filme. Este teto evita que a sessão fique
 * presa para sempre; é alto o bastante para não cortar uma fonte lenta porém
 * útil, e ajustável por `MEDIA_TETO_PECAS_MS`.
 */
const TETO_PECAS_MS = Number(process.env.MEDIA_TETO_PECAS_MS) || 15 * 60 * 1000

/**
 * Abaixo desta contagem de peers, a janela de leitura é ampliada.
 *
 * Com poucos peers o *picker* do WebTorrent tem pouca escolha de onde buscar
 * cada pedaço, e uma janela apertada faz o download se concentrar em poucos
 * pedaços que talvez estejam justamente com o peer mais lento. Alargar a janela
 * dá ao picker mais pedaços elegíveis e permite que ele peça a um peer vizinho
 * o que o outro não entrega — a malha pequena deixa de ser gargalo.
 */
const PEERS_ESCASSOS = Number(process.env.MEDIA_PEERS_ESCASSOS) || 3

/** Fator de ampliação da janela quando os peers são escassos. */
const FATOR_JANELA_PEERS_ESCASSOS = Number(process.env.MEDIA_FATOR_JANELA_ESCASSOS) || 2

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

/**
 * Tamanho mínimo, em bytes, para o arquivo valer uma sondagem do ffprobe.
 *
 * Nos primeiros porcentos do download o WebTorrent cria o arquivo com tamanho
 * zero (ou alguns poucos bytes) e o ffprobe falha com "No such file or
 * directory" ou "Invalid data found" — erros que pareciam corrupção, mas eram
 * só o arquivo ainda vazio. Exigimos um mínimo antes de sondar para não gastar
 * uma chamada do ffprobe a cada segundo em cima de um arquivo inexistente.
 */
const TAMANHO_MINIMO_SONDAGEM = 1024 * 1024

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
 * Erro de fonte rotulado com o motivo da desistência.
 *
 * O overlay decide o que dizer com base neste rótulo, não no texto: "sem peers"
 * pede outro lançamento, "sem vídeo" denuncia um pacote que não serve. Carregar
 * o motivo junto do erro evita que o frontend volte a interpretar mensagens por
 * comparação de string.
 *
 * Motivos usados aqui: `sem_metadados`, `sem_peers`, `sem_dados`, `sem_video` e
 * `rede` — este último para quando a fonte não pôde ser alcançada (nome que não
 * resolve, conversão que cai na abertura), porque a resposta certa do usuário é
 * tentar de novo, não trocar de filme.
 */
function erroDaFonte(motivo, mensagem) {
  const erro = new Error(mensagem)
  erro.motivo = motivo

  return erro
}

/**
 * Cria uma sessão e começa a preparar a reprodução.
 *
 * A função devolve o id imediatamente: a conexão do torrent e a conversão
 * acontecem em segundo plano, e o frontend acompanha o andamento pelo status.
 *
 * `temporada` e `episodio` são opcionais e só chegam no fluxo de série. Eles
 * existem por causa dos packs: quando a fonte é a temporada inteira num torrent
 * só, o media-service precisa saber qual episódio procurar entre os arquivos.
 * No fluxo de filme eles vêm vazios e a escolha segue sendo "o maior vídeo".
 *
 * @param {object} opcoes
 * @param {string} opcoes.magnet link magnet da fonte escolhida
 * @param {number|string} opcoes.filmeId identificador do filme (para log)
 * @param {number|string} [opcoes.temporada] temporada do episódio, no fluxo de série
 * @param {number|string} [opcoes.episodio] episódio procurado, no fluxo de série
 * @returns {{sessao_id: string, status: string}}
 */
export function criarSessao({ magnet, filmeId, temporada = null, episodio = null }) {
  const id = uuid()
  const diretorio = path.join(os.tmpdir(), `stretor-${id}`)

  /*
   * Normalizamos aqui, e não na rota, para que o contrato aceite tanto número
   * quanto string vinda de JSON. Qualquer valor que não vire inteiro positivo é
   * tratado como "sem episódio" — o preparo cai no comportamento de sempre, sem
   * risco de comparar com `NaN`.
   */
  const numeroTemporada = Number.parseInt(temporada, 10)
  const numeroEpisodio = Number.parseInt(episodio, 10)
  const temEpisodio = Number.isInteger(numeroTemporada) && numeroTemporada > 0
    && Number.isInteger(numeroEpisodio) && numeroEpisodio > 0

  const sessao = {
    id,
    filmeId,
    magnet,
    temporada: temEpisodio ? numeroTemporada : null,
    episodio: temEpisodio ? numeroEpisodio : null,
    status: 'conectando',
    mensagem: 'Conectando à fonte...',
    diretorio,
    torrent: null,
    comando: null,
    fluxo: null,
    playlist: null,
    erro: null,
    // Motivo rotulado da desistência (`sem_peers`, `sem_metadados`, ...), para o
    // overlay explicar a falha em vez de só tentar a próxima fonte em silêncio.
    motivo: null,
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
    sessao.motivo = erro.motivo ?? null
  })

  return { sessao_id: id, status: sessao.status }
}

/**
 * Extensões de vídeo que o provedor direto pode entregar.
 *
 * Diferente do torrent, aqui não há malha nem escolha de arquivo: a URL já
 * aponta para o vídeo. A extensão serve só para decidir como o FFmpeg lê a
 * entrada — um `.m3u8` é uma playlist HLS (o FFmpeg a segue sozinho) e os
 * demais são arquivos progressivos.
 */
const EXTENSOES_DIRETAS = ['.mp4', '.m4v', '.webm', '.mkv', '.mov', '.m3u8', '.mpd']

/**
 * Quantas vezes a conversão de uma fonte direta é aberta antes de condená-la.
 *
 * Cada abertura é uma conexão nova, com uma resolução de nome nova. Uma única
 * tentativa transformava um soluço de DNS em fonte morta — foi o que fazia a
 * primeira abertura de cada episódio falhar enquanto a segunda passava. Três é
 * o suficiente para atravessar o instante ruim sem acumular espera: uma fonte
 * realmente fora do ar encerra a abertura por volta de um segundo.
 */
const TENTATIVAS_CONVERSAO_DIRETA = 3

/** Espera entre duas aberturas da conversão, em ms. */
const ESPERA_REABERTURA_MS = 1000

/**
 * Cria uma sessão de reprodução a partir de um link direto (MP4/HLS).
 *
 * É o caminho de socorro para conteúdo raro: quando a cascata de torrents não
 * devolve nenhuma fonte viva, o backend oferece uma URL de streaming direto e o
 * media-service a converte para HLS do mesmo jeito que faria com um torrent. O
 * player não precisa saber a diferença — consome a mesma playlist.
 *
 * A diferença de fundo é que não há download em malha: o FFmpeg lê a URL
 * remota diretamente (arquivo progressivo) ou segue a playlist HLS de origem.
 * Por isso não há janela de leitura, nem seleção de pedaços, nem espera por
 * peers — o gargalo passa a ser a banda do servidor de origem.
 *
 * @param {object} opcoes
 * @param {string} opcoes.url URL do vídeo direto (MP4, HLS, etc.)
 * @param {number|string} opcoes.filmeId identificador do filme (para log)
 * @param {number|string} [opcoes.temporada] temporada, no fluxo de série
 * @param {number|string} [opcoes.episodio] episódio, no fluxo de série
 * @returns {{sessao_id: string, status: string}}
 */
export function criarSessaoDireta({ url, filmeId, temporada = null, episodio = null }) {
  const id = uuid()
  const diretorio = path.join(os.tmpdir(), `stretor-${id}`)

  const numeroTemporada = Number.parseInt(temporada, 10)
  const numeroEpisodio = Number.parseInt(episodio, 10)
  const temEpisodio = Number.isInteger(numeroTemporada) && numeroTemporada > 0
    && Number.isInteger(numeroEpisodio) && numeroEpisodio > 0

  const sessao = {
    id,
    filmeId,
    // Guardamos a URL no mesmo campo do magnet para o encerramento e o log
    // seguirem um caminho só; `tipo` é quem distingue os dois fluxos.
    magnet: url,
    tipo: 'direto',
    url,
    temporada: temEpisodio ? numeroTemporada : null,
    episodio: temEpisodio ? numeroEpisodio : null,
    status: 'conectando',
    mensagem: 'Conectando à fonte direta...',
    diretorio,
    torrent: null,
    comando: null,
    fluxo: null,
    playlist: null,
    erro: null,
    motivo: null,
    progresso: null,
    download: null,
    duracao: null,
    idiomaAudio: null,
    idiomaAudioRotulo: null,
    idiomasAudio: [],
    posicaoLeitura: 0,
    contador: null,
    janela: null,
    cancelada: false,
    criadaEm: Date.now(),
  }

  sessoes.set(id, sessao)

  prepararSessaoDireta(sessao).catch((erro) => {
    if (erro instanceof SessaoCancelada || sessao.cancelada) {
      logger.info(`[sessao ${id}] preparo direto interrompido: a sessão foi encerrada`)
      return
    }

    logger.error(`[sessao ${id}] falha ao preparar fonte direta:`, erro.message)
    sessao.status = 'erro'
    sessao.erro = erro.message
    sessao.motivo = erro.motivo ?? null
  })

  return { sessao_id: id, status: sessao.status }
}

/**
 * Confere se o nome da fonte resolve antes de entregar a URL ao FFmpeg.
 *
 * O primeiro `lookup` de um host depois de um período frio é o ponto frágil do
 * caminho direto. No container, o DNS embutido do Docker devolveu `EAI_AGAIN`
 * (o `Try again` que o FFmpeg reporta) e a sondagem falhou — embora a mesma URL
 * abrisse um minuto depois, com a resolução já esquentada. Resolver aqui, com
 * algumas tentativas, faz duas coisas: aquece o caminho que o FFmpeg vai usar e
 * separa dois desfechos que a mensagem do `ffprobe` confunde — "o host não
 * resolve", em que nenhuma conversão teria chance, de "o host resolve, mas o
 * servidor recusou a sondagem", em que ainda vale tentar.
 *
 * A paciência é medida em tempo, e não em tentativas, porque o custo de uma
 * falha aqui é alto: medido no container, cada `getaddrinfo` que não responde
 * consome exatamente o `timeout:1` do `resolv.conf` antes de desistir. Uma
 * rajada de resolução (vista em amostragem: três de cada quatro consultas
 * falhando por cerca de um minuto) duraria mais que qualquer espera curta, então
 * as voltas cobrem alguns segundos antes de condenar a fonte — e o overlay ainda
 * oferece a nova tentativa, que é gratuita.
 *
 * A recusa sobe rotulada como `rede` para o overlay poder oferecer a nova
 * tentativa em vez de culpar o filme.
 */
async function conferirNomeDaFonte(sessao) {
  let host = ''

  try {
    host = new URL(sessao.url).hostname
  } catch {
    // URL malformada: a checagem de extensão já cuidou do formato, e o FFmpeg
    // dirá o que houve com o endereço.
    return
  }

  if (!host) return

  try {
    await comRetentativa(() => dns.promises.lookup(host), { tentativas: 6, esperaMs: 700 })
  } catch (erro) {
    throw erroDaFonte('rede', `O endereço da fonte não resolveu (${host}).`)
  }
}

/**
 * Prepara uma sessão de fonte direta: sonda a URL e converte para HLS.
 *
 * O FFmpeg aceita a URL como entrada e cuida do resto — num MP4 progressivo ele
 * faz requisições HTTP com `Range` para buscar o índice e as amostras; num HLS
 * ele baixa a playlist e os segmentos. Não precisamos baixar o arquivo inteiro
 * antes: a conversão publica os segmentos conforme os bytes chegam, exatamente
 * como no caminho do torrent.
 *
 * A sondagem (`ffprobe`) também aceita URL, mas é frágil em servidores que não
 * respondem a `Range` ou que exigem cabeçalhos específicos. Por isso a análise
 * aqui é tolerante: se o ffprobe falhar, seguimos com um modo conservador
 * (`video`, que transcodifica) em vez de derrubar a sessão — o FFmpeg ainda
 * pode conseguir ler o que o ffprobe não conseguiu.
 */
async function prepararSessaoDireta(sessao) {
  const extensao = extensaoDaUrl(sessao.url)

  if (!EXTENSOES_DIRETAS.includes(extensao)) {
    throw erroDaFonte(
      'formato_desconhecido',
      `O link direto não aponta para um formato de vídeo reconhecido (${extensao || 'sem extensão'}).`
    )
  }

  sessao.status = 'aguardando'
  sessao.mensagem = 'Lendo o cabeçalho da fonte...'

  // O nome é resolvido antes de o FFmpeg ser chamado — ver `conferirNomeDaFonte`.
  await conferirNomeDaFonte(sessao)

  /*
   * A análise é o único ponto que pode falhar sem condenar a sessão. Um
   * servidor que não responde ao ffprobe ainda pode ser lido pelo FFmpeg, então
   * tratamos a falha como "não sei" e caímos no modo mais seguro.
   *
   * A exceção é a falha de resolução, e ela é deliberada: `analisarArquivo` já
   * repetiu a sondagem quando o erro era transitório, então seguir adiante
   * entregaria a sessão a uma conversão que morre na abertura com o mesmo erro.
   * Era esse o caminho da "primeira tentativa que fica convertendo e não vai":
   * o modo conservador transcodificava, o FFmpeg encerrava por DNS em um
   * segundo, e a tela esperava 180 s por um processo que já tinha morrido.
   */
  let analise = null

  try {
    analise = await analisarArquivo(sessao.url, extensao)
  } catch (erro) {
    if (ehFalhaDeResolucao(erro)) {
      throw erroDaFonte('rede', `O endereço da fonte não resolveu (${resumirErro(erro)}).`)
    }

    logger.warn(
      `[sessao ${sessao.id}] ffprobe não leu a fonte direta (${resumirErro(erro)}); seguindo com transcodificação`
    )
  }

  conferirSessao(sessao)

  const modo = analise?.modo ?? 'video'

  sessao.duracao = analise?.duracao ?? null
  sessao.idiomasAudio = analise?.idiomasAudio ?? []
  sessao.idiomaAudio = analise?.idiomaAudio ?? null
  sessao.idiomaAudioRotulo = analise?.idiomaAudioRotulo ?? null
  sessao.temAudioPortugues = sessao.idiomasAudio.length
    ? temFaixaPortuguesa(sessao.idiomasAudio)
    : null

  sessao.status = 'convertendo'
  sessao.mensagem = mensagemDoModo(modo)
  sessao.modo = modo
  sessao.tempoBase = 0
  sessao.indiceAudio = analise?.indiceAudio ?? null

  logger.info(
    `[sessao ${sessao.id}] fonte direta modo=${modo} video=${analise?.videoCodec ?? '?'} audio=${analise?.audioCodec ?? '?'} duracao=${sessao.duracao ?? '?'} url=${sessao.url}`
  )

  /*
   * O FFmpeg lê a URL diretamente. Passamos `caminho` (e não `fluxo`) porque a
   * entrada remota é buscável: o FFmpeg consegue voltar para o `moov` no fim de
   * um MP4 sem faststart, o que um pipe não permitiria.
   *
   * A conversão é aberta, acompanhada e — quando morre na abertura por um motivo
   * de rede — reaberta. Cada abertura é uma conexão nova, com uma resolução de
   * nome nova, e é isso que faz a primeira tentativa do usuário passar sozinha
   * quando o DNS do container respondeu "tente de novo" na anterior.
   */
  let ultimoPercentual = null
  let comando = null

  for (let tentativa = 1; tentativa <= TENTATIVAS_CONVERSAO_DIRETA; tentativa += 1) {
    comando = iniciarConversao({
      caminho: sessao.url,
      extensao,
      diretorio: sessao.diretorio,
      modo,
      duracaoEsperada: sessao.duracao,
      indiceAudio: sessao.indiceAudio,
      aoProgredir: (progresso) => {
        sessao.progresso = progresso
      },
    })

    registrarConversao(sessao, comando)

    try {
      /*
       * A fonte direta não tem peers: o que prova que ela está viva é o avanço da
       * conversão. Guardamos o último percentual visto e comparamos a cada checagem
       * do buffer — se mudou, o FFmpeg trabalhou e o prazo do buffer reinicia. Sem
       * isso, uma conversão longa (fonte HTTP lenta, arquivo grande) estourava os
       * 180 s e a sessão morria como erro mesmo estando a avançar.
       *
       * O `estaVivo` fecha o outro lado: um FFmpeg que já encerrou não vai publicar
       * segmento nenhum, então esperar o prazo inteiro só esconderia a causa.
       */
      await aguardarBufferInicial(
        sessao.diretorio,
        8,
        180000,
        () => {
          const percentual = sessao.progresso?.percentual ?? null

          if (percentual === null || percentual === ultimoPercentual) {
            return false
          }

          ultimoPercentual = percentual
          return true
        },
        () => comando.erro() === null
      )

      break
    } catch (erro) {
      const causa = comando.erro()

      /*
       * Sem erro do comando, o processo está vivo: o prazo estourou com a
       * conversão parada, e reabrir não mudaria o resultado.
       */
      if (causa === null) throw erro

      const transitoria = ehFalhaTransitoria(causa)
      const ultimaTentativa = tentativa === TENTATIVAS_CONVERSAO_DIRETA

      /*
       * O motivo acompanha a causa, e não o prazo: "tempo esgotado" esconde se a
       * fonte caiu (rede) ou se ela entregou algo que o FFmpeg não leu.
       */
      if (ultimaTentativa) {
        throw transitoria
          ? erroDaFonte('rede', `A fonte caiu antes do primeiro segmento (${resumirErro(causa)}).`)
          : erroDaFonte('sem_dados', `A conversão não publicou nenhum segmento (${resumirErro(causa)}).`)
      }

      if (!transitoria) {
        throw erroDaFonte('sem_dados', `A conversão não publicou nenhum segmento (${resumirErro(causa)}).`)
      }

      logger.warn(
        `[sessao ${sessao.id}] conversão caiu na abertura (${resumirErro(causa)}); reabrindo (tentativa ${tentativa + 1} de ${TENTATIVAS_CONVERSAO_DIRETA})`
      )

      sessao.mensagem = 'Reconectando à fonte...'
      ultimoPercentual = null

      // O processo já morreu, mas pode ter deixado um filho pendurado sobre a
      // pasta da sessão; encerramos antes de abrir a próxima conversão.
      pararConversao(sessao)
      conferirSessao(sessao)

      await new Promise((resolve) => setTimeout(resolve, ESPERA_REABERTURA_MS))
    }
  }

  conferirSessao(sessao)

  registrarDiagnostico(sessao)

  sessao.status = 'pronto'
  sessao.mensagem = 'Pronto para reproduzir'
  sessao.playlist = path.join(sessao.diretorio, 'playlist.m3u8')
}

/**
 * Extrai a extensão de uma URL, ignorando a query string.
 *
 * Uma URL de streaming costuma carregar token e parâmetros depois do `?`
 * (`.../video.mp4?token=abc`), e `path.extname` os incluiria no resultado. O
 * caminho é isolado antes para que a extensão saia limpa.
 */
function extensaoDaUrl(url) {
  try {
    const caminho = new URL(url).pathname
    return path.extname(caminho).toLowerCase()
  } catch {
    // URL malformada: cai no extname cru, que ao menos tenta algo.
    return path.extname(String(url).split('?')[0]).toLowerCase()
  }
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

/**
 * Tamanho da antecedência da janela, entre o mínimo e o máximo.
 *
 * Com poucos peers a janela é ampliada. Numa malha pequena o *picker* tem pouca
 * escolha de onde buscar cada pedaço: uma janela apertada o obriga a insistir
 * nos mesmos pedaços, que podem estar justamente com o peer mais lento. Alargar
 * a janela dá a ele mais pedaços elegíveis e permite pedir a um peer vizinho o
 * que o outro não entrega — a malha pequena deixa de ser gargalo. O teto máximo
 * continua valendo para a janela não virar a seleção do filme inteiro.
 */
function tamanhoDaJanela(sessao, arquivo) {
  const alvo = bytesDoTempo(sessao, arquivo, ANTECEDENCIA_SEGUNDOS) ?? ANTECEDENCIA_MINIMA_BYTES
  const peers = sessao.torrent?.numPeers ?? 0
  const escassos = peers > 0 && peers <= PEERS_ESCASSOS
  const ampliado = escassos ? alvo * FATOR_JANELA_PEERS_ESCASSOS : alvo

  return Math.min(Math.max(ampliado, ANTECEDENCIA_MINIMA_BYTES), ANTECEDENCIA_MAXIMA_BYTES)
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

  const arquivo = escolherArquivoDeVideo(torrent, sessao.temporada, sessao.episodio)

  if (!arquivo) {
    throw erroDaFonte('sem_video', 'A fonte não contém um arquivo de vídeo reconhecido.')
  }

  /*
   * Num pack de temporada o torrent traz todos os episódios. Este log diz qual
   * arquivo a seleção escolheu para o episódio pedido — é o que permite conferir
   * depois por que o player abriu (ou não) o capítulo certo.
   */
  if (sessao.episodio !== null) {
    const rotulo = `S${String(sessao.temporada).padStart(2, '0')}E${String(sessao.episodio).padStart(2, '0')}`
    logger.info(`[sessao ${sessao.id}] episódio ${rotulo} -> arquivo "${arquivo.path}"`)
  }

  /*
   * Num pack de temporada o torrent traz todos os episódios e o WebTorrent
   * seleciona todos por padrão. Sem isolar o arquivo do episódio, o download se
   * espalha pelo pack inteiro e o episódio pedido fica sem os pedaços iniciais —
   * o FFmpeg não lê o cabeçalho e a sessão trava em "aguardando". Isolamos aqui,
   * antes de a janela de leitura começar a mexer nas prioridades.
   */
  isolarArquivoDoEpisodio(sessao, torrent, arquivo)

  const caminho = caminhoDoArquivo(torrent, arquivo)

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
   * Antes de sondar o cabeçalho, exigimos que o começo do filme esteja contíguo
   * em disco. Sem isso, o ffprobe era chamado nos primeiros porcentos do
   * download — quando o arquivo ainda nem existia — e devolvia "No such file or
   * directory", um erro que parecia corrupção mas era só o arquivo ausente.
   * Esperar os primeiros megabytes também evita que o ffprobe leia um arquivo
   * esparso e interprete os zeros do trecho não baixado como dados inválidos.
   */
  await aguardarInicio(sessao, arquivo)
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

  /*
   * Fato que o porteiro de idioma consulta: existe faixa em português? Fica
   * `null` quando a sondagem não trouxe faixas, para não reprovar uma fonte por
   * falta de dado — só o `false` (prova de que só há áudio original) reprova.
   */
  sessao.temAudioPortugues = sessao.idiomasAudio.length
    ? temFaixaPortuguesa(sessao.idiomasAudio)
    : null

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
  // Faixa de áudio escolhida na análise; o reposicionamento precisa dela para
  // remontar a conversão com o mesmo mapeamento de faixas.
  sessao.indiceAudio = analise.indiceAudio ?? null

  /*
   * Este log é a leitura do caso: caminho escolhido, codecs, duração e o
   * `start_time` da fonte. O `start_time` alto denuncia um arquivo cuja timeline
   * não começa em zero — junto com o cabeçalho (`ftyp>moov` é *faststart*,
   * `ftyp>mdat` é índice no fim), dá para saber o que o FFmpeg recebeu sem
   * adivinhar pelo comportamento do player.
   */
  logger.info(
    `[sessao ${sessao.id}] caminho=${indiceNoFim ? 'disco' : 'pipe'} modo=${analise.modo} video=${analise.videoCodec} pixFmt=${analise.pixFmt ?? '?'} audio=${analise.audioCodec} audioIdioma=${sessao.idiomaAudioRotulo ?? sessao.idiomaAudio ?? '?'} faixaAudio=${analise.indiceAudio ?? '?'} faixasAudio=${sessao.idiomasAudio.length} keyframes=${analise.intervaloKeyframes?.toFixed(2) ?? '?'}s duracao=${sessao.duracao ?? '?'} inicioFonte=${analise.inicioFonte ?? '?'}s cabecalho=${descreverCabecalho(caminho)}`
  )

  /*
   * A lista completa das faixas fica numa linha própria: é longa e só interessa
   * quando o áudio escolhido não bate com o que a fonte prometia.
   */
  logger.info(
    `[sessao ${sessao.id}] faixas de áudio: ${descreverFaixasAudio(sessao.idiomasAudio)} (português: ${sessao.temAudioPortugues === null ? '?' : sessao.temAudioPortugues ? 'sim' : 'não'})`
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
      indiceAudio: analise.indiceAudio,
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
        indiceAudio: analise.indiceAudio,
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
 * Resume as faixas de áudio do arquivo para o log da sessão.
 *
 * O log antes só dizia o idioma da faixa escolhida. Quando a fonte promete
 * "Dublado" e toca em inglês, a pergunta é sempre a mesma: o arquivo não tem
 * PT-BR, ou tem e não foi reconhecido? Só a lista completa responde — cada
 * faixa com seu código (`language`) e título (`title`), que é onde a dublagem
 * costuma se esconder quando o encoder não marca o idioma.
 *
 * @param {Array<object>} faixas faixas descritas por `descreverFaixaAudio`
 * @returns {string}
 */
function descreverFaixasAudio(faixas) {
  if (!faixas?.length) return 'nenhuma'

  return faixas
    .map((faixa) => {
      const codigo = faixa.codigo ?? 'sem-código'
      const titulo = faixa.rotulo ?? 'sem-título'

      return `#${faixa.indice}[${codigo}|${titulo}]`
    })
    .join(' ')
}

/**
 * Registra no log como a playlist publicada começa.
 *
 * Sequência de mídia, primeiro `#EXTINF` e quantidade de segmentos: se o
 * primeiro trecho tiver duração coerente e a sequência for zero, a conversão
 * partiu do início do arquivo. É a evidência que faltava para separar um
 * problema de conversão de um problema de player.
 *
 * Vai além da contagem porque ela sozinha não distingue uma conversão saudável
 * de um muxer que cria o arquivo e morre antes de fechá-lo: um `.ts` de 4 s em
 * 1080p ocupa centenas de KB, e algumas centenas de **bytes** significam um
 * segmento quebrado. Como o `SourceBuffer` recusa anexar exatamente isso, o
 * tamanho dos primeiros trechos é o dado que separa "codec incompatível" de
 * "arquivo vazio". O cabeçalho cru da playlist entra junto pelo mesmo motivo: é
 * nele que se lê a versão do HLS, o `TARGETDURATION` e os nomes reais dos
 * trechos, sem depender do que o player interpretou.
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
    const nomes = linhas.filter((linha) => linha.endsWith('.ts'))

    logger.info(
      `[sessao ${sessao.id}] playlist: ${sequencia ?? 'sem sequência'} | ${primeiro ?? 'sem EXTINF'} | segmentos=${nomes.length}`
    )

    const tamanhos = nomes
      .slice(0, 3)
      .map((nome) => {
        try {
          return `${nome}=${fs.statSync(path.join(sessao.diretorio, nome)).size}`
        } catch {
          return `${nome}=ausente`
        }
      })
      .join(' ')

    logger.info(`[sessao ${sessao.id}] primeiros trechos: ${tamanhos || 'nenhum'}`)
    logger.info(`[sessao ${sessao.id}] playlist crua:\n${linhas.slice(0, 12).join('\n')}`)
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
 * Aguarda uma faixa do arquivo ficar presente, com paciência por progresso.
 *
 * Usada tanto para o índice (`moov`) quanto para o começo do filme. O download
 * corre em paralelo; aqui só esperamos o pedaço do arquivo que interessa.
 *
 * O prazo não é um relógio fixo: ele só corre enquanto o download estiver
 * **parado**. Cada avanço real de bytes baixados renova a espera, então uma
 * fonte de poucos seeds que anda devagar nunca é cortada no meio do caminho —
 * só é abandonada quando estagna de verdade (`ESTAGNACAO_PECAS_MS`) ou quando
 * bate o teto absoluto (`TETO_PECAS_MS`), que existe para uma fonte
 * patologicamente lenta não prender a sessão para sempre.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {number} de byte inicial da faixa
 * @param {number} ate byte final da faixa
 * @param {number} timeoutMs teto absoluto de espera (opcional)
 */
function aguardarPecas(sessao, arquivo, de, ate, timeoutMs = TETO_PECAS_MS) {
  const inicio = Date.now()
  let ultimoBaixado = sessao.torrent?.downloaded ?? 0
  let ultimoAvanco = Date.now()

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

      /*
       * O sinal de vida é o total baixado do torrent, não a faixa pedida: o
       * picker pode estar fechando pedaços vizinhos antes do trecho que
       * interessa, e isso também é progresso — a fonte está entregando.
       */
      const baixado = sessao.torrent?.downloaded ?? ultimoBaixado

      if (baixado > ultimoBaixado) {
        ultimoBaixado = baixado
        ultimoAvanco = Date.now()
      }

      const agora = Date.now()
      const parado = agora - ultimoAvanco

      if (parado > ESTAGNACAO_PECAS_MS || agora - inicio > timeoutMs) {
        const peers = sessao.torrent?.numPeers ?? 0
        const velocidade = sessao.torrent?.downloadSpeed ?? 0

        /*
         * O diagnóstico separa os dois desfechos que antes chegavam iguais ao
         * usuário: a fonte que nunca entregou nada (parada desde o início) e a
         * que entregava e parou no meio (seed caiu). A velocidade no instante da
         * desistência diz se ainda havia tráfego residual.
         */
        logger.warn(
          `[sessao ${sessao.id}] trecho do vídeo não chegou: ` +
            `parado há ${Math.round(parado / 1000)}s, ` +
            `baixado ${emMB(baixado)}, peers ${peers}, ` +
            `velocidade ${emMB(velocidade)}/s, ` +
            `faixa ${emMB(de)}–${emMB(ate)}`
        )

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
 * O prazo fica por conta de `aguardarPecas`, que o mede por progresso: uma
 * fonte de poucos seeds que anda devagar não é cortada, só a que estagna.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 */
function aguardarInicio(sessao, arquivo) {
  return aguardarPecas(sessao, arquivo, 0, Math.min(BYTES_INICIAIS, arquivo.length))
}

/**
 * Aguarda o download completo do arquivo.
 *
 * Reserva do caminho não progressivo, usada quando o `moov` não pôde ser
 * localizado. Como em `aguardarPecas`, a paciência é medida por progresso: o
 * download completo de uma fonte lenta pode levar muito tempo, e um teto fixo
 * derrubava a sessão no meio de um download que estava andando. Só desistimos
 * quando o download estagna de verdade ou quando bate o teto absoluto.
 *
 * @param {object} sessao
 * @param {import('webtorrent').TorrentFile} arquivo
 * @param {number} timeoutMs teto absoluto de espera
 */
function aguardarDownloadCompleto(sessao, arquivo, timeoutMs = TETO_PECAS_MS) {
  const inicio = Date.now()
  let ultimoBaixado = sessao.torrent?.downloaded ?? 0
  let ultimoAvanco = Date.now()

  return new Promise((resolve, reject) => {
    const verificar = () => {
      if (sessao.cancelada) return reject(new SessaoCancelada())

      if (arquivo.progress >= 1) {
        return resolve()
      }

      const baixado = sessao.torrent?.downloaded ?? ultimoBaixado

      if (baixado > ultimoBaixado) {
        ultimoBaixado = baixado
        ultimoAvanco = Date.now()
      }

      const agora = Date.now()
      const parado = agora - ultimoAvanco

      if (parado > ESTAGNACAO_PECAS_MS || agora - inicio > timeoutMs) {
        const peers = sessao.torrent?.numPeers ?? 0

        logger.warn(
          `[sessao ${sessao.id}] download do filme não terminou: ` +
            `parado há ${Math.round(parado / 1000)}s, ` +
            `baixado ${emMB(baixado)}, peers ${peers}, ` +
            `progresso ${Math.round(arquivo.progress * 100)}%`
        )

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
 * @param {number} timeoutMs prazo para os metadados, em ms
 * @returns {Promise<import('webtorrent').Torrent>}
 */
/**
 * Monta o caminho real do arquivo no disco.
 *
 * O WebTorrent baixa cada torrent dentro de uma pasta com o nome do próprio
 * torrent (`torrent.path/<torrent.name>/...`), e o `arquivo.path` que ele expõe
 * já é relativo a essa pasta — não à raiz de download. Juntar apenas
 * `torrent.path` com `arquivo.path` produzia um caminho sem a pasta do torrent,
 * e o ffprobe falhava com "No such file or directory" mesmo com o arquivo
 * existindo alguns níveis abaixo. O nome do torrent costuma trazer espaços,
 * acentos e colchetes ("American Horror Story 1ª Temporada [2011 DUAL ÁUDIO]
 * 720p PT BR"), mas isso não é problema: o `path.join` monta a string e o
 * fluent-ffmpeg a repassa como argumento de vetor, sem passar pelo shell.
 *
 * @param {import('webtorrent').Torrent} torrent
 * @param {import('webtorrent').TorrentFile} arquivo
 * @returns {string} caminho absoluto do arquivo
 */
export function tamanhoDoArquivoEmDisco(caminho) {
  try {
    const info = fs.statSync(caminho)

    return info.isFile() ? info.size : null
  } catch (erro) {
    // ENOENT é o caso normal nos primeiros segundos: o WebTorrent ainda não
    // criou o arquivo. Qualquer outro erro (permissão, caminho inválido) também
    // devolve `null` para o laço seguir esperando em vez de derrubar a sessão.
    return null
  }
}

export function caminhoDoArquivo(torrent, arquivo) {
  /*
   * O `arquivo.path` pode, em versões antigas do WebTorrent, já vir prefixado
   * com o nome do torrent. Nesse caso juntar a pasta de novo duplicaria o
   * segmento e o arquivo não seria encontrado. Só prefixamos quando o caminho
   * ainda não começa pela pasta do torrent.
   */
  const relativo = arquivo.path ?? arquivo.name ?? ''
  const jaTemPasta = torrent.name && relativo.startsWith(`${torrent.name}/`)

  return jaTemPasta ? path.join(torrent.path, relativo) : path.join(torrent.path, torrent.name ?? '', relativo)
}

async function adicionarTorrent(magnet, timeoutMs = TIMEOUT_METADADOS_MS) {
  const existente = await cliente.get(magnet)

  if (existente) {
    if (existente.ready) {
      return existente
    }

    return esperarMetadados(existente, timeoutMs)
  }

  return esperarMetadados(cliente.add(magnet, { path: os.tmpdir() }), timeoutMs)
}

/**
 * Espera o torrent publicar os metadados, com prazo.
 *
 * O evento `ready` só chega quando algum peer entrega o `info` do torrent — é o
 * único ponto do preparo em que uma fonte morta trava sem erro. Antes, o ramo de
 * reuso (`cliente.get` de um magnet já adicionado) esperava sem limite nenhum:
 * bastava a primeira tentativa falhar para a segunda pendurar o overlay para
 * sempre. Agora os dois caminhos passam por aqui e ambos têm prazo.
 *
 * O `numPeers` no instante da falha separa as duas leituras: zero peers é fonte
 * morta — trocar de lançamento —, peers presentes mas calados é rede ruim, que
 * ainda vale uma nova tentativa.
 *
 * @param {import('webtorrent').Torrent} torrent
 * @param {number} timeoutMs prazo, em ms (a inspeção de pack usa um prazo curto)
 * @returns {Promise<import('webtorrent').Torrent>}
 */
function esperarMetadados(torrent, timeoutMs = TIMEOUT_METADADOS_MS) {
  if (torrent.ready) {
    return Promise.resolve(torrent)
  }

  return new Promise((resolve, reject) => {
    const finalizar = () => {
      clearTimeout(temporizador)
      torrent.off('ready', aoFicarPronto)
      torrent.off('error', aoFalhar)
    }

    const aoFicarPronto = () => {
      finalizar()
      resolve(torrent)
    }

    const aoFalhar = (erro) => {
      finalizar()
      reject(erro.motivo ? erro : erroDaFonte('sem_metadados', erro.message))
    }

    const temporizador = setTimeout(() => {
      const peers = torrent.numPeers ?? 0

      /*
       * Diagnóstico da malha no instante da desistência. "Sem peers" tem duas
       * leituras muito diferentes — fonte morta de verdade ou rede do container
       * que não resolve tracker nem entra no DHT — e sem estes números as duas
       * ficam idênticas no log. Os anunciadores vêm do próprio magnet; se a
       * lista estiver vazia, o problema é de montagem do magnet, não de rede.
       */
      const anunciadores = (torrent.announce ?? []).length
      const porta = cliente.torrentPort ?? PORTA_TORRENT

      logger.warn(
        `[fonte] metadados não chegaram em ${timeoutMs / 1000}s ` +
          `(peers: ${peers}, trackers: ${anunciadores}, porta: ${porta}, ` +
          `dht: ${cliente.dht ? 'ligado' : 'desligado'})`
      )

      finalizar()

      reject(
        erroDaFonte(
          peers === 0 ? 'sem_peers' : 'sem_metadados',
          `A fonte não entregou os metadados em ${timeoutMs / 1000}s (peers: ${peers}).`
        )
      )
    }, timeoutMs)

    torrent.once('ready', aoFicarPronto)
    torrent.once('error', aoFalhar)

    // O torrent pode ter ficado pronto entre o teste acima e o registro dos
    // listeners; sem esta conferência o `ready` já emitido se perderia e só
    // restaria esperar o prazo por um dado que já está disponível.
    if (torrent.ready) {
      aoFicarPronto()
    }
  })
}

/**
 * Escolhe o arquivo de vídeo do torrent.
 *
 * Sem `temporada`/`episodio` (fluxo de filme), vale a heurística de sempre: o
 * maior arquivo com extensão de vídeo é quase sempre o filme, já que amostras e
 * extras são menores.
 *
 * Com um episódio pedido (fluxo de série, inclusive pack de temporada), o maior
 * arquivo não serve: o pack tem todos os episódios e o maior deles é só o de
 * melhor qualidade. Procuramos primeiro o arquivo cujo caminho declara a
 * numeração pedida; se nenhum declarar, caímos no maior vídeo — que é o melhor
 * palpite quando o pacote não segue o padrão SxxExx.
 *
 * @param {import('webtorrent').Torrent} torrent
 * @param {number|null} temporada
 * @param {number|null} episodio
 */
function escolherArquivoDeVideo(torrent, temporada = null, episodio = null) {
  const videos = torrent.files.filter((arquivo) =>
    EXTENSOES_VIDEO.includes(path.extname(arquivo.name).toLowerCase())
  )

  if (videos.length === 0) {
    return null
  }

  if (temporada && episodio) {
    const doEpisodio = videos.find((arquivo) =>
      caminhoCorrespondeAoEpisodio(arquivo.path, temporada, episodio)
    )

    if (doEpisodio) {
      return doEpisodio
    }

    logger.warn(
      `[torrent] nenhum arquivo casa S${temporada}E${episodio}; usando o maior vídeo do pacote`
    )
  }

  return videos.sort((a, b) => b.length - a.length)[0]
}

/**
 * Isola o arquivo do episódio dentro de um pack de temporada.
 *
 * O WebTorrent seleciona **todos** os arquivos de um torrent com prioridade 1
 * assim que os metadados chegam. Num pack de temporada isso é desastroso: o
 * *picker* espalha os pedidos por dezenas de episódios e o arquivo que o usuário
 * pediu fica sem os pedaços iniciais — o FFmpeg não consegue ler o cabeçalho, a
 * conversão trava em "aguardando" e o download aparenta ter parado em ~11%
 * (justamente a fração que o pack inteiro já tinha baixado de forma difusa).
 *
 * Aqui varremos `torrent.files` e deixamos **apenas** o arquivo alvo ativo:
 * todos os outros recebem `deselect()` (prioridade 0). A partir daí a banda
 * inteira trabalha pelo episódio certo, e a janela móvel de `iniciarJanela`
 * cuida de manter quentes só os pedaços à frente da leitura.
 *
 * Só agimos quando há um episódio pedido: no fluxo de filme o torrent costuma
 * ter um único vídeo e a seleção padrão já é a correta — mexer nela só criaria
 * risco sem ganho.
 *
 * @param {object} sessao
 * @param {import('webtorrent').Torrent} torrent
 * @param {import('webtorrent').TorrentFile} alvo arquivo do episódio escolhido
 * @returns {number} quantidade de arquivos desselecionados
 */
function isolarArquivoDoEpisodio(sessao, torrent, alvo) {
  if (sessao.episodio === null) return 0

  const arquivos = torrent.files ?? []
  let desselecionados = 0

  for (const arquivo of arquivos) {
    if (arquivo === alvo) continue

    /*
     * `deselect` é o caminho preferido: zera a prioridade do arquivo inteiro de
     * uma vez. Quando a versão do WebTorrent não expõe o método, caímos para
     * `select(0)` — a prioridade 0 tem o mesmo efeito prático de tirar o arquivo
     * da fila do *picker*.
     */
    try {
      if (typeof arquivo.deselect === 'function') {
        arquivo.deselect()
      } else {
        arquivo.select(0)
      }

      desselecionados += 1
    } catch (erro) {
      logger.warn(
        `[sessao ${sessao.id}] falha ao desselecionar "${arquivo.path ?? arquivo.name}":`,
        erro.message
      )
    }
  }

  /*
   * O arquivo alvo recebe prioridade máxima. A janela móvel vai reajustar os
   * intervalos logo em seguida, mas esta seleção inicial garante que o *picker*
   * já comece pelo episódio certo — sem ela, o primeiro instante do download
   * ainda poderia atacar os arquivos que acabamos de soltar.
   */
  try {
    alvo.select(1)
  } catch (erro) {
    logger.warn(`[sessao ${sessao.id}] falha ao selecionar o arquivo do episódio:`, erro.message)
  }

  logger.info(
    `[sessao ${sessao.id}] pack isolado: "${alvo.path ?? alvo.name}" selecionado, ` +
      `${desselecionados} arquivo(s) desselecionado(s) de ${arquivos.length}`
  )

  return desselecionados
}

/**
 * Diz se o caminho de um arquivo declara a temporada/episódio pedidos.
 *
 * O teste é feito sobre o caminho inteiro, não só o nome, porque muitos packs
 * organizam os episódios em pasta ("Temporada 1/Episódio 02.mkv") e deixam o
 * nome do arquivo sem a numeração.
 *
 * Cobre os padrões que aparecem na prática: "S01E02", "T01E02", "1x02",
 * "Temporada 1 Episódio 2", "Episódio 02" e "Capítulo 02". Os zeros à esquerda
 * são opcionais para casar tanto "E2" quanto "E02".
 *
 * @param {string} caminho caminho relativo do arquivo dentro do torrent
 * @param {number} temporada
 * @param {number} episodio
 */
function caminhoCorrespondeAoEpisodio(caminho, temporada, episodio) {
  const texto = caminho.toLowerCase()

  /*
   * O nome do arquivo é a última parte do caminho. Os padrões que numeram o
   * episódio sem "SxxExx" precisam olhar só para ele: a pasta do pack costuma
   * trazer o ano ("American Horror Story 1ª Temporada [2011 DUAL ÁUDIO] 720p
   * PT BR"), e um número solto na pasta casaria o episódio errado.
   */
  const nomeArquivo = texto.split('/').pop() || texto

  const padroes = [
    // S01E02 / S1 E2 / S01.E02
    new RegExp(`s0*${temporada}[\\s._-]*e0*${episodio}(?!\\d)`, 'i'),
    // T01E02
    new RegExp(`t0*${temporada}[\\s._-]*e0*${episodio}(?!\\d)`, 'i'),
    // 1x02
    new RegExp(`(?<!\\d)0*${temporada}x0*${episodio}(?!\\d)`, 'i'),
    // Temporada 1 ... Episódio 2
    new RegExp(`temporada\\s*0*${temporada}.{0,30}?epis[oó]dio\\s*0*${episodio}`, 'iu'),
    // Episódio 02 (quando a pasta já diz a temporada)
    new RegExp(`epis[oó]dio\\s*0*${episodio}(?!\\d)`, 'iu'),
    // Capítulo 02
    new RegExp(`cap[ií]tulo\\s*0*${episodio}(?!\\d)`, 'iu'),
  ]

  /*
   * "01 - Pilot.mp4", "12 - Afterbirth.mp4", "01. Pilot", "01_Pilot": o número
   * do episódio abre o NOME do arquivo, separado do título por espaço, ponto,
   * hífen ou underline. É o padrão dos packs dublados PT-BR, que não usam
   * "SxxExx" em lugar nenhum.
   *
   * A âncora é o começo do nome do arquivo, e o número precisa ser seguido de
   * um separador — sem isso "720p" casaria o episódio 7 e "1080p" o episódio
   * 10. O separador obrigatório já fecha essa brecha, então não há mais o
   * lookahead `(?!p)`: ele barrava títulos legítimos que começam com "P"
   * ("Pilot", "Parte", "Prólogo"), e era por isso que "01 - Pilot.mp4" não
   * casava e o fallback escolhia o maior vídeo do pacote — o episódio errado.
   */
  padroes.push(new RegExp(`^0*${episodio}\\s*[-._]\\s*\\S`, 'i'))

  /*
   * "Ep 01", "Ep. 01", "E01" solto: alguns packs numeram o episódio com o
   * prefixo "ep" em vez de "SxxExx". O `(?!\\d)` evita casar "Ep 010".
   */
  padroes.push(new RegExp(`(?:^|[\\s._-])ep\\.?\\s*0*${episodio}(?!\\d)`, 'i'))

  return padroes.some((padrao) => padrao.test(nomeArquivo))
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

      const peers = torrent.numPeers ?? 0

      /*
       * Sem nenhum peer é a mesma leitura do magnet sem metadados: a fonte não
       * existe mais na malha. Com peers, mas sem tráfego, o problema é outro —
       * a fonte conversa e não entrega —, e essa diferença muda o conselho que
       * o overlay dá ao usuário.
       */
      reject(
        erroDaFonte(
          peers === 0 ? 'sem_peers' : 'sem_dados',
          `A fonte não enviou dados em ${TIMEOUT_DADOS_MS / 1000}s (peers: ${peers}).`
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

    /*
     * O ffprobe não pode ser disparado antes de o arquivo existir em disco. Nos
     * primeiros porcentos do download o WebTorrent ainda não criou o arquivo (ou
     * o criou com tamanho zero), e o ffprobe devolvia "No such file or directory"
     * — um erro que parecia corrupção, mas era só o arquivo ausente. Esperamos o
     * arquivo aparecer com um tamanho mínimo útil antes de sondar.
     */
    const tamanhoEmDisco = tamanhoDoArquivoEmDisco(caminho)

    if (tamanhoEmDisco === null) {
      ultimoErro = new Error('arquivo ainda não existe em disco')
      await new Promise((resolve) => setTimeout(resolve, 1000))
      continue
    }

    if (tamanhoEmDisco < TAMANHO_MINIMO_SONDAGEM) {
      ultimoErro = new Error(`arquivo ainda pequeno demais (${tamanhoEmDisco} bytes)`)
      await new Promise((resolve) => setTimeout(resolve, 1000))
      continue
    }

    try {
      const analise = await analisarArquivo(caminho)

      // Um cabeçalho lido pela metade devolve os streams sem os codecs. Aceitar
      // isso faria o FFmpeg "concluir" sem gerar segmento nenhum, então só
      // consideramos a análise válida quando vídeo e áudio foram identificados.
      const audioEVideoLidos = Boolean(analise.videoCodec && analise.audioCodec)

      /*
       * Para um codec que copiaríamos, o `codec_name` sozinho não basta. Ele sai
       * do cabeçalho do contêiner e chega antes dos dados, enquanto o `pix_fmt`
       * depende do SPS, que vive nos primeiros quadros do stream. Aceitar a
       * análise só com o codec deixava passar um 10-bit lido pela metade
       * (`codec_name='h264'`, `pix_fmt` vazio): `decidirModo` o classificava
       * como copiável e o MSE recusava o segmento. Por isso insistimos até o
       * `pix_fmt` chegar, enquanto o codec for de um tipo que copiaríamos.
       */
      const faltaProfundidade = profundidadeRelevante(analise.videoCodec) && !analise.pixFmt

      if (audioEVideoLidos && !faltaProfundidade) {
        return analise
      }

      /*
       * Com o torrent completo os dados estão todos em disco: se o `pix_fmt`
       * ainda não veio, não virá. Aceitamos a análise e deixamos `decidirModo`
       * tratar a ausência como motivo para transcodificar, em vez de copiar um
       * vídeo de profundidade desconhecida.
       */
      if (arquivo.progress >= 1 && audioEVideoLidos) {
        return analise
      }

      ultimoErro = new Error('cabeçalho incompleto')
    } catch (erro) {
      /*
       * ENOENT aqui é diferente de "ffprobe exited with code 1". O primeiro diz
       * que o arquivo sumiu entre a checagem de tamanho e a sondagem (o torrent
       * foi removido, por exemplo); o segundo diz que o arquivo existe mas o
       * ffprobe não conseguiu lê-lo. Rotulamos para o log final não confundir os
       * dois desfechos.
       */
      ultimoErro = erro?.code === 'ENOENT'
        ? new Error('arquivo ausente no momento da sondagem')
        : erro
    }

    /*
     * Se o torrent já terminou e ainda assim não lemos o cabeçalho, não há mais
     * o que esperar: os dados estão todos em disco e o ffprobe continua
     * recusando o arquivo. Isso não é lentidão — é arquivo corrompido, falso
     * vídeo (sample, `.nfo` renomeado) ou o episódio errado escolhido pelo
     * fallback. Registramos o nome do arquivo para o log apontar qual foi.
     */
    if (arquivo.progress >= 1) {
      logger.warn(
        `[torrent] arquivo completo mas ilegível: "${arquivo.name}" (${arquivo.length} bytes) — ${ultimoErro?.message || 'formato desconhecido'}`
      )

      break
    }

    await new Promise((resolve) => setTimeout(resolve, 1000))
  }

  /*
   * A mensagem separa os dois desfechos: o arquivo completo que o ffprobe
   * recusou (provável arquivo errado ou corrompido) do arquivo que nunca
   * terminou de baixar dentro do prazo (fonte lenta ou sem peers). Sem essa
   * distinção, os dois casos chegavam ao usuário como "cabeçalho ilegível" e
   * escondiam a causa real.
   */
  const completo = arquivo.progress >= 1
  const causa = ultimoErro?.message || 'formato desconhecido'

  throw new Error(
    completo
      ? `O arquivo "${arquivo.name}" não é um vídeo legível: ${causa}`
      : `Não foi possível ler o cabeçalho do vídeo a tempo: ${causa}`
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
    /*
     * Motivo rotulado da desistência (`sem_metadados`, `sem_peers`,
     * `sem_dados`, `sem_video`). O overlay usa o rótulo para explicar o que
     * aconteceu; sem ele só restava dizer "não conectou".
     */
    motivo: sessao.motivo ?? null,
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
     * Fato do áudio para o porteiro de idioma: `true`/`false` quando o arquivo
     * foi sondado, `null` quando não há faixas a julgar. O frontend só reprova a
     * fonte no `false` — prometer dublagem e entregar o áudio original é o caso
     * que ele precisa descartar antes de montar o player.
     */
    tem_audio_pt: sessao.temAudioPortugues ?? null,
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
   * Reaplicamos o isolamento do pack antes de reabrir a janela: um seek não pode
   * devolver prioridade aos outros episódios. Sem isso, a janela nova partiria
   * de um torrent com todos os arquivos ainda selecionados e o download voltaria
   * a se espalhar pelo pack.
   */
  isolarArquivoDoEpisodio(sessao, sessao.torrent, arquivo)

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
        indiceAudio: sessao.indiceAudio,
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
        indiceAudio: sessao.indiceAudio,
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

/**
 * Inspeciona o conteúdo de um torrent à procura de indício de áudio PT-BR.
 *
 * Diferente de `verificarFonte()`, que mede a malha (peers, velocidade), esta
 * função só precisa dos **metadados**: lê o nome do torrent e os caminhos dos
 * arquivos e classifica o pack. É o socorro do backend quando o nome do pack não
 * prova o idioma — o pack nacional costuma esconder a tag na pasta ("Dublado/...")
 * ou no nome de cada episódio, não no título do torrent.
 *
 * Não baixa nenhum byte: `torrent.files` já vem preenchido assim que o `info` do
 * magnet chega. O torrent é removido no fim para não ocupar o cliente com um
 * pack de dezenas de gigabytes — a reprodução, quando houver, o adiciona de novo.
 * Se o magnet já estiver no cliente (uma sessão em andamento), ele é preservado:
 * remover um torrent em uso derrubaria a reprodução do usuário.
 *
 * @param {string} magnet
 * @param {number} esperaMs prazo para os metadados (curto: é caminho crítico)
 * @returns {Promise<{ok: boolean, motivo?: string, nome: string, arquivos: number, indicio_pt_br: boolean, prova: string|null, amostra: string[]}>}
 */
export async function inspecionarTorrent(magnet, esperaMs = TIMEOUT_METADADOS_MS) {
  let torrent
  let jaEstavaNoCliente = false

  try {
    const existente = await cliente.get(magnet)

    if (existente) {
      jaEstavaNoCliente = true
      torrent = existente.ready ? existente : await esperarMetadados(existente, esperaMs)
    } else {
      torrent = await esperarMetadados(cliente.add(magnet, { path: os.tmpdir() }), esperaMs)
    }
  } catch (erro) {
    logger.warn('[inspecao] falha ao ler o metadado do pack:', erro.message)

    return {
      ok: false,
      motivo: erro.motivo ?? 'sem_metadados',
      nome: '',
      arquivos: 0,
      indicio_pt_br: false,
      prova: null,
      amostra: [],
    }
  }

  const nome = torrent.name ?? ''
  const caminhos = (torrent.files ?? []).map((arquivo) => arquivo.path || arquivo.name || '')

  // O nome do torrent é a prova mais barata; se ele já indica PT-BR, nem vale
  // varrer os arquivos. Quando não indica, olhamos os caminhos: muitos packs
  // nacionais marcam a pasta ("Temporada 1 Dublado/") ou cada episódio.
  let indicio = contemIndicioPtBr(nome)
  let prova = indicio ? nome : null

  if (!indicio) {
    for (const caminho of caminhos) {
      if (contemIndicioPtBr(caminho)) {
        indicio = true
        prova = caminho
        break
      }
    }
  }

  if (!jaEstavaNoCliente) {
    try {
      cliente.remove(torrent, { destroyStore: true })
    } catch (erro) {
      logger.warn('[inspecao] falha ao remover o torrent de teste:', erro.message)
    }
  }

  return {
    ok: true,
    nome,
    arquivos: caminhos.length,
    indicio_pt_br: indicio,
    prova,
    amostra: caminhos.slice(0, 5),
  }
}
