<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
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
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Revise o dry-run, faça backup e use --apply --force-production.\n"
    );
    exit(1);
}

$pdo = Database::connection();

function tableExistsPgModule0V1(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT 1
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = :table_name
          LIMIT 1"
    );
    $stmt->execute(['table_name' => $table]);

    return (bool) $stmt->fetchColumn();
}

$requiredTables = [
    'courses',
    'modules',
    'phases',
    'lessons',
    'lesson_blocks',
    'lab_activities',
    'lab_activity_hints',
];

$missing = array_values(array_filter(
    $requiredTables,
    static fn (string $table): bool => !tableExistsPgModule0V1($pdo, $table)
));

if ($missing !== []) {
    fwrite(
        STDERR,
        "ABORTADO: tabelas ausentes: " . implode(', ', $missing) . "\n"
    );
    exit(1);
}

$courseSlug = 'postgresql-zero-intermediario';
$moduleSlug = 'primeiros-passos-postgresql';

$courseStmt = $pdo->prepare(
    "SELECT id, title, status, experience_key
       FROM courses
      WHERE slug = :slug
      LIMIT 1"
);
$courseStmt->execute(['slug' => $courseSlug]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(
        STDERR,
        "ABORTADO: curso PostgreSQL não encontrado. Execute primeiro database/criar_curso_postgresql_v1.php.\n"
    );
    exit(1);
}

if ((string) ($course['experience_key'] ?? '') !== 'postgresql') {
    fwrite(STDERR, "ABORTADO: experience_key do curso não é postgresql.\n");
    exit(1);
}

if ((string) $course['status'] !== 'draft') {
    fwrite(
        STDERR,
        "ABORTADO: o curso já não está em draft. Este script não altera conteúdo publicado.\n"
    );
    exit(1);
}

$moduleStmt = $pdo->prepare(
    "SELECT id, title, status
       FROM modules
      WHERE course_id = :course_id
        AND slug = :slug
      LIMIT 1"
);
$moduleStmt->execute([
    'course_id' => (int) $course['id'],
    'slug' => $moduleSlug,
]);
$module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(
        STDERR,
        "ABORTADO: Módulo 0 não encontrado. Execute database/criar_grade_postgresql_v1.php.\n"
    );
    exit(1);
}

if ((string) $module['status'] !== 'draft') {
    fwrite(STDERR, "ABORTADO: o Módulo 0 já não está em draft.\n");
    exit(1);
}

