<?php

/*
 * Endpoint pequeno, usado só pelo "testador ao vivo" da página
 * performance.php: apaga do Redis a chave de cache de UM produto
 * específico, forçando a próxima busca por esse id a ir no MySQL de novo.
 *
 * É basicamente um botão de "esquece esse produto, Redis" — útil pra
 * demonstração (permite forçar um cache miss na hora, sem esperar os 300s
 * de TTL expirarem sozinhos).
 *
 * ATENÇÃO pra quem for reaproveitar esse projeto: isso aqui é uma
 * ferramenta de DEMONSTRAÇÃO. Num sistema em produção de verdade, um
 * endpoint que apaga cache não ficaria exposto sem autenticação — qualquer
 * pessoa poderia ficar limpando o cache repetidamente e forçar carga
 * extra no banco (isso é, inclusive, uma variação do "cache stampede" que
 * vamos ver na Fase 11).
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe um id válido, por exemplo: ?id=1'], JSON_UNESCAPED_UNICODE);
    exit;
}

$redis = require __DIR__ . '/../config/redis.php';

// del() devolve quantas chaves foram realmente removidas (0 ou 1, aqui).
$removido = $redis->del("produto:{$id}") > 0;

echo json_encode([
    'id' => $id,
    'removido_do_cache' => $removido,
], JSON_UNESCAPED_UNICODE);
