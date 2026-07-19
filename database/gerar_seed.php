<?php

/*
 * Esse script NÃO faz parte da aplicação em si — ele é uma FERRAMENTA de
 * desenvolvimento que a gente roda só UMA VEZ pra gerar o arquivo
 * database/produtos.sql (com a tabela + os dados de exemplo).
 *
 * Por que gerar via PHP em vez de escrever as 5 mil linhas de INSERT na mão?
 * Porque pra testar performance/cache de forma realista, "3 produtos de
 * exemplo" não é suficiente — precisamos de um volume razoável de dados
 * (aqui: 5.000 produtos) pra simular uma tabela de verdade.
 *
 * Como rodar (de dentro do container do PHP, já que é lá que o PHP existe):
 *   docker compose exec php php database/gerar_seed.php > database/produtos.sql
 *
 * O ">" ali redireciona a saída (tudo que a gente faz "echo") pro arquivo
 * produtos.sql. Ou seja: esse script "imprime" um SQL completo, e a gente
 * captura essa impressão dentro de um arquivo .sql de verdade.
 */

declare(strict_types=1);

// Quantidade de produtos fake que vamos gerar. Dá pra aumentar esse número
// se quiser testar com uma tabela ainda maior mais pra frente.
const QUANTIDADE_PRODUTOS = 5000;

// Quantos INSERTs a gente agrupa em uma única instrução SQL.
// Fazer 5.000 instruções INSERT separadas seria válido, mas bem mais lento
// de importar do que agrupar várias linhas dentro de um único INSERT.
const TAMANHO_DO_LOTE = 500;

// Lista de categorias possíveis. array_rand vai sortear uma dessas pra cada produto.
$categorias = [
    'Eletrônicos', 'Livros', 'Roupas', 'Casa', 'Esportes',
    'Beleza', 'Brinquedos', 'Alimentos', 'Informática', 'Móveis',
];

// Lista de adjetivos e "bases" de nome, só pra combinar e gerar nomes de
// produto que pareçam um pouco variados (ex.: "Fone Bluetooth Premium X123").
$adjetivos = ['Premium', 'Compacto', 'Profissional', 'Básico', 'Turbo', 'Clássico', 'Smart', 'Ultra'];
$bases = [
    'Eletrônicos' => ['Fone Bluetooth', 'Carregador USB-C', 'Caixa de Som', 'Smartwatch', 'Cabo HDMI'],
    'Livros' => ['Romance', 'Livro de Receitas', 'Biografia', 'Guia Prático', 'Quadrinho'],
    'Roupas' => ['Camiseta', 'Calça Jeans', 'Jaqueta', 'Vestido', 'Boné'],
    'Casa' => ['Jogo de Panelas', 'Luminária', 'Tapete', 'Cortina', 'Organizador'],
    'Esportes' => ['Bola de Futebol', 'Tênis de Corrida', 'Halteres', 'Bicicleta', 'Corda de Pular'],
    'Beleza' => ['Perfume', 'Creme Facial', 'Shampoo', 'Batom', 'Escova Elétrica'],
    'Brinquedos' => ['Quebra-Cabeça', 'Boneco de Ação', 'Jogo de Tabuleiro', 'Pelúcia', 'Carrinho'],
    'Alimentos' => ['Café em Grãos', 'Chocolate', 'Azeite', 'Barra de Cereal', 'Chá'],
    'Informática' => ['Mouse Sem Fio', 'Teclado Mecânico', 'Pendrive', 'Webcam', 'Hub USB'],
    'Móveis' => ['Cadeira', 'Estante', 'Mesa de Escritório', 'Sofá', 'Rack de TV'],
];

// Função que gera um preço aleatório entre 9.90 e 4999.90, com 2 casas decimais.
// mt_rand trabalha só com inteiros, então geramos centavos e dividimos por 100.
function precoAleatorio(): float
{
    return mt_rand(990, 499990) / 100;
}

// --- A partir daqui começamos a "imprimir" o arquivo SQL de verdade ---

