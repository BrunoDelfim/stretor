# Comandos

Referência dos comandos do dia a dia. O [`Makefile`](../Makefile:1) na raiz
encapsula as operações mais comuns do Docker.

## Makefile

| Alvo | O que faz |
|------|-----------|
| `make up` | Sobe tudo com rebuild (`docker compose up -d --build`). |
| `make up-fast` | Sobe sem rebuild e sem reexecutar o init, usando a sentinela `.bootstrap-done`. Se a sentinela não existir, cai para `make up`. |
| `make down` | Para e remove os containers. |
| `make restart` | Reinicia os containers. |
| `make logs` | Acompanha os logs de todos os serviços. |
| `make ps` | Lista o estado dos containers. |
| `make build` | Apenas constrói as imagens. |
| `make cache-clear` | Limpa o cache do Laravel (`php artisan cache:clear`). As listas de fontes ficam em cache por 30 min, então rode isto depois de mexer na busca do Torznab — senão você testa com a resposta antiga. |
| `make prowlarr` | Reexecuta o provisionamento do Prowlarr: descobre a chave da API e cadastra os indexadores públicos PT-BR. Útil depois de `make fresh` ou ao adicionar uma definição nova. |
| `make fresh` | Remove a sentinela, apaga os volumes e sobe tudo do zero. |
| `make reset-init` | Remove a sentinela, forçando o bootstrap na próxima subida. |

> Use `make up-fast` no dia a dia quando nada mudou desde a última subida
> bem-sucedida — é bem mais rápido que `make up`.

## Docker Compose

```bash
# Ver logs de um serviço específico
docker compose logs -f backend

# Parar tudo
docker compose down

# Parar e remover volumes (apaga dados)
docker compose down -v
```

## Backend (Laravel / Artisan)

```bash
# Rodar migrations manualmente (normalmente desnecessário: é automático)
docker compose exec backend php artisan migrate

# Rodar queue worker
docker compose exec backend php artisan queue:work

# Reprovisionar o Prowlarr (descobre a chave da API e cadastra os indexadores PT-BR)
docker compose exec backend php artisan prowlarr:provisionar
```

> As migrations rodam sozinhas na subida do backend. O comando manual existe
> apenas para casos pontuais de diagnóstico.

## Frontend (Vite / npm)

```bash
# Instalar dependências (normalmente automático no entrypoint)
docker compose exec frontend npm install

# Build de produção
docker compose exec frontend npm run build
```

## Media Service (Node)

```bash
# Ver logs do serviço de mídia
docker compose logs -f media-service
```

## Próximos passos

- Como subir o ambiente do zero: [Ambiente](ambiente.md)
- Endpoints disponíveis: [API](api.md)
