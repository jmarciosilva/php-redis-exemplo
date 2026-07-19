# Roadmap do projeto

Este arquivo mostra todas as fases planejadas pro `php-redis-exemplo`. Conforme cada fase for concluída, marco o checkbox aqui — assim dá pra acompanhar o progresso do post sem precisar ler o histórico do git inteiro.

## Fase 1 — Ambiente Docker ✅
- [x] `docker-compose.yml` com 4 serviços: `nginx`, `php` (PHP-FPM), `mysql`, `redis`
- [x] Dockerfile do PHP com as extensões necessárias (`pdo_mysql`, `redis`)
- [x] Config do Nginx apontando pra pasta `public/`
- [x] Variáveis de ambiente (`.env` ou similar) pra credenciais do banco/redis
- [x] Testar `docker-compose up` de ponta a ponta numa máquina limpa (via `public/index.php`, checagem temporária: extensões, conexão MySQL e conexão Redis com SET/GET real — todos passando)

## Fase 2 — Base de dados ✅
- [x] `database/produtos.sql` com criação da tabela `produtos`
- [x] Carga de dados de exemplo (5.000 produtos gerados via `database/gerar_seed.php`, volume suficiente pra tornar o benchmark realista)
- [x] `config/database.php` — conexão PDO comentada linha a linha (validado via `public/index.php`, que confirmou os 5.000 produtos importados)

## Fase 3 — Conexão com Redis ✅
- [x] `config/redis.php` — conexão com extensão `phpredis`, comentada linha a linha
- [x] Teste manual simples de `SET`/`GET` pra validar que a conexão funciona (via `redis-cli` direto no container, e também via `public/index.php` usando `config/redis.php`)

## Fase 4 — Consulta direto no banco (baseline, sem cache) ✅
- [x] `src/ProdutoRepository.php` com método que busca produto só no MySQL
- [x] `public/produto.php` funcionando end-to-end sem nenhum cache ainda (testado com id válido, id inexistente e id inválido)
- [x] Esse é o "estado ruim" que vamos comparar depois no benchmark (endpoint já devolve `tempo_resposta_ms` pra facilitar essa comparação)
- [x] Bug extra corrigido: dados do seed vinham com encoding duplicado (mojibake) porque o cliente MySQL usado na importação automática usa `latin1` por padrão — resolvido adicionando `SET NAMES utf8mb4;` no topo do `produtos.sql` gerado

## Fase 5 — Cache-Aside básico (item único) ✅
- [x] Buscar produto no Redis antes de ir no MySQL
- [x] Se não encontrar, consultar MySQL e salvar no Redis com TTL
- [x] Definir estratégia de chave (`produto:{id}`)
- [x] Comentários explicando cada decisão (por que esse TTL, por que essa chave, etc.)
- [x] Testado na prática: 1ª chamada (miss) ~24,6ms via MySQL, 2ª chamada (hit) ~2,6ms via Redis — quase 10x mais rápido — com TTL de 300s confirmado via `redis-cli ttl`

## Fase 6 — Benchmark comparativo
- [ ] `benchmark/benchmark.php` — script PHP que dispara N requisições e mede tempo médio
- [ ] Rodar cenário "sem cache" e "com cache"
- [ ] Documentar como rodar o benchmark no README

## Fase 7 — Números reais no ANALISE.md
- [ ] Rodar o benchmark localmente e coletar os números
- [ ] Substituir a tabela placeholder do `ANALISE.md` pelos resultados reais
- [ ] Comentar o que os números mostram (e onde o cache ajuda mais ou menos)

## Fase 8 — Cache de listagem de produtos
- [ ] Endpoint (ou método) que lista vários produtos (ex.: por categoria)
- [ ] Discutir por que cachear uma lista é diferente de cachear um item único (chave, tamanho do dado, TTL menor, paginação)
- [ ] Implementar o cache da listagem

## Fase 9 — Invalidação de cache
- [ ] Endpoint (ou script) que simula atualização de um produto no MySQL
- [ ] Ao atualizar, invalidar (ou atualizar) a chave correspondente no Redis
- [ ] Explicar o risco de dado desatualizado (stale) se isso não for feito
- [ ] Cobrir também o impacto na listagem (invalidar item único não basta se existe cache de lista)

## Fase 10 — Cache stampede
- [ ] Demonstrar o problema: TTL expira e várias requisições simultâneas caem no MySQL ao mesmo tempo
- [ ] Implementar uma solução simples (ex.: lock/mutex no Redis, ou jitter no TTL)
- [ ] Comparar comportamento antes/depois da correção (se possível, com o benchmark)

## Fase 11 — Revisão geral
- [ ] Revisar todos os comentários do código (clareza, tom, consistência em PT-BR)
- [ ] Revisar README.md com instruções finais de como rodar tudo
- [ ] Conferir se ANALISE.md reflete o projeto final (não só o rascunho inicial)

## Fase 12 — Publicação
- [ ] Escrever o post do blog usando este repositório como base
- [ ] (Opcional) Subir uma demo hospedada
- [ ] Atualizar o README.md com o link do post e/ou da demo
