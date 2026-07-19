<?php

/*
 * Esse arquivo guarda a lógica das checagens de ambiente (extensões do
 * PHP, conexão com MySQL, seed importado, conexão com Redis) numa única
 * função, pra ser reaproveitada em dois lugares:
 *
 *   - public/index.php: roda as checagens no carregamento inicial da
 *     página (funciona mesmo sem JavaScript).
 *   - public/diagnostico.php: devolve as mesmas checagens em JSON, usado
 *     pelo botão "Testar agora" (via JavaScript) pra rodar tudo de novo
 *     sem precisar recarregar a página inteira.
 *
 * Cada checagem agora também mede quanto tempo ela levou (em ms) — não
 * muda o resultado, mas é mais uma chance de "sentir" a diferença entre
 * abrir uma conexão nova toda vez (MySQL, Redis) e uma checagem instantânea
 * (extensão instalada ou não).
 */

declare(strict_types=1);

/**
 * Roda todas as checagens de ambiente e devolve um array associativo:
 * nome da checagem => ['ok' => bool, 'mensagem' => string, 'tempo_ms' => float|null].
 * "tempo_ms" fica null pras checagens instantâneas (extensão instalada ou não),
 * que não fazem sentido cronometrar.
 */
function executarChecagensDeAmbiente(): array
{
    $testes = [];

    // --- Teste 1: a extensão pdo_mysql está instalada? ---
    $testes['Extensão pdo_mysql'] = extension_loaded('pdo_mysql')
        ? ['ok' => true, 'mensagem' => 'Instalada e habilitada.', 'tempo_ms' => null]
        : ['ok' => false, 'mensagem' => 'NÃO encontrada — confira o Dockerfile do PHP.', 'tempo_ms' => null];

    // --- Teste 2: a extensão do Redis está instalada? ---
    $testes['Extensão redis'] = extension_loaded('redis')
        ? ['ok' => true, 'mensagem' => 'Instalada e habilitada.', 'tempo_ms' => null]
        : ['ok' => false, 'mensagem' => 'NÃO encontrada — confira o Dockerfile do PHP.', 'tempo_ms' => null];

    // --- Teste 3: dá pra conectar de verdade no MySQL? ---
    $inicioMysql = microtime(true);
    try {
        $pdo = require __DIR__ . '/../config/database.php';
        $tempoMysqlMs = round((microtime(true) - $inicioMysql) * 1000, 2);

        $testes['Conexão com o MySQL'] = [
            'ok' => true,
            'mensagem' => 'Conectou normalmente em "' . getenv('DB_HOST') . '".',
            'tempo_ms' => $tempoMysqlMs,
        ];
    } catch (PDOException $erro) {
        $testes['Conexão com o MySQL'] = [
            'ok' => false,
            'mensagem' => 'Falhou: ' . $erro->getMessage(),
            'tempo_ms' => round((microtime(true) - $inicioMysql) * 1000, 2),
        ];
    }

    // --- Teste 3.1: os dados de exemplo (seed) foram importados? ---
    if (isset($pdo)) {
        $inicioSeed = microtime(true);

        try {
            $quantidade = (int) $pdo->query('SELECT COUNT(*) FROM produtos')->fetchColumn();
            $tempoSeedMs = round((microtime(true) - $inicioSeed) * 1000, 2);

            $testes['Dados de exemplo (seed)'] = ($quantidade > 0)
                ? ['ok' => true, 'mensagem' => "Encontrei {$quantidade} produtos na tabela.", 'tempo_ms' => $tempoSeedMs]
                : ['ok' => false, 'mensagem' => 'A tabela "produtos" existe, mas está vazia.', 'tempo_ms' => $tempoSeedMs];
        } catch (PDOException $erro) {
            $testes['Dados de exemplo (seed)'] = [
                'ok' => false,
                'mensagem' => 'Tabela "produtos" não encontrada: ' . $erro->getMessage(),
                'tempo_ms' => round((microtime(true) - $inicioSeed) * 1000, 2),
            ];
        }
    }

    // --- Teste 4: dá pra conectar de verdade no Redis? ---
    $inicioRedis = microtime(true);
    try {
        $redis = require __DIR__ . '/../config/redis.php';

        // Fazemos um SET e um GET de verdade, só pra provar que não é só a
        // conexão que funciona, mas que o Redis está realmente guardando e
        // devolvendo dado.
        $redis->set('teste_ambiente_docker', 'funcionando');
        $valor = $redis->get('teste_ambiente_docker');
        $tempoRedisMs = round((microtime(true) - $inicioRedis) * 1000, 2);

        $testes['Conexão com o Redis'] = ($valor === 'funcionando')
            ? ['ok' => true, 'mensagem' => 'Conectou e um SET/GET de teste funcionou.', 'tempo_ms' => $tempoRedisMs]
            : ['ok' => false, 'mensagem' => 'Conectou, mas o SET/GET de teste não bateu.', 'tempo_ms' => $tempoRedisMs];
    } catch (\Throwable $erro) {
        $testes['Conexão com o Redis'] = [
            'ok' => false,
            'mensagem' => 'Falhou: ' . $erro->getMessage(),
            'tempo_ms' => round((microtime(true) - $inicioRedis) * 1000, 2),
        ];
    }

    return $testes;
}
