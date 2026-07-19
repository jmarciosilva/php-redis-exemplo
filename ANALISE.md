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

## O que vamos medir (benchmark)

Teoria sem número é só opinião. Por isso, além do código, vamos ter um script de benchmark (`benchmark/benchmark.php`, ver [ROADMAP.md](ROADMAP.md)) que:

1. Dispara **N requisições sequenciais e/ou concorrentes** contra o endpoint de produto.
2. Roda o teste **sem cache** (forçando sempre ir no MySQL).
3. Roda o teste **com cache** (Redis já aquecido).
4. Compara: tempo médio de resposta, tempo total, e (se possível) requisições por segundo.

> ⚠️ Os números abaixo são um placeholder. Assim que o ambiente Docker e o benchmark estiverem prontos, esta seção vai ser substituída pelos resultados reais coletados localmente.

| Cenário       | Tempo médio por requisição | Observações |
|---------------|----------------------------|-------------|
| Sem cache     | _a preencher_               | toda requisição bate no MySQL |
| Com cache     | _a preencher_               | dado servido direto do Redis |

## Problemas de cache que também vamos abordar (não só o "feliz")

Além do ganho de performance, cache mal feito cria problemas próprios — e é exatamente isso que a empresa do teste técnico estava sofrendo. Vamos tratar, nas fases seguintes do roadmap:

- **Dado desatualizado (invalidação de cache)**: o que acontece se o produto for atualizado no banco e o Redis continuar servindo a versão antiga?
- **Cache de item único vs. cache de listagem**: são estratégias de chave e invalidação bem diferentes, e misturar as duas é uma fonte comum de bug.
- **Cache stampede** (ou "dog-piling"): quando o cache de um dado muito acessado expira, e várias requisições simultâneas caem no MySQL ao mesmo tempo pra reconstruir o mesmo cache — na prática, recriando o problema que o cache deveria resolver, só que de forma concentrada.

Essas três situações são clássicas e frequentemente ignoradas em tutoriais de cache que só mostram o caminho feliz.
