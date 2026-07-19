/*
 * JavaScript "puro" (sem nenhuma biblioteca) que dá vida ao "testador ao
 * vivo" da página performance.php. A ideia: você digita um id de produto,
 * clica em "Buscar" e vê na hora se a resposta veio do Redis ou do MySQL
 * — e o tempo que levou. Clicando de novo no MESMO id, a origem deve
 * trocar pra "redis" (porque a primeira busca já populou o cache).
 */

// Espera o HTML inteiro carregar antes de tentar pegar os elementos da
// página — sem isso, os document.getElementById() abaixo poderiam rodar
// antes desses elementos existirem ainda.
document.addEventListener('DOMContentLoaded', function () {
    const campoId = document.getElementById('campo-id');
    const botaoBuscar = document.getElementById('botao-buscar');
    const botaoLimparCache = document.getElementById('botao-limpar-cache');
    const areaResultado = document.getElementById('resultado-teste');
    const listaHistorico = document.getElementById('historico-testes');

    // Se por algum motivo esses elementos não existirem na página (ex.:
    // alguém reaproveitou esse script noutro lugar), a gente simplesmente
    // não faz nada, em vez de quebrar com um erro no console.
    if (!campoId || !botaoBuscar || !areaResultado) {
        return;
    }

    /**
     * Faz uma requisição pro endpoint /produto.php?id=... e devolve os
     * dados já convertidos de JSON pra objeto JavaScript. Trabalha tanto
     * pra resposta de sucesso (200) quanto de erro (400/404), porque nos
     * dois casos o produto.php devolve um corpo JSON.
     */
    async function buscarProduto(id) {
        const resposta = await fetch('/produto.php?id=' + encodeURIComponent(id));
        const dados = await resposta.json();
        return { ok: resposta.ok, dados: dados };
    }

    /**
     * Desenha o card de resultado na tela a partir da resposta do
     * endpoint. Usa a classe .badge-redis ou .badge-mysql (já definidas
     * em estilo.css) pra colorir a origem — sempre com um ícone + texto
     * junto, nunca só a cor, pra continuar acessível.
     */
    function mostrarResultado(id, resposta) {
        if (!resposta.ok) {
            areaResultado.innerHTML =
                '<p><strong>Erro:</strong> ' + escaparHtml(resposta.dados.erro) + '</p>';
            return;
        }

        const origem = resposta.dados.origem;
        const classeBadge = origem === 'redis' ? 'badge-redis' : 'badge-mysql';
        const iconeBadge = origem === 'redis' ? '⚡' : '🗄️';
        const textoBadge = origem === 'redis' ? 'Redis (cache)' : 'MySQL (banco)';

        areaResultado.innerHTML =
            '<p><strong>' + escaparHtml(resposta.dados.produto.nome) + '</strong></p>' +
            '<p>' +
            '<span class="badge ' + classeBadge + '">' + iconeBadge + ' ' + textoBadge + '</span> ' +
            '&nbsp;tempo de resposta: <strong>' + resposta.dados.tempo_resposta_ms + ' ms</strong>' +
            '</p>';

        adicionarNoHistorico(id, origem, resposta.dados.tempo_resposta_ms);
    }

    /**
     * Acrescenta uma linha no histórico (lista dos últimos testes feitos
     * nesta página), sempre no TOPO da lista, pra ficar fácil ver o
     * padrão "1ª vez mysql, depois sempre redis" se conferindo o mesmo id
     * várias vezes seguidas.
     */
    function adicionarNoHistorico(id, origem, tempoMs) {
        if (!listaHistorico) {
            return;
        }

        const item = document.createElement('li');
        const agora = new Date().toLocaleTimeString('pt-BR');

        item.innerHTML =
            '<span>' + agora + ' — produto ' + escaparHtml(String(id)) + '</span>' +
            '<span>' + escaparHtml(origem) + ' · ' + tempoMs + ' ms</span>';

        listaHistorico.insertBefore(item, listaHistorico.firstChild);

        // Mantém só os últimos 8 itens na tela, pra não crescer pra sempre.
        while (listaHistorico.children.length > 8) {
            listaHistorico.removeChild(listaHistorico.lastChild);
        }
    }

    // escapa caracteres especiais de HTML antes de inserir texto vindo do
    // servidor dentro do innerHTML — evita que um nome de produto com
    // caracteres tipo "<" quebre a página ou vire um XSS.
    function escaparHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    botaoBuscar.addEventListener('click', function () {
        const id = parseInt(campoId.value, 10);

        if (!id || id <= 0) {
            areaResultado.innerHTML = '<p>Digite um id válido (número maior que zero).</p>';
            return;
        }

        buscarProduto(id).then(function (resposta) {
            mostrarResultado(id, resposta);
        });
    });

    if (botaoLimparCache) {
        botaoLimparCache.addEventListener('click', function () {
            const id = parseInt(campoId.value, 10);

            if (!id || id <= 0) {
                return;
            }

            fetch('/limpar_cache.php?id=' + encodeURIComponent(id))
                .then(function (resposta) { return resposta.json(); })
                .then(function () {
                    areaResultado.innerHTML =
                        '<p>Cache do produto ' + escaparHtml(String(id)) + ' foi limpo. ' +
                        'Clique em "Buscar" de novo: a próxima origem deve ser <strong>mysql</strong>.</p>';
                });
        });
    }
});
