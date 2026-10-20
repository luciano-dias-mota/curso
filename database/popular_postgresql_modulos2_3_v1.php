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

function pgContentTableExists(PDO $pdo, string $table): bool
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
    static fn (string $table): bool => !pgContentTableExists($pdo, $table)
));

if ($missing !== []) {
    fwrite(STDERR, "ABORTADO: tabelas ausentes: " . implode(', ', $missing) . "\n");
    exit(1);
}

$courseStmt = $pdo->prepare(
    "SELECT id, title, status, experience_key
       FROM courses
      WHERE slug = 'postgresql-zero-intermediario'
      LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course || (string) ($course['experience_key'] ?? '') !== 'postgresql') {
    fwrite(STDERR, "ABORTADO: curso PostgreSQL não encontrado ou experiência inválida.\n");
    exit(1);
}

$courseId = (int) $course['id'];

$definitions = [
    'construindo-banco-ld-informatica' => [
        'label' => 'Módulo 2 — Modelagem e Criação de Tabelas',
        'lessons' => [
            'create-table' => [
                'summary' => 'Crie sua primeira tabela PostgreSQL entendendo a função de cada definição.',
                'minutes' => 22,
                'blocks' => [
                    ['theory', 'CREATE TABLE transforma o modelo em estrutura', "CREATE TABLE cria uma estrutura persistente no banco. Cada coluna recebe nome, tipo e, quando necessário, regras de validação.\n\nUma definição simples:\n\nCREATE TABLE clientes (\n    id integer NOT NULL,\n    nome text NOT NULL,\n    email text\n);\n\nA partir desse momento o PostgreSQL conhece a tabela e consegue validar os dados enviados para ela."],
                    ['example', 'Comece com uma entidade clara', "No projeto LD Informática, clientes, produtos e pedidos representam entidades diferentes. Evite criar uma tabela genérica com dezenas de colunas sem relação entre si.\n\nUma boa tabela descreve um conjunto coerente de registros e possui nomes que revelam a intenção de cada coluna."],
                    ['summary', 'DDL muda a estrutura do banco', "CREATE TABLE faz parte dos comandos de definição de dados. Diferentemente de SELECT, que apenas consulta, esse comando modifica o esquema do banco.\n\nAntes de executar DDL em ambientes reais, confirme o banco, o schema e o impacto da alteração."],
                ],
                'lab' => [
                    'title' => 'Criando a tabela clientes_base',
                    'instructions' => 'Crie clientes_base com id integer obrigatório, nome text obrigatório e email text opcional.',
                    'starter' => "CREATE TABLE clientes_base (\n    id integer NOT NULL,\n    nome text NOT NULL,\n    email text\n);",
                    'validator_type' => 'schema_table',
                    'validator' => [
                        'table' => 'clientes_base',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                            ['name' => 'nome', 'type' => 'text', 'nullable' => false],
                            ['name' => 'email', 'type' => 'text', 'nullable' => true],
                        ],
                        'allow_extra_columns' => false,
                    ],
                    'bootstrap_sql' => 'DROP TABLE IF EXISTS clientes_base;',
                    'state_version' => 15,
                    'xp' => 30,
                    'hints' => [
                        ['Estrutura', 'Use CREATE TABLE clientes_base (...) e separe cada coluna por vírgula.'],
                        ['Obrigatoriedade', 'As colunas id e nome precisam de NOT NULL; email pode permanecer sem essa regra.'],
                    ],
                ],
            ],

            'tipos-de-dados' => [
                'summary' => 'Escolha tipos coerentes para números, textos, valores lógicos e datas.',
                'minutes' => 24,
                'blocks' => [
                    ['theory', 'Tipo de dado representa significado', "O tipo informa ao PostgreSQL quais valores uma coluna aceita e quais operações fazem sentido.\n\ninteger serve para inteiros; numeric para valores decimais exatos; text para texto; boolean para verdadeiro/falso; date para uma data sem horário.\n\nEscolher o tipo correto melhora consistência e reduz conversões desnecessárias."],
                    ['comparison', 'Tipos usados no projeto', "Necessidade | Tipo inicial\nIdentificador inteiro | integer\nNome ou descrição | text\nPreço | numeric\nAtivo/inativo | boolean\nData de cadastro | date\nData e hora de evento | timestamp\n\nNão escolha o tipo apenas pelo valor de exemplo atual. Pense no domínio que a coluna deverá representar."],
                    ['warning', 'Dinheiro merece precisão', "Valores monetários não devem depender de aproximação binária. Para preço, numeric é uma escolha didática e segura neste curso.\n\nMais adiante você verá que a escala e a precisão também podem ser definidas, por exemplo numeric(10,2)."],
                ],
                'lab' => [
                    'title' => 'Definindo tipos de produtos',
                    'instructions' => 'Crie produtos_tipos com id integer, nome text, preco numeric, ativo boolean e criado_em date; todas as colunas devem ser obrigatórias.',
                    'starter' => "CREATE TABLE produtos_tipos (\n    id integer NOT NULL,\n    nome text NOT NULL,\n    preco numeric NOT NULL,\n    ativo boolean NOT NULL,\n    criado_em date NOT NULL\n);",
                    'validator_type' => 'schema_table',
                    'validator' => [
                        'table' => 'produtos_tipos',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                            ['name' => 'nome', 'type' => 'text', 'nullable' => false],
                            ['name' => 'preco', 'type' => 'numeric', 'nullable' => false],
                            ['name' => 'ativo', 'type' => 'boolean', 'nullable' => false],
                            ['name' => 'criado_em', 'type' => 'date', 'nullable' => false],
                        ],
                        'allow_extra_columns' => false,
                    ],
                    'bootstrap_sql' => 'DROP TABLE IF EXISTS produtos_tipos;',
                    'state_version' => 16,
                    'xp' => 30,
                    'hints' => [
                        ['Tipos', 'Use integer, text, numeric, boolean e date conforme o enunciado.'],
                        ['Regra', 'Todas as cinco colunas precisam de NOT NULL.'],
                    ],
                ],
            ],

            'identity' => [
                'summary' => 'Gere identificadores automaticamente com colunas identity.',
                'minutes' => 20,
                'blocks' => [
                    ['theory', 'IDENTITY delega a geração do ID', "GENERATED ... AS IDENTITY permite que o PostgreSQL gere valores sequenciais para uma coluna.\n\nExemplo:\n\nid integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY\n\nA aplicação não precisa calcular manualmente o próximo número, evitando concorrência e duplicidade."],
                    ['comparison', 'ALWAYS e BY DEFAULT', "GENERATED ALWAYS prioriza a geração do banco e restringe valores manuais comuns. GENERATED BY DEFAULT permite que um valor explícito substitua a geração automática.\n\nPara começar, ALWAYS deixa clara a responsabilidade do banco sobre o identificador."],
                    ['memory', 'Identity não substitui a chave', "Identity resolve geração de valores. PRIMARY KEY resolve identidade lógica e unicidade da linha.\n\nÉ comum usar as duas características na mesma coluna, mas são conceitos diferentes."],
                ],
                'lab' => [
                    'title' => 'Criando IDs automáticos',
                    'instructions' => 'Crie categorias_identity com id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY e nome text obrigatório.',
                    'starter' => "CREATE TABLE categorias_identity (\n    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,\n    nome text NOT NULL\n);",
                    'validator_type' => 'schema_table',
                    'validator' => [
                        'table' => 'categorias_identity',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                            ['name' => 'nome', 'type' => 'text', 'nullable' => false],
                        ],
                        'allow_extra_columns' => false,
                    ],
                    'bootstrap_sql' => 'DROP TABLE IF EXISTS categorias_identity;',
                    'state_version' => 17,
                    'xp' => 30,
                    'hints' => [
                        ['Geração', 'A definição do id começa com integer GENERATED ALWAYS AS IDENTITY.'],
                        ['Identidade', 'Acrescente PRIMARY KEY à coluna id e deixe nome como text NOT NULL.'],
                    ],
                ],
            ],

            'primary-key' => [
                'summary' => 'Defina a chave primária e entenda por que cada linha precisa de uma identidade estável.',
                'minutes' => 20,
                'blocks' => [
                    ['theory', 'PRIMARY KEY identifica uma linha', "Uma chave primária não pode se repetir e não pode ser NULL. Ela fornece uma forma estável de referenciar exatamente um registro.\n\nNo projeto, pedidos, produtos e clientes terão IDs próprios que poderão ser utilizados por outras tabelas."],
                    ['example', 'Chave técnica e dado de negócio', "E-mail pode ser único, mas pode mudar. Um id técnico costuma ser mais estável para relacionamentos.\n\nPor isso é comum usar id como PRIMARY KEY e aplicar UNIQUE separadamente em campos de negócio que também não podem se repetir."],
                    ['warning', 'Evite depender da posição da linha', "A ordem em que um registro aparece em SELECT não é sua identidade. Sem uma chave confiável, updates, relacionamentos e integrações tornam-se frágeis."],
                ],
                'lab' => [
                    'title' => 'Criando a chave de pedidos',
                    'instructions' => 'Crie pedidos_pk com id integer PRIMARY KEY e descricao text obrigatória.',
                    'starter' => "CREATE TABLE pedidos_pk (\n    id integer PRIMARY KEY,\n    descricao text NOT NULL\n);",
                    'validator_type' => 'schema_table',
                    'validator' => [
                        'table' => 'pedidos_pk',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                            ['name' => 'descricao', 'type' => 'text', 'nullable' => false],
                        ],
                        'allow_extra_columns' => false,
                    ],
                    'bootstrap_sql' => 'DROP TABLE IF EXISTS pedidos_pk;',
                    'state_version' => 18,
                    'xp' => 30,
                    'hints' => [
                        ['Chave', 'Escreva id integer PRIMARY KEY. PRIMARY KEY já implica NOT NULL.'],
                        ['Descrição', 'A segunda coluna deve ser descricao text NOT NULL.'],
                    ],
                ],
            ],

            'not-null-unique-default' => [
                'summary' => 'Proteja dados com NOT NULL, UNIQUE e valores DEFAULT.',
                'minutes' => 24,
                'blocks' => [
                    ['theory', 'Constraints transformam regras em proteção', "NOT NULL exige um valor. UNIQUE impede duplicidades. DEFAULT fornece um valor quando a coluna é omitida no INSERT.\n\nEssas regras vivem no banco e protegem dados mesmo quando diferentes aplicações escrevem nas mesmas tabelas."],
                    ['example', 'Usuário ativo por padrão', "Uma coluna ativo boolean NOT NULL DEFAULT true representa uma regra simples: novos registros são ativos quando nenhum valor diferente é informado.\n\nJá email text UNIQUE NOT NULL exige presença e impede dois cadastros com o mesmo e-mail."],
                    ['warning', 'DEFAULT não corrige NULL explícito', "DEFAULT é usado quando a coluna é omitida ou quando o comando solicita DEFAULT. Se uma coluna NOT NULL receber NULL explicitamente, a operação continuará inválida."],
                ],
                'lab' => [
                    'title' => 'Aplicando regras em usuários',
                    'instructions' => 'Crie usuarios_regras com id PRIMARY KEY, email UNIQUE NOT NULL e ativo boolean NOT NULL DEFAULT true.',
                    'starter' => "CREATE TABLE usuarios_regras (\n    id integer PRIMARY KEY,\n    email text UNIQUE NOT NULL,\n    ativo boolean NOT NULL DEFAULT true\n);",
                    'validator_type' => 'schema_table',
                    'validator' => [
                        'table' => 'usuarios_regras',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                            ['name' => 'email', 'type' => 'text', 'nullable' => false],
                            ['name' => 'ativo', 'type' => 'boolean', 'nullable' => false],
                        ],
                        'allow_extra_columns' => false,
                    ],
                    'bootstrap_sql' => 'DROP TABLE IF EXISTS usuarios_regras;',
                    'state_version' => 19,
                    'xp' => 35,
                    'hints' => [
                        ['E-mail', 'Combine text UNIQUE NOT NULL na coluna email.'],
                        ['Padrão', 'A coluna ativo deve terminar com NOT NULL DEFAULT true.'],
                    ],
                ],
            ],

            'check' => [
                'summary' => 'Use CHECK para impedir valores que violem regras simples do domínio.',
                'minutes' => 20,
                'blocks' => [
                    ['theory', 'CHECK valida uma expressão', "CHECK exige que uma condição seja verdadeira para os dados aceitos.\n\nquantidade integer CHECK (quantidade >= 0)\n\nimpede estoque negativo sem depender apenas de validação na interface."],
                    ['example', 'Regra perto do dado', "Uma aplicação pode esquecer uma validação ou existir mais de um sistema escrevendo no banco. Quando a regra é essencial à integridade, colocá-la também no banco cria uma última barreira de proteção."],
                    ['warning', 'CHECK não substitui todas as regras de negócio', "Use CHECK para condições que podem ser avaliadas de forma consistente a partir da linha e do contexto do banco. Fluxos complexos de negócio continuam pertencendo a serviços e processos adequados."],
                ],
                'lab' => [
                    'title' => 'Impedindo estoque negativo',
                    'instructions' => 'Crie estoque_check com id PRIMARY KEY e quantidade integer NOT NULL com CHECK quantidade >= 0.',
                    'starter' => "CREATE TABLE estoque_check (\n    id integer PRIMARY KEY,\n    quantidade integer NOT NULL CHECK (quantidade >= 0)\n);\n\nSELECT COUNT(*)::int AS checks\nFROM information_schema.table_constraints\nWHERE table_name = 'estoque_check'\n  AND constraint_type = 'CHECK';",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['checks'],
                        'row_count' => 1,
                    ],
                    'bootstrap_sql' => 'DROP TABLE IF EXISTS estoque_check;',
                    'state_version' => 20,
                    'xp' => 35,
                    'hints' => [
                        ['Expressão', 'Use CHECK (quantidade >= 0) depois de NOT NULL.'],
                        ['Validação', 'Mantenha o SELECT final para o laboratório confirmar que o CHECK foi criado.'],
                    ],
                ],
            ],

            'alter-table' => [
                'summary' => 'Evolua uma tabela existente com ALTER TABLE sem recriá-la do zero.',
                'minutes' => 23,
                'blocks' => [
                    ['theory', 'ALTER TABLE muda uma estrutura existente', "Sistemas evoluem. ALTER TABLE permite adicionar, remover ou alterar elementos de uma tabela existente.\n\nALTER TABLE clientes\nADD COLUMN telefone text;\n\nO comando preserva a tabela e adiciona a nova coluna."],
                    ['warning', 'Alterações estruturais exigem planejamento', "Em produção, uma alteração pode afetar dados antigos, consultas, índices e aplicações. Antes de alterar tipo, remover coluna ou tornar uma coluna obrigatória, avalie os registros existentes."],
                    ['summary', 'Migração deve ser reproduzível', "Em projetos profissionais, mudanças de schema normalmente são registradas em migrations versionadas. Isso permite reproduzir a mesma evolução em desenvolvimento, teste e produção."],
                ],
                'lab' => [
                    'title' => 'Adicionando telefone aos clientes',
                    'instructions' => 'A tabela clientes_alter já existe. Adicione a coluna telefone text.',
                    'starter' => "ALTER TABLE clientes_alter\nADD COLUMN telefone text;",
                    'validator_type' => 'schema_table',
                    'validator' => [
                        'table' => 'clientes_alter',
                        'columns' => [
                            ['name' => 'id', 'type' => 'integer', 'nullable' => false],
                            ['name' => 'nome', 'type' => 'text', 'nullable' => false],
                            ['name' => 'telefone', 'type' => 'text', 'nullable' => true],
                        ],
                        'allow_extra_columns' => false,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS clientes_alter;\nCREATE TABLE clientes_alter (id integer PRIMARY KEY, nome text NOT NULL);",
                    'state_version' => 21,
                    'xp' => 35,
                    'hints' => [
                        ['Comando', 'Comece com ALTER TABLE clientes_alter.'],
                        ['Nova coluna', 'Use ADD COLUMN telefone text.'],
                    ],
                ],
            ],
        ],
    ],

    'crud-na-pratica' => [
        'label' => 'Módulo 3 — Inserindo, Atualizando e Excluindo Dados',
        'lessons' => [
            'insert' => [
                'summary' => 'Cadastre registros com INSERT declarando de forma explícita as colunas usadas.',
                'minutes' => 20,
                'blocks' => [
                    ['theory', 'INSERT cria novas linhas', "A forma recomendada declara as colunas e depois os valores:\n\nINSERT INTO clientes (nome, cidade)\nVALUES ('Ana', 'Cuiaba');\n\nDeclarar as colunas deixa o comando mais resistente a mudanças na estrutura da tabela."],
                    ['warning', 'Strings usam aspas simples', "Em SQL, textos literais normalmente usam aspas simples. Aspas duplas são usadas para identificadores delimitados.\n\nVALUES ('Ana') é texto. VALUES (\"Ana\") tentaria interpretar Ana como identificador em muitos contextos."],
                    ['summary', 'Confirme o dado inserido', "Durante o aprendizado, faça um SELECT depois do INSERT para visualizar o estado da tabela. Em aplicações, recursos como RETURNING permitem recuperar dados inseridos sem uma consulta separada."],
                ],
                'lab' => [
                    'title' => 'Cadastrando o primeiro cliente',
                    'instructions' => 'Insira Ana, de Cuiaba, em clientes_crud e depois retorne nome e cidade.',
                    'starter' => "INSERT INTO clientes_crud (nome, cidade)\nVALUES ('Ana', 'Cuiaba');\n\nSELECT nome, cidade\nFROM clientes_crud\nORDER BY id;",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['nome', 'cidade'],
                        'rows' => [['nome' => 'Ana', 'cidade' => 'Cuiaba']],
                        'ordered' => true,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS clientes_crud;\nCREATE TABLE clientes_crud (id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, nome text NOT NULL, cidade text NOT NULL);",
                    'state_version' => 22,
                    'xp' => 30,
                    'hints' => [
                        ['INSERT', 'Use INSERT INTO clientes_crud (nome, cidade) VALUES (...).'],
                        ['Conferência', 'Mantenha o SELECT final para visualizar e validar a linha criada.'],
                    ],
                ],
            ],

            'insert-multiplas-linhas' => [
                'summary' => 'Cadastre vários registros em uma única instrução INSERT.',
                'minutes' => 19,
                'blocks' => [
                    ['theory', 'VALUES aceita várias linhas', "Um INSERT pode cadastrar várias linhas separando grupos de valores por vírgula.\n\nINSERT INTO produtos (nome, preco) VALUES\n('Mouse', 80),\n('Teclado', 120);\n\nIsso reduz viagens entre aplicação e banco quando os registros podem ser enviados em lote."],
                    ['warning', 'A ordem precisa seguir as colunas', "Cada grupo em VALUES deve respeitar a lista de colunas declarada no INSERT e possuir quantidade compatível de valores."],
                    ['example', 'Lotes devem continuar coerentes', "Inserção em lote não elimina constraints. Se uma linha violar uma regra dentro de uma instrução atômica, a operação pode falhar sem deixar um conjunto parcialmente inconsistente."],
                ],
                'lab' => [
                    'title' => 'Inserindo três produtos',
                    'instructions' => 'Cadastre Mouse, Teclado e Webcam em produtos_insert usando um único INSERT e retorne a quantidade total.',
                    'starter' => "INSERT INTO produtos_insert (nome, preco) VALUES\n    ('Mouse', 80),\n    ('Teclado', 120),\n    ('Webcam', 220);\n\nSELECT COUNT(*)::int AS total\nFROM produtos_insert;",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['total'],
                        'rows' => [['total' => 3]],
                        'ordered' => true,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS produtos_insert;\nCREATE TABLE produtos_insert (id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, nome text NOT NULL, preco integer NOT NULL);",
                    'state_version' => 23,
                    'xp' => 30,
                    'hints' => [
                        ['Várias linhas', 'Separe cada grupo (...) por vírgula dentro do mesmo VALUES.'],
                        ['Total', 'Mantenha SELECT COUNT(*)::int AS total no final.'],
                    ],
                ],
            ],

            'returning' => [
                'summary' => 'Recupere imediatamente valores produzidos por INSERT, UPDATE ou DELETE.',
                'minutes' => 20,
                'blocks' => [
                    ['theory', 'RETURNING devolve o registro afetado', "PostgreSQL pode retornar dados da própria operação de escrita:\n\nINSERT INTO clientes (nome)\nVALUES ('Ana')\nRETURNING id, nome;\n\nIsso é especialmente útil quando o banco gera o ID."],
                    ['example', 'Menos uma consulta', "Sem RETURNING, a aplicação poderia inserir e depois executar outro SELECT apenas para descobrir o identificador. RETURNING reduz esse trabalho e mantém a operação clara."],
                    ['memory', 'RETURNING também existe em UPDATE e DELETE', "O recurso não é exclusivo de INSERT. Mais adiante ele pode mostrar exatamente quais registros foram atualizados ou removidos."],
                ],
                'lab' => [
                    'title' => 'Capturando o pedido criado',
                    'instructions' => 'Insira o pedido Manutencao notebook e use RETURNING para devolver id e descricao.',
                    'starter' => "INSERT INTO pedidos_returning (descricao)\nVALUES ('Manutencao notebook')\nRETURNING id, descricao;",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['id', 'descricao'],
                        'row_count' => 1,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS pedidos_returning;\nCREATE TABLE pedidos_returning (id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY, descricao text NOT NULL);",
                    'state_version' => 24,
                    'xp' => 35,
                    'hints' => [
                        ['Final do INSERT', 'Acrescente RETURNING id, descricao depois do VALUES.'],
                        ['Não use SELECT extra', 'O próprio INSERT deve devolver a linha.'],
                    ],
                ],
            ],

            'update' => [
                'summary' => 'Altere dados existentes com UPDATE e valide o resultado.',
                'minutes' => 21,
                'blocks' => [
                    ['theory', 'UPDATE altera linhas existentes', "UPDATE informa a tabela, os novos valores e normalmente uma condição:\n\nUPDATE produtos\nSET preco = 150\nWHERE id = 1;\n\nSET descreve a mudança; WHERE delimita quais linhas podem ser alteradas."],
                    ['warning', 'UPDATE sem WHERE pode atingir todas as linhas', "Antes de executar uma alteração importante, faça um SELECT com a mesma condição. Se o SELECT não representa exatamente o conjunto pretendido, o UPDATE também não está pronto."],
                    ['summary', 'Valide depois da alteração', "Depois de alterar, consulte os registros envolvidos ou use RETURNING. O objetivo não é apenas executar sem erro, mas provar que o estado final está correto."],
                ],
                'lab' => [
                    'title' => 'Atualizando o preço',
                    'instructions' => 'Altere para 150 o preço do produto id 1 e depois retorne id e preco.',
                    'starter' => "UPDATE produtos_update\nSET preco = 150\nWHERE id = 1;\n\nSELECT id, preco\nFROM produtos_update\nWHERE id = 1;",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['id', 'preco'],
                        'rows' => [['id' => 1, 'preco' => 150]],
                        'ordered' => true,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS produtos_update;\nCREATE TABLE produtos_update (id integer PRIMARY KEY, nome text NOT NULL, preco integer NOT NULL);\nINSERT INTO produtos_update VALUES (1, 'Teclado', 120), (2, 'Mouse', 80);",
                    'state_version' => 25,
                    'xp' => 35,
                    'hints' => [
                        ['SET', 'Use SET preco = 150.'],
                        ['Segurança', 'Restrinja a alteração com WHERE id = 1.'],
                    ],
                ],
            ],

            'delete' => [
                'summary' => 'Remova registros com DELETE usando condições verificáveis.',
                'minutes' => 20,
                'blocks' => [
                    ['theory', 'DELETE remove linhas, não a tabela', "DELETE FROM clientes WHERE ativo = false; remove registros que atendem à condição. A estrutura da tabela continua existindo.\n\nDROP TABLE é uma operação estrutural diferente e muito mais ampla."],
                    ['warning', 'DELETE sem WHERE é destrutivo', "DELETE FROM tabela; remove todas as linhas. Em situações reais, confirme filtros e use transações quando apropriado."],
                    ['example', 'SELECT antes, DELETE depois', "Uma rotina segura é executar primeiro SELECT * FROM tabela WHERE condição. Depois de revisar o conjunto, reutilize a condição no DELETE."],
                ],
                'lab' => [
                    'title' => 'Removendo clientes inativos',
                    'instructions' => 'Remova os registros inativos de clientes_delete e depois retorne a quantidade restante.',
                    'starter' => "DELETE FROM clientes_delete\nWHERE ativo = false;\n\nSELECT COUNT(*)::int AS total\nFROM clientes_delete;",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['total'],
                        'rows' => [['total' => 2]],
                        'ordered' => true,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS clientes_delete;\nCREATE TABLE clientes_delete (id integer PRIMARY KEY, nome text NOT NULL, ativo boolean NOT NULL);\nINSERT INTO clientes_delete VALUES (1,'Ana',true),(2,'Bruno',false),(3,'Carla',true);",
                    'state_version' => 26,
                    'xp' => 35,
                    'hints' => [
                        ['Filtro', 'Use WHERE ativo = false.'],
                        ['Conferência', 'Depois do DELETE, o SELECT COUNT deve retornar apenas os dois ativos.'],
                    ],
                ],
            ],

            'where-seguro' => [
                'summary' => 'Use WHERE como barreira de segurança antes de UPDATE e DELETE.',
                'minutes' => 22,
                'blocks' => [
                    ['theory', 'A condição é parte da mudança', "Em escrita de dados, WHERE não deve ser tratado como detalhe. Ele define o alcance da operação.\n\nQuanto mais específica a condição, mais fácil revisar se o comando está alinhado à intenção."],
                    ['case_study', 'Atualização por categoria', "Se a regra é aumentar estoque apenas dos periféricos, valide primeiro:\n\nSELECT * FROM estoque_update WHERE categoria = 'Perifericos';\n\nSó depois transforme o SELECT em UPDATE usando exatamente a mesma condição."],
                    ['memory', 'Pergunta obrigatória antes de executar', "Se eu trocar UPDATE/DELETE por SELECT usando este WHERE, as linhas retornadas são exatamente as que desejo alterar?\n\nSe a resposta não for claramente sim, revise o comando."],
                ],
                'lab' => [
                    'title' => 'Atualização seletiva de estoque',
                    'instructions' => 'Some 10 unidades somente aos produtos da categoria Perifericos e retorne nome e quantidade ordenados por id.',
                    'starter' => "UPDATE estoque_update\nSET quantidade = quantidade + 10\nWHERE categoria = 'Perifericos';\n\nSELECT nome, quantidade\nFROM estoque_update\nORDER BY id;",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['nome', 'quantidade'],
                        'rows' => [
                            ['nome' => 'Mouse', 'quantidade' => 15],
                            ['nome' => 'Teclado', 'quantidade' => 18],
                            ['nome' => 'Monitor', 'quantidade' => 2],
                        ],
                        'ordered' => true,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS estoque_update;\nCREATE TABLE estoque_update (id integer PRIMARY KEY, nome text NOT NULL, categoria text NOT NULL, quantidade integer NOT NULL);\nINSERT INTO estoque_update VALUES (1,'Mouse','Perifericos',5),(2,'Teclado','Perifericos',8),(3,'Monitor','Video',2);",
                    'state_version' => 27,
                    'xp' => 40,
                    'hints' => [
                        ['Cálculo', 'SET quantidade = quantidade + 10 usa o valor atual da própria linha.'],
                        ['Escopo', "O WHERE precisa restringir categoria = 'Perifericos'."],
                    ],
                ],
            ],

            'mini-crud' => [
                'summary' => 'Combine INSERT, UPDATE, DELETE e SELECT em um pequeno fluxo CRUD.',
                'minutes' => 28,
                'blocks' => [
                    ['theory', 'CRUD descreve operações essenciais', "Create, Read, Update e Delete representam criação, leitura, alteração e remoção. Em SQL, aparecem principalmente como INSERT, SELECT, UPDATE e DELETE."],
                    ['case_study', 'Um fluxo completo', "Um sistema pode cadastrar categorias, listar o catálogo, corrigir um nome e remover uma categoria criada por engano. O valor do exercício está em perceber como cada comando altera o mesmo estado persistente."],
                    ['summary', 'Pense no estado final', "Ao encadear operações, pergunte qual deve ser o estado final da tabela. Essa mentalidade ajuda tanto a escrever testes quanto a depurar sistemas de dados."],
                ],
                'lab' => [
                    'title' => 'Mini CRUD de categorias',
                    'instructions' => 'Insira Hardware, Perifericos e Video; renomeie Hardware para Componentes; exclua Video; ao final retorne id e nome ordenados.',
                    'starter' => "INSERT INTO categorias_crud (id, nome) VALUES\n    (1, 'Hardware'),\n    (2, 'Perifericos'),\n    (3, 'Video');\n\nUPDATE categorias_crud\nSET nome = 'Componentes'\nWHERE id = 1;\n\nDELETE FROM categorias_crud\nWHERE id = 3;\n\nSELECT id, nome\nFROM categorias_crud\nORDER BY id;",
                    'validator_type' => 'query_result',
                    'validator' => [
                        'columns' => ['id', 'nome'],
                        'rows' => [
                            ['id' => 1, 'nome' => 'Componentes'],
                            ['id' => 2, 'nome' => 'Perifericos'],
                        ],
                        'ordered' => true,
                    ],
                    'bootstrap_sql' => "DROP TABLE IF EXISTS categorias_crud;\nCREATE TABLE categorias_crud (id integer PRIMARY KEY, nome text NOT NULL UNIQUE);",
                    'state_version' => 28,
                    'xp' => 50,
                    'hints' => [
                        ['Sequência', 'Execute INSERT, depois UPDATE, depois DELETE e finalize com SELECT.'],
                        ['Condições', 'No UPDATE use id = 1 e no DELETE use id = 3.'],
                    ],
                ],
            ],
        ],
    ],
];

