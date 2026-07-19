<?php

/*
 * Esse script NÃO faz parte da aplicação — é uma ferramenta de linha de
 * comando que a gente roda manualmente pra MEDIR, com números de verdade,
 * a diferença de desempenho entre "com cache" e "sem cache". É exatamente
 * o que prometemos lá no ANALISE.md: nada de "confia, é mais rápido".
 *
 * Como rodar (de dentro do container do PHP):
 *   docker compose exec php php benchmark/benchmark.php
 *
 * Opcionalmente, dá pra escolher quantos LOTES de requisições concorrentes
 * disparar por cenário, e quantas requisições SIMULTÂNEAS tem em cada lote:
 *   docker compose exec php php benchmark/benchmark.php 30 20
 *   (30 lotes de 20 requisições simultâneas = 600 requisições por cenário)
 *
 * IMPORTANTE — por que requisições CONCORRENTES, e não uma de cada vez?
 * Numa primeira versão deste script, disparávamos uma requisição por vez,
 * sequencialmente. Só que, com uma tabela de 5.000 linhas e busca indexada
 * por chave primária, o MySQL responde tão rápido (poucos milissegundos)
 * que a diferença pro Redis praticamente não aparecia! O problema real que
 * o cache resolve não é "uma consulta isolada é lenta" — é "várias
 * requisições SIMULTÂNEAS disputando a mesma conexão/lock do banco" (que é
 * exatamente o tipo de problema relatado no teste técnico que inspirou
 * esse projeto, ver ANALISE.md). Por isso o benchmark dispara lotes de
 * requisições em paralelo, usando curl_multi — a forma "PHP puro" de fazer
 * várias chamadas HTTP ao mesmo tempo, sem depender de threads.
 *
 * IMPORTANTE 2: esse script roda DENTRO do container do PHP, e por isso
 * acessa o Nginx pelo nome do serviço no Docker Compose ("nginx"), na
 * porta 80 — e não por "localhost:8080", que é só o endereço que funciona
 * de FORA do Docker (do seu navegador, na sua máquina).
 */

declare(strict_types=1);

// --- Configuração do benchmark ---

// Quantos LOTES de requisições concorrentes disparar em cada cenário.
$totalDeLotes = isset($argv[1]) ? (int) $argv[1] : 30;

// Quantas requisições SIMULTÂNEAS tem dentro de cada lote — ou seja, o
// "nível de concorrência". É esse número que simula "várias pessoas
// acessando o mesmo produto ao mesmo tempo".
$requisicoesSimultaneasPorLote = isset($argv[2]) ? (int) $argv[2] : 20;

// Endereço do endpoint de produto, visto de DENTRO da rede do Docker.
const URL_DO_ENDPOINT = 'http://nginx/produto.php';

// Simula uma lista de "produtos populares": em vez de bater sempre no
// mesmo id (o que seria um pouco artificial), sorteamos entre esses 5 ids
// em cada requisição. Um conjunto PEQUENO de ids é de propósito aqui: é
// isso que faz vários usuários "colidirem" pedindo os mesmos produtos ao
// mesmo tempo, que é o cenário que realmente estressa o MySQL.
const IDS_DE_PRODUTOS_POPULARES = [1, 2, 3, 4, 5];

// Pra forçar o cenário "sem cache", o script precisa apagar chaves do
// Redis — por isso reaproveitamos o config/redis.php, em vez de duplicar
// essa lógica de conexão.
$redis = require __DIR__ . '/../config/redis.php';

/**
 * Dispara um LOTE de requisições HTTP em paralelo (todas ao mesmo tempo,
 * de verdade) usando a extensão curl no modo "multi". Devolve uma lista
 * com o tempo (em milissegundos) que cada requisição individual levou.
 *
 * Isso é bem diferente de "fazer um for com file_get_contents": aqui as
 * $quantidade requisições são todas ENVIADAS antes de esperarmos qualquer
 * resposta, então elas competem entre si pelos mesmos recursos (conexões
 * do MySQL, workers do PHP-FPM, etc) — exatamente como aconteceria com
 * usuários de verdade acessando o site ao mesmo tempo.
 */
function dispararLoteConcorrente(array $ids, int $quantidade): array
{
    // curl_multi é o "gerenciador" que cuida de várias transferências curl
    // ao mesmo tempo dentro de um único processo PHP.
    $multiHandle = curl_multi_init();
    $handles = [];
    $inicioPorHandle = [];

    for ($i = 0; $i < $quantidade; $i++) {
        $id = $ids[array_rand($ids)];

        $handle = curl_init(URL_DO_ENDPOINT . '?id=' . $id);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true); // não imprime a resposta, só guarda ela
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);

        curl_multi_add_handle($multiHandle, $handle);

        $handles[] = $handle;
        // Guardamos o instante de início de CADA requisição usando o
        // spl_object_id do handle como chave (cada $handle é um objeto
        // CurlHandle único, então isso nunca colide).
        $inicioPorHandle[spl_object_id($handle)] = microtime(true);
    }

    // Esse loop "bombeia" todas as transferências até que todas terminem.
    // curl_multi_exec devolve, em $aindaRodando, quantas transferências
    // ainda estão em andamento; quando chega a 0, acabou tudo.
    do {
        $status = curl_multi_exec($multiHandle, $aindaRodando);
        if ($aindaRodando) {
            // Espera um pouco por atividade na rede antes de checar de novo,
            // em vez de ficar em loop consumindo CPU à toa.
            curl_multi_select($multiHandle);
        }
    } while ($aindaRodando && $status === CURLM_OK);

    $tempos = [];

    foreach ($handles as $handle) {
        $tempoEmMs = (microtime(true) - $inicioPorHandle[spl_object_id($handle)]) * 1000;
        $tempos[] = $tempoEmMs;

        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);
    }

    curl_multi_close($multiHandle);

    return $tempos;
}

