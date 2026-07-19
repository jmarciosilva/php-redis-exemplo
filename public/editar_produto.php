<?php

/*
 * Essa página é um "laboratório" pra ver o problema de dado desatualizado
 * (stale) na prática, e como a invalidação de cache resolve ele.
 *
 * O fluxo pra testar:
 *   1. Veja um produto primeiro (em /produto.php?id=X ou no testador ao
 *      vivo de /performance.php) — isso guarda ele no Redis.
 *   2. Carregue o MESMO produto aqui, mude o preço/estoque, e clique em
 *      "Salvar SEM invalidar" — o MySQL muda, mas o Redis continua com o
 *      valor ANTIGO por até 300s (o TTL de produto:{id}).
 *   3. Veja o produto de novo em /produto.php?id=X: repare que ele ainda
 *      mostra o valor velho, mesmo o MySQL já tendo o novo — isso é o bug.
 *   4. Volte aqui, mude de novo, e clique em "Salvar COM invalidação" —
 *      dessa vez o Redis é atualizado na hora.
 *   5. Veja o produto de novo: agora já reflete o valor novo imediatamente.
 *
 * Esta página sempre lê o MySQL direto (via buscarPorIdSemCache()), nunca
 * o Redis, pra sempre mostrar a "fonte da verdade" — e também mostra, lado
 * a lado, o que o Redis tem guardado NESTE EXATO MOMENTO pra esse produto,
 * pra você conseguir ver com os próprios olhos quando os dois divergem.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/ProdutoRepository.php';

$pdo = require __DIR__ . '/../config/database.php';
$redis = require __DIR__ . '/../config/redis.php';
$repositorio = new ProdutoRepository($pdo, $redis);

// --- Processa o formulário de edição, se veio um POST ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idEnviado = (int) ($_POST['id'] ?? 0);
    $nomeEnviado = trim((string) ($_POST['nome'] ?? ''));
    $precoEnviado = (float) ($_POST['preco'] ?? 0);
    $estoqueEnviado = (int) ($_POST['estoque'] ?? 0);

    // O nome do botão clicado ("acao") diz qual dos dois métodos chamar —
    // veja no HTML mais abaixo os dois <button> com o mesmo name="acao".
    $acao = (string) ($_POST['acao'] ?? '');

    if ($idEnviado > 0 && $nomeEnviado !== '') {
        if ($acao === 'com_invalidacao') {
            $repositorio->atualizarComInvalidacaoDeCache($idEnviado, $nomeEnviado, $precoEnviado, $estoqueEnviado);
        } else {
            $repositorio->atualizarSemInvalidarCache($idEnviado, $nomeEnviado, $precoEnviado, $estoqueEnviado);
        }
    }

    // Padrão "Post/Redirect/Get": depois de processar o POST, redirecionamos
    // de volta pra essa mesma página via GET. Isso evita que, se o usuário
    // der F5 na página, o navegador pergunte se quer "reenviar o formulário"
    // (e acabe salvando a mesma alteração duas vezes sem querer).
    header('Location: /editar_produto.php?id=' . $idEnviado . '&acao=' . urlencode($acao));
    exit;
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$acaoRealizada = isset($_GET['acao']) ? (string) $_GET['acao'] : null;

$produtoAtual = null;
$cacheAtual = null;
$cacheTtl = null;

if ($id > 0) {
    // Sempre a fonte da verdade — nunca passa pelo Redis.
    $produtoAtual = $repositorio->buscarPorIdSemCache($id);

    // Espiando o Redis diretamente (só pra mostrar na tela, não faz parte
    // do fluxo normal da aplicação) — pra comparar com o que está no MySQL.
    $chaveDoCache = "produto:{$id}";
    $valorBrutoNoCache = $redis->get($chaveDoCache);

    if ($valorBrutoNoCache !== false) {
        $cacheAtual = json_decode($valorBrutoNoCache, true);
        $cacheTtl = $redis->ttl($chaveDoCache);
    }
}

// Compara campo a campo se o que está no Redis ainda bate com o MySQL.
// (string) dos dois lados porque o PDO devolve DECIMAL como string, e o
// json_decode do valor cacheado preserva esse mesmo formato.
$cacheDesatualizado = false;
if ($produtoAtual !== null && $cacheAtual !== null) {
    $cacheDesatualizado = (string) $produtoAtual['nome'] !== (string) $cacheAtual['nome']
        || (string) $produtoAtual['preco'] !== (string) $cacheAtual['preco']
        || (string) $produtoAtual['estoque'] !== (string) $cacheAtual['estoque'];
}

$tituloDaPagina = 'Editar produto (invalidação de cache)';
$subtituloDaPagina = 'Simule uma atualização e veja o Redis ficar (ou não) desatualizado';
$paginaAtiva = 'editar';
require __DIR__ . '/../src/views/cabecalho.php';
?>

<div class="aviso">
    <span class="icone">🧪</span>
    <div>
        <strong>Como testar:</strong> primeiro visite um produto em
        <a href="/produto.php?id=1">/produto.php?id=1</a> (isso guarda ele no cache). Depois carregue o
        mesmo id aqui embaixo, mude o preço/estoque e salve <strong>sem invalidar</strong> —
        volte no <code>/produto.php</code> e repare que o valor antigo continua vindo por até 300s.
        Salve de novo <strong>com invalidação</strong> e veja o valor novo aparecer na hora.
    </div>
</div>

<?php if ($acaoRealizada === 'com_invalidacao'): ?>
    <div class="aviso" style="border-color: var(--status-bom); background-color: var(--status-bom-fundo);">
        <span class="icone">✅</span>
        <div><strong>Salvo com invalidação.</strong> O cache desse produto (e de todas as listagens) foi apagado — a próxima leitura já vem atualizada.</div>
    </div>
<?php elseif ($acaoRealizada === 'sem_invalidar'): ?>
    <div class="aviso" style="border-color: var(--status-critico); background-color: var(--status-critico-fundo);">
        <span class="icone">⚠️</span>
        <div><strong>Salvo SEM invalidar.</strong> O MySQL já tem o valor novo, mas o Redis (se esse produto já estava em cache) ainda vai devolver o valor antigo até o TTL expirar.</div>
    </div>
<?php endif; ?>

<div class="card">
    <form class="linha-formulario" method="get" action="/editar_produto.php">
        <label for="id">Id do produto:</label>
        <input type="number" name="id" id="id" min="1" value="<?= $id > 0 ? $id : '' ?>">
        <button type="submit" class="botao botao-fantasma">Carregar</button>
    </form>
</div>

<?php if ($id > 0 && $produtoAtual === null): ?>
    <div class="aviso">
        <span class="icone">❌</span>
        <div>Nenhum produto encontrado com id <?= $id ?>.</div>
    </div>
<?php elseif ($produtoAtual !== null): ?>

    <div class="card">
        <h2 style="margin-top:0;">O que cada lado tem AGORA para o produto <?= $id ?></h2>

        <div class="tabela-wrapper">
            <table>
                <thead>
                    <tr>
                        <th>Fonte</th>
                        <th>Nome</th>
                        <th class="numerico">Preço</th>
                        <th class="numerico">Estoque</th>
                        <th>Detalhe</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="badge badge-mysql">🗄️ MySQL</span></td>
                        <td><?= htmlspecialchars($produtoAtual['nome']) ?></td>
                        <td class="numerico">R$ <?= number_format((float) $produtoAtual['preco'], 2, ',', '.') ?></td>
                        <td class="numerico"><?= (int) $produtoAtual['estoque'] ?></td>
                        <td>sempre a fonte da verdade</td>
                    </tr>
                    <tr>
                        <td><span class="badge badge-redis">⚡ Redis</span></td>
                        <?php if ($cacheAtual !== null): ?>
                            <td><?= htmlspecialchars($cacheAtual['nome']) ?></td>
                            <td class="numerico">R$ <?= number_format((float) $cacheAtual['preco'], 2, ',', '.') ?></td>
                            <td class="numerico"><?= (int) $cacheAtual['estoque'] ?></td>
                            <td>expira em <?= $cacheTtl ?>s</td>
                        <?php else: ?>
                            <td colspan="3" style="color: var(--tinta-fraca);">nada em cache agora (nunca visitado, ou já expirou)</td>
                            <td></td>
                        <?php endif; ?>
                    </tr>
                </tbody>
            </table>
        </div>

        <?php if ($cacheDesatualizado): ?>
            <div class="aviso" style="margin-top: 1rem; margin-bottom: 0; border-color: var(--status-critico); background-color: var(--status-critico-fundo);">
                <span class="icone">⚠️</span>
                <div><strong>O Redis está desatualizado em relação ao MySQL!</strong> É exatamente esse o problema que a invalidação de cache resolve.</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <h2 style="margin-top:0;">Editar</h2>
        <form method="post" action="/editar_produto.php">
            <input type="hidden" name="id" value="<?= $id ?>">

            <div class="linha-formulario">
                <label for="nome">Nome:</label>
                <input type="text" name="nome" id="nome" value="<?= htmlspecialchars($produtoAtual['nome']) ?>" style="flex-grow: 1;">
            </div>

            <div class="linha-formulario">
                <label for="preco">Preço (R$):</label>
                <input type="number" name="preco" id="preco" step="0.01" min="0" value="<?= htmlspecialchars((string) $produtoAtual['preco']) ?>">

                <label for="estoque">Estoque:</label>
                <input type="number" name="estoque" id="estoque" min="0" value="<?= (int) $produtoAtual['estoque'] ?>">
            </div>

            <div class="linha-formulario">
                <button type="submit" name="acao" value="sem_invalidar" class="botao botao-fantasma">
                    Salvar SEM invalidar cache (demonstra o bug)
                </button>
                <button type="submit" name="acao" value="com_invalidacao" class="botao">
                    Salvar COM invalidação (correto)
                </button>
            </div>
        </form>
    </div>

<?php endif; ?>

<?php require __DIR__ . '/../src/views/rodape.php'; ?>
