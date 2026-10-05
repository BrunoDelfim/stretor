#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Sonda da fonte — captura o `video_url` que o player revela.

Este script é a medição que fechou a questão do muro do SuperFlix, guardada
aqui para poder ser repetida. O navegador *head-full* atravessa o Turnstile
e — embutido num iframe da página do `.lat` — recebe o *player* de verdade
(`Player | ...`), não a tela de "Acesso Restrito".

Essa página publica uma API própria:

    POST /player/options    -> lista de servidores (cada um com um ID)
    POST /player/source     -> { video_id, page_token, ... } e responde
                               `data.video_url`, que o player abre num iframe

Os cabeçalhos saem de `sfEdgeHeaders()` (`X-Page-Token`, `X-Requested-With`).
Em vez de adivinhar o `video_id`, a sonda instala um gravador de XHR na própria
página, deixa o player carregar os servidores sozinho e repete a consulta de
`/player/source` com o ID que ele mesmo devolveu.

## Como rodar

Ela vive **dentro** do container do FlareSolverr, porque é de lá que vêm o
Chromium, o Xvfb e o Selenium já instalados — nada precisa ser baixado:

    docker cp scripts/sonda-superflix.py stretor_flaresolverr:/tmp/
    docker exec stretor_flaresolverr python3 /tmp/sonda-superflix.py

