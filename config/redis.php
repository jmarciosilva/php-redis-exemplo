<?php

/*
 * Assim como o config/database.php cuida da conexão com o MySQL, esse
 * arquivo cuida só de UMA coisa: abrir e devolver uma conexão com o Redis,
 * já configurada. Qualquer outro arquivo do projeto (como o
 * ProdutoRepository, na Fase 5) vai poder fazer:
 *
 *     $redis = require __DIR__ . '/../config/redis.php';
 *
 * ...e já ganha uma conexão Redis pronta pra usar, sem se preocupar com
 * host, porta ou como a extensão funciona por dentro.
 */

declare(strict_types=1);

// getenv() lê as variáveis de ambiente que definimos no docker-compose.yml
// pro serviço "php": REDIS_HOST (o nome do serviço "redis" lá no compose,
// que o Docker resolve como se fosse um endereço) e REDIS_PORT (a porta
// padrão do Redis, 6379).
$host = (string) getenv('REDIS_HOST');
$porta = (int) getenv('REDIS_PORT');

// A classe Redis vem da extensão "phpredis", que instalamos via PECL no
// Dockerfile do PHP (docker/php/Dockerfile). Diferente do PDO, aqui a gente
// primeiro cria o objeto vazio e DEPOIS conecta com ->connect(), em vez de
// já conectar no construtor.
$redis = new Redis();

// connect() é quem realmente abre a conexão TCP com o servidor Redis.
// Se o Redis estiver fora do ar ou o host/porta estiverem errados, esse
// método lança uma RedisException — e, igual fizemos no database.php,
// deixamos essa exceção "vazar" pra quem chamou esse arquivo, porque sem
// Redis boa parte da aplicação não faz sentido continuar rodando.
$redis->connect($host, $porta);

// Repare que a extensão do Redis, diferente do PDO, já lança exceção por
// padrão em caso de erro de conexão — não precisamos configurar nenhum
// "modo de erro" à parte aqui.

// SCAN_RETRY faz o comando SCAN (usado na Fase 10 pra encontrar e invalidar
// várias chaves de listagem de uma vez, ver ProdutoRepository) repetir
// sozinho quando o Redis devolve um "cursor" intermediário vazio — sem
// isso, precisaríamos escrever esse retry na mão toda vez que usássemos
// SCAN. É só uma opção de conveniência, não muda o que o SCAN devolve.
$redis->setOption(Redis::OPT_SCAN, Redis::SCAN_RETRY);

// Igual no config/database.php, devolvemos a conexão pronta através de um
// "return". Isso é o que permite escrever
// "$redis = require __DIR__ . '/../config/redis.php';" em qualquer lugar
// do projeto.
return $redis;
