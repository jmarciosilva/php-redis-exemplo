<?php

/*
 * Mesma listagem de produtos que produtos.php, só que usando
 * listarPaginadoComCache() — o método do ProdutoRepository que implementa
 * o padrão Cache-Aside pra LISTAS (Fase 9), não só pra item único (Fase 5).
 *
 * Por que uma página SEPARADA, em vez de só trocar o método dentro de
 * produtos.php? Pra deixar as duas versões vivas ao mesmo tempo — assim um
 * dev júnior (ou um recrutador) sempre consegue comparar "com cache" vs
 * "sem cache" lado a lado, sem depender de estado de cache "quente" ou
 * "frio" na hora que olhar. Repare que os dois arquivos são quase
 * idênticos — a ÚNICA diferença de verdade é a linha que chama
 * listarPaginadoComCache() em vez de listarPaginado().
 *
 * Recarregue a página duas vezes com o mesmo filtro/página: na primeira
 * (ou depois de 60s, quando o cache dessa combinação expira) a origem é
 * 'mysql'; nas seguintes, vira 'redis' e o tempo cai bastante.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/ProdutoRepository.php';

$pdo = require __DIR__ . '/../config/database.php';
$redis = require __DIR__ . '/../config/redis.php';
$repositorio = new ProdutoRepository($pdo, $redis);

// Precisa ser o MESMO valor usado em produtos.php — a chave de cache leva
// o tamanho de página em conta, então tamanhos diferentes gerariam chaves
// (e portanto comparações) diferentes.
const PRODUTOS_POR_PAGINA = 20;

$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

$categoriaSelecionada = isset($_GET['categoria']) && $_GET['categoria'] !== ''
    ? (string) $_GET['categoria']
    : null;

$categoriasDisponiveis = $repositorio->listarCategorias();

$inicioDaConsulta = microtime(true);

// A ÚNICA diferença real em relação a produtos.php: chamamos a versão
// COM cache do repositório.
$resultado = $repositorio->listarPaginadoComCache($pagina, PRODUTOS_POR_PAGINA, $categoriaSelecionada);

$tempoDaConsultaMs = round((microtime(true) - $inicioDaConsulta) * 1000, 2);
$origemDaListagem = $repositorio->origemDaUltimaListagem();

$produtos = $resultado['produtos'];
$totalDeProdutos = $resultado['total'];
$totalDePaginas = max(1, (int) ceil($totalDeProdutos / PRODUTOS_POR_PAGINA));

/**
 * Monta a URL desta mesma página trocando só o parâmetro "pagina",
 * mantendo o filtro de categoria atual (se tiver algum).
 */
function urlDaPagina(int $numeroDaPagina, ?string $categoria): string
{
    $parametros = ['pagina' => $numeroDaPagina];

    if ($categoria !== null) {
        $parametros['categoria'] = $categoria;
    }

    return '/produtos_cache.php?' . http_build_query($parametros);
}

$tituloDaPagina = 'Produtos (com cache)';
$subtituloDaPagina = "{$totalDeProdutos} produtos no catálogo — listagem cacheada no Redis (Cache-Aside, TTL de 60s)";
$paginaAtiva = 'produtos_cache';
require __DIR__ . '/../src/views/cabecalho.php';
?>

<div class="aviso">
    <span class="icone">⚡</span>
    <div>
        <strong>Essa é a versão COM cache (Cache-Aside de listagem).</strong>
        A primeira vez que uma combinação de página + categoria é pedida, vem do MySQL e fica guardada no Redis por 60s.
        Recarregue a página com o mesmo filtro: a origem deve virar <code>redis</code> e o tempo cair bastante.
        Compare com a <a href="/produtos.php">versão sem cache</a>.
    </div>
</div>

<?php
$badgeDaOrigem = $origemDaListagem === 'redis'
    ? ['classe' => 'badge-redis', 'icone' => '⚡', 'texto' => 'Redis (cache)']
    : ['classe' => 'badge-mysql', 'icone' => '🗄️', 'texto' => 'MySQL (banco)'];
?>
<p style="color: var(--tinta-secundaria); margin-bottom: 1.5rem;">
    Esta listagem foi gerada via
    <span class="badge <?= $badgeDaOrigem['classe'] ?>"><?= $badgeDaOrigem['icone'] ?> <?= $badgeDaOrigem['texto'] ?></span>
    em <strong><?= $tempoDaConsultaMs ?> ms</strong>.
</p>

<form class="linha-formulario" method="get" action="/produtos_cache.php">
    <label for="categoria">Categoria:</label>
    <select name="categoria" id="categoria" onchange="this.form.submit()">
        <option value="">Todas</option>
        <?php foreach ($categoriasDisponiveis as $categoria): ?>
            <option value="<?= htmlspecialchars($categoria) ?>" <?= $categoria === $categoriaSelecionada ? 'selected' : '' ?>>
                <?= htmlspecialchars($categoria) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <noscript><button class="botao botao-fantasma" type="submit">Filtrar</button></noscript>
</form>

<div class="card">
    <div class="tabela-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Categoria</th>
                    <th class="numerico">Preço</th>
                    <th class="numerico">Estoque</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($produtos as $produto): ?>
                    <tr>
                        <td><?= (int) $produto['id'] ?></td>
                        <td><?= htmlspecialchars($produto['nome']) ?></td>
                        <td><?= htmlspecialchars($produto['categoria']) ?></td>
                        <td class="numerico">R$ <?= number_format((float) $produto['preco'], 2, ',', '.') ?></td>
                        <td class="numerico"><?= (int) $produto['estoque'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="paginacao">
    <?php if ($pagina > 1): ?>
        <a href="<?= htmlspecialchars(urlDaPagina($pagina - 1, $categoriaSelecionada)) ?>">← Anterior</a>
    <?php endif; ?>

    <span class="atual">Página <?= $pagina ?> de <?= $totalDePaginas ?></span>

    <?php if ($pagina < $totalDePaginas): ?>
        <a href="<?= htmlspecialchars(urlDaPagina($pagina + 1, $categoriaSelecionada)) ?>">Próxima →</a>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../src/views/rodape.php'; ?>