$moduleFind = $pdo->prepare(
    "SELECT id, title, status
       FROM modules
      WHERE course_id = :course_id
        AND slug = :slug
      LIMIT 1"
);

$lessonFindSql = "
    SELECT l.id, l.slug, l.title, l.status
      FROM lessons l
      INNER JOIN phases p ON p.id = l.phase_id
     WHERE p.module_id = ?
       AND l.slug IN (%s)
";

$plan = [];

foreach ($definitions as $moduleSlug => $moduleDefinition) {
    $moduleFind->execute([
        'course_id' => $courseId,
        'slug' => $moduleSlug,
    ]);
    $module = $moduleFind->fetch(PDO::FETCH_ASSOC);

    if (!$module) {
        fwrite(
            STDERR,
            "ABORTADO: módulo {$moduleSlug} não encontrado. Execute database/criar_grade_postgresql_v1.php.\n"
        );
        exit(1);
    }

    $slugs = array_keys($moduleDefinition['lessons']);
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $lessonFind = $pdo->prepare(sprintf($lessonFindSql, $placeholders));
    $lessonFind->execute([(int) $module['id'], ...$slugs]);

    $rows = [];
    foreach ($lessonFind->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(string) $row['slug']] = $row;
    }

    $missingLessons = array_values(array_diff($slugs, array_keys($rows)));
    if ($missingLessons !== []) {
        fwrite(
            STDERR,
            "ABORTADO: aulas ausentes em {$moduleDefinition['label']}: "
            . implode(', ', $missingLessons) . "\n"
        );
        exit(1);
    }

    foreach ($rows as $row) {
        if ((string) $row['status'] !== 'draft') {
            fwrite(
                STDERR,
                "ABORTADO: a aula '{$row['title']}' já não está em draft. Conteúdo publicado não será sobrescrito.\n"
            );
            exit(1);
        }
    }

    $plan[$moduleSlug] = [
        'module' => $module,
        'rows' => $rows,
        'definition' => $moduleDefinition,
    ];
}

