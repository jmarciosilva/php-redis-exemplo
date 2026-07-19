<?php

/*
 * Endpoint quase idêntico a produto.php, com UMA única diferença: usa
 * buscarPorIdComProtecaoContraStampede() em vez de buscarPorId(). Existe
 * como um arquivo separado pelo mesmo motivo de produtos.php/
 * produtos_cache.php: deixar as duas versões vivas ao mesmo tempo, pra
 * comparar "com proteção" e "sem proteção" lado a lado.
 *
 * Sozinho, com uma requisição de cada vez, esse endpoint se comporta
 * exatamente igual a produto.php — a diferença só aparece sob RAJADAS de
 * requisições simultâneas pro mesmo id, quando o cache está vazio. Ver
 * benchmark/stampede.php, que é quem realmente demonstra a diferença.
 */

declare(strict_types=1);

$inicioDaRequisicao = microtime(true);

require_once __DIR__ . '/../src/ProdutoRepository.php';

header('Content-Type: application/json; charset=utf-8');

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'Informe um id de produto válido, por exemplo: ?id=1',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$redis = require __DIR__ . '/../config/redis.php';
$repositorio = new ProdutoRepository($pdo, $redis);

// A única linha diferente de produto.php: usa a versão COM proteção
// contra cache stampede (lock no Redis + espera + fallback).
$produto = $repositorio->buscarPorIdComProtecaoContraStampede($id);

if ($produto === null) {
    http_response_code(404);
    echo json_encode([
        'erro' => "Produto com id {$id} não encontrado.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$tempoDeRespostaMs = round((microtime(true) - $inicioDaRequisicao) * 1000, 2);

echo json_encode([
    'origem' => $repositorio->origemDaUltimaBusca(),
    'tempo_resposta_ms' => $tempoDeRespostaMs,
    'produto' => $produto,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