$lessons = [
    'banco-de-dados-e-postgresql' => [
        'summary' => 'Entenda o papel de um banco de dados, de um SGBD e do PostgreSQL antes de escrever SQL.',
        'minutes' => 14,
        'blocks' => [
            [
                'theory',
                'O que é um banco de dados',
                "Um banco de dados é uma coleção organizada de informações mantidas para consulta e atualização. Em uma aplicação real, ele guarda fatos que precisam permanecer disponíveis mesmo depois que o programa fecha.\n\nPense em clientes, produtos, pedidos, usuários e atendimentos. Esses dados precisam ser armazenados com estrutura, consistência e possibilidade de pesquisa.\n\n- dado: um valor ou fato registrado\n- banco de dados: o conjunto organizado desses dados\n- aplicação: o sistema que lê e altera os dados conforme as regras do negócio",
            ],
            [
                'comparison',
                'Banco de dados, SGBD e PostgreSQL',
                "Conceito | Papel\nBanco de dados | Onde os dados organizados são mantidos\nSGBD | Software que cria, consulta, protege e administra bancos\nPostgreSQL | SGBD usado neste curso\nSQL | Linguagem usada para conversar com o banco\n\nO PostgreSQL recebe comandos SQL, verifica regras, executa operações e devolve resultados. O banco é o conteúdo; o PostgreSQL é o sistema que gerencia esse conteúdo.",
            ],
            [
                'summary',
                'Por que começar pelo PostgreSQL',
                "O PostgreSQL é adequado para aprender SQL porque trabalha com tabelas, relacionamentos, restrições, transações e consultas avançadas usadas em aplicações reais.\n\nNeste curso você não vai apenas decorar comandos. Vamos construir progressivamente o banco da LD Informática e usar os mesmos conceitos em consultas, cadastros, relatórios e manutenção de dados.\n\nMemorize: SQL é a linguagem; PostgreSQL é o SGBD; suas tabelas e registros formam o banco que a aplicação utiliza.",
            ],
        ],
        'lab' => [
            'title' => 'Confirmando que o PostgreSQL está ativo',
            'instructions' => "Execute uma consulta simples que devolva uma coluna chamada conectado com uma linha. O objetivo é validar seu primeiro contato com o PostgreSQL executado no navegador.",
            'starter' => "SELECT 1 AS conectado;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['conectado'],
                'row_count' => 1,
            ],
            'bootstrap_sql' => "SELECT 1;",
            'state_version' => 1,
            'xp' => 15,
            'hints' => [
                ['Primeira pista', 'Uma consulta pode retornar um valor constante sem acessar uma tabela. Use SELECT.'],
                ['Segunda pista', 'Use AS conectado para dar o nome esperado à coluna.'],
            ],
        ],
    ],

    'tabelas-linhas-e-colunas' => [
        'summary' => 'Visualize como tabelas organizam entidades em colunas e registros em linhas.',
        'minutes' => 16,
        'blocks' => [
            [
                'theory',
                'A tabela como estrutura',
                "Em um banco relacional, uma tabela representa um conjunto de registros do mesmo tipo. Uma tabela clientes pode guardar vários clientes; uma tabela produtos pode guardar vários produtos.\n\nAs colunas definem quais características cada registro possui. As linhas armazenam ocorrências concretas.\n\nid | nome | cidade\n1 | Ana | Cuiaba\n2 | Bruno | Varzea Grande\n\nNesse exemplo, id, nome e cidade são colunas. Cada cliente é uma linha.",
            ],
            [
                'theory',
                'Colunas têm significado e tipo',
                "Uma coluna não é apenas uma posição visual. Ela tem nome e tipo de dado, e pode receber regras. Um id costuma ser numérico; um nome costuma ser texto; uma data usa um tipo temporal.\n\nO tipo ajuda o PostgreSQL a impedir operações sem sentido e a armazenar cada valor de forma apropriada. Mais adiante você definirá tipos, chaves e restrições ao criar suas próprias tabelas.",
            ],
            [
                'memory',
                'Linha não é coluna',
                "Use esta regra mental:\n\n- coluna responde: que informação existe sobre cada item?\n- linha responde: qual item específico está registrado?\n\nEm clientes, nome é uma coluna. O registro de Ana é uma linha. Essa distinção será essencial para entender SELECT, INSERT, UPDATE e relacionamentos.",
            ],
        ],
        'lab' => [
            'title' => 'Lendo uma tabela de clientes',
            'instructions' => "Consulte a tabela clientes e retorne as colunas id, nome e cidade, nessa ordem. Ordene pelo id para manter a saída previsível.",
            'starter' => "SELECT id, nome, cidade\nFROM clientes\nORDER BY id;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['id', 'nome', 'cidade'],
                'row_count' => 3,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS clientes;\nCREATE TABLE clientes (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    cidade text NOT NULL\n);\nINSERT INTO clientes (id, nome, cidade) VALUES\n    (1, 'Ana', 'Cuiaba'),\n    (2, 'Bruno', 'Varzea Grande'),\n    (3, 'Carla', 'Cuiaba');",
            'state_version' => 2,
            'xp' => 20,
            'hints' => [
                ['Colunas', 'Depois de SELECT, escreva id, nome, cidade separados por vírgula.'],
                ['Origem dos dados', 'A cláusula FROM deve apontar para a tabela clientes.'],
            ],
        ],
    ],

    'sql-e-ferramentas' => [
        'summary' => 'Entenda o papel do SQL, do pgAdmin, do psql e do laboratório PGlite usado no curso.',
        'minutes' => 15,
        'blocks' => [
            [
                'theory',
                'SQL é a linguagem',
                "SQL significa Structured Query Language. É a linguagem usada para consultar e manipular dados relacionais e também para definir estruturas do banco.\n\nDurante o curso você verá grupos de comandos na prática:\n- consulta de dados com SELECT\n- criação e alteração de estruturas\n- inclusão, alteração e exclusão de registros\n- controle de transações e outros recursos do PostgreSQL",
            ],
            [
                'comparison',
                'pgAdmin, psql e laboratório do curso',
                "Ferramenta | Uso principal\npgAdmin | Interface gráfica para administrar e consultar PostgreSQL\npsql | Cliente de linha de comando do PostgreSQL\nLaboratório LD Academy | PostgreSQL executado no navegador para prática guiada\n\nA sintaxe SQL aprendida aqui pode ser reaproveitada em pgAdmin e psql. O laboratório reduz a barreira inicial porque você pratica sem instalar um servidor local.",
            ],
            [
                'warning',
                'Laboratório não é o banco de produção',
                "O PostgreSQL do laboratório roda isolado no seu navegador. Ele existe para estudo e não utiliza o banco MySQL/MariaDB que mantém usuários, matrículas e progresso da plataforma.\n\nEssa separação é intencional: um comando SQL digitado pelo aluno nunca deve ser executado diretamente no banco principal da LD Academy.",
            ],
        ],
        'lab' => [
            'title' => 'Identificando o ambiente SQL',
            'instructions' => "Execute a consulta preparada. Ela deve retornar uma coluna chamada versao com uma linha, mostrando que você está conversando com um PostgreSQL real no navegador.",
            'starter' => "SELECT version() AS versao;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['versao'],
                'row_count' => 1,
            ],
            'bootstrap_sql' => "SELECT 1;",
            'state_version' => 3,
            'xp' => 15,
            'hints' => [
                ['Função pronta', 'O PostgreSQL possui a função version(), que retorna informações do servidor.'],
                ['Alias', 'Mantenha AS versao para que o nome da coluna seja validado.'],
            ],
        ],
    ],

    'primeiro-select' => [
        'summary' => 'Escreva sua primeira consulta SELECT e controle quais colunas aparecem no resultado.',
        'minutes' => 17,
        'blocks' => [
            [
                'theory',
                'A anatomia mínima do SELECT',
                "Uma consulta básica pode ser lida quase como uma frase:\n\nSELECT nome, preco\nFROM produtos;\n\nSELECT informa quais colunas queremos receber. FROM informa de qual tabela os dados serão lidos. O ponto e vírgula encerra a instrução e é uma boa prática, especialmente quando existem vários comandos.",
            ],
            [
                'example',
                'Selecionando somente o necessário',
                "Evite pensar em SELECT * como padrão automático. Durante a exploração ele é útil, mas em consultas de aplicação costuma ser melhor declarar as colunas necessárias.\n\nSELECT nome, preco FROM produtos;\n\nEssa consulta deixa claro o contrato do resultado e evita trazer colunas que a tela ou relatório não utiliza.",
            ],
            [
                'summary',
                'Leia o resultado como uma tabela',
                "O resultado de SELECT também possui colunas e linhas. As colunas do resultado são determinadas pela projeção da consulta; as linhas dependem dos registros encontrados.\n\nNeste momento foque em três perguntas:\n1. quais colunas estou pedindo?\n2. de qual tabela elas vêm?\n3. quantas linhas a consulta devolveu?",
            ],
        ],
        'lab' => [
            'title' => 'Seu primeiro SELECT em produtos',
            'instructions' => "Retorne nome e preco da tabela produtos, nessa ordem. Ordene pelo id para manter a sequência original dos registros.",
            'starter' => "SELECT nome, preco\nFROM produtos\nORDER BY id;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome', 'preco'],
                'row_count' => 4,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS produtos;\nCREATE TABLE produtos (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    preco numeric(10,2) NOT NULL,\n    estoque integer NOT NULL\n);\nINSERT INTO produtos (id, nome, preco, estoque) VALUES\n    (1, 'Teclado', 120.00, 8),\n    (2, 'Mouse', 80.00, 15),\n    (3, 'Monitor', 950.00, 3),\n    (4, 'Webcam', 220.00, 0);",
            'state_version' => 4,
            'xp' => 20,
            'hints' => [
                ['Projeção', 'Depois de SELECT, peça somente nome e preco.'],
                ['Tabela', 'Use FROM produtos e finalize ordenando por id.'],
            ],
        ],
    ],

    'consultando-tabela-pronta' => [
        'summary' => 'Explore um conjunto de dados pronto e comece a responder perguntas usando SELECT.',
        'minutes' => 18,
        'blocks' => [
            [
                'theory',
                'Consultar é responder perguntas',
                "Uma consulta útil parte de uma pergunta. Em vez de apenas executar SQL, formule o que você deseja descobrir.\n\nExemplo: quais produtos ainda possuem estoque?\n\nA partir dessa pergunta identificamos a tabela, as colunas desejadas e a condição que os registros precisam atender. Nos próximos módulos você aprofundará filtros; aqui o objetivo é perceber que SQL transforma perguntas em instruções precisas.",
            ],
            [
                'example',
                'Conhecendo antes de alterar',
                "Em rotinas profissionais, consulte antes de modificar. Antes de UPDATE ou DELETE, uma consulta SELECT com a mesma condição ajuda a conferir quais registros serão afetados.\n\nEssa disciplina reduz erros e será retomada no módulo de CRUD.",
            ],
            [
                'summary',
                'Observe colunas, linhas e valores',
                "Ao executar uma consulta, não olhe apenas se apareceu resultado. Confira:\n- nomes das colunas\n- quantidade de linhas\n- valores inesperados ou NULL\n- ordenação apresentada\n\nAprender SQL também é aprender a validar o que a consulta realmente fez.",
            ],
        ],
        'lab' => [
            'title' => 'Produtos disponíveis em estoque',
            'instructions' => "Liste nome e preco apenas dos produtos cujo estoque seja maior que zero. Ordene do menor preco para o maior.",
            'starter' => "SELECT nome, preco\nFROM produtos\nWHERE estoque > 0\nORDER BY preco;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome', 'preco'],
                'row_count' => 3,
            ],
            'bootstrap_sql' => "CREATE TABLE IF NOT EXISTS produtos (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    preco numeric(10,2) NOT NULL,\n    estoque integer NOT NULL\n);\nTRUNCATE TABLE produtos;\nINSERT INTO produtos (id, nome, preco, estoque) VALUES\n    (1, 'Teclado', 120.00, 8),\n    (2, 'Mouse', 80.00, 15),\n    (3, 'Monitor', 950.00, 3),\n    (4, 'Webcam', 220.00, 0);",
            'state_version' => 5,
            'xp' => 25,
            'hints' => [
                ['Condição', 'A coluna estoque precisa ser comparada com zero usando >.'],
                ['Ordenação', 'ORDER BY preco usa ordem crescente por padrão.'],
            ],
        ],
    ],

    'lendo-erros-sql' => [
        'summary' => 'Aprenda a interpretar erros de sintaxe e corrigir uma instrução sem depender de tentativa aleatória.',
        'minutes' => 18,
        'blocks' => [
            [
                'theory',
                'Erro é informação',
                "Quando o PostgreSQL rejeita um comando, a mensagem de erro é parte do processo de desenvolvimento. Ela costuma indicar a categoria do problema e, muitas vezes, a região aproximada onde o parser encontrou algo inválido.\n\nLeia a mensagem antes de reescrever tudo. Procure palavras-chave incorretas, vírgulas ausentes, nomes de tabelas ou colunas errados e aspas não fechadas.",
            ],
            [
                'warning',
                'Não corrija no escuro',
                "Uma estratégia ruim é alterar várias partes do SQL ao mesmo tempo sem entender o erro. Faça mudanças pequenas e execute novamente.\n\nChecklist inicial:\n- SELECT está escrito corretamente?\n- as colunas existem?\n- FROM aponta para uma tabela existente?\n- strings estão entre aspas simples?\n- parênteses e vírgulas estão completos?",
            ],
            [
                'case_study',
                'Depuração simples',
                "Considere: SELEC nome FROM produtos;\n\nO banco não conhece SELEC como palavra-chave válida. O erro não está nos dados nem na tabela: está na sintaxe. Corrigindo para SELECT, a instrução pode ser analisada novamente.\n\nNo laboratório você receberá justamente uma consulta com esse tipo de erro e deverá corrigi-la.",
            ],
        ],
        'lab' => [
            'title' => 'Corrigindo uma consulta inválida',
            'instructions' => "O código inicial contém um erro de sintaxe. Corrija a consulta para retornar a coluna nome de todos os produtos e ordene alfabeticamente pelo nome.",
            'starter' => "SELEC nome\nFROM produtos\nORDER BY nome;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome'],
                'row_count' => 4,
            ],
            'bootstrap_sql' => "CREATE TABLE IF NOT EXISTS produtos (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    preco numeric(10,2) NOT NULL,\n    estoque integer NOT NULL\n);\nTRUNCATE TABLE produtos;\nINSERT INTO produtos (id, nome, preco, estoque) VALUES\n    (1, 'Teclado', 120.00, 8),\n    (2, 'Mouse', 80.00, 15),\n    (3, 'Monitor', 950.00, 3),\n    (4, 'Webcam', 220.00, 0);",
            'state_version' => 6,
            'xp' => 25,
            'hints' => [
                ['Leia a primeira palavra', 'A palavra-chave que inicia uma consulta é SELECT, com T no final.'],
                ['Resultado esperado', 'Depois de corrigir SELECT, mantenha nome, FROM produtos e ORDER BY nome.'],
            ],
        ],
    ],
];

