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
├── docker/                # Dockerfiles e configs (nginx, php, etc.)
├── config/
│   ├── database.php       # configuração de conexão com o MySQL
│   └── redis.php          # configuração de conexão com o Redis
├── src/
│   └── ProdutoRepository.php  # onde mora a lógica do Cache-Aside
├── public/
│   └── produto.php        # ponto de entrada (endpoint) da aplicação
├── database/
│   └── produtos.sql       # script de criação + carga de dados de exemplo
├── benchmark/
│   └── benchmark.php      # script que mede tempo médio com e sem cache
├── docker-compose.yml
├── ANALISE.md
├── ROADMAP.md
└── README.md
```

## Como rodar

> ⚠️ Seção será detalhada assim que o `docker-compose.yml` e os serviços estiverem implementados (ver [ROADMAP.md](ROADMAP.md)). Por enquanto, o esqueleto esperado é:

```bash
# 1. Clonar o repositório
git clone git@github.com:jmarciosilva/php-redis-exemplo.git
cd php-redis-exemplo

# 2. Subir o ambiente (nginx + php-fpm + mysql + redis)
docker-compose up -d

# 3. Importar a base de dados de exemplo
docker exec -i <container-mysql> mysql -u root -p produtos < database/produtos.sql

# 4. Acessar a aplicação
# http://localhost:8080/produto.php?id=1
```

## Como rodar o benchmark

> ⚠️ Detalhes vêm junto com a fase de benchmark do roadmap. A ideia é ter um script PHP simples (`benchmark/benchmark.php`) que dispara N requisições contra o endpoint com e sem cache habilitado, e imprime o tempo médio de resposta — sem depender de ferramentas externas, pra ficar fácil de rodar em qualquer máquina.

## Licença

Uso livre para fins de estudo. Se esse material te ajudou, um link de volta pro post é sempre bem-vindo :)
