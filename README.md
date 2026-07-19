# php-redis-exemplo

Projeto de estudo (e material de post do blog) mostrando, na prática e sem "mágica" de framework, como usar o **Redis** para cachear consultas de um app **PHP puro** e melhorar o desempenho.

A ideia surgiu depois de um teste de emprego onde percebi que muita gente usa cache no dia a dia sem realmente entender o que está acontecendo por baixo dos panos — o Laravel (e outros frameworks) resolve tudo com uma linha de código e esconde a parte interessante. Aqui a gente vai construir isso na mão, passo a passo, comentando cada linha em português pra quem tá começando conseguir acompanhar.

> 📖 Post completo explicando tudo: *(link do post vai entrar aqui quando publicar)*
> 🌐 Demo hospedada: *(link do deploy vai entrar aqui, se/quando eu subir)*

## O que esse projeto ensina

- Como o padrão **Cache-Aside** funciona na prática (o mais comum e mais fácil de explicar numa entrevista).
- Como conectar no Redis e no MySQL usando só extensões nativas do PHP (sem ORM, sem framework).
- Como decidir chave de cache, TTL (tempo de expiração) e o que cachear.
- Problemas reais de cache que empresas erram: dado desatualizado (invalidação), cache de lista vs. item único, e o famoso **cache stampede**.
- Como medir, com números de verdade, o quanto o cache melhora (ou não) o desempenho — nada de "confia, é mais rápido".

Veja o [ANALISE.md](ANALISE.md) para entender o problema de performance que estamos resolvendo, e o [ROADMAP.md](ROADMAP.md) para acompanhar as fases de desenvolvimento (vou marcando ✅ conforme avança).

## Stack

- **PHP** (puro, sem framework) rodando via **PHP-FPM**
- **Nginx** como servidor web na frente do PHP-FPM
- **MySQL** como banco de dados "de verdade" (a fonte da verdade dos produtos)
- **Redis** como camada de cache
- **Docker Compose** pra subir tudo isso com um comando só

> Por que Nginx + PHP-FPM e não o servidor embutido do PHP (`php -S`)? Porque o servidor embutido atende **uma requisição por vez** (single-thread), e isso destrói qualquer benchmark de concorrência — os testes de "com cache vs. sem cache" não fariam sentido. Com PHP-FPM (múltiplos workers) o benchmark reflete uma situação bem mais próxima da realidade.

## Estrutura do projeto

```
php-redis-exemplo/
├── docker/
│   ├── php/
│   │   └── Dockerfile     # imagem do PHP-FPM com as extensões pdo_mysql e redis
│   └── nginx/
│       └── default.conf   # config do Nginx apontando pra pasta public/
├── config/
│   ├── database.php       # configuração de conexão com o MySQL
│   └── redis.php          # configuração de conexão com o Redis
├── src/
│   └── ProdutoRepository.php  # onde mora a lógica do Cache-Aside
├── public/
│   ├── index.php          # (temporário) checagem do ambiente Docker, ver Fase 1
│   └── produto.php        # ponto de entrada (endpoint) da aplicação
├── database/
│   ├── produtos.sql        # script de criação da tabela + 5.000 produtos de exemplo (gerado)
│   └── gerar_seed.php      # gerador do produtos.sql (rodar de novo só se quiser mudar os dados)
├── benchmark/
│   └── benchmark.php      # script que mede tempo médio com e sem cache
├── docker-compose.yml
├── .env.example            # modelo das variáveis de ambiente (copiar pra .env)
├── ANALISE.md
├── ROADMAP.md
└── README.md
```

## Como rodar

```bash
# 1. Clonar o repositório
git clone git@github.com:jmarciosilva/php-redis-exemplo.git
cd php-redis-exemplo

# 2. Criar o seu arquivo de variáveis de ambiente a partir do modelo
cp .env.example .env

# 3. Subir o ambiente (nginx + php-fpm + mysql + redis), construindo as imagens
docker compose up -d --build

# 4. Conferir se os 4 containers estão de pé
docker compose ps
```

Com tudo no ar, acesse **http://localhost:8080/** — você vai ver uma página de checagem (temporária, só das Fases 1 e 2) confirmando que:

- as extensões `pdo_mysql` e `redis` do PHP estão instaladas;
- a aplicação conseguiu conectar de verdade no MySQL;
- a tabela `produtos` existe e já tem os 5.000 produtos de exemplo importados;
- a aplicação conseguiu conectar de verdade no Redis (e fez um `SET`/`GET` de teste).

Essa página (`public/index.php`) é só um teste de ambiente — a partir da Fase 4 do [ROADMAP.md](ROADMAP.md) ela dá lugar ao endpoint de verdade (`public/produto.php`).

> `database/produtos.sql` já vem pronto no repositório (é gerado por `database/gerar_seed.php`, ver seção abaixo) e é executado **automaticamente** pelo MySQL na primeira vez que o container sobe (por isso a pasta `database/` está montada em `/docker-entrypoint-initdb.d` no `docker-compose.yml`). Se você já tinha subido o ambiente antes desse arquivo existir, rode `docker compose down -v` (isso apaga o volume do banco) e suba de novo com `docker compose up -d --build` pra forçar a reimportação.

## Dados de exemplo (seed)

A tabela `produtos` vem com **5.000 produtos fictícios** (nomes, categorias, preços e estoque gerados aleatoriamente), pra dar volume suficiente pra testar cache e performance de verdade — 3 linhas de exemplo não seriam suficientes pra isso.

O arquivo `database/produtos.sql` já está pronto e commitado, mas se quiser regenerá-lo (por exemplo, pra mudar a quantidade de produtos em `database/gerar_seed.php`), rode:

```bash
docker compose exec php php database/gerar_seed.php > database/produtos.sql
docker compose down -v && docker compose up -d --build
```

## Como rodar o benchmark

O script `benchmark/benchmark.php` compara "com cache" e "sem cache" na prática, disparando lotes de requisições **concorrentes** (via `curl_multi`, sem depender de ferramentas externas tipo `ab`/`wrk`) contra o endpoint `produto.php`. Rode de dentro do container do PHP:

```bash
docker compose exec php php benchmark/benchmark.php
```

Por padrão ele roda 30 lotes de 20 requisições simultâneas por cenário. Dá pra ajustar os dois parâmetros (lotes e concorrência por lote):

```bash
docker compose exec php php benchmark/benchmark.php 20 50
# 20 lotes de 50 requisições simultâneas = 1.000 requisições por cenário
```

> Por que concorrente, e não uma requisição de cada vez? Ver a explicação completa em [ANALISE.md](ANALISE.md) — resumindo: com uma tabela pequena e busca indexada, o MySQL responde rápido demais pra mostrar diferença quando testado sequencialmente. O gargalo real (e o que o cache resolve) só aparece com várias requisições simultâneas disputando a mesma conexão do banco.

## Licença

Uso livre para fins de estudo. Se esse material te ajudou, um link de volta pro post é sempre bem-vindo :)
