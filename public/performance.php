<?php

/*
 * Dashboard de performance: mostra o resultado da ÚLTIMA vez que o
 * benchmark/benchmark.php rodou (lido de benchmark/ultimo_resultado.json)
 * e traz um "testador ao vivo", onde dá pra ver na hora — sem terminal,
 * sem JSON cru — se um produto veio do Redis ou do MySQL.
 *
 * O gráfico de barras aqui é feito só com HTML + CSS (a largura de cada
 * barra é calculada em PHP como uma porcentagem do maior valor) — sem
 * nenhuma biblioteca de gráficos, seguindo a mesma filosofia "sem mágica
 * escondida" do resto do projeto.
 */

declare(strict_types=1);

$caminhoDoResultado = __DIR__ . '/../benchmark/ultimo_resultado.json';
$resultadoDoBenchmark = null;

if (file_exists($caminhoDoResultado)) {
    // json_decode com "true" no segundo argumento devolve array associativo
    // (em vez de um objeto stdClass), do mesmo jeito que já fazemos com os
    // dados vindos do Redis no ProdutoRepository.
    $resultadoDoBenchmark = json_decode(file_get_contents($caminhoDoResultado), true);
}

/**
 * Calcula a largura (em %) que a barra de um valor deve ter na tela,
 * relativa ao maior valor entre os dois cenários — ou seja, o cenário
 * mais lento sempre ocupa 100% da trilha, e o outro fica proporcional.
 */
function larguraDaBarra(float $valor, float $maiorValor): float
{
    return $maiorValor > 0 ? ($valor / $maiorValor) * 100 : 0;
}

$tituloDaPagina = 'Performance';
$subtituloDaPagina = 'O resultado do benchmark Cache-Aside, e um testador ao vivo pra ver a diferença na prática';
$paginaAtiva = 'performance';
require __DIR__ . '/../src/views/cabecalho.php';
?>

<?php if ($resultadoDoBenchmark === null): ?>

    <div class="aviso">
        <span class="icone">ℹ️</span>
        <div>
            <strong>Ainda não existe nenhum resultado de benchmark salvo.</strong>
            Rode <code>docker compose exec php php benchmark/benchmark.php</code> e recarregue esta página
            pra ver o gráfico comparando "com cache" e "sem cache".
        </div>
    </div>

<?php else:
    $semCache = $resultadoDoBenchmark['sem_cache'];
    $comCache = $resultadoDoBenchmark['com_cache'];
    $maiorMedia = max($semCache['media'], $comCache['media']);
?>

    <div class="placar-grade">
        <div class="card">
            <div class="placar-numero"><?= number_format($resultadoDoBenchmark['vezes_mais_rapido'], 1) ?>x</div>
            <div class="placar-rotulo">mais rápido com cache (média)</div>
        </div>
        <div class="card">
            <div class="placar-numero"><?= number_format($semCache['media'], 1) ?> ms</div>
            <div class="placar-rotulo">média sem cache</div>
        </div>
        <div class="card">
            <div class="placar-numero"><?= number_format($comCache['media'], 1) ?> ms</div>
            <div class="placar-rotulo">média com cache</div>
        </div>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Tempo médio de resposta: com cache x sem cache</h2>

        <div class="grafico-barra-linha">
            <span class="rotulo-serie">Sem cache</span>
            <div class="grafico-barra-trilha">
                <div class="grafico-barra-preenchimento cor-critica" style="width: <?= larguraDaBarra($semCache['media'], $maiorMedia) ?>%"></div>
            </div>
            <span class="grafico-barra-valor"><?= number_format($semCache['media'], 2) ?> ms</span>
        </div>

        <div class="grafico-barra-linha">
            <span class="rotulo-serie">Com cache</span>
            <div class="grafico-barra-trilha">
                <div class="grafico-barra-preenchimento cor-boa" style="width: <?= larguraDaBarra($comCache['media'], $maiorMedia) ?>%"></div>
            </div>
            <span class="grafico-barra-valor"><?= number_format($comCache['media'], 2) ?> ms</span>
        </div>

        <div class="grafico-legenda">
            <span class="item"><span class="amostra" style="background-color: var(--status-critico)"></span> Sem cache (sempre vai no MySQL)</span>
            <span class="item"><span class="amostra" style="background-color: var(--status-bom)"></span> Com cache (Cache-Aside normal)</span>
        </div>

        <p style="color: var(--tinta-secundaria); font-size: 0.85rem; margin-top: 1rem;">
            Gerado em <?= htmlspecialchars($resultadoDoBenchmark['gerado_em']) ?> ·
            <?= (int) $resultadoDoBenchmark['total_de_requisicoes_por_cenario'] ?> requisições por cenário
            (<?= (int) $resultadoDoBenchmark['requisicoes_simultaneas_por_lote'] ?> simultâneas por lote) ·
            ver metodologia completa em <a href="https://github.com/jmarciosilva/php-redis-exemplo/blob/main/ANALISE.md">ANALISE.md</a>
        </p>

        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Cenário</th>
                        <th class="numerico">Média (ms)</th>
                        <th class="numerico">Mín (ms)</th>
                        <th class="numerico">Máx (ms)</th>
                        <th class="numerico">p95 (ms)</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Sem cache</td>
                        <td class="numerico"><?= number_format($semCache['media'], 2) ?></td>
                        <td class="numerico"><?= number_format($semCache['minimo'], 2) ?></td>
                        <td class="numerico"><?= number_format($semCache['maximo'], 2) ?></td>
                        <td class="numerico"><?= number_format($semCache['p95'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>Com cache</td>
                        <td class="numerico"><?= number_format($comCache['media'], 2) ?></td>
                        <td class="numerico"><?= number_format($comCache['minimo'], 2) ?></td>
                        <td class="numerico"><?= number_format($comCache['maximo'], 2) ?></td>
                        <td class="numerico"><?= number_format($comCache['p95'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

<?php endif; ?>

<div class="card">
    <h2 style="margin-top:0;">Testador ao vivo</h2>
    <p style="color: var(--tinta-secundaria);">
        Digite um id de produto (experimente de 1 a 5000) e clique em "Buscar". Buscando o
        <strong>mesmo id de novo</strong>, a origem deve trocar de <code>mysql</code> pra <code>redis</code> —
        e o tempo de resposta cair bastante. Use "Limpar cache desse id" pra forçar um miss de novo, sem esperar o TTL expirar.
    </p>

    <div class="linha-formulario">
        <label for="campo-id">Id do produto:</label>
        <input type="number" id="campo-id" min="1" value="1">
        <button type="button" class="botao" id="botao-buscar">Buscar produto</button>
        <button type="button" class="botao botao-fantasma" id="botao-limpar-cache">Limpar cache desse id</button>
    </div>

    <div class="resultado-teste" id="resultado-teste">
        <p style="color: var(--tinta-fraca);">Nenhuma busca ainda. Clique em "Buscar produto" acima.</p>
    </div>

    <ul class="historico-testes" id="historico-testes"></ul>
</div>

<script src="/assets/js/performance.js"></script>

<?php require __DIR__ . '/../src/views/rodape.php'; ?>