echo "=================================================================\n";
echo " LD ACADEMY - POSTGRESQL MODULOS 2 E 3 - CONTEUDO V1\n";
echo "=================================================================\n";
echo 'Banco: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo 'Curso: #' . $courseId . ' - ' . (string) $course['title'] . "\n";
echo 'Status do curso: ' . (string) $course['status'] . "\n";
echo 'Modo: ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

$totalLessons = 0;
$totalBlocks = 0;
$totalLabs = 0;
$totalHints = 0;

foreach ($plan as $item) {
    echo $item['definition']['label'] . "\n";

    foreach ($item['definition']['lessons'] as $slug => $definition) {
        $lessonId = (int) $item['rows'][$slug]['id'];

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

        $totalLessons++;
        $totalBlocks += $blocks;
        $totalLabs++;
        $totalHints += $hints;

        printf(
            "  %-28s blocos %d->%d | labs %d->1 | hints %d\n",
            $slug,
            (int) $blockStmt->fetchColumn(),
            $blocks,
            (int) $labStmt->fetchColumn(),
            $hints
        );
    }
}

echo "\nPrevisto: {$totalLessons} aulas, {$totalBlocks} blocos, {$totalLabs} labs, {$totalHints} dicas.\n";
echo "Módulos e aulas permanecem em rascunho até a liberação de teste.\n";

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup e revisão, execute:\n";
    echo "php database/popular_postgresql_modulos2_3_v1.php --apply";
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

    foreach ($plan as $item) {
        foreach ($item['definition']['lessons'] as $slug => $definition) {
            $lessonId = (int) $item['rows'][$slug]['id'];

            $updateLesson->execute([
                'summary' => $definition['summary'],
                'estimated_minutes' => (int) $definition['minutes'],
                'xp_reward' => 30,
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
                    "Não foi possível localizar o laboratório da aula {$slug}."
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
    }

    $pdo->commit();

    echo "\n[OK] {$totalLessons} aulas preenchidas.\n";
    echo "[OK] {$totalBlocks} blocos didáticos sincronizados.\n";
    echo "[OK] {$totalLabs} laboratórios PGlite criados em rascunho.\n";
    echo "[OK] {$totalHints} dicas sincronizadas.\n";
    echo "[OK] Nenhum novo módulo foi publicado automaticamente.\n";
    echo "[OK] Para liberar estes módulos à Lilian, execute novamente o script de acesso privado após revisar o conteúdo.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
