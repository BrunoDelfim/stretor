# Comandos

Referência dos comandos do dia a dia. Quase tudo acontece por dentro dos
containers: o [`Makefile`](../Makefile:1) da raiz encapsula as operações mais
comuns do Docker, e o `artisan` é chamado via `docker compose exec`.

## Como disparar

- Rode sempre da **raiz do projeto** (onde está o `docker-compose.yml`).
- Os containers precisam estar de pé (`make up` / `make up-fast`).
- `docker compose exec backend php artisan ...` entra no serviço `backend` e roda
  o comando do Laravel.
- Em script ou CI (sem terminal interativo), acrescente `-T`:
  `docker compose exec -T backend php artisan ...`. Sem ele, o `exec` tenta
  alocar um TTY e falha onde não há um.

## Makefile

| Alvo | O que faz |
|------|-----------|
| `make up` | Sobe tudo com rebuild (`docker compose up -d --build`). |
| `make up-fast` | Sobe sem rebuild e sem reexecutar o init, usando a sentinela `.bootstrap-done`. Se a sentinela não existir, cai para `make up`. |
| `make down` | Para e remove os containers. |
| `make restart` | Reinicia os containers (não recria — ver `aplicar`). |
| `make build` | Apenas constrói as imagens. |
| `make ps` | Lista o estado dos containers. |
| `make logs` | Acompanha os logs de todos os serviços. |
| `make aplicar` | Recria só o `backend` e limpa os caches de config e de aplicação. É o que faz uma variável nova do `.env`/`docker-compose.yml` valer — `restart` não basta, porque env só entra no container na **criação** dele. |
| `make aplicar-frontend` | Recria só o `frontend`. Depois de mexer em qualquer `VITE_*` no `.env` (o HMR inclusive), é isto que faz o novo valor valer. |
| `make cache-clear` | Limpa o cache do Laravel (`php artisan cache:clear`). As listas de fontes ficam em cache por 30 min, então rode isto depois de mexer na busca do Torznab — senão você testa com a resposta antiga. |
| `make prowlarr` | Reexecuta o provisionamento do Prowlarr: descobre a chave da API e cadastra os indexadores da lista `indexadores` de `config/services.php` (padrão: `1337x`, `thepiratebay` e `torrentgalaxy`). Útil depois de `make fresh` ou ao adicionar uma definição nova. O comando só soma indexador — não remove nem recria o que foi excluído no painel. |
| `make reset-init` | Remove a sentinela, forçando o bootstrap na próxima subida. |
| `make fresh` | Remove a sentinela, apaga os volumes e sobe tudo do zero. |

> Use `make up-fast` no dia a dia quando nada mudou desde a última subida
> bem-sucedida — é bem mais rápido que `make up`.

## Docker Compose

```bash
# Ver logs de todos os serviços, ou de um só
docker compose logs -f
docker compose logs -f backend

# Estado dos containers
docker compose ps

# Parar tudo
docker compose down

# Parar e remover volumes (APAGA os dados: banco, cache e config do Prowlarr)
docker compose down -v

# Abrir um shell dentro de um container
docker compose exec backend sh
docker compose exec postgres psql -U stretor -d stretor

# Rodar um comando pontual em qualquer serviço
docker compose exec frontend sh -c 'npm run build'
```

## Backend (Laravel / Artisan)

Todo comando abaixo leva o prefixo `docker compose exec backend` antes de
`php artisan`.

### Migrations e banco

```bash
# Aplicar migrations pendentes (no dia a dia é automático na subida)
docker compose exec backend php artisan migrate

# Ver o estado de cada migration
docker compose exec backend php artisan migrate:status

# Popular o banco com dados de exemplo
docker compose exec backend php artisan db:seed

# Recriar o schema do zero (DESTRUTIVO) e, opcionalmente, semear
docker compose exec backend php artisan migrate:fresh
docker compose exec backend php artisan migrate:fresh --seed
```

> As migrations rodam sozinhas no entrypoint do backend. Os comandos manuais
> servem para diagnóstico e para recriar dados em desenvolvimento.

### Cache e configuração

```bash
# Limpar o cache da aplicação (inclui as listas de fontes, cacheadas por 30 min)
docker compose exec backend php artisan cache:clear

# Limpar o cache de configuração (rode após mexer em .env/config/services.php)
docker compose exec backend php artisan config:clear

# Inspecionar o valor efetivo de uma config
docker compose exec backend php artisan config:show services.verificacao
```

### Console interativo (tinker)

```bash
docker compose exec backend php artisan tinker
# Dentro do tinker, por exemplo:
# >>> App\Models\Disponibilidade::where('tem_fonte', true)->count();
```

### Testes

```bash
# Suíte inteira
docker compose exec backend php artisan test

# Um arquivo específico
docker compose exec backend php artisan test tests/Feature/VerificacaoFontesTest.php

# Filtrar por nome de teste/classe
docker compose exec backend php artisan test --filter=VerificacaoFontes
```