$lessonSlugs = array_keys($lessons);
$placeholders = implode(',', array_fill(0, count($lessonSlugs), '?'));

$lookup = $pdo->prepare(
    "SELECT l.id, l.slug, l.title, l.status, p.slug AS phase_slug
       FROM lessons l
       INNER JOIN phases p ON p.id = l.phase_id
       INNER JOIN modules m ON m.id = p.module_id
      WHERE m.id = ?
        AND l.slug IN ({$placeholders})
      ORDER BY p.position, l.position"
);
$lookup->execute([(int) $module['id'], ...$lessonSlugs]);
$lessonRows = $lookup->fetchAll(PDO::FETCH_ASSOC);

$found = [];
foreach ($lessonRows as $row) {
    $found[(string) $row['slug']] = $row;
}

$missingLessons = array_values(array_diff($lessonSlugs, array_keys($found)));
if ($missingLessons !== []) {
    fwrite(
        STDERR,
        "ABORTADO: aulas não encontradas na grade: " . implode(', ', $missingLessons) . "\n"
    );
    exit(1);
}

foreach ($found as $row) {
    if ((string) $row['status'] !== 'draft') {
        fwrite(
            STDERR,
            "ABORTADO: a aula '{$row['title']}' não está mais em draft. Nenhum conteúdo será sobrescrito.\n"
        );
        exit(1);
    }
}

