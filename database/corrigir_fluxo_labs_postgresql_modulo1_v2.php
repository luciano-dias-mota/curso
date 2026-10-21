<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: revise o dry-run, faça backup e use --apply --force-production.\n");
    exit(1);
}

$pdo = Database::connection();

$courseStmt = $pdo->prepare(
    "SELECT id, title
       FROM courses
      WHERE slug = 'postgresql-zero-intermediario'
        AND experience_key = 'postgresql'
      LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "Curso PostgreSQL não encontrado.\n");
    exit(2);
}

$moduleStmt = $pdo->prepare(
    "SELECT id, title
       FROM modules
      WHERE course_id = :course_id
        AND slug = 'consultando-dados-select'
      LIMIT 1"
);
$moduleStmt->execute(['course_id' => (int) $course['id']]);
$module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(STDERR, "Módulo 1 não encontrado.\n");
    exit(3);
}

$definitions = [
    'select-colunas' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você projetou somente as colunas necessárias.',
            'success_explanation' => 'Você usou SELECT para escolher nome e categoria sem trazer todas as colunas da tabela. Isso é projeção de dados.',
            'practical_context' => 'Em sistemas reais, selecionar somente as colunas necessárias reduz dados trafegados, deixa o contrato da consulta claro e facilita manutenção.',
        ],
        'challenge' => [
            'title' => 'Desafio: escolha outras colunas',
            'instructions' => 'Sem copiar o exemplo: retorne nome e preco da tabela produtos_catalogo, nessa ordem. Ordene pelo id.',
            'validator' => [
                'columns' => ['nome', 'preco'],
                'rows' => [
                    ['nome' => 'Teclado', 'preco' => 120],
                    ['nome' => 'Mouse', 'preco' => 80],
                    ['nome' => 'Monitor', 'preco' => 950],
                    ['nome' => 'Webcam', 'preco' => 220],
                    ['nome' => 'SSD', 'preco' => 430],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você escolheu as colunas sozinho.',
                'success_explanation' => 'Você adaptou a projeção para nome e preco sem depender do código guiado.',
                'practical_context' => 'Esse raciocínio é usado ao montar APIs, relatórios e telas que precisam apenas de parte das informações de uma tabela.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: adapte novamente a projeção',
            'instructions' => 'Retorne categoria e estoque da tabela produtos_catalogo, nessa ordem. Ordene pelo id.',
            'validator' => [
                'columns' => ['categoria', 'estoque'],
                'rows' => [
                    ['categoria' => 'Perifericos', 'estoque' => 8],
                    ['categoria' => 'Perifericos', 'estoque' => 15],
                    ['categoria' => 'Video', 'estoque' => 3],
                    ['categoria' => 'Video', 'estoque' => 0],
                    ['categoria' => 'Armazenamento', 'estoque' => 6],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! Projeção de dados fixada.',
                'success_explanation' => 'Você repetiu a mesma estrutura com colunas diferentes e mostrou que entendeu a lógica do SELECT.',
                'practical_context' => 'No trabalho, a estrutura da consulta muda conforme a informação solicitada. Saber adaptar as colunas é mais importante do que decorar um comando.',
            ],
        ],
    ],
    'aliases-as' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você criou aliases claros.',
            'success_explanation' => 'AS mudou apenas o nome exibido no resultado, sem alterar o nome real das colunas da tabela.',
            'practical_context' => 'Aliases tornam relatórios, APIs e consultas com JOINs mais legíveis para quem consome ou mantém o SQL.',
        ],
        'challenge' => [
            'title' => 'Desafio: novos aliases',
            'instructions' => 'Retorne nome como item e preco como valor. Ordene pelo id.',
            'validator' => [
                'columns' => ['item', 'valor'],
                'rows' => [
                    ['item' => 'Teclado', 'valor' => 120],
                    ['item' => 'Mouse', 'valor' => 80],
                    ['item' => 'Monitor', 'valor' => 950],
                    ['item' => 'Webcam', 'valor' => 220],
                    ['item' => 'SSD', 'valor' => 430],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você adaptou os aliases.',
                'success_explanation' => 'Você renomeou duas colunas no resultado sem alterar a tabela original.',
                'practical_context' => 'Um desenvolvedor usa aliases para devolver nomes de campos mais claros em relatórios e integrações.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: aliases em outro contexto',
            'instructions' => 'Retorne categoria como grupo e estoque como quantidade. Ordene pelo id.',
            'validator' => [
                'columns' => ['grupo', 'quantidade'],
                'rows' => [
                    ['grupo' => 'Perifericos', 'quantidade' => 8],
                    ['grupo' => 'Perifericos', 'quantidade' => 15],
                    ['grupo' => 'Video', 'quantidade' => 3],
                    ['grupo' => 'Video', 'quantidade' => 0],
                    ['grupo' => 'Armazenamento', 'quantidade' => 6],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! Você dominou aliases básicos.',
                'success_explanation' => 'Você aplicou AS novamente com nomes diferentes e manteve a consulta correta.',
                'practical_context' => 'Em consultas profissionais, aliases ajudam a padronizar nomes mesmo quando o banco possui nomes técnicos ou antigos.',
            ],
        ],
    ],
    'distinct' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você removeu duplicidades do resultado.',
            'success_explanation' => 'DISTINCT devolveu cada cidade apenas uma vez, mesmo existindo vários clientes na mesma cidade.',
            'practical_context' => 'DISTINCT é útil para listas de categorias, cidades, status e outros valores únicos, mas não deve esconder duplicações causadas por JOINs incorretos.',
        ],
        'challenge' => [
            'title' => 'Desafio: cidades dos clientes ativos',
            'instructions' => 'Retorne as cidades distintas apenas dos clientes ativos. Ordene por cidade.',
            'validator' => [
                'columns' => ['cidade'],
                'rows' => [
                    ['cidade' => 'Cuiaba'],
                    ['cidade' => 'Sinop'],
                    ['cidade' => 'Varzea Grande'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você combinou DISTINCT e filtro.',
                'success_explanation' => 'Você removeu repetições depois de limitar o conjunto aos clientes ativos.',
                'practical_context' => 'Esse padrão aparece em filtros de tela, listas de opções e relatórios onde só interessam valores únicos dentro de um subconjunto.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: combinação distinta',
            'instructions' => 'Retorne as combinações distintas de cidade e ativo. Ordene por cidade e depois por ativo.',
            'validator' => [
                'columns' => ['cidade', 'ativo'],
                'rows' => [
                    ['cidade' => 'Cuiaba', 'ativo' => false],
                    ['cidade' => 'Cuiaba', 'ativo' => true],
                    ['cidade' => 'Sinop', 'ativo' => true],
                    ['cidade' => 'Varzea Grande', 'ativo' => true],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! Você entendeu o alcance do DISTINCT.',
                'success_explanation' => 'DISTINCT considerou a combinação das duas colunas, não apenas cidade isoladamente.',
                'practical_context' => 'Isso evita um erro comum: acreditar que DISTINCT age só sobre a primeira coluna selecionada.',
            ],
        ],
    ],
    'where-comparacoes' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você filtrou registros com WHERE.',
            'success_explanation' => 'WHERE avaliou cada linha e manteve somente produtos com preco maior ou igual a 200.',
            'practical_context' => 'DBAs, analistas e desenvolvedores usam WHERE constantemente para localizar exatamente os registros que precisam consultar, atualizar ou investigar.',
        ],
        'challenge' => [
            'title' => 'Desafio: produtos abaixo de 200',
            'instructions' => 'Retorne somente o nome dos produtos com preço menor que 200. Ordene alfabeticamente.',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Mouse'],
                    ['nome' => 'Teclado'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você adaptou o operador de comparação.',
                'success_explanation' => 'Você trocou a condição e obteve outro subconjunto da mesma tabela.',
                'practical_context' => 'Alterar operadores de comparação é rotina em consultas de estoque, preço, datas, limites e indicadores.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: localize estoque zerado',
            'instructions' => 'Retorne o nome dos produtos cujo estoque seja igual a 0. Ordene pelo nome.',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Webcam'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! Filtro básico consolidado.',
                'success_explanation' => 'Você usou igualdade para localizar exatamente um estado específico dos dados.',
                'practical_context' => 'Esse tipo de filtro serve para localizar itens sem estoque, chamados por status, clientes por ID e inúmeros cenários de suporte.',
            ],
        ],
    ],
    'and-or-not' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você combinou condições corretamente.',
            'success_explanation' => 'AND exigiu cliente ativo e os parênteses agruparam as alternativas de cidade ligadas por OR.',
            'practical_context' => 'Consultas reais quase sempre combinam vários critérios. Parênteses explícitos reduzem erros de interpretação e manutenção.',
        ],
        'challenge' => [
            'title' => 'Desafio: ativos fora de Cuiabá',
            'instructions' => 'Retorne o nome dos clientes ativos cuja cidade seja diferente de Cuiaba. Ordene pelo nome.',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Bruno'],
                    ['nome' => 'Diego'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você combinou AND com diferença.',
                'success_explanation' => 'A consulta exigiu duas condições simultâneas: ativo verdadeiro e cidade diferente de Cuiaba.',
                'practical_context' => 'Combinar status e localização é comum em filtros de atendimento, logística e relatórios operacionais.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: duas cidades e status ativo',
            'instructions' => 'Retorne os clientes ativos de Cuiaba ou Varzea Grande. Use parênteses para agrupar as cidades e ordene pelo nome.',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Ana'],
                    ['nome' => 'Bruno'],
                    ['nome' => 'Elisa'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! Lógica booleana fixada.',
                'success_explanation' => 'Você preservou a regra de ativo e agrupou corretamente duas alternativas de cidade.',
                'practical_context' => 'Essa estrutura aparece em permissões, filtros de busca e regras de negócio com múltiplos critérios.',
            ],
        ],
    ],
    'like-in-between' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você combinou lista, intervalo e padrão de texto.',
            'success_explanation' => 'IN limitou categorias, BETWEEN definiu um intervalo inclusivo e ILIKE procurou texto sem diferenciar maiúsculas de minúsculas.',
            'practical_context' => 'Filtros combinados são comuns em catálogos, pesquisas de chamados, relatórios de vendas e sistemas administrativos.',
        ],
        'challenge' => [
            'title' => 'Desafio: novo recorte do catálogo',
            'instructions' => "Retorne o nome dos produtos das categorias Perifericos ou Video, com preço entre 80 e 220, cujo nome contenha a letra e. Use ILIKE e ordene pelo nome.",
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Mouse'],
                    ['nome' => 'Teclado'],
                    ['nome' => 'Webcam'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você adaptou três filtros ao mesmo tempo.',
                'success_explanation' => 'Você reutilizou IN, BETWEEN e ILIKE com valores diferentes, sem copiar o exemplo.',
                'practical_context' => 'Saber adaptar filtros compostos é essencial para responder solicitações que mudam parâmetros, mas não a lógica.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: encontre item por categoria, faixa e texto',
            'instructions' => "Retorne o nome dos produtos das categorias Video ou Armazenamento, com preço entre 200 e 500, cujo nome contenha a letra s. Use ILIKE e ordene pelo nome.",
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'SSD'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! Filtros expressivos consolidados.',
                'success_explanation' => 'Você alterou categorias, intervalo e padrão textual mantendo a estrutura correta.',
                'practical_context' => 'Essa capacidade de adaptação é o que permite construir filtros dinâmicos em aplicações e relatórios.',
            ],
        ],
    ],
    'is-null' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você filtrou valores ausentes corretamente.',
            'success_explanation' => 'IS NULL encontrou registros sem e-mail. Em SQL, NULL representa ausência ou valor desconhecido e não deve ser comparado com =.',
            'practical_context' => 'Localizar campos ausentes é rotina em saneamento de dados, suporte, cadastros incompletos e integrações.',
        ],
        'challenge' => [
            'title' => 'Desafio: cadastros com e-mail informado',
            'instructions' => 'Retorne o nome dos clientes cujo e-mail esteja informado. Ordene pelo nome.',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Ana'],
                    ['nome' => 'Carla'],
                    ['nome' => 'Elisa'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você usou IS NOT NULL.',
                'success_explanation' => 'Você buscou exatamente o caso oposto: registros em que o valor está presente.',
                'practical_context' => 'Esse padrão ajuda a separar cadastros completos dos que ainda precisam de correção.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: identifique os IDs incompletos',
            'instructions' => 'Retorne id e nome dos clientes sem e-mail. Ordene pelo id.',
            'validator' => [
                'columns' => ['id', 'nome'],
                'rows' => [
                    ['id' => 2, 'nome' => 'Bruno'],
                    ['id' => 4, 'nome' => 'Diego'],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! NULL deixou de ser armadilha.',
                'success_explanation' => 'Você combinou IS NULL com uma projeção diferente para localizar os registros que precisam de correção.',
                'practical_context' => 'Em manutenção de dados, normalmente precisamos do ID junto ao registro para identificar exatamente o que será tratado.',
            ],
        ],
    ],
    'order-by-limit' => [
        'guided_feedback' => [
            'success_title' => 'Parabéns! Você montou uma página determinística.',
            'success_explanation' => 'ORDER BY definiu a sequência, LIMIT restringiu a quantidade e OFFSET pulou as linhas da página anterior.',
            'practical_context' => 'Paginação é comum em sistemas web, APIs, relatórios e telas administrativas. Sem uma ordenação estável, páginas podem repetir ou perder registros.',
        ],
        'challenge' => [
            'title' => 'Desafio: primeira página do catálogo',
            'instructions' => 'Retorne nome e preco da primeira página, com duas linhas. Ordene por preço decrescente e id crescente como desempate. Use LIMIT 2 sem OFFSET.',
            'validator' => [
                'columns' => ['nome', 'preco'],
                'rows' => [
                    ['nome' => 'Notebook', 'preco' => 3200],
                    ['nome' => 'Monitor', 'preco' => 950],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Muito bem! Você construiu a primeira página.',
                'success_explanation' => 'Você manteve a ordenação e mudou o recorte para os dois primeiros registros.',
                'practical_context' => 'Aplicações calculam OFFSET conforme a página solicitada, mas a lógica depende sempre de uma ordenação consistente.',
            ],
        ],
        'reinforcement' => [
            'title' => 'Reforço: terceira página',
            'instructions' => 'Retorne nome e preco da terceira página, com duas linhas por página. Use a mesma ordenação e LIMIT 2 OFFSET 4.',
            'validator' => [
                'columns' => ['nome', 'preco'],
                'rows' => [
                    ['nome' => 'Teclado', 'preco' => 120],
                    ['nome' => 'Mouse', 'preco' => 80],
                ],
                'ordered' => true,
            ],
            'feedback' => [
                'success_title' => 'Excelente! Paginação básica consolidada.',
                'success_explanation' => 'Você calculou corretamente o deslocamento da terceira página e preservou a mesma ordenação.',
                'practical_context' => 'Esse conceito aparece em listagens grandes, embora sistemas mais avançados também possam usar paginação por cursor.',
            ],
        ],
    ],
];

$lessonStmt = $pdo->prepare(
    "SELECT l.id, l.slug, l.title, l.status,
            p.id AS phase_id
       FROM lessons l
       INNER JOIN phases p ON p.id = l.phase_id
      WHERE p.module_id = :module_id
        AND l.slug = :slug
        AND l.status = 'published'
      LIMIT 1"
);

$activityStmt = $pdo->prepare(
    "SELECT *
       FROM lab_activities
      WHERE lesson_id = :lesson_id
        AND position = 1
        AND status = 'published'
      LIMIT 1"
);

$rows = [];
foreach ($definitions as $slug => $definition) {
    $lessonStmt->execute([
        'module_id' => (int) $module['id'],
        'slug' => $slug,
    ]);
    $lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);

    if (!$lesson) {
        fwrite(STDERR, "ABORTADO: aula publicada não encontrada: {$slug}.\n");
        exit(4);
    }

    $activityStmt->execute(['lesson_id' => (int) $lesson['id']]);
    $activity = $activityStmt->fetch(PDO::FETCH_ASSOC);

    if (!$activity) {
        fwrite(STDERR, "ABORTADO: atividade publicada posição 1 ausente em {$slug}.\n");
        exit(5);
    }

    $bootstrap = json_decode((string) ($activity['bootstrap_config'] ?? '{}'), true);
    if (!is_array($bootstrap) || trim((string) ($bootstrap['sql'] ?? '')) === '') {
        fwrite(STDERR, "ABORTADO: bootstrap SQL inválido em {$slug}.\n");
        exit(6);
    }

    $rows[$slug] = [
        'lesson' => $lesson,
        'activity' => $activity,
        'bootstrap' => $bootstrap,
    ];
}

