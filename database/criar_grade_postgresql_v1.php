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

$courseStmt = $pdo->prepare(
    "SELECT id, title, status
     FROM courses
     WHERE slug = 'postgresql-zero-intermediario'
     LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(
        STDERR,
        "ABORTADO: curso PostgreSQL não encontrado. Execute primeiro database/criar_curso_postgresql_v1.php.\n"
    );
    exit(1);
}

$courseId = (int) $course['id'];

$grade = [
    'primeiros-passos-postgresql' => [
        [
            'slug' => 'fundamentos-banco-postgresql',
            'title' => 'Fundamentos de Banco de Dados e PostgreSQL',
            'description' => 'Vocabulário essencial para começar sem pressupor conhecimento prévio.',
            'lessons' => [
                ['banco-de-dados-e-postgresql', 'Banco de dados e PostgreSQL: o que são'],
                ['tabelas-linhas-e-colunas', 'Tabelas, linhas e colunas'],
                ['sql-e-ferramentas', 'SQL, pgAdmin, psql e o laboratório do curso'],
            ],
        ],
        [
            'slug' => 'primeiro-contato-sql',
            'title' => 'Primeiro Contato com SQL',
            'description' => 'Primeiras consultas em um banco preparado para o aluno experimentar imediatamente.',
            'lessons' => [
                ['primeiro-select', 'Seu primeiro SELECT'],
                ['consultando-tabela-pronta', 'Consultando uma tabela pronta'],
                ['lendo-erros-sql', 'Como ler erros e aprender com eles'],
            ],
        ],
    ],
    'consultando-dados-select' => [
        [
            'slug' => 'select-projecao',
            'title' => 'SELECT e Projeção de Dados',
            'description' => 'Escolha de colunas, aliases e eliminação de duplicidades.',
            'lessons' => [
                ['select-colunas', 'Selecionando colunas com SELECT'],
                ['aliases-as', 'Aliases com AS'],
                ['distinct', 'Removendo duplicidades com DISTINCT'],
            ],
        ],
        [
            'slug' => 'filtros-ordenacao',
            'title' => 'Filtros, Condições e Ordenação',
            'description' => 'Localize exatamente os dados necessários e controle a apresentação dos resultados.',
            'lessons' => [
                ['where-comparacoes', 'WHERE e operadores de comparação'],
                ['and-or-not', 'Combinando condições com AND, OR e NOT'],
                ['like-in-between', 'LIKE, IN e BETWEEN'],
                ['is-null', 'NULL, IS NULL e IS NOT NULL'],
                ['order-by-limit', 'ORDER BY, LIMIT e paginação básica'],
            ],
        ],
    ],
    'construindo-banco-ld-informatica' => [
        [
            'slug' => 'criando-estrutura',
            'title' => 'Criando Estruturas',
            'description' => 'O aluno começa a construir o banco cumulativo da LD Informática.',
            'lessons' => [
                ['create-table', 'Criando tabelas com CREATE TABLE'],
                ['tipos-de-dados', 'Tipos de dados no PostgreSQL'],
                ['identity', 'Colunas identity e geração de IDs'],
            ],
        ],
        [
            'slug' => 'restricoes-estrutura',
            'title' => 'Restrições e Qualidade dos Dados',
            'description' => 'Regras que protegem a consistência das tabelas.',
            'lessons' => [
                ['primary-key', 'PRIMARY KEY'],
                ['not-null-unique-default', 'NOT NULL, UNIQUE e DEFAULT'],
                ['check', 'Validando regras com CHECK'],
                ['alter-table', 'Evoluindo tabelas com ALTER TABLE'],
            ],
        ],
    ],
    'crud-na-pratica' => [
        [
            'slug' => 'inserindo-dados',
            'title' => 'Inserindo Dados',
            'description' => 'Cadastro de dados reais do projeto e retorno imediato dos registros criados.',
            'lessons' => [
                ['insert', 'INSERT: cadastrando dados'],
                ['insert-multiplas-linhas', 'Inserindo várias linhas'],
                ['returning', 'RETURNING: vendo o que acabou de mudar'],
            ],
        ],
        [
            'slug' => 'alterando-excluindo-dados',
            'title' => 'Alterando e Excluindo com Segurança',
            'description' => 'UPDATE e DELETE com foco em segurança e conferência antes da alteração.',
            'lessons' => [
                ['update', 'UPDATE: alterando registros'],
                ['delete', 'DELETE: removendo registros'],
                ['where-seguro', 'WHERE seguro em UPDATE e DELETE'],
                ['mini-crud', 'Desafio CRUD completo'],
            ],
        ],
    ],
    'relacionamentos-integridade' => [
        [
            'slug' => 'modelando-relacionamentos',
            'title' => 'Modelando Relacionamentos',
            'description' => 'Como transformar relações do mundo real em tabelas conectadas.',
            'lessons' => [
                ['conceito-relacionamentos', 'Por que relacionar tabelas'],
                ['um-para-muitos', 'Relacionamento 1:N'],
                ['muitos-para-muitos', 'Relacionamento N:N e tabela associativa'],
            ],
        ],
        [
            'slug' => 'foreign-keys-integridade',
            'title' => 'Foreign Keys e Integridade Referencial',
            'description' => 'O PostgreSQL impedindo referências inválidas e controlando alterações relacionadas.',
            'lessons' => [
                ['foreign-key', 'FOREIGN KEY na prática'],
                ['integridade-referencial', 'Entendendo integridade referencial'],
                ['on-delete-on-update', 'ON DELETE e ON UPDATE'],
            ],
        ],
    ],
    'joins-consultas-multiplas-tabelas' => [
        [
            'slug' => 'joins-fundamentais',
            'title' => 'JOINs Fundamentais',
            'description' => 'Combine tabelas relacionadas e entenda o que cada tipo de JOIN preserva.',
            'lessons' => [
                ['inner-join', 'INNER JOIN'],
                ['left-join', 'LEFT JOIN'],
                ['right-full-join', 'RIGHT JOIN e FULL JOIN'],
            ],
        ],
        [
            'slug' => 'relatorios-multiplas-tabelas',
            'title' => 'Relatórios com Múltiplas Tabelas',
            'description' => 'Consultas que aproximam o SQL dos problemas reais de uma aplicação.',
            'lessons' => [
                ['multiplos-joins', 'Combinando vários JOINs'],
                ['joins-com-filtros', 'JOINs com filtros e ordenação'],
                ['relatorio-os-vendas', 'Relatório integrado da LD Informática'],
            ],
        ],
    ],
    'agregacoes-sql-intermediario' => [
        [
            'slug' => 'agregacoes-grupos',
            'title' => 'Agregações e Grupos',
            'description' => 'Transforme registros em indicadores e resumos úteis.',
            'lessons' => [
                ['funcoes-agregacao', 'COUNT, SUM, AVG, MIN e MAX'],
                ['group-by', 'GROUP BY'],
                ['having', 'HAVING: filtrando grupos'],
            ],
        ],
        [
            'slug' => 'consultas-intermediarias',
            'title' => 'Consultas Intermediárias',
            'description' => 'Ferramentas para resolver consultas mais expressivas sem perder legibilidade.',
            'lessons' => [
                ['case', 'CASE: regras dentro da consulta'],
                ['subconsultas', 'Subconsultas'],
                ['cte-with', 'CTEs com WITH'],
                ['funcoes-texto', 'Funções de texto'],
                ['funcoes-data-hora', 'Funções de data e hora'],
                ['cast-coalesce', 'CAST e COALESCE'],
                ['on-conflict', 'UPSERT com ON CONFLICT'],
            ],
        ],
    ],
    'recursos-profissionais-postgresql' => [
        [
            'slug' => 'consistencia-performance',
            'title' => 'Consistência e Performance',
            'description' => 'Recursos usados em aplicações reais para consistência, reutilização e análise de consultas.',
            'lessons' => [
                ['transactions', 'Transações: BEGIN, COMMIT e ROLLBACK'],
                ['views', 'Views'],
                ['indexes', 'Índices: quando e por que usar'],
                ['explain', 'EXPLAIN e leitura inicial do plano'],
            ],
        ],
        [
            'slug' => 'organizacao-seguranca',
            'title' => 'Organização, Acesso e Operação',
            'description' => 'Introdução prática aos recursos que aproximam o aluno do uso profissional do PostgreSQL.',
            'lessons' => [
                ['schemas', 'Schemas'],
                ['roles', 'Roles e usuários'],
                ['grant-revoke', 'GRANT e REVOKE'],
                ['sequences-identity', 'Sequences e revisão de identity'],
                ['backup-restore-intro', 'Backup e restauração: fundamentos'],
            ],
        ],
    ],
    'projeto-final-ld-informatica' => [
        [
            'slug' => 'construindo-projeto-final',
            'title' => 'Construindo o Banco Final',
            'description' => 'Consolidação do modelo da LD Informática a partir do que foi aprendido.',
            'lessons' => [
                ['desenho-schema-final', 'Planejando o schema final'],
                ['criando-tabelas-principais', 'Criando as tabelas principais'],
                ['relacionamentos-regras-final', 'Relacionamentos e regras de integridade'],
                ['carga-dados-projeto', 'Carregando dados de teste'],
            ],
        ],
        [
            'slug' => 'desafio-integrador',
            'title' => 'Desafio Integrador',
            'description' => 'Consultas, correções e decisões de banco que simulam demandas reais.',
            'lessons' => [
                ['relatorios-operacionais', 'Relatórios operacionais'],
                ['consultas-analiticas', 'Consultas analíticas'],
                ['debug-sql', 'Encontrando e corrigindo SQL com erro'],
                ['desafio-final', 'Desafio final do banco LD Informática'],
                ['revisao-proximos-passos', 'Revisão e próximos passos no PostgreSQL'],
            ],
        ],
    ],
];