> Os testes isolam o banco: o `VerificacaoFontesTest` cria e usa o banco
> `stretor_testing` (via `RefreshDatabase`), então o banco de desenvolvimento
> **nunca** é tocado. O banco de teste é criado sozinho na primeira execução,
> sem passo manual.

### Prowlarr

```bash
# Reprovisionar: descobre a chave da API e cadastra os indexadores (idempotente)
docker compose exec backend php artisan prowlarr:provisionar

# Apenas descobrir a chave, sem mexer nos indexadores
docker compose exec backend php artisan prowlarr:provisionar --apenas-chave
```

### Verificação de fontes (gate da Home e da busca)

A Home e a busca só mostram um título depois de **provar** que existe fonte para
ele. Como provar custa requisições aos trackers, a rotina roda em **modo furtivo**
(uma requisição por vez, com pausa entre itens e entre páginas).

A rodada diária é responsabilidade do container `scheduler`, que sobe junto com o
stack e roda `php artisan schedule:work` — sem cron na máquina hospedeira. Ele
dispara `fontes:verificar` todo dia no horário de `VERIFICACAO_HORARIO` (padrão
`04:00`), desde que `VERIFICACAO_AGENDADA` esteja ligada (padrão: ligada). Para
desligar a rotina automática e só rodar sob comando, basta `VERIFICACAO_AGENDADA=false`.

> A prova de cada página **vence** em `VERIFICACAO_VALIDADE_HORAS` (padrão 24).
> Vencida, a Home volta a servir o TMDB do dia até a rodada seguinte regravar a
> lista. É o que impede a Home de congelar quando uma execução falha ou o
> agendador fica parado — foi exatamente esse o sintoma quando a rotina diária
> não estava no ar: as páginas seguiam `ok` com a lista de dias antes.

#### `fontes:limpar` — apaga os links comprovados

A busca guarda a fonte que reproduziu inteira e a coloca na frente da fila na
próxima abertura do mesmo título. É o que faz a segunda sessão começar sem
tentativa-e-erro — e o que faz um magnet antigo continuar aparecendo primeiro
mesmo depois de a busca aprender a achar um link direto melhor. O histórico não
expira; só tem teto por título.

```bash
# Histórico inteiro (todos os títulos e episódios)
docker compose exec backend php artisan fontes:limpar

# Só um título
docker compose exec backend php artisan fontes:limpar --tmdb=969681

# Só um episódio
docker compose exec backend php artisan fontes:limpar --tmdb=1399 --temporada=2 --episodio=5
```

| Opção | Para que serve |
|-------|----------------|
| `--tmdb=ID` | Limita ao título |
| `--tipo=filme\|serie` | Limita ao tipo (sem isso, os dois) |
| `--temporada=N` | Temporada do episódio (exige `--tmdb` e `--episodio`) |
| `--episodio=N` | Episódio (exige `--tmdb` e `--temporada`) |

Não se perde nada: a próxima busca redescobre as fontes e o que reproduzir volta a
ser gravado. O cache de **resposta dos provedores** (30 min, Redis) é outro: para
ele, `TORRENTS_CACHE_BYPASS=true` ou `make cache-clear`.

#### `fontes:status` — só leitura, não toca em tracker

Diz quantas páginas estão prontas, quanto já foi indexado e como terminou a
última rodada. É o companheiro de leitura do `fontes:verificar` — use antes e
depois de uma carga.

```bash
docker compose exec backend php artisan fontes:status
```

Além dos totais, ele responde às perguntas que explicam uma Home parada:

| Linha | O que significa |
|-------|-----------------|
| `das quais com prova vencida` | Páginas `ok` além de `VERIFICACAO_VALIDADE_HORAS`. Nelas a Home está servindo o TMDB do dia — a lista não está congelada, mas também não passou pelo portão. |
| `das quais travadas` | Páginas em `em andamento` há mais que a validade: resíduo de uma execução interrompida. O próximo `fontes:verificar` as devolve a `pendente`. |
| `Última página verificada` | Data da verificação mais recente. Se está velha, a rotina diária não está rodando. |
| `Rotina automática LIGADA/DESLIGADA` | Reflete `VERIFICACAO_AGENDADA` e mostra o horário do agendador. |

#### `fontes:verificar` — a carga de verdade

```bash
# Verificação COMPLETA: todas as páginas de VERIFICACAO_PAGINAS (padrão 25)
docker compose exec backend php artisan fontes:verificar
```

Sem nenhuma opção, ele varre o catálogo inteiro (páginas 1..`VERIFICACAO_PAGINAS`),
bate nos trackers nos títulos ainda sem prova e fecha com o resumo da rodada.