echo "-- Arquivo gerado automaticamente por database/gerar_seed.php — não edite na mão!\n";
echo "-- Se precisar mudar os dados, ajuste o gerador e rode o comando de novo.\n\n";

// Isso aqui é importante e fácil de esquecer: o cliente "mysql" que o
// container usa pra importar esse arquivo automaticamente (na primeira vez
// que o MySQL sobe) usa "latin1" como charset padrão de sessão, mesmo que
// esse arquivo esteja salvo em UTF-8. Sem esse SET NAMES, cada caractere
// acentuado (ex.: "número") seria mal interpretado byte a byte e acabaria
// gravado errado no banco — um bug clássico de "mojibake" (tipo "nÃºmero").
// Com o SET NAMES, avisamos explicitamente pro MySQL: "os bytes que vêm a
// seguir são UTF-8, decodifique certo".
echo "SET NAMES utf8mb4;\n\n";

// Criamos a tabela só se ela ainda não existir — assim o script pode ser
// executado de novo sem quebrar caso a tabela já esteja lá.
echo "CREATE TABLE IF NOT EXISTS produtos (\n";
echo "    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,\n";         // identificador único de cada produto
echo "    nome VARCHAR(150) NOT NULL,\n";                          // nome exibido do produto
echo "    descricao TEXT NOT NULL,\n";                             // texto descritivo (mais longo, por isso TEXT e não VARCHAR)
echo "    categoria VARCHAR(50) NOT NULL,\n";                       // usado na Fase 8 (cache de listagem por categoria)
echo "    preco DECIMAL(10,2) NOT NULL,\n";                        // DECIMAL evita erro de arredondamento que FLOAT teria com dinheiro
echo "    estoque INT UNSIGNED NOT NULL DEFAULT 0,\n";             // quantidade disponível
echo "    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,\n";
echo "    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n";
echo "    INDEX idx_categoria (categoria)\n";                       // acelera consultas futuras que filtram por categoria
echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n\n";

// Percorremos os produtos em "lotes" (chunks), pra montar um único
// INSERT com várias linhas de valores por vez.
for ($inicioDoLote = 0; $inicioDoLote < QUANTIDADE_PRODUTOS; $inicioDoLote += TAMANHO_DO_LOTE) {
    $linhas = [];

    // Quantos produtos ainda faltam gerar dentro deste lote específico.
    $fimDoLote = min($inicioDoLote + TAMANHO_DO_LOTE, QUANTIDADE_PRODUTOS);

    for ($i = $inicioDoLote; $i < $fimDoLote; $i++) {
        // Sorteia uma categoria e, dentro dela, uma "base" de nome compatível
        // (ex.: categoria "Livros" só combina com bases tipo "Romance", "Biografia"...).
        $categoria = $categorias[array_rand($categorias)];
        $base = $bases[$categoria][array_rand($bases[$categoria])];
        $adjetivo = $adjetivos[array_rand($adjetivos)];

        // Nome final combina base + adjetivo + um número, pra não repetir nomes exatamente iguais.
        $nome = sprintf('%s %s %04d', $base, $adjetivo, $i + 1);
        $descricao = sprintf('Produto da categoria %s, ideal pro dia a dia. Item número %d do nosso catálogo de exemplo.', $categoria, $i + 1);
        $preco = precoAleatorio();
        $estoque = mt_rand(0, 500);

        // addslashes() escapa aspas simples pra não quebrar o SQL (aqui é só
        // pra gerar um arquivo estático de seed; na aplicação de verdade,
        // vamos usar PDO com prepared statements, nunca concatenação de string).
        $linhas[] = sprintf(
            "('%s', '%s', '%s', %.2f, %d)",
            addslashes($nome),
            addslashes($descricao),
            addslashes($categoria),
            $preco,
            $estoque
        );
    }

    echo "INSERT INTO produtos (nome, descricao, categoria, preco, estoque) VALUES\n";
    echo implode(",\n", $linhas) . ";\n\n";
}