echo "=================================================================\n";
echo " LD ACADEMY - POSTGRESQL MODULO 0 - CONTEUDO V1\n";
echo "=================================================================\n";
echo 'Banco:  ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Curso:  #' . (int) $course['id'] . ' - ' . (string) $course['title'] . "\n";
echo 'Modulo: #' . (int) $module['id'] . ' - ' . (string) $module['title'] . "\n";
echo 'Modo:   ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

$totalBlocks = 0;
$totalLabs = 0;
$totalHints = 0;

foreach ($lessons as $slug => $definition) {
    $lessonId = (int) $found[$slug]['id'];

    $blockStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM lesson_blocks WHERE lesson_id = :lesson_id'
    );
    $blockStmt->execute(['lesson_id' => $lessonId]);

    $labStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM lab_activities WHERE lesson_id = :lesson_id'
    );
    $labStmt->execute(['lesson_id' => $lessonId]);

    $blocks = count($definition['blocks']);
    $hints = count($definition['lab']['hints']);
    $totalBlocks += $blocks;
    $totalLabs++;
    $totalHints += $hints;

    printf(
        "%-34s blocos %d->%d | labs %d->1 | hints %d\n",
        $slug,
        (int) $blockStmt->fetchColumn(),
        $blocks,
        (int) $labStmt->fetchColumn(),
        $hints
    );
}

