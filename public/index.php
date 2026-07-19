<?php

/*
 * ATENÇÃO: esse arquivo é TEMPORÁRIO! Ele existe só pra Fase 1 (ambiente Docker),
 * pra você conseguir confirmar que Nginx + PHP-FPM + MySQL + Redis estão todos
 * conversando entre si antes da gente escrever qualquer regra de negócio de verdade.
 * Nas próximas fases (a partir da Fase 4), esse conteúdo vai ser substituído pelo
 * endpoint de produto de verdade (public/produto.php).
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
// getenv() lê as variáveis de ambiente que definimos no docker-compose.yml
// (lembra que lá em cima a gente passou DB_HOST, DB_DATABASE, etc pro container "php"?).
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        getenv('DB_HOST'),
        getenv('DB_PORT'),
        getenv('DB_DATABASE')
    );

    // PDO é a forma "padrão" do PHP de conversar com bancos de dados.
    // Se a conexão falhar, ele lança uma exceção (por isso o try/catch).
    $pdo = new PDO($dsn, getenv('DB_USERNAME'), getenv('DB_PASSWORD'));

    $testes['Conexão com o MySQL'] = ['ok' => true, 'mensagem' => 'Conectou normalmente em "' . getenv('DB_HOST') . '".'];
} catch (PDOException $erro) {
    $testes['Conexão com o MySQL'] = ['ok' => false, 'mensagem' => 'Falhou: ' . $erro->getMessage()];
}

// --- Teste 4: dá pra conectar de verdade no Redis? ---
try {
    // A classe Redis vem da extensão que instalamos via PECL no Dockerfile.
    $redis = new Redis();
    $redis->connect((string) getenv('REDIS_HOST'), (int) getenv('REDIS_PORT'));

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
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Ambiente Docker - php-redis-exemplo</title>
</head>
<body style="font-family: sans-serif; padding: 2rem;">
    <h1>Checagem do ambiente Docker</h1>
    <p>Página temporária da Fase 1, só pra confirmar que tudo está de pé.</p>
    <ul>
        <?php foreach ($testes as $nome => $resultado): ?>
            <li>
                <?= $resultado['ok'] ? '✅' : '❌' ?>
                <strong><?= htmlspecialchars($nome) ?>:</strong>
                <?= htmlspecialchars($resultado['mensagem']) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</body>
</html>
