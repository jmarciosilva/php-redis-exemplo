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
- [x] Carga de dados de exemplo (10.000 produtos gerados via `database/gerar_seed.php`, volume suficiente pra tornar o benchmark realista)
- [x] `config/database.php` — conexão PDO comentada linha a linha (validado via `public/index.php`, que confirmou os 10.000 produtos importados)

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

## Fase 6 — Benchmark comparativo ✅
- [x] `benchmark/benchmark.php` — script PHP que dispara lotes de requisições **concorrentes** (via `curl_multi`) e mede tempo médio, mínimo, máximo e p95
- [x] Rodar cenário "sem cache" e "com cache"
- [x] Documentar como rodar o benchmark no README
- [x] Extensão `curl` adicionada ao Dockerfile do PHP (necessária pro `curl_multi`, só usado pelo benchmark)

## Fase 7 — Números reais no ANALISE.md ✅
- [x] Rodar o benchmark localmente e coletar os números
- [x] Substituir a tabela placeholder do `ANALISE.md` pelos resultados reais
- [x] Comentar o que os números mostram (e onde o cache ajuda mais ou menos) — inclusive o aprendizado de que requisições sequenciais não revelam o problema; só concorrência real mostra a vantagem do cache
- [x] **Revisado na Fase 12**: os números originais desta fase (1,5x–1,6x) foram medidos com o bug do `pm.max_children = 5` (ver Fase 11) ainda presente — refeitos com o PHP-FPM corrigido, ver a tabela atualizada no `ANALISE.md`

## Fase 8 — Interface visual (views) ✅
- [x] `public/assets/css/estilo.css` — CSS puro (sem framework), com suporte a modo claro/escuro
- [x] Cabeçalho/rodapé reaproveitados entre páginas (`src/views/cabecalho.php`, `src/views/rodape.php`, fora de `public/` por segurança)
- [x] `public/produtos.php` — listagem de produtos em tabela, com paginação e filtro por categoria (ainda sem cache, de propósito — prepara o terreno pra Fase 9)
- [x] `public/performance.php` — dashboard com os números do último benchmark (lidos de `benchmark/ultimo_resultado.json`) e um "testador ao vivo" (id → busca via JS → mostra origem Redis/MySQL e tempo)
- [x] `public/limpar_cache.php` — endpoint de apoio pro testador ao vivo, força um cache miss sob demanda
- [x] `public/index.php` restilizado com o mesmo layout, virando a "página de diagnóstico" do projeto
- [x] `ProdutoRepository` ganhou `listarPaginado()` e `listarCategorias()` (ainda sem cache)
- [x] Contador visual de origem/tempo em `produtos.php` (badge MySQL/Redis + ms), usando `origemDaUltimaListagem()` — já preparado pra virar "Redis" sozinho assim que a Fase 9 adicionar cache, sem precisar mexer no HTML
- [x] Seed aumentado de 5.000 para **10.000 produtos**, mais próximo de uma tabela real de mercado

## Fase 9 — Cache de listagem de produtos ✅
- [x] Decisão de arquitetura: `produtos.php` permanece pra sempre sem cache (baseline) e uma página nova, `produtos_cache.php`, mostra a versão com cache — as duas convivem lado a lado pro dev júnior/recrutador comparar a qualquer momento, sem depender do cache estar "quente" ou "frio"
- [x] `ProdutoRepository::listarPaginadoComCache()` — Cache-Aside pra listagem (Redis → MySQL → grava no Redis)
- [x] Chave de cache considera página + tamanho de página + categoria (`listagem:produtos:pagina:{n}:por_pagina:{n}:categoria:{c|todas}`) — diferente de `produto:{id}`, aqui não existe "um id só"
- [x] TTL de 60s pra listagem (bem menor que os 300s do item único) — comentado o porquê: lista muda por mais motivos (produto novo, produto removido, qualquer preço/estoque na página) e a combinação de chaves é maior
- [x] SQL da consulta extraído pra `buscarListagemNoMysql()` (privado), reaproveitado pelas duas versões (com e sem cache), sem duplicar código
- [x] Testado na prática: 1ª busca de um filtro = `mysql`; mesma busca de novo = `redis`; filtro diferente = `mysql` de novo (chave diferente); `produtos.php` (baseline) continua sempre `mysql` mesmo repetindo — TTL de 60s confirmado via `redis-cli ttl`