| Opção | Para que serve |
|-------|----------------|
| `--paginas=N` | Limita a rodada às N primeiras páginas. `--paginas=1` é o teste rápido do dia a dia. |
| `--limite=N` | Teto de títulos testados nesta rodada, para tirar só uma amostra. |
| `--sem-pausa` | Desliga as pausas de furtividade. **Só em teste** — contra trackers reais, o ritmo acelerado pode derrubar o teu acesso. |

```bash
# Teste rápido: só a primeira página
docker compose exec backend php artisan fontes:verificar --paginas=1

# Amostra: as 3 primeiras páginas, no máximo 20 títulos testados
docker compose exec backend php artisan fontes:verificar --paginas=3 --limite=20
```

> **Deixe terminar.** A lista de cada página é regravada **no fim da página**. Se
> você interromper (`Ctrl-C`) no meio, uma fonte provada naquele trecho só aparece
> na Home na próxima rodada daquela página. Como há 45s de pausa **entre páginas**
> mesmo quando não há nada a testar, uma rodada completa leva de ~20 a ~70 min —
> rode em primeiro plano e aguarde.

> **Idempotente e parcimonioso.** Título que já tem fonte não é re-testado. Quem
> volta à fila é quem ainda não tem veredito, e o prazo depende do que aconteceu
> na última tentativa: a busca que **não pôde concluir** (inconclusivo) volta na
> virada do dia (`VERIFICACAO_VALIDADE_HORAS`, padrão 24h), e o veredito
> **definitivo** — perguntou-se a todos os provedores e não havia nada — só é
> reexaminado depois da janela longa (`VERIFICACAO_VALIDADE_DIAS_DEFINITIVO`,
> padrão 7 dias), porque o acervo não muda de um dia para o outro.

Parâmetros lidos de `config/services.php` (todos com override em `.env`):

| Chave `.env` | Padrão | O que controla |
|--------------|--------|----------------|
| `VERIFICACAO_PAGINAS` | 25 | Quantas páginas do `/trending/all/day` verificar. |
| `VERIFICACAO_VALIDADE_HORAS` | 24 | Validade da prova de quem pediu nova chance (busca inconclusiva): vencida, o título volta à fila na virada do dia. |
| `VERIFICACAO_VALIDADE_DIAS_DEFINITIVO` | 7 | Janela longa do veredito definitivo, em dias: um "sem fonte" que perguntou a todos só volta a ser testado depois disso. |
| `VERIFICACAO_ORCAMENTO_TITULO` | 30 | Tempo (s) que uma busca de fonte pode gastar. Curto demais faz o corte no meio e a série nasce "sem fonte". |
| `VERIFICACAO_PAUSA_ITENS_MS` | 6000 | Pausa entre um título e o próximo (base, recebe jitter). |
| `VERIFICACAO_PAUSA_PAGINAS_MS` | 45000 | Pausa entre uma página e a seguinte. |

## Frontend (Vite / npm)

```bash
# Instalar dependências (normalmente automático no entrypoint)
docker compose exec frontend npm install

# Build de produção (gera o bundle em dist/)
docker compose exec frontend npm run build

# Servidor de desenvolvimento (o Vite já sobe sozinho dentro do container)
docker compose exec frontend npm run dev

# Pré-visualizar o build de produção
docker compose exec frontend npm run preview
```

> Depois de mudar qualquer variável `VITE_*` no `.env`, rode
> `make aplicar-frontend` para recriar o container — o Vite lê essas variáveis no
> start, não a cada requisição.

## Media Service (Node)

```bash
# Acompanhar os logs do serviço de mídia
docker compose logs -f media-service

# Rodar os testes do serviço
docker compose exec media-service npm test

# Com o serviço no ar os testes precisam de porta própria: `sessoes.js` cria o
# cliente WebTorrent já na importação, e o serviço em execução ocupa a
# `MEDIA_TORRENT_PORT` (51413) — sem isolar, o arquivo morre com `EADDRINUSE`.
# O cliente também mantém o event loop vivo, daí o `--test-force-exit`.
docker compose exec media-service sh -lc \
  'MEDIA_TORRENT_PORT=51414 node --test --test-force-exit --test-concurrency=1 test/'

# Subir em modo watch durante o desenvolvimento
docker compose exec media-service npm run dev
```

## Receitas rápidas

```bash
# Ambiente do zero (apaga volumes)
make fresh

# Subir no dia a dia quando nada mudou
make up-fast

# Mudou o .env e quer que valha
make aplicar            # backend
make aplicar-frontend   # frontend

# Mexeu na busca de fontes: limpar o cache (listas ficam 30 min)
make cache-clear

# Diagnóstico da verificação, sem tocar em tracker
docker compose exec backend php artisan fontes:status

# Carga completa de verificação (deixe terminar)
docker compose exec backend php artisan fontes:verificar
```

## Próximos passos

- Como subir o ambiente do zero: [Ambiente](ambiente.md)
- Endpoints disponíveis: [API](api.md)
- Como a verificação de fontes nasceu: [Plano de verificação](../plans/verificacao-de-fontes.md)
