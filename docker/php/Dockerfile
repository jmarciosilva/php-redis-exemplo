# Imagem oficial do PHP já com o PHP-FPM incluso (é o "motor" que processa o PHP nos bastidores).
# Usamos a versão 8.3 porque é uma versão recente e estável do PHP.
FROM php:8.3-fpm

# Aqui a gente atualiza a lista de pacotes do sistema (dentro do container, que é baseado em Debian)
# e instala as dependências exigidas por algumas extensões do PHP:
# - libzip-dev / unzip: exigidos por extensões de compactação
# - libcurl4-openssl-dev: exigido pela extensão curl (usada no benchmark, pra disparar
#   requisições HTTP concorrentes de verdade via curl_multi — ver benchmark/benchmark.php)
# No final, limpamos o cache do apt (rm -rf /var/lib/apt/lists/*) só pra deixar a imagem mais leve.
RUN apt-get update && apt-get install -y \
        libzip-dev \
        unzip \
        libcurl4-openssl-dev \
    && rm -rf /var/lib/apt/lists/*

# "docker-php-ext-install" é um script que já vem pronto nas imagens oficiais do PHP
# pra instalar extensões nativas. Aqui instalamos:
# - pdo: a interface genérica do PHP pra falar com bancos de dados
# - pdo_mysql: o "driver" específico que ensina o PDO a conversar com MySQL
# - curl: pra fazer requisições HTTP (usado só pelo benchmark, não pela aplicação em si)
RUN docker-php-ext-install pdo pdo_mysql curl

# A extensão do Redis NÃO vem instalada por padrão no PHP, então precisamos buscar ela via PECL
# (é tipo um "gerenciador de pacotes" de extensões do PHP) e depois habilitar ela com o comando
# docker-php-ext-enable (que só cria o arquivo de configuração apontando pra extensão instalada).
RUN pecl install redis && docker-php-ext-enable redis

# Define a pasta padrão de trabalho dentro do container. É aqui que o código da nossa
# aplicação vai ficar montado (ver o "volumes" no docker-compose.yml).
WORKDIR /var/www

# Não precisamos de CMD/ENTRYPOINT customizado: a imagem base do PHP-FPM já sobe o
# PHP-FPM escutando na porta 9000 sozinha quando o container inicia.
