<?php

/*
 * Script de demonstração do **cache stampede** (também chamado de
 * "dog-piling"): o que acontece quando MUITAS requisições pedem o MESMO
 * produto bem no instante em que o cache dele está vazio — o TTL acabou
 * de expirar, ou o produto nunca foi visitado. Sem proteção, TODAS essas
 * requisições caem no MySQL ao mesmo tempo, mesmo sendo, na prática, a
 * MESMA pergunta repetida N vezes — é como se o cache, por um instante,
 * simplesmente não existisse.
 *
 * Diferente do benchmark/benchmark.php (que mede TEMPO de resposta), esse
 * script mede uma coisa mais direta e mais fácil de entender: QUANTAS
 * VEZES o MySQL foi realmente consultado durante uma rajada de N
 * requisições simultâneas pro mesmo produto. Sem proteção, esperamos um
 * número próximo de N. Com proteção (lock no Redis), esperamos 1.
 *
 * Como rodar (de dentro do container do PHP):
 *   docker compose exec php php benchmark/stampede.php
 *   docker compose exec php php benchmark/stampede.php 3 30
 *   (produto id=3, 30 requisições simultâneas por cenário)
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/ProdutoRepository.php';

$idDeTeste = isset($argv[1]) ? (int) $argv[1] : 1;
$requisicoesSimultaneas = isset($argv[2]) ? (int) $argv[2] : 30;

$pdo = require __DIR__ . '/../config/database.php';
$redis = require __DIR__ . '/../config/redis.php';
$repositorio = new ProdutoRepository($pdo, $redis);

/**
 * Dispara $quantidade requisições HTTP TODAS AO MESMO TEMPO (mesmo
 * mecanismo de benchmark.php: curl_multi) contra a URL informada, e só
 * volta depois que TODAS já terminaram.
 */
function dispararRajadaConcorrente(string $url, int $quantidade): void
{
    $multiHandle = curl_multi_init();
    $handles = [];

    for ($i = 0; $i < $quantidade; $i++) {
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);
        curl_multi_add_handle($multiHandle, $handle);
        $handles[] = $handle;
    }

    do {
        $status = curl_multi_exec($multiHandle, $aindaRodando);
        if ($aindaRodando) {
            curl_multi_select($multiHandle);
        }
    } while ($aindaRodando && $status === CURLM_OK);

    foreach ($handles as $handle) {
        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);
    }

    curl_multi_close($multiHandle);
}

/**
 * Roda um cenário completo: garante que o cache do produto está VAZIO
 * (simulando o instante em que o TTL acabou de expirar), zera o contador
 * de consultas ao MySQL, dispara a rajada, e devolve quantas vezes o
 * MySQL foi consultado durante ela.
 */
function rodarCenarioDeStampede(
    string $nomeDoCenario,
    string $url,
    int $id,
    int $quantidade,
    Redis $redis,
    ProdutoRepository $repositorio
): int {
    echo "Rodando \"{$nomeDoCenario}\" ({$quantidade} requisições simultâneas pro produto {$id})...\n";

    // Garante que a rajada INTEIRA vai encontrar o cache vazio ao mesmo
    // tempo — esse é o gatilho de um stampede de verdade.
    $redis->del("produto:{$id}");
    $repositorio->resetarContadorDeConsultasMysql($id);

    dispararRajadaConcorrente($url, $quantidade);

    return $repositorio->contarConsultasMysql($id);
}

// --- Cenário 1: SEM proteção (produto.php) ---
$consultasSemProtecao = rodarCenarioDeStampede(
    'SEM proteção contra stampede',
    "http://nginx/produto.php?id={$idDeTeste}",
    $idDeTeste,
    $requisicoesSimultaneas,
    $redis,
    $repositorio
);

// --- Cenário 2: COM proteção (produto_protegido.php) ---
$consultasComProtecao = rodarCenarioDeStampede(
    'COM proteção contra stampede (lock no Redis)',
    "http://nginx/produto_protegido.php?id={$idDeTeste}",
    $idDeTeste,
    $requisicoesSimultaneas,
    $redis,
    $repositorio
);

// --- Relatório final ---

echo "\n=== Resultado do teste de stampede ({$requisicoesSimultaneas} requisições simultâneas) ===\n\n";

$linhaFormato = "%-45s %s\n";
printf($linhaFormato, 'Cenário', 'Consultas reais ao MySQL');
printf($linhaFormato, 'Sem proteção', $consultasSemProtecao);
printf($linhaFormato, 'Com proteção (lock no Redis)', $consultasComProtecao);

echo "\n";

if ($consultasSemProtecao > 1) {
    echo "Sem proteção, {$consultasSemProtecao} requisições diferentes bateram no MySQL — pra responder\n";
    echo "exatamente a MESMA pergunta ({$requisicoesSimultaneas} pediram o produto {$idDeTeste} ao mesmo tempo).\n";
} else {
    echo "Curioso: nem o cenário sem proteção causou mais de 1 consulta dessa vez — a\n";
    echo "concorrência real na sua máquina pode ter sido menor que o esperado. Tente aumentar\n";
    echo "o número de requisições simultâneas (segundo argumento) e rodar de novo.\n";
}

echo "Com proteção, apenas {$consultasComProtecao} consulta(s) ao MySQL — as outras esperaram e reaproveitaram\n";
echo "o resultado que o processo dono do lock já tinha guardado no cache.\n";
