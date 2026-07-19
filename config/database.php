<?php

/*
 * Esse arquivo é responsável por UMA coisa só: abrir e devolver uma conexão
 * PDO com o MySQL, já configurada do jeito certo. A ideia é que qualquer
 * outro arquivo do projeto (como o ProdutoRepository, na Fase 4) só precise
 * fazer:
 *
 *     $pdo = require __DIR__ . '/../config/database.php';
 *
 * ...e já ganhe uma conexão pronta pra usar, sem precisar saber host, porta,
 * usuário ou senha. Isso é o que chamamos de "centralizar a configuração".
 */

declare(strict_types=1);

// getenv() lê as variáveis de ambiente. Lembra que no docker-compose.yml a
// gente definiu DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME e DB_PASSWORD
// pro serviço "php"? É exatamente elas que estamos lendo aqui.
$host = getenv('DB_HOST');
$porta = getenv('DB_PORT');
$banco = getenv('DB_DATABASE');
$usuario = getenv('DB_USERNAME');
$senha = getenv('DB_PASSWORD');

// O DSN ("Data Source Name") é uma string que diz ao PDO: qual driver usar
// (mysql), em qual host/porta o banco está, qual banco acessar e qual
// charset usar. utf8mb4 é o charset recomendado hoje em dia porque suporta
// todos os caracteres Unicode (inclusive emojis, se um dia precisar).
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $porta, $banco);

// Aqui abrimos a conexão de verdade. Se algo der errado (senha errada,
// MySQL fora do ar, banco inexistente), o PDO já lança uma PDOException
// sozinho — não precisamos de um try/catch aqui, porque se a conexão
// falhar, faz sentido a aplicação inteira quebrar (sem banco, não tem
// como continuar rodando mesmo).
$pdo = new PDO($dsn, $usuario, $senha, [
    // Faz o PDO lançar exceções (PDOException) quando uma consulta falha,
    // em vez de só retornar "false" silenciosamente. Isso deixa os erros
    // muito mais fáceis de perceber e depurar.
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,

    // Faz os resultados das consultas virem como array associativo
    // (['id' => 1, 'nome' => 'Produto X']) em vez do padrão do PDO, que
    // devolve tanto índice numérico quanto nome de coluna misturados.
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Esse "return" é o pulo do gato: como esse arquivo é incluído com
// "require" (não com "include" comum), o valor retornado aqui vira o
// valor da própria expressão "require" em quem chamou. Por isso dá pra
// escrever "$pdo = require __DIR__ . '/../config/database.php';" e já
// receber a conexão prontinha.
return $pdo;
