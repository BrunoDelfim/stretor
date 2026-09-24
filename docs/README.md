# Manual do Stretor

Este é o manual vivo do projeto. Aqui ficam as particularidades, decisões de
arquitetura e detalhes operacionais que não cabem no [`README.md`](../README.md:1)
— que deve continuar sendo apenas a porta de entrada.

## Como este manual se organiza

Cada página cobre um assunto e é autocontida. Os links abaixo são o mapa de
navegação:

| Página | O que você encontra |
|--------|---------------------|
| [Arquitetura](arquitetura.md) | Componentes, portas, fluxo entre serviços e estrutura de pastas. |
| [Ambiente](ambiente.md) | Variáveis de `.env`, bootstrap automático e como subir tudo. |
| [Comandos](comandos.md) | Alvos do `Makefile`, `docker compose`, `artisan` e `npm`. |
| [API](api.md) | Endpoints do backend e do media-service, paginação e cache. |
| [Frontend](frontend.md) | Organização Vue, rolagem infinita, skeletons e componentes. |
| [Integrações](integracoes.md) | TMDB, cache Redis, torrents/legendas e Real-Debrid. |
| [Fluxo de Branches](fluxo-branches.md) | Estratégia de branches e commits. |

## Manual x planos

Há duas pastas com propósitos diferentes — vale não confundir:

- **`docs/`** (este manual): documentação **viva**. Descreve como o sistema
  funciona **hoje**. É atualizada sempre que o comportamento muda.
- **[`plans/`](../plans/):** registro **histórico**. Guarda o "porquê" das
  decisões de cada entrega — o diagnóstico, as alternativas descartadas e os
  critérios de aceite do momento. Não é reescrito depois; é o diário de bordo.

Quando precisar entender *como usar*, leia `docs/`. Quando precisar entender
*por que ficou assim*, leia `plans/`.
