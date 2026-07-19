<?php
/*
 * Parcial de HTML reaproveitado por todas as páginas (index.php,
 * produtos.php, performance.php). A ideia é simples: em vez de copiar e
 * colar o mesmo <head> e a mesma barra de navegação em cada arquivo, a
 * gente escreve uma vez aqui e cada página faz um "require" pra incluir
 * esse pedaço.
 *
 * Esse arquivo fica FORA da pasta public/ de propósito: o Nginx só serve
 * arquivos que estão dentro de public/ (ver docker/nginx/default.conf),
 * então ninguém consegue acessar esse arquivo direto pela URL — ele só
 * funciona quando incluído por uma página que já está em public/.
 *
 * Quem inclui esse arquivo deve definir ANTES estas variáveis:
 *   $tituloDaPagina    (string) — vira o <title> e o texto do <h1>
 *   $paginaAtiva       (string) — 'inicio' | 'produtos' | 'produtos_cache' | 'performance',
 *                                  usado só pra destacar o link certo no menu
 *   $subtituloDaPagina (string, opcional) — uma linha de apoio abaixo do <h1>
 */
$subtituloDaPagina = $subtituloDaPagina ?? null;
?><!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($tituloDaPagina) ?> · php-redis-exemplo</title>
    <link rel="stylesheet" href="/assets/css/estilo.css">
</head>
<body>
    <nav class="nav-topo">
        <div class="container">
            <a class="marca" href="/">php-redis-exemplo</a>
            <ul>
                <li><a href="/" class="<?= $paginaAtiva === 'inicio' ? 'ativo' : '' ?>">Diagnóstico</a></li>
                <li><a href="/produtos.php" class="<?= $paginaAtiva === 'produtos' ? 'ativo' : '' ?>">Produtos (sem cache)</a></li>
                <li><a href="/produtos_cache.php" class="<?= $paginaAtiva === 'produtos_cache' ? 'ativo' : '' ?>">Produtos (com cache)</a></li>
                <li><a href="/performance.php" class="<?= $paginaAtiva === 'performance' ? 'ativo' : '' ?>">Performance</a></li>
            </ul>
        </div>
    </nav>
    <main class="container">
        <div class="cabecalho-pagina">
            <h1><?= htmlspecialchars($tituloDaPagina) ?></h1>
            <?php if ($subtituloDaPagina !== null): ?>
                <p><?= htmlspecialchars($subtituloDaPagina) ?></p>
            <?php endif; ?>
        </div>
        <!-- O conteúdo específico de cada página começa logo depois deste include -->

