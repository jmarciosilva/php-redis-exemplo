<?php

/*
 * Um "Repository" é só um nome chique pra uma classe cuja única
 * responsabilidade é buscar/salvar dados de UMA entidade específica —
 * aqui, produtos. A ideia é que o resto da aplicação nunca escreva SQL
 * direto: ela só chama métodos como "buscarPorId()" e não precisa saber
 * COMO o dado é buscado (se é do MySQL, do Redis, ou de onde for).
 *
 * Nesta Fase 4, esse repositório só sabe conversar com o MySQL — é
 * exatamente o "estado ruim" que a gente quer medir no benchmark antes
 * de colocar o Redis no meio (Fase 5). Toda chamada aqui bate direto no
 * banco de dados, sem nenhum cache.
 */

declare(strict_types=1);

class ProdutoRepository
{
    // Guardamos a conexão PDO recebida de fora (em vez de criar uma nova
    // aqui dentro). Isso se chama "injeção de dependência": a classe não
    // precisa saber DE ONDE veio a conexão, só usa ela.
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Busca um único produto pelo id, direto no MySQL.
     *
     * Devolve um array associativo com os dados do produto, ou null se
     * não existir nenhum produto com esse id.
     */
    public function buscarPorId(int $id): ?array
    {
        // "prepare" monta a consulta com um placeholder (:id) no lugar do
        // valor. Isso é o que chamamos de "prepared statement": o PDO
        // manda a consulta e o valor SEPARADAMENTE pro MySQL, então não
        // tem como um valor malicioso (ex.: "1; DROP TABLE produtos") virar
        // parte do comando SQL. É assim que a gente evita SQL Injection —
        // NUNCA concatene variáveis direto dentro de uma string SQL.
        $consulta = $this->pdo->prepare(
            'SELECT id, nome, descricao, categoria, preco, estoque
             FROM produtos
             WHERE id = :id'
        );

        // Aqui sim entra o valor de verdade, no lugar do placeholder ":id".
        $consulta->execute(['id' => $id]);

        // fetch() pega UMA linha do resultado (já configuramos, lá no
        // config/database.php, pra vir como array associativo). Se não
        // encontrar nenhuma linha, o PDO devolve "false".
        $produto = $consulta->fetch();

        // Convertendo "false" (nada encontrado) pra "null", que é mais
        // claro de entender pra quem for usar esse método depois
        // (o tipo de retorno "?array" já avisa: "ou é um array, ou é null").
        return $produto === false ? null : $produto;
    }
}