echo "\nPrevisto: 6 aulas, {$totalBlocks} blocos, {$totalLabs} laboratórios, {$totalHints} dicas.\n";
echo "Todos permanecem em rascunho.\n";

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup e revisão, execute:\n";
    echo "php database/popular_postgresql_modulo0_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $updateLesson = $pdo->prepare(
        "UPDATE lessons
            SET summary = :summary,
                estimated_minutes = :estimated_minutes,
                xp_reward = :xp_reward,
                completion_mode = 'lab'
          WHERE id = :lesson_id
            AND status = 'draft'"
    );

    $upsertBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
            (lesson_id, block_type, title, content, media_url, position, is_required)
         VALUES
            (:lesson_id, :block_type, :title, :content, NULL, :position, 1)
         ON DUPLICATE KEY UPDATE
            block_type = VALUES(block_type),
            title = VALUES(title),
            content = VALUES(content),
            media_url = NULL,
            is_required = 1"
    );

    $upsertActivity = $pdo->prepare(
        "INSERT INTO lab_activities
            (lesson_id, engine_key, activity_type, title, instructions, starter_code,
             validator_type, validator_config, bootstrap_config, unlock_rule,
             state_version, xp_reward, is_required, max_attempts, position, status)
         VALUES
            (:lesson_id, 'postgresql_pglite', 'sql', :title, :instructions, :starter_code,
             :validator_type, :validator_config, :bootstrap_config, 'reading_complete',
             :state_version, :xp_reward, 1, NULL, 1, 'draft')
         ON DUPLICATE KEY UPDATE
            engine_key = VALUES(engine_key),
            activity_type = VALUES(activity_type),
            title = VALUES(title),
            instructions = VALUES(instructions),
            starter_code = VALUES(starter_code),
            validator_type = VALUES(validator_type),
            validator_config = VALUES(validator_config),
            bootstrap_config = VALUES(bootstrap_config),
            unlock_rule = VALUES(unlock_rule),
            state_version = VALUES(state_version),
            xp_reward = VALUES(xp_reward),
            is_required = 1,
            max_attempts = NULL,
            status = 'draft'"
    );

    $activityIdStmt = $pdo->prepare(
        "SELECT id
           FROM lab_activities
          WHERE lesson_id = :lesson_id
            AND position = 1
          LIMIT 1"
    );

    $upsertHint = $pdo->prepare(
        "INSERT INTO lab_activity_hints
            (activity_id, position, title, content, unlock_after_attempts, xp_penalty)
         VALUES
            (:activity_id, :position, :title, :content, :unlock_after_attempts, 0)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            content = VALUES(content),
            unlock_after_attempts = VALUES(unlock_after_attempts),
            xp_penalty = 0"
    );

    foreach ($lessons as $slug => $definition) {
        $lessonId = (int) $found[$slug]['id'];

        $updateLesson->execute([
            'summary' => $definition['summary'],
            'estimated_minutes' => (int) $definition['minutes'],
            'xp_reward' => 20,
            'lesson_id' => $lessonId,
        ]);

        foreach ($definition['blocks'] as $index => [$type, $title, $content]) {
            $upsertBlock->execute([
                'lesson_id' => $lessonId,
                'block_type' => $type,
                'title' => $title,
                'content' => $content,
                'position' => $index + 1,
            ]);
        }

        $lab = $definition['lab'];
        $upsertActivity->execute([
            'lesson_id' => $lessonId,
            'title' => $lab['title'],
            'instructions' => $lab['instructions'],
            'starter_code' => $lab['starter'],
            'validator_type' => $lab['validator_type'],
            'validator_config' => json_encode(
                $lab['validator'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'bootstrap_config' => json_encode(
                [
                    'sql' => $lab['bootstrap_sql'],
                    'statement_timeout_ms' => 10000,
                    'max_result_rows' => 100,
                ],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ),
            'state_version' => (int) $lab['state_version'],
            'xp_reward' => (int) $lab['xp'],
        ]);

        $activityIdStmt->execute(['lesson_id' => $lessonId]);
        $activityId = (int) ($activityIdStmt->fetchColumn() ?: 0);

        if ($activityId <= 0) {
            throw new RuntimeException(
                "Não foi possível localizar o laboratório criado para a aula {$slug}."
            );
        }

        foreach ($lab['hints'] as $index => [$title, $content]) {
            $upsertHint->execute([
                'activity_id' => $activityId,
                'position' => $index + 1,
                'title' => $title,
                'content' => $content,
                'unlock_after_attempts' => $index + 1,
            ]);
        }
    }

    $pdo->commit();

    echo "\n[OK] 6 aulas do Módulo 0 preenchidas.\n";
    echo "[OK] {$totalBlocks} blocos didáticos sincronizados.\n";
    echo "[OK] 6 laboratórios PGlite criados em rascunho.\n";
    echo "[OK] {$totalHints} dicas de laboratório sincronizadas.\n";
    echo "[OK] Curso, módulo, fases, aulas e labs continuam sem publicação.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
