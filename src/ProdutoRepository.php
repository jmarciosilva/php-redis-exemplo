<?php

/*
 * Um "Repository" é só um nome chique pra uma classe cuja única
 * responsabilidade é buscar/salvar dados de UMA entidade específica —
 * aqui, produtos. A ideia é que o resto da aplicação nunca escreva SQL
 * (nem comando de Redis) direto: ela só chama métodos como
 * "buscarPorId()" e não precisa saber COMO o dado é buscado.
 *
 * A partir desta Fase 5, esse repositório implementa o padrão
 * **Cache-Aside**:
 *
 *   1. Primeiro, procura o produto no Redis.
 *   2. Se achou, devolve na hora (sem tocar no MySQL).
 *   3. Se não achou, busca no MySQL.
 *   4. Antes de devolver, guarda o resultado no Redis (com expiração),
 *      pra que a PRÓXIMA busca pelo mesmo id já venha do cache.
 *
 * Repare que o MySQL continua sendo a "fonte da verdade" — o Redis é só
 * uma cópia temporária, mais rápida de ler, dos dados que já sabemos.
 */

declare(strict_types=1);

class ProdutoRepository
{
    // Tempo (em segundos) que um produto fica guardado no cache antes de
    // expirar sozinho. Escolhemos 300s (5 minutos) porque é um meio-termo:
    // é tempo suficiente pra aliviar MUITO a carga no MySQL quando um
    // produto fica popular (várias pessoas pedindo o mesmo id em sequência),
    // mas não é tanto tempo a ponto do dado ficar perigosamente desatualizado
    // se o preço ou o estoque mudarem. Na Fase 9 (invalidação de cache) a
    // gente vai ver que TTL sozinho não é suficiente pra todos os casos.
    private const TTL_CACHE_EM_SEGUNDOS = 300;

    // Guardamos as duas conexões recebidas de fora (injeção de dependência):
    // a classe não precisa saber DE ONDE vieram, só usa as duas prontas.
    private PDO $pdo;
    private Redis $redis;

    // Guarda de onde veio o resultado da ÚLTIMA chamada a buscarPorId():
    // 'redis' (veio do cache) ou 'mysql' (precisou consultar o banco).
    // É só um jeito simples de deixar essa informação disponível pra quem
    // chamou o repositório (o public/produto.php usa isso pra mostrar no
    // JSON de resposta), sem misturar esse detalhe dentro dos dados do
    // próprio produto.
    private ?string $origemDaUltimaBusca = null;

    public function __construct(PDO $pdo, Redis $redis)
    {
        $this->pdo = $pdo;
        $this->redis = $redis;
    }

    public function origemDaUltimaBusca(): ?string
    {
        return $this->origemDaUltimaBusca;
    }

    /**
     * Busca um único produto pelo id, usando o padrão Cache-Aside.
     *
     * Devolve um array associativo com os dados do produto, ou null se
     * não existir nenhum produto com esse id (nem no cache, nem no banco).
     */
    public function buscarPorId(int $id): ?array
    {
        // Estratégia de chave: "produto:{id}", por exemplo "produto:42".
        // Prefixar com "produto:" é uma convenção comum no Redis (às vezes
        // chamada de "namespacing") — evita que essa chave colida com
        // outras chaves que a gente for criar mais pra frente (por exemplo,
        // "listagem:categoria:livros" na Fase 8) dentro do mesmo Redis.
        $chave = "produto:{$id}";

        // --- Passo 1: tenta achar no Redis primeiro ---
        $produtoEmCache = $this->redis->get($chave);

        // O phpredis devolve "false" quando a chave não existe (ou expirou).
        // Se vier qualquer coisa diferente de false, é porque achamos o
        // produto no cache.
        if ($produtoEmCache !== false) {
            $this->origemDaUltimaBusca = 'redis';

            // No Redis, tudo é guardado como string — por isso, lá na frente
            // (passo 3), guardamos o produto como uma string JSON. Aqui a
            // gente faz o caminho inverso: transforma essa string JSON de
            // volta em array associativo (o "true" no final pede exatamente
            // isso — sem ele, json_decode devolveria um objeto stdClass).
            return json_decode($produtoEmCache, true);
        }

        // --- Passo 2: não tava no cache, então busca no MySQL ---
        $this->origemDaUltimaBusca = 'mysql';

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
        $consulta->execute(['id' => $id]);

        // fetch() pega UMA linha do resultado (já configuramos, lá no
        // config/database.php, pra vir como array associativo). Se não
        // encontrar nenhuma linha, o PDO devolve "false".
        $produto = $consulta->fetch();

        // Se não existe esse produto nem no MySQL, não tem o que cachear —
        // devolvemos null direto (convertendo o "false" do PDO pra "null",
        // que é mais claro de entender pra quem for usar esse método).
        if ($produto === false) {
            return null;
        }

        // --- Passo 3: guarda no Redis pra próxima vez vir do cache ---
        // setex() = "SET com EXpiração": grava a chave já com um tempo de
        // vida (em segundos). Depois desse tempo, o próprio Redis apaga a
        // chave sozinho — a gente não precisa fazer limpeza manual.
        // json_encode transforma nosso array PHP numa string (formato que
        // o Redis entende), já que Redis não guarda arrays diretamente.
        $this->redis->setex($chave, self::TTL_CACHE_EM_SEGUNDOS, json_encode($produto));

        return $produto;
    }
}