echo "=================================================================\n";
echo " CURSO MI - GRADE POSTGRESQL ZERO AO INTERMEDIARIO V1\n";
echo "=================================================================\n";
echo "Curso: #{$courseId} {$course['title']} ({$course['status']})\n";
echo 'Modo:  ' . ($apply ? 'APLICAR' : 'DRY-RUN') . "\n\n";

$moduleStmt = $pdo->prepare(
    'SELECT id, title FROM modules WHERE course_id = :course_id AND slug = :slug LIMIT 1'
);

$totalPhases = 0;
$totalLessons = 0;

foreach ($grade as $moduleSlug => $phases) {
    $moduleStmt->execute([
        'course_id' => $courseId,
        'slug' => $moduleSlug,
    ]);
    $module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$module) {
        throw new RuntimeException(
            'Módulo-base não encontrado: ' . $moduleSlug
            . '. Execute database/criar_curso_postgresql_v1.php.'
        );
    }

    echo $module['title'] . "\n";

    foreach ($phases as $phaseIndex => $phase) {
        $totalPhases++;
        $totalLessons += count($phase['lessons']);

        echo sprintf(
            "  [%s] Fase %d: %s (%d aulas)\n",
            $apply ? 'APLICAR' : 'PREVER',
            $phaseIndex + 1,
            $phase['title'],
            count($phase['lessons'])
        );
    }
}

