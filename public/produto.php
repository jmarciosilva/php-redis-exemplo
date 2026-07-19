<?php

/*
 * Esse é o endpoint de verdade da aplicação: você acessa, por exemplo,
 *   http://localhost:8080/produto.php?id=42
 * e ele devolve os dados do produto de id 42 em formato JSON.
 *
 * IMPORTANTE: nesta Fase 4, esse arquivo AINDA NÃO usa Redis — toda
 * requisição consulta o MySQL direto, sempre. É de propósito: esse é o
 * "estado ruim" (sem cache) que vamos comparar com o "estado bom" (com
 * cache) lá na Fase 6, quando escrevermos o script de benchmark. Sem essa
 * comparação de antes/depois, o benefício do Redis fica só na teoria.
 */

declare(strict_types=1);

// Marca o instante em que a requisição começou a ser processada. Vamos usar
// isso lá no final pra calcular quanto tempo o PHP levou — esse número é
// justamente o que o benchmark da Fase 6 vai comparar com/sem cache.
$inicioDaRequisicao = microtime(true);

// require_once carrega a classe ProdutoRepository. Como não estamos usando
// Composer/autoload neste projeto (é tudo "PHP puro", de propósito), a
// gente inclui os arquivos manualmente, um por um.
require_once __DIR__ . '/../src/ProdutoRepository.php';

// Toda resposta desse endpoint vai ser JSON, então já avisamos isso no
// cabeçalho da resposta HTTP.
header('Content-Type: application/json; charset=utf-8');

// $_GET é o array onde o PHP guarda os parâmetros que vieram na URL depois
// do "?" (nesse caso, o "id"). Fazemos um cast pra (int) porque tudo que
// vem de $_GET chega como string — mesmo que a URL seja "?id=42".
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Validação básica: um id de produto sempre é um número positivo. Se vier
// vazio, "abc" (que vira 0 no cast) ou negativo, é um pedido inválido.
if ($id <= 0) {
    // Código 400 = "Bad Request", ou seja, "a culpa foi de quem pediu".
    http_response_code(400);
    echo json_encode([
        'erro' => 'Informe um id de produto válido, por exemplo: ?id=1',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Aqui abrimos a conexão com o banco (config/database.php já devolve um
// PDO prontinho) e criamos o repositório passando essa conexão pra ele.
$pdo = require __DIR__ . '/../config/database.php';
$repositorio = new ProdutoRepository($pdo);

// A ÚNICA linha que realmente busca o dado. Sem cache: isso aqui SEMPRE
// dispara uma consulta nova no MySQL, não importa quantas vezes o mesmo
// id seja pedido em seguida.
$produto = $repositorio->buscarPorId($id);

// Se o repositório devolveu null, é porque não existe produto com esse id.
if ($produto === null) {
    // Código 404 = "Not Found".
    http_response_code(404);
    echo json_encode([
        'erro' => "Produto com id {$id} não encontrado.",
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Calcula quanto tempo passou desde o início da requisição, em milissegundos.
// round(..., 2) deixa só 2 casas decimais, só pra ficar mais legível.
$tempoDeRespostaMs = round((microtime(true) - $inicioDaRequisicao) * 1000, 2);

// Devolve o produto encontrado, junto com metadados úteis pra gente
// acompanhar performance: de onde veio o dado (por enquanto, sempre "mysql"
// — na Fase 5 isso vai poder virar "redis") e quanto tempo levou.
echo json_encode([
    'origem' => 'mysql', // a partir da Fase 5, pode virar 'redis' quando vier do cache
    'tempo_resposta_ms' => $tempoDeRespostaMs,
    'produto' => $produto,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
