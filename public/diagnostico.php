<?php

/*
 * Endpoint JSON usado pelo botão "🔄 Testar agora" da página de
 * diagnóstico (index.php). Roda exatamente as mesmas checagens que a
 * página já faz no carregamento inicial (usando o mesmo
 * executarChecagensDeAmbiente(), pra não duplicar lógica), só que devolve
 * o resultado em JSON, pra ser consumido via JavaScript sem precisar
 * recarregar a página inteira.
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/diagnostico.php';

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'verificado_em' => date('H:i:s'),
    'testes' => executarChecagensDeAmbiente(),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
