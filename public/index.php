<?php

/*
 * Página de diagnóstico do ambiente: nasceu na Fase 1 só pra confirmar que
 * Nginx + PHP-FPM + MySQL + Redis estavam todos conversando entre si, e
 * continua útil como uma "página de saúde" do projeto — se algum dia algo
 * parar de funcionar depois de mexer no Docker, é aqui que você confere
 * o que quebrou primeiro.
 *
 * A lógica das checagens em si mora em src/diagnostico.php (função
 * executarChecagensDeAmbiente()), reaproveitada por esse arquivo (pro
 * carregamento inicial, que funciona mesmo sem JavaScript) e por
 * public/diagnostico.php (o endpoint JSON que o botão "Testar agora" usa
 * pra rodar tudo de novo sem recarregar a página).
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/diagnostico.php';

$testes = executarChecagensDeAmbiente();

header('Content-Type: text/html; charset=utf-8');

$tituloDaPagina = 'Diagnóstico do ambiente';
$subtituloDaPagina = 'Checagem rápida de que Nginx + PHP-FPM + MySQL + Redis estão todos conversando entre si.';
$paginaAtiva = 'inicio';
require __DIR__ . '/../src/views/cabecalho.php';
?>

<div class="linha-formulario">
    <button type="button" class="botao" id="botao-testar">🔄 Testar agora</button>
    <span style="color: var(--tinta-secundaria); font-size: 0.9rem;" id="ultima-checagem">
        última checagem: ao carregar a página
    </span>
</div>

<div class="card">
    <div class="tabela-wrapper">
        <table>
            <thead>
                <tr>
                    <th>Situação</th>
                    <th>Verificação</th>
                    <th>Detalhe</th>
                    <th class="numerico">Tempo</th>
                </tr>
            </thead>
            <tbody id="corpo-da-tabela-diagnostico">
                <?php foreach ($testes as $nome => $resultado): ?>
                    <tr>
                        <td><?= $resultado['ok'] ? '✅' : '❌' ?></td>
                        <td><strong><?= htmlspecialchars($nome) ?></strong></td>
                        <td><?= htmlspecialchars($resultado['mensagem']) ?></td>
                        <td class="numerico"><?= $resultado['tempo_ms'] !== null ? $resultado['tempo_ms'] . ' ms' : '—' ?></td>
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

<script src="/assets/js/diagnostico.js"></script>

<?php require __DIR__ . '/../src/views/rodape.php'; ?>