## Fase 10 — Invalidação de cache ✅
- [x] `public/editar_produto.php` — "laboratório" que carrega um produto do MySQL, mostra lado a lado o que está no MySQL x o que está no Redis agora, e deixa editar
- [x] Dois caminhos no `ProdutoRepository`, de propósito: `atualizarSemInvalidarCache()` (demonstra o bug) e `atualizarComInvalidacaoDeCache()` (correto) — mesmo botão de UPDATE (`executarAtualizacaoNoMysql()`), reaproveitado
- [x] Explicado (em código e na página) o risco de dado desatualizado (stale): testei o ciclo completo — visita popula cache → edita sem invalidar → `produto.php` ainda mostra o valor velho (origem `redis`) → edita com invalidação → `produto.php` mostra o valor novo na hora (origem `mysql`)
- [x] Cobre também o impacto na listagem: `atualizarComInvalidacaoDeCache()` também apaga TODAS as chaves `listagem:produtos:*` (via `SCAN`, não `KEYS`, pra não bloquear o Redis) — testado: duas listagens cacheadas, ambas some depois de uma única edição
- [x] Discutido por que a invalidação de item único é precisa (uma chave) mas a de listagem é "grosseira" (apaga tudo, já que rastrear quais páginas contêm um produto seria bem mais complexo) — mitigado pelo TTL curto de 60s da Fase 9

## Fase 11 — Cache stampede ✅
- [x] Achado extra antes de começar: `pm.max_children` do PHP-FPM estava em 5 (padrão da imagem oficial) — bem abaixo da concorrência que usamos nos benchmarks (20-50). Aumentado pra 30 no Dockerfile, senão boa parte das "requisições simultâneas" ficaria enfileirada esperando um worker livre, escondendo tanto o stampede quanto os ganhos medidos nos benchmarks anteriores
- [x] `ProdutoRepository::buscarPorIdComProtecaoContraStampede()` — solução por lock (`SET NX EX` no Redis): só um processo consulta o MySQL por vez pra um id sem cache; os demais esperam (polling curto) e reaproveitam o cache recém-populado; se a espera estourar, cai num fallback (consulta o MySQL direto)
- [x] `public/produto_protegido.php` — endpoint gêmeo de `produto.php`, só que usando a versão protegida, pro "antes/depois" ficar comparável lado a lado (mesmo padrão de `produtos.php`/`produtos_cache.php`)
- [x] `benchmark/stampede.php` — script dedicado que dispara uma rajada de N requisições simultâneas pro MESMO produto (cache vazio de propósito) e mede, com um contador real no Redis, quantas vezes o MySQL foi consultado de verdade
- [x] Comparação real, rodada 3x com produtos diferentes: **sem proteção, 3 a 6 consultas redundantes ao MySQL** pra responder a mesma pergunta feita 30 vezes ao mesmo tempo; **com proteção, sempre exatamente 1**

## Fase 12 — Revisão geral ✅
- [x] Revisão de comentários: corrigidas várias referências a "Fase X" que ficaram desatualizadas depois da renumeração (Fase 8 inserida) ou que descreviam algo como "futuro" que já tinha sido implementado (`benchmark.php`, `gerar_seed.php`, `limpar_cache.php`, `produtos.php`, `ProdutoRepository.php`) — nenhum erro de lint, comportamento reconferido depois de cada ajuste
- [x] Docblock principal do `ProdutoRepository` reescrito como um mapa geral da classe (o que existe, o que é baseline vs. otimizado), em vez de descrever só a Fase 5
- [x] README.md revisado: árvore de arquivos realinhada, lista de páginas/endpoints completada (faltavam `produto_protegido.php` e `diagnostico.php`)
- [x] **Achado importante ao revisar o ANALISE.md**: os números do benchmark (Fase 7) tinham sido medidos com o bug do `pm.max_children = 5` (só descoberto na Fase 11) ainda presente. Refizemos os testes com o PHP-FPM corrigido e o resultado mudou: dentro da capacidade do PHP-FPM (≤30 simultâneas), o cache não faz diferença perceptível pra essa consulta indexada numa tabela de 10k linhas — o ganho real (1,1x-1,5x, com variância notável entre execuções) só aparece quando a concorrência ultrapassa a capacidade do servidor e as requisições passam a esperar em fila. `ANALISE.md` reescrito com essa história completa, com transparência sobre a variância observada

## Fase 13 — Publicação
- [x] Decisão: **sem demo hospedada**. A hospedagem compartilhada do Hostinger não roda Docker nem deixa manter um Redis próprio (sem root, sem processo daemon persistente) — resolver isso exigiria um Redis gerenciado externo, e ainda assim o projeto tem páginas que escrevem no banco sem autenticação (`editar_produto.php`, `limpar_cache.php`), de propósito didático, mas arriscadas num deploy público de verdade. Optamos por indicar o link do repositório no GitHub, pra rodar localmente via Docker (já é o que o README ensina)
- [ ] Escrever o post do blog usando este repositório como base
- [ ] Atualizar o README.md com o link do post quando publicar
