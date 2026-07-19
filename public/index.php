<?php

/*
 * Página de diagnóstico do ambiente: nasceu na Fase 1 só pra confirmar que
 * Nginx + PHP-FPM + MySQL + Redis estavam todos conversando entre si, e
 * continua útil como uma "página de saúde" do projeto — se algum dia algo
 * parar de funcionar depois de mexer no Docker, é aqui que você confere
 * o que quebrou primeiro.
 */

// declare(strict_types=1) faz o PHP ser "rígido" com os tipos das variáveis
// (por exemplo, não deixa passar uma string "10" onde se espera um int 10 sem avisar).
// É uma boa prática pra evitar bugs bobos de tipagem.
declare(strict_types=1);

// Array onde vamos guardar o resultado de cada teste que fizermos abaixo.
// A chave é o nome do teste, o valor é um array com "ok" (true/false) e uma "mensagem".
$testes = [];

// --- Teste 1: a extensão pdo_mysql está instalada? ---
// extension_loaded() só checa se a extensão existe no PHP rodando, não testa conexão ainda.
$testes['Extensão pdo_mysql'] = extension_loaded('pdo_mysql')
    ? ['ok' => true, 'mensagem' => 'Instalada e habilitada.']
    : ['ok' => false, 'mensagem' => 'NÃO encontrada — confira o Dockerfile do PHP.'];

// --- Teste 2: a extensão do Redis está instalada? ---
$testes['Extensão redis'] = extension_loaded('redis')
    ? ['ok' => true, 'mensagem' => 'Instalada e habilitada.']
    : ['ok' => false, 'mensagem' => 'NÃO encontrada — confira o Dockerfile do PHP.'];

// --- Teste 3: dá pra conectar de verdade no MySQL? ---
// Agora usamos o config/database.php de verdade (Fase 2), em vez de montar
// a conexão na mão aqui — é exatamente esse arquivo que o ProdutoRepository
// vai usar mais pra frente.
try {
    $pdo = require __DIR__ . '/../config/database.php';

    $testes['Conexão com o MySQL'] = ['ok' => true, 'mensagem' => 'Conectou normalmente em "' . getenv('DB_HOST') . '".'];
} catch (PDOException $erro) {
    $testes['Conexão com o MySQL'] = ['ok' => false, 'mensagem' => 'Falhou: ' . $erro->getMessage()];
}

// --- Teste 3.1: os dados de exemplo (seed) da Fase 2 foram importados? ---
// Só faz sentido rodar essa consulta se a conexão acima deu certo, por isso
// checamos isset($pdo) antes.
if (isset($pdo)) {
    try {
        // query() aqui é seguro porque não tem NENHUM dado vindo de fora
        // (nem do usuário, nem de variável) dentro do SQL — é um texto fixo.
        // Quando tiver dado variável entrando na consulta (ex.: um "id" vindo
        // da URL), a gente sempre usa prepared statements (prepare + execute).
        $quantidade = (int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn();

        $testes['Dados de exemplo (seed)'] = ($quantidade > 0)
            ? ['ok' => true, 'mensagem' => "Encontrei {$quantidade} produtos na tabela."]
            : ['ok' => false, 'mensagem' => 'A tabela "produtos" existe, mas está vazia.'];
    } catch (PDOException $erro) {
        $testes['Dados de exemplo (seed)'] = ['ok' => false, 'mensagem' => 'Tabela "produtos" não encontrada: ' . $erro->getMessage()];
    }
}

// --- Teste 4: dá pra conectar de verdade no Redis? ---
// Agora usamos o config/redis.php de verdade (Fase 3), em vez de montar a
// conexão na mão aqui — é exatamente esse arquivo que o ProdutoRepository
// vai usar mais pra frente pra guardar/buscar produtos em cache.
try {
    $redis = require __DIR__ . '/../config/redis.php';

    // Fazemos um SET e um GET de verdade, só pra provar que não é só a conexão
    // que funciona, mas que o Redis está realmente guardando e devolvendo dado.
    $redis->set('teste_ambiente_docker', 'funcionando');
    $valor = $redis->get('teste_ambiente_docker');

    $testes['Conexão com o Redis'] = ($valor === 'funcionando')
        ? ['ok' => true, 'mensagem' => 'Conectou e um SET/GET de teste funcionou.']
        : ['ok' => false, 'mensagem' => 'Conectou, mas o SET/GET de teste não bateu.'];
} catch (\Throwable $erro) {
    $testes['Conexão com o Redis'] = ['ok' => false, 'mensagem' => 'Falhou: ' . $erro->getMessage()];
}

// Define que a resposta vai ser HTML (só pra ficar mais fácil de ler no navegador).
header('Content-Type: text/html; charset=utf-8');

$tituloDaPagina = 'Diagnóstico do ambiente';
$subtituloDaPagina = 'Checagem rápida de que Nginx + PHP-FPM + MySQL + Redis estão todos conversando entre si.';
$paginaAtiva = 'inicio';
require __DIR__ . '/../src/views/cabecalho.php';
?>

<div class="card">
    <div class="tabela-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Situação</th>
                    <th>Verificação</th>
                    <th>Detalhe</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($testes as $nome => $resultado): ?>
                    <tr>
                        <td><?= $resultado['ok'] ? '✅' : '❌' ?></td>
                        <td><strong><?= htmlspecialchars($nome) ?></strong></td>
                        <td><?= htmlspecialchars($resultado['mensagem']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<p style="color: var(--tinta-secundaria);">
    Quer ver a aplicação de verdade? Veja a <a href="/produtos.php">listagem de produtos</a> ou o
    <a href="/performance.php">dashboard de performance</a>.
</p>

<?php require __DIR__ . '/../src/views/rodape.php'; ?>