echo "\nTotais previstos: {$totalPhases} fases e {$totalLessons} aulas.\n";
echo "Todas serão criadas como RASCUNHO e completion_mode=lab.\n";
echo "Nenhum bloco teórico ou desafio será criado por este script.\n";

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup e revisão, execute:\n";
    echo "php database/criar_grade_postgresql_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    $phaseFind = $pdo->prepare(
        'SELECT id FROM phases WHERE module_id = :module_id AND slug = :slug LIMIT 1'
    );
    $phasePos = $pdo->prepare(
        'SELECT id, title FROM phases WHERE module_id = :module_id AND position = :position LIMIT 1'
    );
    $phaseInsert = $pdo->prepare(
        "INSERT INTO phases
            (module_id, title, slug, description, phase_type, position,
             required_score, required_lessons_pct, max_attempts, xp_reward,
             is_required, status)
         VALUES
            (:module_id, :title, :slug, :description, 'normal', :position,
             70.00, 100.00, NULL, 100, 1, 'draft')"
    );

    $lessonFind = $pdo->prepare(
        'SELECT id FROM lessons WHERE phase_id = :phase_id AND slug = :slug LIMIT 1'
    );
    $lessonPos = $pdo->prepare(
        'SELECT id, title FROM lessons WHERE phase_id = :phase_id AND position = :position LIMIT 1'
    );
    $lessonInsert = $pdo->prepare(
        "INSERT INTO lessons
            (phase_id, title, slug, summary, position, estimated_minutes,
             xp_reward, is_required, completion_mode, status)
         VALUES
            (:phase_id, :title, :slug, NULL, :position, 12,
             20, 1, 'lab', 'draft')"
    );

    foreach ($grade as $moduleSlug => $phases) {
        $moduleStmt->execute([
            'course_id' => $courseId,
            'slug' => $moduleSlug,
        ]);
        $module = $moduleStmt->fetch(PDO::FETCH_ASSOC);
        $moduleId = (int) $module['id'];

        foreach ($phases as $phaseIndex => $phase) {
            $phasePosition = $phaseIndex + 1;

            $phaseFind->execute([
                'module_id' => $moduleId,
                'slug' => $phase['slug'],
            ]);
            $phaseId = (int) ($phaseFind->fetchColumn() ?: 0);

            if ($phaseId <= 0) {
                $phasePos->execute([
                    'module_id' => $moduleId,
                    'position' => $phasePosition,
                ]);
                $collision = $phasePos->fetch(PDO::FETCH_ASSOC);

                if ($collision) {
                    throw new RuntimeException(
                        sprintf(
                            'Posição %d da fase em "%s" já está ocupada por "%s".',
                            $phasePosition,
                            $module['title'],
                            $collision['title']
                        )
                    );
                }

                $phaseInsert->execute([
                    'module_id' => $moduleId,
                    'title' => $phase['title'],
                    'slug' => $phase['slug'],
                    'description' => $phase['description'],
                    'position' => $phasePosition,
                ]);
                $phaseId = (int) $pdo->lastInsertId();
            }

            foreach ($phase['lessons'] as $lessonIndex => [$lessonSlug, $lessonTitle]) {
                $lessonPosition = $lessonIndex + 1;

                $lessonFind->execute([
                    'phase_id' => $phaseId,
                    'slug' => $lessonSlug,
                ]);

                if ($lessonFind->fetchColumn()) {
                    continue;
                }

                $lessonPos->execute([
                    'phase_id' => $phaseId,
                    'position' => $lessonPosition,
                ]);
                $collision = $lessonPos->fetch(PDO::FETCH_ASSOC);

                if ($collision) {
                    throw new RuntimeException(
                        sprintf(
                            'Posição %d da aula na fase "%s" já está ocupada por "%s".',
                            $lessonPosition,
                            $phase['title'],
                            $collision['title']
                        )
                    );
                }

                $lessonInsert->execute([
                    'phase_id' => $phaseId,
                    'title' => $lessonTitle,
                    'slug' => $lessonSlug,
                    'position' => $lessonPosition,
                ]);
            }
        }
    }

    $pdo->commit();

    echo "\n[OK] Grade-base garantida.\n";
    echo "[OK] {$totalPhases} fases previstas.\n";
    echo "[OK] {$totalLessons} aulas previstas.\n";
    echo "[OK] Todo o conteúdo permanece em rascunho.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
