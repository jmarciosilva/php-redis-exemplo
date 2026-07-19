/*
 * JavaScript puro (sem biblioteca nenhuma) que liga o botão "🔄 Testar
 * agora" da página de diagnóstico (index.php). Ao clicar, busca
 * /diagnostico.php via fetch e redesenha a tabela de checagens na hora,
 * sem recarregar a página inteira — e mostra quanto tempo cada checagem
 * levou dessa vez.
 */

document.addEventListener('DOMContentLoaded', function () {
    const botao = document.getElementById('botao-testar');
    const corpoDaTabela = document.getElementById('corpo-da-tabela-diagnostico');
    const ultimaChecagem = document.getElementById('ultima-checagem');

    if (!botao || !corpoDaTabela) {
        return;
    }

    // escapa caracteres especiais de HTML antes de inserir texto vindo do
    // servidor dentro do innerHTML — evita que uma mensagem de erro com
    // caracteres tipo "<" quebre a página ou vire um XSS.
    function escaparHtml(texto) {
        const div = document.createElement('div');
        div.textContent = texto;
        return div.innerHTML;
    }

    function desenharTabela(testes) {
        let html = '';

        for (const nome in testes) {
            const resultado = testes[nome];
            const icone = resultado.ok ? '✅' : '❌';
            const tempo = resultado.tempo_ms !== null ? resultado.tempo_ms + ' ms' : '—';

            html +=
                '<tr>' +
                '<td>' + icone + '</td>' +
                '<td><strong>' + escaparHtml(nome) + '</strong></td>' +
                '<td>' + escaparHtml(resultado.mensagem) + '</td>' +
                '<td class="numerico">' + tempo + '</td>' +
                '</tr>';
        }

        corpoDaTabela.innerHTML = html;
    }

    botao.addEventListener('click', function () {
        // Desabilita o botão e troca o texto enquanto espera a resposta,
        // só pra deixar claro que algo está acontecendo (evita cliques
        // repetidos disparando várias checagens ao mesmo tempo à toa).
        botao.disabled = true;
        botao.textContent = '⏳ Testando...';

        fetch('/diagnostico.php')
            .then(function (resposta) { return resposta.json(); })
            .then(function (dados) {
                desenharTabela(dados.testes);
                ultimaChecagem.textContent = 'última checagem: ' + dados.verificado_em;
            })
            .catch(function () {
                ultimaChecagem.textContent = 'última checagem: falhou ao testar (veja o console)';
            })
            .finally(function () {
                botao.disabled = false;
                botao.textContent = '🔄 Testar agora';
            });
    });
});
