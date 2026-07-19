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
│   └── produtos.sql       # script de criação + carga de dados de exemplo
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

Com tudo no ar, acesse **http://localhost:8080/** — você vai ver uma página de checagem (temporária, só da Fase 1) confirmando que:

- as extensões `pdo_mysql` e `redis` do PHP estão instaladas;
- a aplicação conseguiu conectar de verdade no MySQL;
- a aplicação conseguiu conectar de verdade no Redis (e fez um `SET`/`GET` de teste).

Essa página (`public/index.php`) é só um teste de ambiente — a partir da Fase 4 do [ROADMAP.md](ROADMAP.md) ela dá lugar ao endpoint de verdade (`public/produto.php`).

> Ainda não existe `database/produtos.sql` (isso é a Fase 2). Assim que ele for criado, o MySQL vai executá-lo **automaticamente** na primeira vez que o container subir (por isso a pasta `database/` já está montada em `/docker-entrypoint-initdb.d` no `docker-compose.yml`). Se você já tinha subido o ambiente antes de o arquivo existir, rode `docker compose down -v` (isso apaga o volume do banco) e suba de novo com `docker compose up -d --build` pra forçar a reimportação.

## Como rodar o benchmark

> ⚠️ Detalhes vêm junto com a fase de benchmark do roadmap. A ideia é ter um script PHP simples (`benchmark/benchmark.php`) que dispara N requisições contra o endpoint com e sem cache habilitado, e imprime o tempo médio de resposta — sem depender de ferramentas externas, pra ficar fácil de rodar em qualquer máquina.

## Licença

Uso livre para fins de estudo. Se esse material te ajudou, um link de volta pro post é sempre bem-vindo :)