Ao final, imprime o endereço do `master.txt` (a playlist master de verdade,
com extensão falsa) e o que o próprio navegador recebeu ao abri-la. O porquê
de essa URL não servir ao media-service — fora do navegador ela responde 403 —
está em `docs/integracoes.md`.
"""

import json
import re
import sys
import time

sys.path.insert(0, "/app")

import requests  # noqa: E402
import undetected_chromedriver as uc  # noqa: E402
from selenium.webdriver.common.by import By  # noqa: E402
from utils import get_chrome_exe_path, start_xvfb_display  # noqa: E402

BASE = "https://superflixonline.lat"
SLUG, TEMP, EPI = "donas-de-casa-desesperadas", 4, 1

# Fica escutando a pagina: guarda toda resposta de /player/* e o endereco que
# o player manda abrir no iframe.
GANCHO = """
window.__SF = window.__SF || { chamadas: [], video: null };
(function(){
  if (window.__SF.pronto) return;
  window.__SF.pronto = true;
  const abrir = XMLHttpRequest.prototype.open;
  XMLHttpRequest.prototype.open = function(metodo, url){
    this.__sfUrl = String(url);
    this.addEventListener('load', function(){
      try {
        if (this.__sfUrl.indexOf('/player/') >= 0) {
          window.__SF.chamadas.push({ url: this.__sfUrl, status: this.status,
                                      corpo: String(this.responseText).slice(0, 4000) });
        }
      } catch(e){}
    });
    return abrir.apply(this, arguments);
  };
  const tentar = function(){
    if (typeof window.openIframe === 'function' && !window.openIframe.__sf) {
      const original = window.openIframe;
      const novo = function(url){ window.__SF.video = String(url); return original.apply(this, arguments); };
      novo.__sf = true;
      window.openIframe = novo;
    }
  };
  setInterval(tentar, 200);
})();
"""


def passo(rotulo, valor=""):
    print(f"[{rotulo}] {valor}", flush=True)


start_xvfb_display()
options = uc.ChromeOptions()
for argumento in (
    "--no-sandbox",
    "--disable-setuid-sandbox",
    "--disable-dev-shm-usage",
    "--no-zygote",
    "--ignore-certificate-errors",
    "--ignore-ssl-errors",
    "--disable-search-engine-choice-screen",
    "--disable-features=LocalNetworkAccessChecks",
    "--window-size=1280,720",
    "--lang=pt-BR",
):
    options.add_argument(argumento)
options.set_capability("goog:loggingPrefs", {"browser": "ALL", "performance": "ALL"})
driver = uc.Chrome(
    options=options,
    browser_executable_path=get_chrome_exe_path(),
    driver_executable_path="/app/chromedriver",
    headless=False,
    windows_headless=False,
)
driver.set_page_load_timeout(70)
driver.set_script_timeout(180)
agente = driver.execute_script("return navigator.userAgent")
passo("navegador", f"head-full | UA={agente[:60]}")

try:
    sessao = requests.Session()
    sessao.headers.update({"User-Agent": agente, "Accept-Language": "pt-BR,pt;q=0.9"})
    token = sessao.get(f"{BASE}/csrf-token", timeout=45).json()["token"]
    epi = f"{BASE}/serie/{SLUG}/{TEMP}/{EPI}"
    pagina = sessao.get(epi, headers={"Referer": f"{BASE}/"}, timeout=45).text
    handle = re.search(r'data-playbackhandle="([^"]+)"', pagina).group(1)
    inicio = sessao.post(
        f"{BASE}/__siteplay/start",
        json={"handle": handle},
        headers={
            "X-CSRF-TOKEN": token,
            "X-Requested-With": "XMLHttpRequest",
            "Referer": epi,
            "Origin": BASE,
            "Accept": "application/json",
        },
        timeout=45,
    )
    alvo = (inicio.json().get("sources") or [])[0]["url"]
    passo("alvo", alvo)

    # 1) O frame embutido na pagina do .lat — e o unico jeito de receber o player.
    driver.get(f"{BASE}/")
    driver.execute_script(
        """
        const el = document.createElement('iframe');
        el.id = 'sonda';
        el.setAttribute('referrerpolicy', 'unsafe-url');
        el.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture; fullscreen');
        el.style.cssText = 'position:fixed;top:0;left:0;width:1280px;height:720px;border:0';
        el.src = arguments[0];
        document.body.appendChild(el);
        """,
        alvo,
    )

    # 2) Espera o player aparecer dentro do iframe (depois do muro).
    dentro = False
    fonte = ""
    for tentativa in range(34):
        time.sleep(3)
        try:
            if not dentro:
                driver.switch_to.default_content()
                driver.switch_to.frame(driver.find_element(By.ID, "sonda"))
                dentro = True
            fonte = driver.page_source
            titulo = driver.title
        except Exception as excecao:
            dentro = False
            passo("player", f"{tentativa:>2} carregando ({excecao.__class__.__name__})")
            continue
        passo("player", f"{tentativa:>2} size={len(fonte)} titulo={titulo[:44]}")
        if "API_URL_SOURCE" in fonte:
            break

    if "API_URL_SOURCE" not in fonte:
        with open("/tmp/frame-browser.html", "w", encoding="utf-8") as fh:
            fh.write(fonte)
        sys.exit("nao chegou ao player — HTML salvo")

    # 3) Os servidores que o proprio player carregou ficam nesta global.
    driver.execute_script(GANCHO)
    lido = driver.execute_async_script(
        """
        const pronto = arguments[arguments.length - 1];
        let tentativas = 0;
        const olhar = function(){
            const lista = currentOptions;
            if (Array.isArray(lista) && lista.length) {
                const dono = (typeof OWNER_CONTEXT_HOST !== 'undefined') ? OWNER_CONTEXT_HOST : null;
                return pronto({ opcoes: lista.slice(0, 6), host_dono: dono });
            }
            if (++tentativas > 40) return pronto({ opcoes: [], erro: 'sem currentOptions' });
            setTimeout(olhar, 1500);
        };
        olhar();
        """,
    )
    passo("servidores", json.dumps(lido)[:700])

    opcoes = lido.get("opcoes") or []
    if not opcoes:
        sys.exit("sem servidores: nao ha video_id para consultar")
    video_id = opcoes[0].get("ID")

    # 4) A consulta que revela o video, com os cabecalhos da propria pagina.
    resultado = driver.execute_async_script(
        """
        const id = arguments[0];
        const host = arguments[1] || '';
        const pronto = arguments[arguments.length - 1];
        const token = PAGE_TOKEN;
        $.ajax({
            url: API_URL_SOURCE,
            method: 'POST',
            headers: sfEdgeHeaders(token),
            data: { video_id: id, page_token: token, host: host, site: host,
                    _token: CSRF_TOKEN || '' },
            success: function(res){ pronto({ status: 200, resposta: res }); },
            error: function(xhr, texto){
                pronto({ status: xhr.status, erro: String(texto),
                         corpo: String(xhr.responseText || '').slice(0, 600) });
            }
        });
        """,
        video_id,
        lido.get("host_dono"),
    )
    passo("source", json.dumps(resultado)[:900])

    dados = (resultado.get("resposta") or {}).get("data") or {}
    video = dados.get("video_url") or ""
    passo("video_url", video[:320])

    if video:
        # O player abre esse endereco num iframe; aqui repetimos o gesto
        # navegando para ele, para ver onde a cadeia termina.
        driver.execute_script("location.href = arguments[0];", video)
        fonte2 = ""
        for tentativa in range(24):
            time.sleep(3)
            try:
                fonte2 = driver.page_source
                url2 = driver.execute_script("return location.href")
            except Exception as excecao:
                passo("video", f"{tentativa:>2} carregando ({excecao.__class__.__name__})")
                continue
            passo("video", f"{tentativa:>2} size={len(fonte2)} url={str(url2)[-56:]}")
            if "FirePlayer(" in fonte2 or "/player/index.php" in fonte2 or "#EXTM3U" in fonte2:
                break

        with open("/tmp/video-pagina.html", "w", encoding="utf-8") as fh:
            fh.write(fonte2)

        # O player carregou (a pagina anuncia 43:04 de duracao), entao ele
        # mesmo pode dizer qual arquivo esta tocando.
        try:
            tocando = driver.execute_script(
                """
                try {
                    const p = jwplayer();
                    const item = p.getPlaylistItem ? p.getPlaylistItem() : null;
                    return {
                        arquivo: item ? item.file : null,
                        estado: p.getState ? p.getState() : null,
                        config: (p.getConfig && p.getConfig().file) || null,
                        fontes: item && item.sources ? item.sources.map(s => s.file || s.src) : null
                    };
                } catch(e) { return { erro: String(e) }; }
                """
            )
        except Exception as excecao:
            tocando = {"erro": excecao.__class__.__name__}
        passo("jwplayer", json.dumps(tocando)[:800])
        passo("url cheia", str(driver.execute_script("return location.href"))[:400])
        passo("cookies", json.dumps(driver.get_cookies())[:1000])

        # A pergunta que decide a arquitetura: o proprio navegador consegue
        # abrir o manifesto? A pagina do video e o manifesto moram no mesmo
        # host, entao este `fetch` sai sem CORS e com os cookies da sessao.
        manifesto_url = tocando.get("arquivo") or ""
        if manifesto_url:
            leitura = driver.execute_async_script(
                """
                const url = arguments[0];
                const pronto = arguments[arguments.length - 1];
                fetch(url, { credentials: 'include' })
                  .then(r => r.text().then(t => pronto({ status: r.status,
                                                         primeiras: t.slice(0, 500) })))
                  .catch(e => pronto({ erro: String(e) }));
                """,
                manifesto_url,
            )
            passo("manifesto no navegador", json.dumps(leitura)[:900])

        # A prova de que o navegador conseguiu o manifesto: o proprio log de
        # rede mostra o status da resposta do player.
        print("\n=== respostas de manifesto ===", flush=True)
        try:
            for entrada in driver.get_log("performance"):
                mensagem = json.loads(entrada["message"])["message"]
                if mensagem.get("method") != "Network.responseReceived":
                    continue
                resposta = mensagem["params"].get("response", {})
                url = resposta.get("url", "")
                if "master" in url or ".m3u8" in url:
                    print(f"  status={resposta.get('status')} {url[:200]}", flush=True)
        except Exception as excecao:
            passo("log", str(excecao)[:140])

        # O m3u8 nao vem no HTML: a pagina pede por JavaScript. O log de
        # rede do proprio navegador diz qual endereco ela buscou.
        print("\n=== o que a pagina pediu na rede ===", flush=True)
        estaticos = (".css", ".js", ".png", ".jpg", ".jpeg", ".gif", ".svg",
                     ".woff", ".woff2", ".ico", ".webp", "googleapis", "gstatic")
        vistas = []
        try:
            for entrada in driver.get_log("performance"):
                mensagem = json.loads(entrada["message"])["message"]
                if mensagem.get("method") != "Network.requestWillBeSent":
                    continue
                url = mensagem["params"]["request"]["url"]
                if url in vistas or any(pista in url for pista in estaticos):
                    continue
                vistas.append(url)
            for url in vistas[-40:]:
                print(f"  {url[:220]}", flush=True)
        except Exception as excecao:
            passo("rede", f"sem log de performance ({excecao.__class__.__name__})")
        if not vistas:
            print("  (nada)")

        if "#EXTM3U" in fonte2:
            passo("veredito", "a propria resposta ja e a playlist")
            passo("m3u8", fonte2[:400].replace("\n", " | "))
        else:
            achado = re.search(
                r'FirePlayer\(\s*["\']([A-Za-z0-9]{16,})["\']', fonte2
            ) or re.search(r"data=([A-Za-z0-9]{16,})", fonte2)
            if not achado:
                passo("veredito", "a pagina do video nao expos o hash do FirePlayer")
            else:
                hash_video = achado.group(1)
                atual = driver.execute_script("return location.href")
                host = re.match(r"https?://([^/]+)", atual).group(1)
                passo("hash", f"{hash_video} @ {host}")
                resposta2 = driver.execute_async_script(
                    """
                    const url = arguments[0];
                    const pronto = arguments[arguments.length - 1];
                    fetch(url, { method: 'POST', credentials: 'include',
                                 headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                      .then(r => r.text().then(t => pronto({ status: r.status,
                                                             corpo: t.slice(0, 3000) })))
                      .catch(e => pronto({ erro: String(e) }));
                    """,
                    f"//{host}/player/index.php?data={hash_video}&do=getVideo",
                )
                passo("getVideo", json.dumps(resposta2)[:900])
                try:
                    dados2 = json.loads(resposta2.get("corpo", ""))
                    passo("securedLink", str(dados2.get("securedLink"))[:320])
                    if isinstance(dados2.get("file"), list):
                        for faixa in dados2["file"]:
                            passo("faixa", f"{faixa.get('label')} -> {str(faixa.get('file'))[:180]}")
                except Exception:
                    passo("json", "getVideo nao devolveu JSON legivel")

    print("\n=== chamadas vistas na pagina ===", flush=True)
    for chamada in (driver.execute_script("return window.__SF ? window.__SF.chamadas : []") or [])[-4:]:
        print(f"[{chamada['status']}] {chamada['url'][:120]} :: {chamada['corpo'][:260]}", flush=True)

finally:
    try:
        driver.quit()
    except Exception:
        pass
    passo("fim", "navegador fechado")
