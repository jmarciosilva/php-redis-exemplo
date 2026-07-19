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

    // TTL da cache de LISTAGEM (Fase 9) — bem mais curto que o dos produtos
    // (300s). Por quê? Uma listagem depende de MUITO mais coisa mudando:
    // um produto novo, um produto removido, ou até só o preço/estoque de
    // QUALQUER produto que apareça naquela página já deixa a lista
    // levemente desatualizada. Além disso, cada combinação de página +
    // categoria + tamanho de página vira uma chave DIFERENTE no Redis (ver
    // listagemComoChave() abaixo) — um TTL curto evita acumular um monte
    // de chaves velhas e raramente reaproveitadas. 60s ainda assim já
    // segura bem o pico de gente batendo na mesma página popular ao mesmo
    // tempo, que é o cenário que mais nos preocupa (ver ANALISE.md).
    // Isso não substitui invalidação de verdade — é só o assunto da Fase 10.
    private const TTL_CACHE_LISTAGEM_EM_SEGUNDOS = 60;

    // --- Configurações da proteção contra CACHE STAMPEDE (Fase 11) ---
    //
    // Tempo de vida do "lock" (trava) que um processo cria no Redis antes
    // de consultar o MySQL. 5 segundos é bem mais que o suficiente pra
    // qualquer consulta nossa terminar; ele existe só como uma rede de
    // segurança: se o processo que criou o lock travar ou cair no meio do
    // caminho, o lock se auto-destrói sozinho depois desse tempo, em vez
    // de ficar travado pra sempre.
    private const TTL_LOCK_EM_SEGUNDOS = 5;

    // Quantas vezes (no máximo) um processo que NÃO conseguiu o lock vai
    // tentar ler o cache de novo antes de desistir e ir direto no MySQL
    // como último recurso.
    private const TENTATIVAS_MAXIMAS_DE_ESPERA = 20;

    // Quanto tempo esperar entre uma tentativa e outra, em microssegundos
    // (50.000 microssegundos = 50 milissegundos). 20 tentativas x 50ms =
    // até 1 segundo de espera no total, o que já é bem generoso pertinho
    // do tempo real que uma consulta ao MySQL leva neste projeto.
    private const INTERVALO_DE_ESPERA_EM_MICROSSEGUNDOS = 50_000;

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
     * ESSE MÉTODO NÃO TEM PROTEÇÃO CONTRA CACHE STAMPEDE — de propósito.
     * Ele é a versão "baseline" (a mesma desde a Fase 5), usada por
     * public/produto.php, que fica como referência de comparação ao lado
     * de public/produto_protegido.php (Fase 11, ver
     * buscarPorIdComProtecaoContraStampede() logo abaixo). Se MUITAS
     * requisições pedirem o MESMO produto bem no instante em que o cache
     * dele está vazio (expirou, ou nunca foi visitado), TODAS elas caem no
     * MySQL ao mesmo tempo — mesmo sendo, na prática, a MESMA pergunta
     * repetida N vezes. Isso é o "cache stampede" (ou "dog-piling").
     *
     * Devolve um array associativo com os dados do produto, ou null se
     * não existir nenhum produto com esse id (nem no cache, nem no banco).
     */
    public function buscarPorId(int $id): ?array
    {
        // Estratégia de chave: "produto:{id}", por exemplo "produto:42".
        // Prefixar com "produto:" é uma convenção comum no Redis (às vezes
        // chamada de "namespacing") — evita que essa chave colida com
        // outras chaves que a gente criou na Fase 9 (as de listagem, tipo
        // "listagem:produtos:pagina:1:por_pagina:20:categoria:todas")
        // dentro do mesmo Redis.
        $chave = "produto:{$id}";

        // --- Passo 1: tenta achar no Redis primeiro ---
        $produtoEmCache = $this->redis->get($chave);

        // O phpredis devolve "false" quando a chave não existe (ou expirou).
        // Se vier qualquer coisa diferente de false, é porque achamos o
        // produto no cache.
        if ($produtoEmCache !== false) {
            $this->origemDaUltimaBusca = 'redis';

            // No Redis, tudo é guardado como string — por isso, mais na
            // frente, guardamos o produto como uma string JSON. Aqui a
            // gente faz o caminho inverso: transforma essa string JSON de
            // volta em array associativo (o "true" no final pede exatamente
            // isso — sem ele, json_decode devolveria um objeto stdClass).
            return json_decode($produtoEmCache, true);
        }

        // --- Passo 2: não tava no cache, então busca no MySQL (sem
        // nenhuma proteção — é exatamente esse ponto que vira um problema
        // quando MUITAS requisições chegam aqui ao mesmo tempo) ---
        $this->origemDaUltimaBusca = 'mysql';
        $produto = $this->buscarPorIdNoMysql($id);

        if ($produto === null) {
            return null;
        }

        // --- Passo 3: guarda no Redis pra próxima vez vir do cache ---
        $this->redis->setex($chave, self::TTL_CACHE_EM_SEGUNDOS, json_encode($produto));

        return $produto;
    }

    /**
     * Mesma busca de buscarPorId(), só que protegida contra **cache
     * stampede** usando um lock (trava) no Redis — só UM processo por vez
     * tem permissão pra ir no MySQL buscar um id que não está em cache;
     * todos os outros esperam esse processo terminar e leem o resultado
     * que ELE guardou no cache, em vez de irem no MySQL também.
     *
     * Usado por public/produto_protegido.php.
     */
    public function buscarPorIdComProtecaoContraStampede(int $id): ?array
    {
        $chave = "produto:{$id}";

        // --- Passo 1: igual antes, tenta o cache primeiro ---
        $produtoEmCache = $this->redis->get($chave);

        if ($produtoEmCache !== false) {
            $this->origemDaUltimaBusca = 'redis';

            return json_decode($produtoEmCache, true);
        }

        // --- Passo 2: cache vazio. Antes de ir no MySQL, tenta "trancar a
        // porta" pra esse id específico ---
        $chaveDoLock = "lock:produto:{$id}";

        // SET com as opções NX + EX faz TUDO isso numa única operação
        // atômica: "crie essa chave com o valor 1, só SE ela ainda não
        // existir (NX = 'not exists'), e já com expiração de 5s (EX)".
        // Como o Redis processa comandos um de cada vez (é single-thread
        // pros comandos), só EXATAMENTE UM processo, entre vários rodando
        // ao mesmo tempo, consegue criar essa chave — é essa garantia que
        // vira o nosso "lock".
        $conseguiuOLock = $this->redis->set($chaveDoLock, '1', ['NX', 'EX' => self::TTL_LOCK_EM_SEGUNDOS]);

        if ($conseguiuOLock) {
            // Fui eu que tranquei a porta: sou o responsável por buscar
            // no MySQL e repor o cache pros outros aproveitarem.
            $this->origemDaUltimaBusca = 'mysql (com lock)';
            $produto = $this->buscarPorIdNoMysql($id);

            if ($produto !== null) {
                $this->redis->setex($chave, self::TTL_CACHE_EM_SEGUNDOS, json_encode($produto));
            }

            // Destranca a porta assim que terminar — não precisa esperar
            // o lock expirar sozinho, já que já fizemos o que precisava.
            $this->redis->del($chaveDoLock);

            return $produto;
        }

        // --- Não consegui o lock: outro processo já está buscando esse
        // produto no MySQL neste exato momento. Em vez de ir no banco
        // também (o que recriaria o próprio stampede que queremos
        // evitar), esperamos um pouquinho e ficamos checando se o cache
        // já foi preenchido pelo processo que tem o lock. ---
        for ($tentativa = 0; $tentativa < self::TENTATIVAS_MAXIMAS_DE_ESPERA; $tentativa++) {
            usleep(self::INTERVALO_DE_ESPERA_EM_MICROSSEGUNDOS);

            $produtoEmCache = $this->redis->get($chave);

            if ($produtoEmCache !== false) {
                $this->origemDaUltimaBusca = 'redis (após esperar o lock)';

                return json_decode($produtoEmCache, true);
            }
        }

        // --- Esperamos bastante (até 1s) e o cache ainda não apareceu —
        // talvez quem tinha o lock tenha travado ou demorado demais. Em
        // vez de deixar o usuário esperando pra sempre, caímos pra buscar
        // direto no MySQL como último recurso: melhor responder devagar
        // do que não responder. ---
        $this->origemDaUltimaBusca = 'mysql (fallback após espera)';

        return $this->buscarPorIdNoMysql($id);
    }

    /**
     * A consulta de verdade no MySQL pra um produto único, sem nenhuma
     * lógica de cache — reaproveitada por buscarPorId() e por
     * buscarPorIdComProtecaoContraStampede(), pra não duplicar SQL.
     *
     * Também incrementa um CONTADOR no Redis, só pra fins de observação:
     * é o que benchmark/stampede.php usa pra medir, de forma bem concreta,
     * "quantas vezes o MySQL foi realmente consultado pra esse produto" —
     * o número que mostra o estrago (ou a ausência dele) de um stampede.
     */
    private function buscarPorIdNoMysql(int $id): ?array
    {
        $this->redis->incr("contador:consultas_mysql:produto:{$id}");

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

        // Convertendo "false" (nada encontrado) pra "null", que é mais
        // claro de entender pra quem for usar esse método depois.
        return $produto === false ? null : $produto;
    }

    /**
     * Zera o contador de consultas ao MySQL de um produto — usado por
     * benchmark/stampede.php antes de cada rajada de teste, pra cada
     * cenário começar a contagem do zero.
     */
    public function resetarContadorDeConsultasMysql(int $id): void
    {
        $this->redis->del("contador:consultas_mysql:produto:{$id}");
    }

    /**
     * Lê o contador de quantas vezes o MySQL foi consultado pra um
     * produto desde o último reset — usado por benchmark/stampede.php
     * pra medir o efeito (ou a ausência dele) de um cache stampede.
     */
    public function contarConsultasMysql(int $id): int
    {
        $valor = $this->redis->get("contador:consultas_mysql:produto:{$id}");

        return $valor === false ? 0 : (int) $valor;
    }

    /**
     * Lista produtos de forma paginada, opcionalmente filtrando por
     * categoria. Devolve um array com duas chaves:
     *   'produtos' => a lista de produtos dessa página
     *   'total'    => quantos produtos existem no TOTAL (com o filtro
     *                 aplicado), usado pra calcular quantas páginas existem
     *
     * ESSE MÉTODO NUNCA USA REDIS — de propósito. Ele é a versão "baseline"
     * usada por public/produtos.php, que fica permanentemente sem cache,
     * servindo de referência de comparação ao lado de
     * public/produtos_cache.php (que usa listarPaginadoComCache() logo
     * abaixo). Assim dá pra comparar os dois tempos lado a lado a qualquer
     * momento, sem depender do cache estar "quente" ou "frio" na hora.
     */
    public function listarPaginado(int $pagina, int $porPagina, ?string $categoria): array
    {
        $this->origemDaUltimaListagem = 'mysql';

        return $this->buscarListagemNoMysql($pagina, $porPagina, $categoria);
    }

    /**
     * Mesma listagem paginada de listarPaginado(), só que usando o padrão
     * **Cache-Aside** (Fase 9): procura no Redis primeiro, e só vai no
     * MySQL se não encontrar.
     *
     * Usado por public/produtos_cache.php — a versão "otimizada" que a
     * gente compara lado a lado com a versão sem cache.
     */
    public function listarPaginadoComCache(int $pagina, int $porPagina, ?string $categoria): array
    {
        $chave = $this->chaveDeCacheDaListagem($pagina, $porPagina, $categoria);

        // --- Passo 1: tenta achar a listagem pronta no Redis ---
        $listagemEmCache = $this->redis->get($chave);

        if ($listagemEmCache !== false) {
            $this->origemDaUltimaListagem = 'redis';

            return json_decode($listagemEmCache, true);
        }

        // --- Passo 2: não tava no cache, busca no MySQL (reaproveitando a
        // mesma consulta do método baseline, pra não duplicar SQL) ---
        $this->origemDaUltimaListagem = 'mysql';
        $resultado = $this->buscarListagemNoMysql($pagina, $porPagina, $categoria);

        // --- Passo 3: guarda no Redis pra próxima vez vir do cache ---
        // TTL bem mais curto que o de produto único (ver o porquê no
        // comentário da constante TTL_CACHE_LISTAGEM_EM_SEGUNDOS).
        $this->redis->setex($chave, self::TTL_CACHE_LISTAGEM_EM_SEGUNDOS, json_encode($resultado));

        return $resultado;
    }

    /**
     * Monta a chave de cache de uma listagem, considerando TODOS os
     * parâmetros que mudam o resultado: página, tamanho de página e
     * categoria. Isso é o que mais diferencia cachear uma LISTA de
     * cachear um item único (produto:{id}) — aqui não existe "um id só",
     * existe uma combinação de filtros, e cada combinação diferente
     * precisa da sua própria chave (senão a página 2 acabaria devolvendo
     * os mesmos dados da página 1, por exemplo).
     */
    private function chaveDeCacheDaListagem(int $pagina, int $porPagina, ?string $categoria): string
    {
        // Se não tem filtro de categoria, usamos a palavra "todas" no lugar
        // — só pra ter uma chave previsível também para esse caso, em vez
        // de deixar um pedaço vazio no meio da string.
        $categoriaNaChave = $categoria ?? 'todas';

        return "listagem:produtos:pagina:{$pagina}:por_pagina:{$porPagina}:categoria:{$categoriaNaChave}";
    }

    /**
     * A consulta de verdade no MySQL, sem nenhuma lógica de cache — usada
     * tanto por listarPaginado() (que sempre chama isso direto) quanto por
     * listarPaginadoComCache() (que só chama isso quando dá cache miss).
     */
    private function buscarListagemNoMysql(int $pagina, int $porPagina, ?string $categoria): array
    {
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
     * Busca um produto DIRETO no MySQL, ignorando o Redis por completo —
     * nem lê, nem grava cache. Usado por public/editar_produto.php pra
     * mostrar sempre o dado "de verdade" (a fonte da verdade) na tela de
     * edição, independente do que estiver (ou não) cacheado no momento.
     */
    public function buscarPorIdSemCache(int $id): ?array
    {
        $consulta = $this->pdo->prepare(
            'SELECT id, nome, descricao, categoria, preco, estoque
             FROM produtos
             WHERE id = :id'
        );
        $consulta->execute(['id' => $id]);

        $produto = $consulta->fetch();

        return $produto === false ? null : $produto;
    }

    /**
     * Atualiza um produto no MySQL e NÃO mexe no Redis — de propósito.
     *
     * Esse método existe só pra DEMONSTRAR o problema: se você já tinha
     * visitado esse produto antes (e portanto ele está cacheado), depois
     * de chamar esse método o Redis continua devolvendo os dados ANTIGOS
     * até o TTL de 300s expirar sozinho. É exatamente esse tipo de "dado
     * desatualizado" (stale) que motivou o teste técnico que inspirou esse
     * projeto (ver ANALISE.md) — e o motivo de nunca fazer isso de verdade
     * numa aplicação real.
     *
     * Devolve true se algum produto foi realmente alterado no banco.
     */
    public function atualizarSemInvalidarCache(int $id, string $nome, float $preco, int $estoque): bool
    {
        return $this->executarAtualizacaoNoMysql($id, $nome, $preco, $estoque) > 0;
    }

    /**
     * Atualiza um produto no MySQL e, dessa vez, invalida corretamente o
     * cache — é assim que uma aplicação de verdade deveria fazer.
     *
     * Duas invalidações acontecem aqui, e são bem diferentes uma da outra:
     *
     *   1. Item único: fácil e preciso. A gente SABE exatamente qual
     *      chave apagar — "produto:{id}" — porque só existe UMA chave
     *      por produto.
     *   2. Listagens: MUITO mais difícil de fazer com precisão. Esse
     *      mesmo produto pode aparecer em várias páginas e em várias
     *      combinações de filtro por categoria (ex.: página 3 "sem
     *      filtro" E página 1 "categoria X", ao mesmo tempo) — descobrir
     *      exatamente quais chaves de listagem contêm esse produto exigiria
     *      rastrear isso à parte (o que é bem mais complexo do que parece).
     *      A saída pragmática — e comum na prática — é invalidar TODAS as
     *      listagens cacheadas de uma vez (ver invalidarCacheDeListagens()).
     *      É uma invalidação "grosseira" (apaga listagens que nem
     *      continham esse produto), mas é simples, correta, e barata: como
     *      o TTL de listagem já é curto (60s), o prejuízo de invalidar a
     *      mais é pequeno.
     *
     * Devolve true se algum produto foi realmente alterado no banco.
     */
    public function atualizarComInvalidacaoDeCache(int $id, string $nome, float $preco, int $estoque): bool
    {
        $linhasAfetadas = $this->executarAtualizacaoNoMysql($id, $nome, $preco, $estoque);

        if ($linhasAfetadas > 0) {
            // 1. Invalidação precisa do item único.
            $this->redis->del("produto:{$id}");

            // 2. Invalidação "grosseira" de todas as listagens cacheadas.
            $this->invalidarCacheDeListagens();
        }

        return $linhasAfetadas > 0;
    }

    /**
     * O UPDATE de verdade no MySQL, sem nenhuma lógica de cache — usado
     * pelos dois métodos acima (com e sem invalidação), pra não duplicar
     * o SQL. Devolve quantas linhas foram alteradas (0 ou 1, já que
     * filtramos por id).
     */
    private function executarAtualizacaoNoMysql(int $id, string $nome, float $preco, int $estoque): int
    {
        $consulta = $this->pdo->prepare(
            'UPDATE produtos
             SET nome = :nome, preco = :preco, estoque = :estoque
             WHERE id = :id'
        );

        $consulta->execute([
            'nome' => $nome,
            'preco' => $preco,
            'estoque' => $estoque,
            'id' => $id,
        ]);

        return $consulta->rowCount();
    }

    /**
     * Apaga TODAS as chaves de listagem cacheadas (todas as combinações de
     * página/tamanho/categoria), usando SCAN em vez de KEYS.
     *
     * Por que SCAN e não KEYS? KEYS varre o Redis inteiro de uma vez só e
     * BLOQUEIA o servidor enquanto isso — num Redis com milhões de chaves,
     * isso poderia travar a aplicação toda por um bom tempo. SCAN faz a
     * mesma varredura, só que em pequenos pedaços (aqui, 100 chaves por
     * vez), sem travar o Redis pros outros comandos que estiverem rodando
     * ao mesmo tempo. Pra um projeto de estudo como esse a diferença não
     * se sente, mas é o hábito certo a criar desde já.
     */
    private function invalidarCacheDeListagens(): void
    {
        $cursor = null;

        do {
            // O terceiro argumento é o "padrão" de chave (igual um coringa
            // de sistema de arquivos: "listagem:produtos:*" bate com
            // qualquer chave que comece com esse prefixo). O quarto é
            // quantas chaves pedir por vez ao Redis.
            $chaves = $this->redis->scan($cursor, 'listagem:produtos:*', 100);

            if ($chaves !== false) {
                foreach ($chaves as $chave) {
                    $this->redis->del($chave);
                }
            }
            // $cursor é atualizado pelo próprio scan() (passado por
            // referência); quando o Redis termina de varrer tudo, ele
            // volta a valer 0, e o loop para.
        } while ($cursor !== 0);
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
