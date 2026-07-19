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

### Duas descobertas importantes no caminho

**1. Sequencial esconde o problema.** Na primeira versão do benchmark, a gente disparava as requisições **uma de cada vez** — e a diferença entre "com cache" e "sem cache" praticamente não aparecia. Por quê? Porque nossa tabela `produtos` tem 10.000 linhas e a busca é por chave primária indexada — pro MySQL, isso é uma consulta trivial, respondida em menos de 1ms. Testando uma requisição isolada de cada vez, não tem gargalo nenhum pra resolver. O problema de cache que motivou esse projeto (ver [Contexto](#contexto)) nunca foi "uma consulta isolada é lenta" — é **muitas requisições simultâneas disputando a mesma conexão/lock do banco**. Por isso o benchmark dispara lotes de requisições **verdadeiramente concorrentes** (via `curl_multi`), não uma de cada vez.

**2. O PHP-FPM também escondia o problema (na direção oposta).** Depois de implementar o benchmark concorrente, os primeiros resultados pareciam ótimos (cache 1,5x-1,6x mais rápido). Só na Fase 11, ao investigar cache stampede, descobrimos que o pool do PHP-FPM só tinha `pm.max_children = 5` — bem abaixo dos 20-50 "simultâneos" que a gente achava estar testando. Ou seja: boa parte das requisições estava **enfileirada** esperando um processo do PHP-FPM ficar livre, não rodando em paralelo de verdade. Corrigimos isso (`docker/php/Dockerfile`, `pm.max_children = 30`) e refizemos os testes — e o resultado mudou bastante (ver abaixo).

Isso é uma lição tão importante quanto o padrão Cache-Aside em si: **um limite de infraestrutura escondido pode inflar OU esconder o benefício de uma otimização** — sempre vale desconfiar de um número bom (ou ruim) demais até entender de onde ele vem.

### Metodologia

1. Um "produto popular" é sorteado (entre 5 ids fixos) a cada requisição.
2. **Cenário "sem cache"**: antes de cada lote, apagamos as chaves do Redis — toda requisição do lote é forçada a ir no MySQL.
3. **Cenário "com cache"**: as chaves não são apagadas — depois dos primeiros acertos, a grande maioria das requisições vem do Redis (Cache-Aside normal).
4. Cada lote dispara N requisições **verdadeiramente simultâneas** (via `curl_multi`), repetido por vários lotes.

### Resultados (com o PHP-FPM já corrigido, 30 processos)

| Concorrência (por lote) | Relação com a capacidade do PHP-FPM (30 processos) | Ganho médio do cache (várias execuções) |
|---|---|---|
| 20 simultâneas | abaixo da capacidade — roda tudo em paralelo de verdade | ~1,0x (sem ganho perceptível) |
| 30 simultâneas | bem na capacidade máxima | ~0,9x–1,0x (instável, dentro do ruído) |
| 80 simultâneas | acima da capacidade — parte fica na fila do PHP-FPM | ~1,1x–1,5x (3 execuções: 1,1x / 1,2x / 1,5x) |
| 150 simultâneas | bem acima da capacidade | ~1,3x |

**O resultado mudou de figura em relação à versão anterior deste documento.** Com o PHP-FPM tendo processos suficientes pra atender a concorrência testada, uma consulta indexada por chave primária numa tabela de 10 mil linhas é simplesmente **barata demais** pro MySQL — rodando dentro da capacidade do servidor (20-30 simultâneas), o cache não faz diferença perceptível. O ganho real só aparece quando a concorrência **ultrapassa a capacidade** do PHP-FPM (80+ simultâneas): aí as requisições começam a esperar na fila, e como cada requisição "sem cache" ocupa um processo por mais tempo (a consulta ao MySQL demora mais que uma leitura do Redis), essa fila anda mais devagar sem cache — o efeito de fila **amplifica** uma diferença de custo por requisição que, sozinha, seria pequena demais pra notar.

Também vale registrar, com transparência: os números variaram bastante entre execuções no mesmo nível de concorrência (rodamos 80 simultâneas três vezes e vimos 1,1x, 1,2x e 1,5x) — Docker Desktop no Windows tem uma camada de virtualização de rede que introduz ruído perceptível nesse tipo de medição. Isso reforça por que rodamos várias vezes em vez de confiar num único número, e por que qualquer benchmark de performance merece ceticismo até ser reproduzido.

> Números exatos variam conforme a máquina e a carga do sistema no momento — rode você mesmo com `docker compose exec php php benchmark/benchmark.php <lotes> <concorrência>` e compare, de preferência mais de uma vez.

## Problemas de cache que também abordamos (não só o "feliz")

Além do ganho de performance, cache mal feito cria problemas próprios — e é exatamente isso que a empresa do teste técnico estava sofrendo.

- **Dado desatualizado (invalidação de cache)**: o que acontece se o produto for atualizado no banco e o Redis continuar servindo a versão antiga? Demonstrado (e corrigido) na Fase 10 — ver `public/editar_produto.php`.
- **Cache de item único vs. cache de listagem**: são estratégias de chave e invalidação bem diferentes, e misturar as duas é uma fonte comum de bug. Tratado na Fase 9 (`listarPaginadoComCache()`) e na invalidação em cascata da Fase 10.
- **Cache stampede** (ou "dog-piling"): quando o cache de um dado muito acessado expira, e várias requisições simultâneas caem no MySQL ao mesmo tempo pra reconstruir o mesmo cache — na prática, recriando o problema que o cache deveria resolver, só que de forma concentrada. Tratado na Fase 11 — ver a seção abaixo.

Essas três situações são clássicas e frequentemente ignoradas em tutoriais de cache que só mostram o caminho feliz.

## Cache stampede: medindo o problema e a correção

### O achado antes mesmo de medir

Foi justamente montando esse teste de stampede que descobrimos o problema do `pm.max_children = 5` do PHP-FPM, contado com mais detalhe na seção de benchmark acima — sem corrigir isso pra 30 processos simultâneos, a demonstração abaixo seria bem mais fraca (ou até invisível), porque a maior parte da "rajada simultânea" ficaria enfileirada esperando um processo livre, em vez de bater no MySQL ao mesmo tempo de verdade.

### A solução: lock no Redis

`ProdutoRepository::buscarPorIdComProtecaoContraStampede()` usa um lock (trava) via `SET produto:{id} ... NX EX`: só o primeiro processo a chegar com o cache vazio consegue "trancar a porta" e ir no MySQL; todos os demais esperam um pouco (polling curto, até ~1s) e reaproveitam o cache que esse primeiro processo populou, em vez de irem no MySQL também. Se a espera estourar (algo deu muito errado), existe um fallback que consulta o MySQL direto — melhor responder devagar do que não responder.

### Metodologia e resultados

`benchmark/stampede.php` garante que o cache de um produto está vazio (apagando a chave de propósito, simulando o instante em que o TTL expira) e dispara 30 requisições **verdadeiramente simultâneas** pro mesmo id, contra `produto.php` (sem proteção) e depois contra `produto_protegido.php` (com proteção) — contando, com um contador de verdade no Redis, quantas vezes o MySQL foi consultado em cada cenário.

| Produto testado | Sem proteção (consultas ao MySQL) | Com proteção (consultas ao MySQL) |
|---|---|---|
| id=1 | 5 | 1 |
| id=2 | 6 | 1 |
| id=3 | 3 | 1 |

Sem proteção, entre 3 e 6 requisições diferentes bateram no MySQL pra responder exatamente a mesma pergunta, feita 30 vezes ao mesmo tempo — o número exato varia a cada execução (depende de quão simultâneas as 30 requisições realmente conseguem chegar), mas nunca foi 1. Com proteção, foi **sempre exatamente 1**, em todas as execuções testadas. Numa tabela maior, numa consulta mais cara, ou com centenas de requisições simultâneas em vez de 30, essa multiplicação sem proteção cresceria proporcionalmente — é exatamente esse tipo de amplificação que transforma "um produto ficou popular" em "o banco caiu".