/**
 * Roda um cenário completo: dispara $totalDeLotes lotes, cada um com
 * $requisicoesPorLote requisições concorrentes, e devolve a lista de
 * TODOS os tempos individuais coletados (em ms).
 *
 * Quando $forcarCacheMiss é true, a gente APAGA as chaves do Redis antes
 * de CADA lote — isso simula "não ter cache nenhum" (toda requisição
 * precisa ir até o MySQL) e, de quebra, ainda simula um mini "cache
 * stampede" a cada lote (várias requisições simultâneas todas batendo no
 * banco pro mesmo produto — assunto que a gente vai aprofundar na Fase 10).
 */
function rodarCenario(
    string $nomeDoCenario,
    bool $forcarCacheMiss,
    int $totalDeLotes,
    int $requisicoesPorLote,
    Redis $redis
): array {
    echo "Rodando cenário \"{$nomeDoCenario}\" ({$totalDeLotes} lotes x {$requisicoesPorLote} requisições simultâneas)...\n";

    $todosOsTempos = [];

    for ($lote = 0; $lote < $totalDeLotes; $lote++) {
        if ($forcarCacheMiss) {
            foreach (IDS_DE_PRODUTOS_POPULARES as $id) {
                $redis->del("produto:{$id}");
            }
        }

        $temposDoLote = dispararLoteConcorrente(IDS_DE_PRODUTOS_POPULARES, $requisicoesPorLote);
        $todosOsTempos = array_merge($todosOsTempos, $temposDoLote);
    }

    return $todosOsTempos;
}

/**
 * Calcula estatísticas simples (média, mínimo, máximo e "percentil 95")
 * a partir de uma lista de tempos em milissegundos.
 *
 * O p95 responde: "abaixo de qual tempo ficaram 95% das requisições?" —
 * é uma métrica mais honesta que a média sozinha, porque não esconde
 * aquelas poucas requisições bem mais lentas que o normal.
 */
function calcularEstatisticas(array $temposEmMs): array
{
    sort($temposEmMs); // ordena do menor pro maior, necessário pro cálculo do p95

    $quantidade = count($temposEmMs);
    $indiceDoP95 = (int) floor($quantidade * 0.95) - 1;
    $indiceDoP95 = max(0, min($indiceDoP95, $quantidade - 1)); // evita estourar os limites do array

    return [
        'media' => array_sum($temposEmMs) / $quantidade,
        'minimo' => $temposEmMs[0],
        'maximo' => $temposEmMs[$quantidade - 1],
        'p95' => $temposEmMs[$indiceDoP95],
    ];
}

// --- Execução dos dois cenários ---

// Cenário 1: SEM cache — cada lote força todo mundo a ir no MySQL ao
// mesmo tempo. Esse é o "estado ruim" que descrevemos no ANALISE.md.
$temposSemCache = rodarCenario('SEM cache (forçando MySQL sempre)', true, $totalDeLotes, $requisicoesSimultaneasPorLote, $redis);

// Antes do cenário 2, limpamos as chaves de novo, pra garantir que os dois
// cenários comecem "do zero" (nenhum dos dois começa com vantagem).
foreach (IDS_DE_PRODUTOS_POPULARES as $id) {
    $redis->del("produto:{$id}");
}

// Cenário 2: COM cache — aqui a gente NÃO apaga as chaves antes de cada
// lote. Só o PRIMEIRO lote tem alguns misses (que populam o cache); todos
// os lotes seguintes encontram os produtos já prontos no Redis — como
// aconteceria na aplicação de verdade, rodando em produção.
$temposComCache = rodarCenario('COM cache (Cache-Aside normal)', false, $totalDeLotes, $requisicoesSimultaneasPorLote, $redis);

// --- Relatório final ---

$estatisticasSemCache = calcularEstatisticas($temposSemCache);
$estatisticasComCache = calcularEstatisticas($temposComCache);
$totalDeRequisicoesPorCenario = $totalDeLotes * $requisicoesSimultaneasPorLote;

echo "\n=== Resultado do benchmark ({$totalDeRequisicoesPorCenario} requisições por cenário, {$requisicoesSimultaneasPorLote} simultâneas por lote) ===\n\n";

$linhaFormato = "%-14s %10s %10s %10s %10s\n";
printf($linhaFormato, 'Cenário', 'Média(ms)', 'Mín(ms)', 'Máx(ms)', 'p95(ms)');
printf(
    $linhaFormato,
    'Sem cache',
    number_format($estatisticasSemCache['media'], 2),
    number_format($estatisticasSemCache['minimo'], 2),
    number_format($estatisticasSemCache['maximo'], 2),
    number_format($estatisticasSemCache['p95'], 2)
);
printf(
    $linhaFormato,
    'Com cache',
    number_format($estatisticasComCache['media'], 2),
    number_format($estatisticasComCache['minimo'], 2),
    number_format($estatisticasComCache['maximo'], 2),
    number_format($estatisticasComCache['p95'], 2)
);

// Calcula quantas vezes mais rápido o cache deixou a média — é o número
// mais "chamativo" pra colocar no post do blog.
$vezesMaisRapido = $estatisticasSemCache['media'] / $estatisticasComCache['media'];

echo "\nCom cache, a média ficou " . number_format($vezesMaisRapido, 1) . "x mais rápida que sem cache.\n";
