<?php

/*
 * Página de listagem de produtos: uma tabela HTML de verdade, com filtro
 * por categoria e paginação — pensada tanto pra um dev júnior quanto pra
 * quem só quer ver a aplicação funcionando visualmente (sem precisar ler
 * JSON no terminal).
 *
 * ESSA PÁGINA NUNCA USA REDIS — de propósito (chama listarPaginado(), o
 * método "baseline" do ProdutoRepository). Ela existe pra ficar como
 * referência permanente de comparação ao lado de produtos_cache.php (Fase
 * 9), que mostra a mesma listagem, só que com Cache-Aside. Assim dá pra
 * comparar os dois tempos lado a lado a qualquer momento, sem depender do
 * cache estar "quente" ou "frio" na hora.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/ProdutoRepository.php';

$pdo = require __DIR__ . '/../config/database.php';
$redis = require __DIR__ . '/../config/redis.php';
$repositorio = new ProdutoRepository($pdo, $redis);

// Quantos produtos mostrar por página. Fixo por enquanto (não vem da URL)
// pra manter a página de cache simples de prever mais pra frente.
const PRODUTOS_POR_PAGINA = 20;

// max(1, ...) garante que nunca vamos tentar acessar a "página 0" ou
// negativa, mesmo que alguém digite isso na URL na mão.
$pagina = max(1, (int) ($_GET['pagina'] ?? 1));

// Se o parâmetro "categoria" vier vazio (ex.: opção "Todas" do filtro),
// tratamos como "sem filtro" (null), não como uma categoria de nome vazio.
$categoriaSelecionada = isset($_GET['categoria']) && $_GET['categoria'] !== ''
    ? (string) $_GET['categoria']
    : null;

$categoriasDisponiveis = $repositorio->listarCategorias();

// Marca o instante antes de buscar os dados, pra medir quanto tempo a
// consulta no MySQL levou de verdade — o mesmo tipo de medição que já
// fazemos em produto.php. Essa página nunca cacheia (ver produtos_cache.php,
// da Fase 9, pra comparar lado a lado), então esse tempo nunca deve cair.
$inicioDaConsulta = microtime(true);

$resultado = $repositorio->listarPaginado($pagina, PRODUTOS_POR_PAGINA, $categoriaSelecionada);

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

    return '/produtos.php?' . http_build_query($parametros);
}

$tituloDaPagina = 'Produtos (sem cache)';
$subtituloDaPagina = "{$totalDeProdutos} produtos no catálogo — consulta direto no MySQL, sempre, de propósito";
$paginaAtiva = 'produtos';
require __DIR__ . '/../src/views/cabecalho.php';
?>

<div class="aviso">
    <span class="icone">⚠️</span>
    <div>
        <strong>Essa é a versão SEM cache, de propósito.</strong>
        Toda vez que você troca de página ou de categoria, é uma consulta nova no MySQL — esse tempo nunca deve cair.
        Compare com a <a href="/produtos_cache.php">versão com cache (Cache-Aside)</a> pra ver a diferença.
    </div>
</div>

<?php
// Mesmo mapeamento de ícone/classe usado no JS de performance.php — aqui é
// PHP porque essa página é renderizada no servidor, não via fetch.
$badgeDaOrigem = $origemDaListagem === 'redis'
    ? ['classe' => 'badge-redis', 'icone' => '⚡', 'texto' => 'Redis (cache)']
    : ['classe' => 'badge-mysql', 'icone' => '🗄️', 'texto' => 'MySQL (banco)'];
?>
<p style="color: var(--tinta-secundaria); margin-bottom: 1.5rem;">
    Esta listagem foi gerada via
    <span class="badge <?= $badgeDaOrigem['classe'] ?>"><?= $badgeDaOrigem['icone'] ?> <?= $badgeDaOrigem['texto'] ?></span>
    em <strong><?= $tempoDaConsultaMs ?> ms</strong>.
</p>

<form class="linha-formulario" method="get" action="/produtos.php">
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
