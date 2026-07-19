# Análise do problema

## Contexto

Esse projeto nasceu depois de eu fazer um teste técnico pra uma vaga onde a empresa relatou ter "vários problemas de cache" em produção. Isso me fez perceber que, apesar do Redis ser usado em praticamente todo lugar, muita gente (inclusive eu, em algum momento) usa ele meio no "achismo" — sem entender de verdade o que o cache está resolvendo, quais são os riscos de usar mal, e como medir se ele está realmente ajudando.

Este documento existe pra deixar isso explícito **antes** de escrever qualquer linha de código: qual é o problema de performance, por que ele acontece, e como vamos comprovar (com números) que a solução funciona.

## O problema: toda consulta batendo direto no banco

Imagina uma página de detalhe de produto de um e-commerce. Sem cache, o fluxo de cada requisição é:

```
Requisição do usuário
      ↓
Aplicação PHP recebe
      ↓
Consulta direto no MySQL (SELECT ... WHERE id = ?)
      ↓
Devolve o resultado
```

Isso parece inofensivo com **1 usuário**. O problema aparece quando o produto vira popular e centenas (ou milhares) de pessoas pedem o **mesmo dado, ao mesmo tempo**:

- Cada requisição abre uma consulta nova no MySQL, mesmo que o dado não tenha mudado nem um pouco entre uma requisição e outra.
- O banco de dados vira o gargalo: CPU e I/O de disco disputados por consultas repetidas e desnecessárias.
- O tempo de resposta da aplicação cresce junto com o número de usuários simultâneos, porque a fila de conexões/consultas no banco aumenta.
- Em casos extremos, o banco fica sobrecarregado e derruba **todas** as funcionalidades do sistema (não só a página de produto), porque o MySQL é compartilhado por toda a aplicação.

Ou seja: estamos pagando o custo de uma consulta ao banco **por requisição**, quando na prática o dado de um produto muda raramente (às vezes só uma vez por dia, ou por hora).

## Por que Redis resolve (parte) disso

O Redis guarda o resultado em memória (muito mais rápido que disco) e serve esse resultado pronto pras próximas requisições, sem precisar tocar no MySQL de novo — até o dado expirar ou ser invalidado. O padrão que vamos implementar é o **Cache-Aside**:

```
Requisição
   ↓
Busca no Redis
   ↓
Encontrou?
 ┌───────────────┐
 │ Sim           │ Não
 ↓               ↓
Retorna cache    Consulta MySQL
                 ↓
              Salva no Redis (com TTL)
                 ↓
              Retorna dados
```

Isso reduz drasticamente o número de consultas ao banco quando o mesmo dado é pedido várias vezes seguidas.

## O que medimos (benchmark)

Teoria sem número é só opinião. Por isso construímos um script de benchmark (`benchmark/benchmark.php`) que dispara lotes de requisições **concorrentes** (de verdade, em paralelo, via `curl_multi`) contra o endpoint `produto.php`, sorteando entre 5 "produtos populares" — simulando várias pessoas pedindo os mesmos produtos ao mesmo tempo.

### Uma descoberta importante no caminho

Na primeira versão do benchmark, a gente disparava as requisições **uma de cada vez, sequencialmente** — e a diferença entre "com cache" e "sem cache" praticamente não aparecia (às vezes o cache até dava uma média pior!). Por quê? Porque nossa tabela `produtos` tem só 5.000 linhas e a busca é por chave primária indexada — pro MySQL, isso é uma consulta trivial, respondida em menos de 1ms. Testando uma requisição isolada de cada vez, não tem gargalo nenhum pra resolver.

O problema de cache que motivou esse projeto (ver [Contexto](#contexto)) nunca foi "uma consulta isolada é lenta" — é **muitas requisições simultâneas disputando a mesma conexão/lock do banco**. Só quando reescrevemos o benchmark pra disparar lotes de requisições **concorrentes** (várias ao mesmo tempo, competindo entre si) é que o MySQL começou a mostrar degradação de verdade, e o Redis passou a se destacar. É um lembrete valioso: **meça sob a condição real do problema, não sob a condição mais conveniente de medir.**

### Metodologia

1. Um "produto popular" é sorteado (entre 5 ids fixos) a cada requisição.
2. **Cenário "sem cache"**: antes de cada lote, apagamos as chaves do Redis — toda requisição do lote é forçada a ir no MySQL.
3. **Cenário "com cache"**: as chaves não são apagadas — depois dos primeiros acertos, a grande maioria das requisições vem do Redis (Cache-Aside normal).
4. Cada lote dispara N requisições **verdadeiramente simultâneas** (via `curl_multi`), repetido por vários lotes.

### Resultados (ambiente local, Docker Desktop/Windows)

| Concorrência (por lote) | Cenário   | Média (ms) | Mín (ms) | Máx (ms) | p95 (ms) |
|--------------------------|-----------|------------|----------|----------|----------|
| 20 simultâneas           | Sem cache | 69,15      | 33,81    | 188,17   | 133,14   |
| 20 simultâneas           | Com cache | 42,41      | 25,36    | 86,46    | 79,14    |
| 50 simultâneas           | Sem cache | 179,11     | 80,62    | 340,05   | 322,50   |
| 50 simultâneas           | Com cache | 121,27     | 65,14    | 254,51   | 234,07   |

**Com cache, a média ficou entre 1,5x e 1,6x mais rápida** nos dois níveis de concorrência testados — e repare que a diferença ABSOLUTA cresce junto com a concorrência (27ms de diferença com 20 simultâneas, 58ms com 50 simultâneas): o MySQL degrada mais rápido que o Redis à medida que a carga simultânea aumenta. Esse é o comportamento que se agravaria ainda mais numa tabela maior, com consultas mais custosas (joins, agregações) ou com muito mais usuários simultâneos do que testamos aqui — o que reforça por que, em produção, "poucos problemas" de cache mal dimensionado viram incidentes grandes rapidamente.

> Números exatos variam conforme a máquina, mas o padrão (cache ganhando e a vantagem crescendo com a concorrência) é reproduzível — rode você mesmo com `docker compose exec php php benchmark/benchmark.php` e compare.

## Problemas de cache que também vamos abordar (não só o "feliz")

Além do ganho de performance, cache mal feito cria problemas próprios — e é exatamente isso que a empresa do teste técnico estava sofrendo. Vamos tratar, nas fases seguintes do roadmap:

- **Dado desatualizado (invalidação de cache)**: o que acontece se o produto for atualizado no banco e o Redis continuar servindo a versão antiga?
- **Cache de item único vs. cache de listagem**: são estratégias de chave e invalidação bem diferentes, e misturar as duas é uma fonte comum de bug.
- **Cache stampede** (ou "dog-piling"): quando o cache de um dado muito acessado expira, e várias requisições simultâneas caem no MySQL ao mesmo tempo pra reconstruir o mesmo cache — na prática, recriando o problema que o cache deveria resolver, só que de forma concentrada.

Essas três situações são clássicas e frequentemente ignoradas em tutoriais de cache que só mostram o caminho feliz.