echo "=================================================================\n";
echo " POSTGRESQL MODULO 1 - FLUXO 3 ATIVIDADES V2\n";
echo "=================================================================\n";
printf("Banco:  %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:  #%d - %s\n", (int) $course['id'], (string) $course['title']);
printf("Modulo: #%d - %s\n", (int) $module['id'], (string) $module['title']);
printf("Modo:   %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

$countStmt = $pdo->prepare(
    "SELECT COUNT(*)
       FROM lab_activities
      WHERE lesson_id = :lesson_id
        AND status = 'published'
        AND is_required = 1"
);

foreach ($rows as $slug => $row) {
    $countStmt->execute(['lesson_id' => (int) $row['lesson']['id']]);
    printf(
        "Aula #%d %-24s | obrigatórias publicadas: %d -> 3\n",
        (int) $row['lesson']['id'],
        $slug,
        (int) $countStmt->fetchColumn()
    );
}

if (!$apply) {
    echo "\nO script irá:\n";
    echo "- manter a atividade 1 como prática guiada;\n";
    echo "- criar/atualizar atividade 2 como desafio sem código;\n";
    echo "- criar/atualizar atividade 3 como reforço sem código;\n";
    echo "- manter tentativas e progresso de laboratório já existentes;\n";
    echo "- reabrir aulas concluídas prematuramente quando ainda faltarem atividades;\n";
    echo "- relockar apenas a próxima aula ainda não iniciada quando necessário.\n";
    echo "\nNenhuma alteração realizada. Após backup, execute:\n";
    echo "php database/corrigir_fluxo_labs_postgresql_modulo1_v2.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $updateGuided = $pdo->prepare(
        "UPDATE lab_activities
            SET activity_type = 'sql_guided',
                bootstrap_config = :bootstrap_config,
                is_required = 1,
                updated_at = NOW()
          WHERE id = :id"
    );

    $upsert = $pdo->prepare(
        "INSERT INTO lab_activities
            (lesson_id, engine_key, activity_type, title, instructions, starter_code,
             validator_type, validator_config, bootstrap_config, unlock_rule,
             state_version, xp_reward, is_required, max_attempts, position, status)
         VALUES
            (:lesson_id, 'postgresql_pglite', 'sql_challenge', :title, :instructions, NULL,
             'query_result', :validator_config, :bootstrap_config, 'reading_complete',
             :state_version, :xp_reward, 1, NULL, :position, 'published')
         ON DUPLICATE KEY UPDATE
            engine_key = VALUES(engine_key),
            activity_type = 'sql_challenge',
            title = VALUES(title),
            instructions = VALUES(instructions),
            starter_code = NULL,
            validator_type = VALUES(validator_type),
            validator_config = VALUES(validator_config),
            bootstrap_config = VALUES(bootstrap_config),
            unlock_rule = VALUES(unlock_rule),
            state_version = VALUES(state_version),
            xp_reward = VALUES(xp_reward),
            is_required = 1,
            max_attempts = NULL,
            status = 'published',
            updated_at = NOW()"
    );

    $deleteHints = $pdo->prepare(
        "DELETE FROM lab_activity_hints
          WHERE activity_id = :activity_id"
    );

    $activityIdAt = $pdo->prepare(
        "SELECT id FROM lab_activities
          WHERE lesson_id = :lesson_id
            AND position = :position
          LIMIT 1"
    );

    foreach ($definitions as $slug => $definition) {
        $row = $rows[$slug];
        $lessonId = (int) $row['lesson']['id'];
        $guided = $row['activity'];
        $baseBootstrap = $row['bootstrap'];

        $guidedBootstrap = array_merge(
            $baseBootstrap,
            $definition['guided_feedback']
        );

        $updateGuided->execute([
            'bootstrap_config' => json_encode(
                $guidedBootstrap,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'id' => (int) $guided['id'],
        ]);

        foreach ([
            2 => $definition['challenge'],
            3 => $definition['reinforcement'],
        ] as $position => $activityDef) {
            $activityBootstrap = array_merge(
                $baseBootstrap,
                $activityDef['feedback']
            );

            $upsert->execute([
                'lesson_id' => $lessonId,
                'title' => $activityDef['title'],
                'instructions' => $activityDef['instructions'],
                'validator_config' => json_encode(
                    array_merge($activityDef['validator'], ['row_count' => count($activityDef['validator']['rows'])]),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'bootstrap_config' => json_encode(
                    $activityBootstrap,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'state_version' => max(1, (int) $guided['state_version']) + ($position - 1),
                'xp_reward' => max(20, (int) $guided['xp_reward']),
                'position' => $position,
            ]);

            $activityIdAt->execute([
                'lesson_id' => $lessonId,
                'position' => $position,
            ]);
            $activityId = (int) ($activityIdAt->fetchColumn() ?: 0);

            if ($activityId <= 0) {
                throw new RuntimeException(
                    "Falha ao localizar atividade {$position} da aula {$slug}."
                );
            }

            $deleteHints->execute(['activity_id' => $activityId]);
        }
    }

    // Reabre somente aulas que foram dadas como concluídas antes de existirem
    // as três atividades obrigatórias. A leitura permanece em 100%.
    $affectedUsers = [];

    foreach ($rows as $slug => $row) {
        $lessonId = (int) $row['lesson']['id'];

        $premature = $pdo->prepare(
            "SELECT ulp.user_id
               FROM user_lesson_progress ulp
              WHERE ulp.lesson_id = :lesson_id
                AND ulp.status = 'completed'
                AND EXISTS (
                    SELECT 1
                      FROM lab_activities la
                      LEFT JOIN lab_activity_progress lap
                        ON lap.activity_id = la.id
                       AND lap.user_id = ulp.user_id
                     WHERE la.lesson_id = ulp.lesson_id
                       AND la.status = 'published'
                       AND la.is_required = 1
                       AND COALESCE(lap.status, 'available') <> 'completed'
                )"
        );
        $premature->execute(['lesson_id' => $lessonId]);
        $userIds = array_map('intval', $premature->fetchAll(PDO::FETCH_COLUMN));

        foreach ($userIds as $userId) {
            $affectedUsers[$userId] = true;

            $pdo->prepare(
                "UPDATE user_lesson_progress
                    SET status = 'in_progress',
                        progress_pct = 100,
                        completed_at = NULL,
                        last_accessed_at = NOW()
                  WHERE user_id = :user_id
                    AND lesson_id = :lesson_id"
            )->execute([
                'user_id' => $userId,
                'lesson_id' => $lessonId,
            ]);

            // Se a próxima aula da mesma fase foi apenas liberada e ainda não
            // começou, volta a bloqueá-la. Não toca em aula já iniciada/concluída.
            $nextStmt = $pdo->prepare(
                "SELECT l2.id
                   FROM lessons l1
                   INNER JOIN lessons l2
                     ON l2.phase_id = l1.phase_id
                    AND l2.status = 'published'
                    AND l2.is_required = 1
                    AND l2.position > l1.position
                  WHERE l1.id = :lesson_id
                  ORDER BY l2.position, l2.id
                  LIMIT 1"
            );
            $nextStmt->execute(['lesson_id' => $lessonId]);
            $nextLessonId = (int) ($nextStmt->fetchColumn() ?: 0);

            if ($nextLessonId > 0) {
                $pdo->prepare(
                    "UPDATE user_lesson_progress
                        SET status = 'locked'
                      WHERE user_id = :user_id
                        AND lesson_id = :lesson_id
                        AND status = 'available'
                        AND COALESCE(blocks_viewed, 0) = 0
                        AND COALESCE(progress_pct, 0) = 0"
                )->execute([
                    'user_id' => $userId,
                    'lesson_id' => $nextLessonId,
                ]);
            }
        }
    }

    // Recalcula progresso de fase/módulo/curso para usuários afetados,
    // sem apagar XP ou tentativas já conquistadas.
    foreach (array_keys($affectedUsers) as $userId) {
        $phaseRows = $pdo->prepare(
            "SELECT p.id
               FROM phases p
              WHERE p.module_id = :module_id
                AND p.status = 'published'"
        );
        $phaseRows->execute(['module_id' => (int) $module['id']]);

        foreach ($phaseRows->fetchAll(PDO::FETCH_COLUMN) as $phaseIdRaw) {
            $phaseId = (int) $phaseIdRaw;
            $stats = $pdo->prepare(
                "SELECT COUNT(l.id) AS total,
                        SUM(CASE WHEN ulp.status = 'completed' THEN 1 ELSE 0 END) AS completed
                   FROM lessons l
                   LEFT JOIN user_lesson_progress ulp
                     ON ulp.lesson_id = l.id
                    AND ulp.user_id = :user_id
                  WHERE l.phase_id = :phase_id
                    AND l.status = 'published'
                    AND l.is_required = 1"
            );
            $stats->execute(['user_id' => $userId, 'phase_id' => $phaseId]);
            $s = $stats->fetch(PDO::FETCH_ASSOC) ?: [];
            $total = (int) ($s['total'] ?? 0);
            $completed = (int) ($s['completed'] ?? 0);
            $pct = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

            $pdo->prepare(
                "UPDATE user_phase_progress
                    SET progress_pct = :pct,
                        status = CASE
                            WHEN :completed = :total AND :total2 > 0 THEN 'completed'
                            WHEN :completed2 > 0 THEN 'in_progress'
                            ELSE status
                        END,
                        completed_at = CASE
                            WHEN :completed3 = :total3 AND :total4 > 0 THEN completed_at
                            ELSE NULL
                        END
                  WHERE user_id = :user_id
                    AND phase_id = :phase_id"
            )->execute([
                'pct' => $pct,
                'completed' => $completed,
                'total' => $total,
                'total2' => $total,
                'completed2' => $completed,
                'completed3' => $completed,
                'total3' => $total,
                'total4' => $total,
                'user_id' => $userId,
                'phase_id' => $phaseId,
            ]);
        }
    }

    $pdo->commit();

    echo "\n[OK] 8 aulas do Módulo 1 agora possuem 3 atividades obrigatórias.\n";
    echo "[OK] Atividade 1 = prática guiada.\n";
    echo "[OK] Atividade 2 = desafio sem código.\n";
    echo "[OK] Atividade 3 = reforço sem código.\n";
    echo "[OK] A próxima aula só é concluída/liberada após as três atividades.\n";
    echo "[OK] Tentativas e progresso de laboratório existentes foram preservados.\n";
    printf("[OK] Usuários com conclusão prematura reconciliados: %d.\n", count($affectedUsers));
    echo "[OK] Nenhum conteúdo do Preparatório MI foi alterado.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(7);
}
