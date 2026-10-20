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

function tableExistsPgModule1V1(PDO $pdo, string $table): bool
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
    static fn (string $table): bool => !tableExistsPgModule1V1($pdo, $table)
));

if ($missing !== []) {
    fwrite(
        STDERR,
        "ABORTADO: tabelas ausentes: " . implode(', ', $missing) . "\n"
    );
    exit(1);
}

$courseSlug = 'postgresql-zero-intermediario';
$moduleSlug = 'consultando-dados-select';

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
        "ABORTADO: Módulo 1 não encontrado. Execute database/criar_grade_postgresql_v1.php.\n"
    );
    exit(1);
}

if ((string) $module['status'] !== 'draft') {
    fwrite(STDERR, "ABORTADO: o Módulo 1 já não está em draft.\n");
    exit(1);
}

$lessons = [
    'select-colunas' => [
        'summary' => 'Domine a projeção de dados escolhendo exatamente as colunas que cada consulta deve retornar.',
        'minutes' => 18,
        'blocks' => [
            [
                'theory',
                'SELECT define a projeção',
                "Quando usamos SELECT, decidimos quais informações farão parte do resultado. Isso é chamado de projeção.\n\nSELECT nome, categoria\nFROM produtos_catalogo;\n\nA tabela pode possuir muitas colunas, mas a consulta retorna somente nome e categoria. Essa escolha torna a intenção do SQL mais clara e reduz dados desnecessários.",
            ],
            [
                'comparison',
                'SELECT * ou colunas explícitas?',
                "Forma | Quando usar\nSELECT * | Exploração rápida e inspeção durante estudo ou diagnóstico\nSELECT coluna1, coluna2 | Consultas de aplicação, relatórios e código mantido\n\nSELECT * depende da estrutura completa da tabela. Se novas colunas surgirem, o resultado também muda. Colunas explícitas deixam o contrato da consulta previsível.",
            ],
            [
                'summary',
                'Ordem das colunas também importa',
                "A ordem declarada depois de SELECT é a ordem apresentada no resultado.\n\nSELECT categoria, nome FROM produtos_catalogo;\n\nproduz um resultado diferente de:\n\nSELECT nome, categoria FROM produtos_catalogo;\n\nOs registros consultados são os mesmos, mas a projeção mudou.",
            ],
        ],
        'lab' => [
            'title' => 'Projetando apenas as colunas necessárias',
            'instructions' => "Retorne somente nome e categoria da tabela produtos_catalogo, nessa ordem. Ordene os registros pelo id.",
            'starter' => "SELECT nome, categoria\nFROM produtos_catalogo\nORDER BY id;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome', 'categoria'],
                'rows' => [
                    ['nome' => 'Teclado', 'categoria' => 'Perifericos'],
                    ['nome' => 'Mouse', 'categoria' => 'Perifericos'],
                    ['nome' => 'Monitor', 'categoria' => 'Video'],
                    ['nome' => 'Webcam', 'categoria' => 'Video'],
                    ['nome' => 'SSD', 'categoria' => 'Armazenamento'],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS produtos_catalogo;\nCREATE TABLE produtos_catalogo (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    categoria text NOT NULL,\n    preco integer NOT NULL,\n    estoque integer NOT NULL\n);\nINSERT INTO produtos_catalogo (id, nome, categoria, preco, estoque) VALUES\n    (1, 'Teclado', 'Perifericos', 120, 8),\n    (2, 'Mouse', 'Perifericos', 80, 15),\n    (3, 'Monitor', 'Video', 950, 3),\n    (4, 'Webcam', 'Video', 220, 0),\n    (5, 'SSD', 'Armazenamento', 430, 6);",
            'state_version' => 7,
            'xp' => 20,
            'hints' => [
                ['Escolha as colunas', 'Depois de SELECT, escreva nome, categoria.'],
                ['Mantenha a ordem', 'Use ORDER BY id para receber os registros na sequência esperada.'],
            ],
        ],
    ],

    'aliases-as' => [
        'summary' => 'Use aliases para tornar nomes de colunas e expressões mais claros no resultado.',
        'minutes' => 17,
        'blocks' => [
            [
                'theory',
                'Alias é um nome temporário',
                "AS cria um nome para a coluna no resultado sem renomear a coluna real da tabela.\n\nSELECT nome AS produto\nFROM produtos_catalogo;\n\nA coluna continua se chamando nome dentro da tabela. Somente a saída dessa consulta passa a apresentar o rótulo produto.",
            ],
            [
                'example',
                'Aliases deixam relatórios legíveis',
                "Aliases são especialmente úteis em expressões e relatórios:\n\nSELECT nome AS produto, preco AS valor_unitario\nFROM produtos_catalogo;\n\nPara nomes simples, o PostgreSQL aceita alias sem AS, mas usar AS em colunas melhora a leitura para quem está começando e deixa a intenção explícita.",
            ],
            [
                'warning',
                'Aspas duplas preservam capitalização e espaços',
                "SELECT nome AS \"Nome do Produto\" ... funciona, mas o alias passa a exigir exatamente aquela grafia quando for referenciado em determinados contextos.\n\nEm código, prefira aliases previsíveis como produto ou valor_unitario. Isso evita dependência de espaços e letras maiúsculas.",
            ],
        ],
        'lab' => [
            'title' => 'Criando aliases claros',
            'instructions' => "Retorne nome com o alias produto e categoria com o alias tipo. Ordene pelo id.",
            'starter' => "SELECT\n    nome AS produto,\n    categoria AS tipo\nFROM produtos_catalogo\nORDER BY id;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['produto', 'tipo'],
                'rows' => [
                    ['produto' => 'Teclado', 'tipo' => 'Perifericos'],
                    ['produto' => 'Mouse', 'tipo' => 'Perifericos'],
                    ['produto' => 'Monitor', 'tipo' => 'Video'],
                    ['produto' => 'Webcam', 'tipo' => 'Video'],
                    ['produto' => 'SSD', 'tipo' => 'Armazenamento'],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS produtos_catalogo;\nCREATE TABLE produtos_catalogo (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    categoria text NOT NULL,\n    preco integer NOT NULL,\n    estoque integer NOT NULL\n);\nINSERT INTO produtos_catalogo (id, nome, categoria, preco, estoque) VALUES\n    (1, 'Teclado', 'Perifericos', 120, 8),\n    (2, 'Mouse', 'Perifericos', 80, 15),\n    (3, 'Monitor', 'Video', 950, 3),\n    (4, 'Webcam', 'Video', 220, 0),\n    (5, 'SSD', 'Armazenamento', 430, 6);",
            'state_version' => 8,
            'xp' => 20,
            'hints' => [
                ['Primeiro alias', 'Use nome AS produto.'],
                ['Segundo alias', 'Use categoria AS tipo e mantenha ORDER BY id.'],
            ],
        ],
    ],

    'distinct' => [
        'summary' => 'Remova combinações duplicadas do resultado usando DISTINCT com consciência do conjunto selecionado.',
        'minutes' => 18,
        'blocks' => [
            [
                'theory',
                'DISTINCT atua sobre o resultado',
                "DISTINCT elimina linhas repetidas considerando todas as colunas selecionadas.\n\nSELECT DISTINCT cidade\nFROM clientes_catalogo;\n\nSe dez clientes forem de Cuiaba, a cidade aparece uma única vez nesse resultado.",
            ],
            [
                'warning',
                'DISTINCT não significa uma coluna isolada',
                "Em SELECT DISTINCT cidade, status, o PostgreSQL procura combinações únicas de cidade e status. A mesma cidade poderá aparecer mais de uma vez se estiver associada a status diferentes.\n\nAntes de usar DISTINCT, pergunte: qual combinação eu realmente quero tornar única?",
            ],
            [
                'example',
                'Quando DISTINCT faz sentido',
                "Use DISTINCT para descobrir categorias existentes, cidades atendidas ou outras listas sem repetição.\n\nNão use DISTINCT apenas para esconder duplicações causadas por uma consulta mal construída. Mais adiante, JOINs e modelagem ajudarão a identificar a origem dessas repetições.",
            ],
        ],
        'lab' => [
            'title' => 'Listando cidades sem repetição',
            'instructions' => "Retorne as cidades distintas da tabela clientes_catalogo em ordem alfabética.",
            'starter' => "SELECT DISTINCT cidade\nFROM clientes_catalogo\nORDER BY cidade;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['cidade'],
                'rows' => [
                    ['cidade' => 'Cuiaba'],
                    ['cidade' => 'Sinop'],
                    ['cidade' => 'Varzea Grande'],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS clientes_catalogo;\nCREATE TABLE clientes_catalogo (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    cidade text NOT NULL,\n    ativo boolean NOT NULL\n);\nINSERT INTO clientes_catalogo (id, nome, cidade, ativo) VALUES\n    (1, 'Ana', 'Cuiaba', true),\n    (2, 'Bruno', 'Varzea Grande', true),\n    (3, 'Carla', 'Cuiaba', false),\n    (4, 'Diego', 'Sinop', true),\n    (5, 'Elisa', 'Cuiaba', true);",
            'state_version' => 9,
            'xp' => 20,
            'hints' => [
                ['Palavra-chave', 'DISTINCT vem logo depois de SELECT.'],
                ['Ordenação', 'Use ORDER BY cidade para comparar o resultado com facilidade.'],
            ],
        ],
    ],

    'where-comparacoes' => [
        'summary' => 'Filtre linhas com WHERE e os principais operadores de comparação.',
        'minutes' => 20,
        'blocks' => [
            [
                'theory',
                'WHERE decide quais linhas entram',
                "WHERE aplica uma condição a cada linha e mantém apenas aquelas em que a expressão é verdadeira.\n\nSELECT nome\nFROM produtos_catalogo\nWHERE preco >= 200;\n\nA projeção continua sendo definida por SELECT; WHERE controla o conjunto de linhas.",
            ],
            [
                'comparison',
                'Operadores de comparação',
                "Operador | Significado\n= | igual\n<> ou != | diferente\n> | maior que\n>= | maior ou igual\n< | menor que\n<= | menor ou igual\n\nTextos normalmente aparecem entre aspas simples, por exemplo categoria = 'Video'. Valores numéricos não precisam dessas aspas.",
            ],
            [
                'warning',
                'Igualdade em SQL usa um único =',
                "Em várias linguagens de programação, == significa comparação. Em SQL, igualdade é escrita com apenas um sinal de igual:\n\nWHERE categoria = 'Video'\n\nO operador <> é a forma tradicional SQL para diferente, embora PostgreSQL também aceite !=.",
            ],
        ],
        'lab' => [
            'title' => 'Filtrando por preço',
            'instructions' => "Retorne somente o nome dos produtos com preço maior ou igual a 200. Ordene alfabeticamente pelo nome.",
            'starter' => "SELECT nome\nFROM produtos_catalogo\nWHERE preco >= 200\nORDER BY nome;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Monitor'],
                    ['nome' => 'SSD'],
                    ['nome' => 'Webcam'],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS produtos_catalogo;\nCREATE TABLE produtos_catalogo (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    categoria text NOT NULL,\n    preco integer NOT NULL,\n    estoque integer NOT NULL\n);\nINSERT INTO produtos_catalogo (id, nome, categoria, preco, estoque) VALUES\n    (1, 'Teclado', 'Perifericos', 120, 8),\n    (2, 'Mouse', 'Perifericos', 80, 15),\n    (3, 'Monitor', 'Video', 950, 3),\n    (4, 'Webcam', 'Video', 220, 0),\n    (5, 'SSD', 'Armazenamento', 430, 6);",
            'state_version' => 10,
            'xp' => 25,
            'hints' => [
                ['Condição', 'A condição deve comparar preco com 200 usando >=.'],
                ['Saída', 'Selecione apenas nome e ordene com ORDER BY nome.'],
            ],
        ],
    ],

    'and-or-not' => [
        'summary' => 'Combine condições com AND, OR e NOT e use parênteses para deixar a lógica explícita.',
        'minutes' => 22,
        'blocks' => [
            [
                'theory',
                'AND exige todas as condições',
                "Com AND, uma linha só passa pelo filtro se todas as condições forem verdadeiras.\n\nWHERE ativo = true AND cidade = 'Cuiaba'\n\nEsse filtro não aceita um cliente inativo de Cuiaba nem um cliente ativo de outra cidade.",
            ],
            [
                'theory',
                'OR amplia alternativas',
                "OR aceita uma linha quando pelo menos uma condição é verdadeira.\n\nWHERE cidade = 'Cuiaba' OR cidade = 'Sinop'\n\nNOT inverte uma condição, mas deve ser usado de forma legível. Muitas vezes existem formas mais claras, como <> ou NOT IN, dependendo do caso.",
            ],
            [
                'warning',
                'Parênteses evitam ambiguidade humana',
                "AND possui precedência maior que OR. Mesmo conhecendo essa regra, use parênteses quando combinar operadores:\n\nWHERE ativo = true\n  AND (cidade = 'Cuiaba' OR cidade = 'Sinop')\n\nO código fica mais fácil de revisar e reduz interpretações erradas.",
            ],
        ],
        'lab' => [
            'title' => 'Combinando condições corretamente',
            'instructions' => "Liste o nome dos clientes ativos que estejam em Cuiaba ou Sinop. Ordene pelo nome.",
            'starter' => "SELECT nome\nFROM clientes_catalogo\nWHERE ativo = true\n  AND (cidade = 'Cuiaba' OR cidade = 'Sinop')\nORDER BY nome;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Ana'],
                    ['nome' => 'Diego'],
                    ['nome' => 'Elisa'],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS clientes_catalogo;\nCREATE TABLE clientes_catalogo (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    cidade text NOT NULL,\n    ativo boolean NOT NULL\n);\nINSERT INTO clientes_catalogo (id, nome, cidade, ativo) VALUES\n    (1, 'Ana', 'Cuiaba', true),\n    (2, 'Bruno', 'Varzea Grande', true),\n    (3, 'Carla', 'Cuiaba', false),\n    (4, 'Diego', 'Sinop', true),\n    (5, 'Elisa', 'Cuiaba', true);",
            'state_version' => 11,
            'xp' => 25,
            'hints' => [
                ['Primeiro filtro', 'Exija ativo = true com AND.'],
                ['Agrupe as cidades', 'Coloque as duas alternativas de cidade entre parênteses ligadas por OR.'],
            ],
        ],
    ],

    'like-in-between' => [
        'summary' => 'Use LIKE, IN e BETWEEN para filtros expressivos sem repetir comparações desnecessárias.',
        'minutes' => 23,
        'blocks' => [
            [
                'theory',
                'LIKE procura padrões em texto',
                "LIKE compara texto com um padrão. O caractere % representa qualquer sequência de caracteres e _ representa um único caractere.\n\nWHERE nome LIKE 'M%'\n\nprocura nomes iniciados por M. No PostgreSQL, ILIKE faz comparação semelhante ignorando diferenças entre maiúsculas e minúsculas.",
            ],
            [
                'comparison',
                'IN reduz vários OR',
                "WHERE categoria IN ('Perifericos', 'Video')\n\né uma forma clara de expressar que categoria pode assumir qualquer valor daquela lista. É equivalente a várias comparações ligadas por OR, mas costuma ser mais fácil de ler e manter.",
            ],
            [
                'memory',
                'BETWEEN inclui as extremidades',
                "WHERE preco BETWEEN 100 AND 500\n\ninclui 100 e 500. Para números, datas e outros valores ordenáveis, BETWEEN é uma forma compacta de representar um intervalo inclusivo.\n\nLembre: BETWEEN a AND b equivale, em essência, a valor >= a AND valor <= b.",
            ],
        ],
        'lab' => [
            'title' => 'Combinando IN, BETWEEN e LIKE',
            'instructions' => "Retorne o nome dos produtos das categorias Perifericos ou Video, com preço entre 100 e 500, cujo nome contenha a letra a. Use ILIKE para não depender de maiúsculas/minúsculas e ordene pelo nome.",
            'starter' => "SELECT nome\nFROM produtos_catalogo\nWHERE categoria IN ('Perifericos', 'Video')\n  AND preco BETWEEN 100 AND 500\n  AND nome ILIKE '%a%'\nORDER BY nome;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Teclado'],
                    ['nome' => 'Webcam'],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS produtos_catalogo;\nCREATE TABLE produtos_catalogo (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    categoria text NOT NULL,\n    preco integer NOT NULL,\n    estoque integer NOT NULL\n);\nINSERT INTO produtos_catalogo (id, nome, categoria, preco, estoque) VALUES\n    (1, 'Teclado', 'Perifericos', 120, 8),\n    (2, 'Mouse', 'Perifericos', 80, 15),\n    (3, 'Monitor', 'Video', 950, 3),\n    (4, 'Webcam', 'Video', 220, 0),\n    (5, 'SSD', 'Armazenamento', 430, 6);",
            'state_version' => 12,
            'xp' => 30,
            'hints' => [
                ['Lista e intervalo', "Use categoria IN (...) e preco BETWEEN 100 AND 500."],
                ['Padrão de texto', "Use nome ILIKE '%a%' para procurar a letra em qualquer posição."],
            ],
        ],
    ],

    'is-null' => [
        'summary' => 'Entenda NULL como ausência de valor e filtre corretamente com IS NULL e IS NOT NULL.',
        'minutes' => 20,
        'blocks' => [
            [
                'theory',
                'NULL não é zero nem texto vazio',
                "NULL representa ausência ou desconhecimento de um valor. Ele não é igual a 0, não é igual a '' e também não é igual a outro NULL no sentido de uma comparação comum.\n\nPor isso, WHERE email = NULL não é a forma correta de procurar valores ausentes.",
            ],
            [
                'theory',
                'Use IS NULL e IS NOT NULL',
                "Para verificar ausência:\n\nWHERE email IS NULL\n\nPara verificar presença:\n\nWHERE email IS NOT NULL\n\nEsses operadores existem porque a lógica envolvendo NULL possui um terceiro estado: desconhecido, além de verdadeiro e falso.",
            ],
            [
                'warning',
                'Comparações com NULL confundem iniciantes',
                "Uma expressão como NULL = NULL não produz verdadeiro. Em SQL, o resultado é desconhecido.\n\nMemorize desde cedo: para testar NULL, use IS NULL ou IS NOT NULL. Essa regra evita uma das falhas mais comuns em consultas.",
            ],
        ],
        'lab' => [
            'title' => 'Encontrando cadastros sem e-mail',
            'instructions' => "Retorne o nome dos clientes cujo e-mail ainda não foi informado. Ordene pelo nome.",
            'starter' => "SELECT nome\nFROM contatos_clientes\nWHERE email IS NULL\nORDER BY nome;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome'],
                'rows' => [
                    ['nome' => 'Bruno'],
                    ['nome' => 'Diego'],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS contatos_clientes;\nCREATE TABLE contatos_clientes (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    email text NULL\n);\nINSERT INTO contatos_clientes (id, nome, email) VALUES\n    (1, 'Ana', 'ana@exemplo.com'),\n    (2, 'Bruno', NULL),\n    (3, 'Carla', 'carla@exemplo.com'),\n    (4, 'Diego', NULL),\n    (5, 'Elisa', 'elisa@exemplo.com');",
            'state_version' => 13,
            'xp' => 25,
            'hints' => [
                ['Operador correto', 'Não use = NULL. Use IS NULL.'],
                ['Ordenação', 'Finalize com ORDER BY nome.'],
            ],
        ],
    ],

    'order-by-limit' => [
        'summary' => 'Controle a ordem do resultado e implemente recortes e paginação básica com LIMIT e OFFSET.',
        'minutes' => 22,
        'blocks' => [
            [
                'theory',
                'ORDER BY torna a saída determinística',
                "Sem ORDER BY, o banco não promete uma ordem específica para as linhas. Se a ordem importa para a tela, relatório ou validação, declare-a.\n\nORDER BY preco DESC\n\nDESC ordena do maior para o menor. ASC é crescente e é o padrão quando omitido.",
            ],
            [
                'example',
                'Desempate com mais de uma coluna',
                "ORDER BY preco DESC, id ASC\n\nprimeiro ordena pelo preço do maior para o menor. Se dois itens possuírem o mesmo preço, o id crescente decide a ordem entre eles. Essa técnica evita resultados instáveis em paginação.",
            ],
            [
                'theory',
                'LIMIT e OFFSET recortam o resultado',
                "LIMIT define quantas linhas serão retornadas. OFFSET informa quantas linhas devem ser ignoradas antes do recorte.\n\nORDER BY preco DESC, id ASC\nLIMIT 2 OFFSET 2\n\nrepresenta, de forma simples, uma segunda página com duas linhas, considerando que a primeira página consumiu as duas primeiras.",
            ],
        ],
        'lab' => [
            'title' => 'Montando a segunda página do catálogo',
            'instructions' => "Retorne nome e preco da segunda página do catálogo, com duas linhas por página. Ordene do maior preço para o menor e use id crescente como desempate. A segunda página deve usar LIMIT 2 OFFSET 2.",
            'starter' => "SELECT nome, preco\nFROM produtos_paginacao\nORDER BY preco DESC, id ASC\nLIMIT 2 OFFSET 2;",
            'validator_type' => 'query_result',
            'validator' => [
                'columns' => ['nome', 'preco'],
                'rows' => [
                    ['nome' => 'SSD', 'preco' => 430],
                    ['nome' => 'Webcam', 'preco' => 220],
                ],
                'ordered' => true,
            ],
            'bootstrap_sql' => "DROP TABLE IF EXISTS produtos_paginacao;\nCREATE TABLE produtos_paginacao (\n    id integer PRIMARY KEY,\n    nome text NOT NULL,\n    preco integer NOT NULL\n);\nINSERT INTO produtos_paginacao (id, nome, preco) VALUES\n    (1, 'Teclado', 120),\n    (2, 'Mouse', 80),\n    (3, 'Monitor', 950),\n    (4, 'Webcam', 220),\n    (5, 'SSD', 430),\n    (6, 'Notebook', 3200);",
            'state_version' => 14,
            'xp' => 30,
            'hints' => [
                ['Ordenação antes do recorte', 'Use ORDER BY preco DESC, id ASC antes de LIMIT e OFFSET.'],
                ['Segunda página', 'Com duas linhas por página, a segunda página ignora as duas primeiras: OFFSET 2.'],
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
echo " LD ACADEMY - POSTGRESQL MODULO 1 - SELECT V1\n";
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
        "%-30s blocos %d->%d | labs %d->1 | hints %d\n",
        $slug,
        (int) $blockStmt->fetchColumn(),
        $blocks,
        (int) $labStmt->fetchColumn(),
        $hints
    );
}

echo "\nPrevisto: 8 aulas, {$totalBlocks} blocos, {$totalLabs} laboratórios, {$totalHints} dicas.\n";
echo "Todos permanecem em rascunho.\n";

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup e revisão, execute:\n";
    echo "php database/popular_postgresql_modulo1_v1.php --apply";
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
            'xp_reward' => 25,
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

    echo "\n[OK] 8 aulas do Módulo 1 preenchidas.\n";
    echo "[OK] {$totalBlocks} blocos didáticos sincronizados.\n";
    echo "[OK] 8 laboratórios PGlite criados em rascunho.\n";
    echo "[OK] {$totalHints} dicas de laboratório sincronizadas.\n";
    echo "[OK] Curso, módulo, fases, aulas e labs continuam sem publicação.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
