# Fluxo de Branches

O projeto adota uma estratégia simples de três branches para separar o que está
estável do que ainda está em construção:

| Branch        | Papel                                                             |
|---------------|-------------------------------------------------------------------|
| `main`        | Versão estável. Só recebe merge quando o projeto está pronto.     |
| `development` | Linha de trabalho ativa. Toda feature nasce e evolui aqui.        |
| `fix`         | Correções de bugs, mescladas de volta em `development`.           |

O dia a dia acontece na `development`. Ao concluir uma entrega relevante, o
código é mesclado na `main`. Correções pontuais saem da `development` para a
`fix` e retornam por merge, mantendo o histórico coeso.

```bash
# Trabalho diário
git checkout development

# Correção de bug
git checkout -b fix/nome-do-ajuste development
# ... commit ...
git checkout development && git merge fix/nome-do-ajuste

# Entrega estável
git checkout main && git merge development
```

## Commits

Os commits devem ser limpos, descritivos e escritos em PT-BR, refletindo
modificações profundas de forma coesa. Versione sempre que houver uma entrega ou
alteração estrutural relevante.

## Próximos passos

- Como subir o ambiente: [Ambiente](ambiente.md)
- Índice do manual: [Manual](README.md)
