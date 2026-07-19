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

    // Mesma ideia do $origemDaUltimaBusca, mas pra listagem (listarPaginado()):
    // guarda se a ÚLTIMA listagem veio do cache ou do banco. Por enquanto
    // sempre vale 'mysql' (a listagem ainda não usa Redis), mas já deixamos
    // esse "gancho" pronto agora — assim, quando a Fase 9 adicionar cache
    // de listagem, a página public/produtos.php (que já lê esse valor pra
    // mostrar o badge de origem + tempo) não vai precisar mudar NADA.
    private ?string $origemDaUltimaListagem = null;

    public function __construct(PDO $pdo, Redis $redis)
    {
        $this->pdo = $pdo;
        $this->redis = $redis;
    }

    public function origemDaUltimaBusca(): ?string
    {
        return $this->origemDaUltimaBusca;
    }

    public function origemDaUltimaListagem(): ?string
    {
        return $this->origemDaUltimaListagem;
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
        // "listagem:categoria:livros" na Fase 9) dentro do mesmo Redis.
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

    /**
     * Lista produtos de forma paginada, opcionalmente filtrando por
     * categoria. Devolve um array com duas chaves:
     *   'produtos' => a lista de produtos dessa página
     *   'total'    => quantos produtos existem no TOTAL (com o filtro
     *                 aplicado), usado pra calcular quantas páginas existem
     *
     * IMPORTANTE: esse método AINDA NÃO usa Redis — toda chamada consulta
     * o MySQL direto. É de propósito: cachear uma LISTA é um problema
     * diferente de cachear um item único (a chave precisa considerar
     * página + filtro + tamanho de página, e a invalidação é mais
     * delicada), e é exatamente disso que trata a próxima fase do projeto.
     */
    public function listarPaginado(int $pagina, int $porPagina, ?string $categoria): array
    {
        // Por enquanto, toda listagem vem do MySQL — não existe outro
        // caminho ainda. Na Fase 9, aqui vai entrar um "if" checando o
        // Redis primeiro, igual já fazemos em buscarPorId().
        $this->origemDaUltimaListagem = 'mysql';

        // Quantos registros "pular" antes de começar a listar. Ex.: na
        // página 3, com 20 por página, pulamos os 40 primeiros (páginas 1 e 2).
        $offset = ($pagina - 1) * $porPagina;

        // Monta a cláusula WHERE só se um filtro de categoria foi informado.
        // Como isso vira parte do TEXTO do SQL (não um valor), a gente
        // constrói com cuidado: aqui só entra uma string fixa nossa
        // ("WHERE categoria = :categoria"), nunca o valor da categoria em
        // si — o valor de verdade sempre entra depois, via bindValue.
        $condicaoCategoria = $categoria !== null ? 'WHERE categoria = :categoria' : '';

        $consulta = $this->pdo->prepare(
            "SELECT id, nome, categoria, preco, estoque
             FROM produtos
             {$condicaoCategoria}
             ORDER BY id
             LIMIT :limite OFFSET :offset"
        );

        if ($categoria !== null) {
            $consulta->bindValue(':categoria', $categoria, PDO::PARAM_STR);
        }

        // LIMIT/OFFSET precisam ser ligados como inteiro (PDO::PARAM_INT):
        // se fossem ligados como string (o padrão do bindValue), o MySQL
        // pode rejeitar a consulta, porque LIMIT/OFFSET não aceitam texto.
        $consulta->bindValue(':limite', $porPagina, PDO::PARAM_INT);
        $consulta->bindValue(':offset', $offset, PDO::PARAM_INT);
        $consulta->execute();

        $produtos = $consulta->fetchAll();

        // Segunda consulta, só pra saber o TOTAL de produtos (sem LIMIT),
        // necessária pra calcular quantas páginas existem no total.
        $consultaDoTotal = $this->pdo->prepare(
            "SELECT COUNT(*) FROM produtos {$condicaoCategoria}"
        );

        if ($categoria !== null) {
            $consultaDoTotal->bindValue(':categoria', $categoria, PDO::PARAM_STR);
        }

        $consultaDoTotal->execute();
        $total = (int) $consultaDoTotal->fetchColumn();

        return [
            'produtos' => $produtos,
            'total' => $total,
        ];
    }

    /**
     * Lista as categorias distintas que existem na tabela, em ordem
     * alfabética — usado pra montar o filtro (<select>) da página de
     * produtos.
     */
    public function listarCategorias(): array
    {
        $consulta = $this->pdo->query('SELECT DISTINCT categoria FROM produtos ORDER BY categoria');

        // fetchAll(PDO::FETCH_COLUMN) devolve só a primeira coluna de cada
        // linha, direto como uma lista simples de strings — em vez de um
        // array de arrays associativos (que seria ['categoria' => 'X']
        // repetido pra cada item, e a gente teria que extrair na mão).
        return $consulta->fetchAll(PDO::FETCH_COLUMN);
    }
}
