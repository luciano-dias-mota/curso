<?php

declare(strict_types=1);

/**
 * Instala POP 5 e POP 6 a partir do Manual POP PMMT 2023 fornecido pelo usuário.
 *
 * Inclui:
 * - duas fases novas no Módulo 7;
 * - 1 aula de visão geral + 1 aula por processo;
 * - blocos pedagógicos curados com foco na literalidade;
 * - taxonomia POP -> Processo -> Procedimento;
 * - 60 questões curadas, com uma única alternativa correta;
 * - vínculos de questões com fase, aula e taxonomia;
 * - idempotência por question_import_keys.
 *
 * Não torna as fases obrigatórias neste script. A integração curricular é feita
 * por database/aplicar_modulos_4a8_progressao_v2.php depois da importação,
 * permitindo criar fixações/checkpoints somente quando o banco já está íntegro.
 *
 * Uso:
 *   php database/aplicar_pop5_pop6_v1.php
 *   php database/aplicar_pop5_pop6_v1.php --apply
 *   php database/aplicar_pop5_pop6_v1.php --apply --force-production
 */

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
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Faça backup e execute com --apply --force-production somente após revisar o dry-run.\n"
    );
    exit(1);
}

$sourceFile = BASE_PATH . '/database/sources/pop5_pop6_v1.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte não encontrada: {$sourceFile}\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);

if ((int)($data['format_version'] ?? 0) !== 1) {
    throw new RuntimeException('Fonte POP5/POP6 incompatível: format_version esperado = 1.');
}

$modulesData = is_array($data['modules'] ?? null) ? $data['modules'] : [];
if (count($modulesData) !== 2) {
    throw new RuntimeException('Fonte POP5/POP6 inválida: esperados exatamente 2 módulos.');
}

$expectedProcessCodes = [
    'POP5' => ['501','502','503','504','505'],
    'POP6' => ['601','602','603','604','605','606','607'],
];

$totalQuestions = 0;
foreach ($modulesData as $moduleData) {
    $code = (string)($moduleData['code'] ?? '');
    $processes = is_array($moduleData['processes'] ?? null) ? $moduleData['processes'] : [];
    $codes = array_map(static fn(array $p): string => (string)($p['code'] ?? ''), $processes);

    if (!isset($expectedProcessCodes[$code]) || $codes !== $expectedProcessCodes[$code]) {
        throw new RuntimeException("Sequência de processos inválida para {$code}.");
    }

    foreach ($processes as $process) {
        $questions = is_array($process['questions'] ?? null) ? $process['questions'] : [];
        if (count($questions) < 4) {
            throw new RuntimeException(
                "Processo {$process['code']} possui menos de 4 questões curadas."
            );
        }

        foreach ($questions as $question) {
            $alts = is_array($question['alternatives'] ?? null) ? $question['alternatives'] : [];
            $correct = 0;
            foreach ($alts as $alt) {
                if (!empty($alt['correct'])) {
                    $correct++;
                }
            }

            if (trim((string)($question['statement'] ?? '')) === ''
                || count($alts) !== 5
                || $correct !== 1) {
                throw new RuntimeException(
                    "Questão inválida na fonte: " . (string)($question['source_key'] ?? '[sem chave]')
                );
            }
            $totalQuestions++;
        }
    }
}

if ($totalQuestions !== 60) {
    throw new RuntimeException("Fonte inválida: esperadas 60 questões; encontradas {$totalQuestions}.");
}

$pdo = Database::connection();

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME=:table"
    );
    $stmt->execute(['table'=>$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function firstAdminId(PDO $pdo): ?int
{
    $stmt = $pdo->query(
        "SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id=u.role_id
         WHERE r.slug='admin'
           AND u.status='active'
         ORDER BY u.id
         LIMIT 1"
    );
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

$courseStmt = $pdo->prepare(
    "SELECT id,title
     FROM courses
     WHERE slug='preparacao-pmmt-merito-intelectual-e-caoc'
       AND status='published'
     LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    throw new RuntimeException('Curso Preparação PMMT publicado não encontrado.');
}

$popModuleStmt = $pdo->prepare(
    "SELECT id,title
     FROM modules
     WHERE course_id=:course_id
       AND status='published'
       AND (
            slug LIKE '%procedimentos-operacionais-padrao%'
         OR title LIKE '%Procedimentos Operacionais%'
       )
     ORDER BY position,id
     LIMIT 1"
);
$popModuleStmt->execute(['course_id'=>(int)$course['id']]);
$popModule = $popModuleStmt->fetch(PDO::FETCH_ASSOC);

if (!$popModule) {
    throw new RuntimeException('Módulo curricular de POP não encontrado.');
}

$popModuleNewTitle = 'Módulo 7 - Procedimentos Operacionais Padrão PMMT: Módulos I a VI';
$popModuleNewDescription = 'Trilha integral do Manual POP PMMT 2023, contemplando os Módulos I a VI com aulas, fixações, checkpoints e banco de questões por processo.';

$ddl = [];

if (!tableExists($pdo, 'study_topics')) {
    $ddl[] =
        "CREATE TABLE study_topics (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            parent_id BIGINT UNSIGNED NULL,
            domain VARCHAR(50) NOT NULL,
            code VARCHAR(80) NOT NULL,
            slug VARCHAR(190) NOT NULL,
            topic_type ENUM('pop','process','procedure','theme') NOT NULL DEFAULT 'theme',
            title VARCHAR(190) NOT NULL,
            position INT UNSIGNED NOT NULL DEFAULT 1,
            active TINYINT(1) NOT NULL DEFAULT 1,
            source_label VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_study_topics_domain_code (domain, code),
            UNIQUE KEY uq_study_topics_domain_slug (domain, slug),
            KEY idx_study_topics_parent (parent_id, position),
            CONSTRAINT fk_study_topics_parent
                FOREIGN KEY (parent_id) REFERENCES study_topics(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

if (!tableExists($pdo, 'question_topics')) {
    $ddl[] =
        "CREATE TABLE question_topics (
            question_id BIGINT UNSIGNED NOT NULL,
            topic_id BIGINT UNSIGNED NOT NULL,
            is_primary TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (question_id, topic_id),
            KEY idx_question_topics_topic (topic_id, question_id),
            CONSTRAINT fk_question_topics_question
                FOREIGN KEY (question_id) REFERENCES questions(id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_question_topics_topic
                FOREIGN KEY (topic_id) REFERENCES study_topics(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

if (!tableExists($pdo, 'question_import_keys')) {
    $ddl[] =
        "CREATE TABLE question_import_keys (
            source_key VARCHAR(120) NOT NULL,
            question_id BIGINT UNSIGNED NOT NULL,
            source_name VARCHAR(190) NOT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (source_key),
            KEY idx_question_import_keys_question (question_id),
            CONSTRAINT fk_question_import_keys_question
                FOREIGN KEY (question_id) REFERENCES questions(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
}

echo "=============================================================\n";
echo " POP 5 + POP 6 - CONTEÚDO, TAXONOMIA E QUESTÕES V1\n";
echo "=============================================================\n";
printf("Banco:       %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:       #%d - %s\n", (int)$course['id'], $course['title']);
printf("Módulo POP:  #%d - %s\n", (int)$popModule['id'], $popModule['title']);
printf("Modo:        %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

$topicCount = 0;
$lessonCount = 0;
foreach ($modulesData as $moduleData) {
    $processes = $moduleData['processes'] ?? [];
    $procedureCount = 0;
    $questionCount = 0;

    foreach ($processes as $process) {
        $procedureCount += count($process['procedures'] ?? []);
        $questionCount += count($process['questions'] ?? []);
    }

    $topics = 1 + count($processes) + $procedureCount;
    $lessons = 1 + count($processes);
    $topicCount += $topics;
    $lessonCount += $lessons;

    printf("%s | %s\n", $moduleData['code'], $moduleData['title']);
    printf("  Processos:      %d\n", count($processes));
    printf("  Procedimentos:  %d\n", $procedureCount);
    printf("  Aulas:          %d\n", $lessons);
    printf("  Tópicos:        %d\n", $topics);
    printf("  Questões:       %d\n", $questionCount);

    foreach ($processes as $process) {
        printf(
            "    - %s | procedimentos=%d | blocos=%d | questões=%d | páginas %d-%d\n",
            $process['code'],
            count($process['procedures'] ?? []),
            count($process['blocks'] ?? []),
            count($process['questions'] ?? []),
            (int)($process['source_pages'][0] ?? 0),
            (int)($process['source_pages'][1] ?? 0)
        );
    }
    echo "\n";
}

printf("TOTAL | aulas=%d | tópicos=%d | questões=%d\n", $lessonCount, $topicCount, $totalQuestions);

if ($ddl !== []) {
    foreach ($ddl as $sql) {
        if (preg_match('/CREATE TABLE\s+(\w+)/i', $sql, $m)) {
            printf("[CRIAR] tabela %s\n", $m[1]);
        }
    }
}

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, execute: php database/aplicar_pop5_pop6_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

foreach ($ddl as $sql) {
    $pdo->exec($sql);
}

$pdo->beginTransaction();

try {
    $pdo->prepare(
        "UPDATE modules
         SET title=:title,
             description=:description,
             updated_at=NOW()
         WHERE id=:id"
    )->execute([
        'title'=>$popModuleNewTitle,
        'description'=>$popModuleNewDescription,
        'id'=>(int)$popModule['id'],
    ]);

    $adminId = firstAdminId($pdo);

    $findPhase = $pdo->prepare(
        "SELECT id,position
         FROM phases
         WHERE module_id=:module_id
           AND slug=:slug
         LIMIT 1"
    );

    $maxPhasePosition = $pdo->prepare(
        "SELECT COALESCE(MAX(position),0)
         FROM phases
         WHERE module_id=:module_id"
    );

    $insertPhase = $pdo->prepare(
        "INSERT INTO phases
            (module_id,title,slug,description,phase_type,is_required,position,
             required_score,required_lessons_pct,max_attempts,xp_reward,status)
         VALUES
            (:module_id,:title,:slug,:description,'normal',0,:position,
             80,100,NULL,0,'published')"
    );

    $updatePhase = $pdo->prepare(
        "UPDATE phases
         SET title=:title,
             description=:description,
             phase_type='normal',
             required_score=80,
             status='published',
             updated_at=NOW()
         WHERE id=:id"
    );

    $findLesson = $pdo->prepare(
        "SELECT id
         FROM lessons
         WHERE phase_id=:phase_id
           AND slug=:slug
         LIMIT 1"
    );

    $maxLessonPosition = $pdo->prepare(
        "SELECT COALESCE(MAX(position),0)
         FROM lessons
         WHERE phase_id=:phase_id"
    );

    $insertLesson = $pdo->prepare(
        "INSERT INTO lessons
            (phase_id,title,slug,summary,is_required,position,estimated_minutes,xp_reward,status)
         VALUES
            (:phase_id,:title,:slug,:summary,0,:position,:estimated_minutes,0,'published')"
    );

    $updateLesson = $pdo->prepare(
        "UPDATE lessons
         SET title=:title,
             summary=:summary,
             estimated_minutes=:estimated_minutes,
             status='published',
             updated_at=NOW()
         WHERE id=:id"
    );

    $deleteBlocks = $pdo->prepare(
        "DELETE FROM lesson_blocks WHERE lesson_id=:lesson_id"
    );

    $insertBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
            (lesson_id,block_type,title,content,media_url,position,is_required)
         VALUES
            (:lesson_id,:block_type,:title,:content,NULL,:position,1)"
    );

    $selectTopic = $pdo->prepare(
        "SELECT id
         FROM study_topics
         WHERE domain='pop'
           AND code=:code
         LIMIT 1"
    );

    $insertTopic = $pdo->prepare(
        "INSERT INTO study_topics
            (parent_id,domain,code,slug,topic_type,title,position,active,source_label)
         VALUES
            (:parent_id,'pop',:code,:slug,:topic_type,:title,:position,1,:source_label)"
    );

    $updateTopic = $pdo->prepare(
        "UPDATE study_topics
         SET parent_id=:parent_id,
             slug=:slug,
             topic_type=:topic_type,
             title=:title,
             position=:position,
             active=1,
             source_label=:source_label,
             updated_at=NOW()
         WHERE id=:id"
    );

    $findKey = $pdo->prepare(
        "SELECT question_id
         FROM question_import_keys
         WHERE source_key=:source_key
         LIMIT 1"
    );

    $findExactQuestion = $pdo->prepare(
        "SELECT id
         FROM questions
         WHERE course_id=:course_id
           AND module_id=:module_id
           AND statement=:statement
         LIMIT 1"
    );

    $insertQuestion = $pdo->prepare(
        "INSERT INTO questions
            (course_id,module_id,phase_id,lesson_id,question_type,statement,
             explanation,difficulty,source_label,active,created_by)
         VALUES
            (:course_id,:module_id,:phase_id,:lesson_id,'multiple_choice',:statement,
             :explanation,:difficulty,:source_label,1,:created_by)"
    );

    $updateImportedQuestion = $pdo->prepare(
        "UPDATE questions
         SET phase_id=:phase_id,
             lesson_id=:lesson_id,
             explanation=:explanation,
             difficulty=:difficulty,
             source_label=:source_label,
             active=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $countAlternatives = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS corrects
         FROM alternatives
         WHERE question_id=:question_id"
    );

    $deleteAlternatives = $pdo->prepare(
        "DELETE FROM alternatives WHERE question_id=:question_id"
    );

    $insertAlternative = $pdo->prepare(
        "INSERT INTO alternatives
            (question_id,label,text,is_correct,explanation,position)
         VALUES
            (:question_id,:label,:text,:is_correct,NULL,:position)"
    );

    $insertKey = $pdo->prepare(
        "INSERT INTO question_import_keys
            (source_key,question_id,source_name)
         VALUES
            (:source_key,:question_id,:source_name)"
    );

    $updateKey = $pdo->prepare(
        "UPDATE question_import_keys
         SET question_id=:question_id,
             source_name=:source_name,
             updated_at=NOW()
         WHERE source_key=:source_key"
    );

    $linkTopic = $pdo->prepare(
        "INSERT INTO question_topics
            (question_id,topic_id,is_primary)
         VALUES
            (:question_id,:topic_id,:is_primary)
         ON DUPLICATE KEY UPDATE
            is_primary=GREATEST(question_topics.is_primary,VALUES(is_primary))"
    );

    $topicParent = $pdo->prepare(
        "SELECT parent_id
         FROM study_topics
         WHERE id=:id
         LIMIT 1"
    );

    $stats = [
        'phases_created'=>0,
        'phases_updated'=>0,
        'lessons_created'=>0,
        'lessons_updated'=>0,
        'blocks'=>0,
        'topics'=>0,
        'questions_new'=>0,
        'questions_updated'=>0,
        'questions_reused'=>0,
        'topic_links'=>0,
    ];

    foreach ($modulesData as $moduleData) {
        $popCode = (string)$moduleData['code'];
        $phaseSlug = (string)$moduleData['slug'];

        $findPhase->execute([
            'module_id'=>(int)$popModule['id'],
            'slug'=>$phaseSlug,
        ]);
        $phase = $findPhase->fetch(PDO::FETCH_ASSOC);

        $phaseDescription =
            'Conteúdo atualizado do ' . $moduleData['title']
            . ' extraído do Manual POP PMMT 2023. '
            . 'A progressão obrigatória é integrada após a auditoria do banco de questões.';

        if ($phase) {
            $phaseId = (int)$phase['id'];
            $updatePhase->execute([
                'title'=>(string)$moduleData['title'],
                'description'=>$phaseDescription,
                'id'=>$phaseId,
            ]);
            $stats['phases_updated']++;
        } else {
            $maxPhasePosition->execute(['module_id'=>(int)$popModule['id']]);
            $phasePosition = (int)$maxPhasePosition->fetchColumn() + 1;

            $insertPhase->execute([
                'module_id'=>(int)$popModule['id'],
                'title'=>(string)$moduleData['title'],
                'slug'=>$phaseSlug,
                'description'=>$phaseDescription,
                'position'=>$phasePosition,
            ]);
            $phaseId = (int)$pdo->lastInsertId();
            $stats['phases_created']++;
        }

        $processLessonIds = [];

        // Visão geral
        $overviewSlug = strtolower($popCode) . '-visao-geral-atualizada';
        $findLesson->execute([
            'phase_id'=>$phaseId,
            'slug'=>$overviewSlug,
        ]);
        $overviewId = $findLesson->fetchColumn();

        $overviewSummary = sprintf(
            '%s: visão geral, mapa de processos e orientação de estudo por literalidade.',
            (string)$moduleData['title']
        );

        if ($overviewId !== false) {
            $overviewId = (int)$overviewId;
            $updateLesson->execute([
                'title'=>(string)$moduleData['intro']['title'],
                'summary'=>$overviewSummary,
                'estimated_minutes'=>12,
                'id'=>$overviewId,
            ]);
            $stats['lessons_updated']++;
        } else {
            $maxLessonPosition->execute(['phase_id'=>$phaseId]);
            $lessonPosition = (int)$maxLessonPosition->fetchColumn() + 1;
            $insertLesson->execute([
                'phase_id'=>$phaseId,
                'title'=>(string)$moduleData['intro']['title'],
                'slug'=>$overviewSlug,
                'summary'=>$overviewSummary,
                'position'=>$lessonPosition,
                'estimated_minutes'=>12,
            ]);
            $overviewId = (int)$pdo->lastInsertId();
            $stats['lessons_created']++;
        }

        $deleteBlocks->execute(['lesson_id'=>$overviewId]);
        $insertBlock->execute([
            'lesson_id'=>$overviewId,
            'block_type'=>'theory',
            'title'=>(string)$moduleData['intro']['title'],
            'content'=>(string)$moduleData['intro']['content'],
            'position'=>1,
        ]);
        $stats['blocks']++;

        $map = [];
        foreach ($moduleData['processes'] as $process) {
            $map[] = sprintf(
                '%s - %s | Procedimentos: %s',
                $process['code'],
                $process['title'],
                implode(', ', $process['procedures'])
            );
        }

        $insertBlock->execute([
            'lesson_id'=>$overviewId,
            'block_type'=>'comparison',
            'title'=>'Mapa completo dos processos',
            'content'=>implode("\n", $map),
            'position'=>2,
        ]);
        $stats['blocks']++;

        // Aulas de processo
        foreach ($moduleData['processes'] as $index=>$process) {
            $code = (string)$process['code'];
            $lessonSlug = strtolower($popCode) . '-processo-' . $code . '-atualizado';

            $findLesson->execute([
                'phase_id'=>$phaseId,
                'slug'=>$lessonSlug,
            ]);
            $lessonId = $findLesson->fetchColumn();

            $summary = sprintf(
                'Processo %s - %s. Procedimentos: %s. Fonte: Manual POP PMMT 2023, páginas %d a %d.',
                $code,
                (string)$process['title'],
                implode(', ', $process['procedures']),
                (int)$process['source_pages'][0],
                (int)$process['source_pages'][1]
            );

            $estimatedMinutes = max(
                12,
                min(55, 8 + (count($process['blocks']) * 6))
            );

            if ($lessonId !== false) {
                $lessonId = (int)$lessonId;
                $updateLesson->execute([
                    'title'=>$popCode . ' - Processo ' . $code . ': ' . $process['title'],
                    'summary'=>$summary,
                    'estimated_minutes'=>$estimatedMinutes,
                    'id'=>$lessonId,
                ]);
                $stats['lessons_updated']++;
            } else {
                $maxLessonPosition->execute(['phase_id'=>$phaseId]);
                $lessonPosition = (int)$maxLessonPosition->fetchColumn() + 1;
                $insertLesson->execute([
                    'phase_id'=>$phaseId,
                    'title'=>$popCode . ' - Processo ' . $code . ': ' . $process['title'],
                    'slug'=>$lessonSlug,
                    'summary'=>$summary,
                    'position'=>$lessonPosition,
                    'estimated_minutes'=>$estimatedMinutes,
                ]);
                $lessonId = (int)$pdo->lastInsertId();
                $stats['lessons_created']++;
            }

            $processLessonIds[$code] = $lessonId;

            $deleteBlocks->execute(['lesson_id'=>$lessonId]);
            $blockPosition = 1;
            foreach ($process['blocks'] as $block) {
                $insertBlock->execute([
                    'lesson_id'=>$lessonId,
                    'block_type'=>(string)($block['type'] ?? 'theory'),
                    'title'=>(string)($block['title'] ?? ''),
                    'content'=>(string)$block['content'],
                    'position'=>$blockPosition++,
                ]);
                $stats['blocks']++;
            }
        }

        // Taxonomia
        $topicIds = [];

        $topicDefinitions = [[
            'code'=>$popCode,
            'parent'=>null,
            'type'=>'pop',
            'slug'=>'pop-' . (int)$moduleData['number'],
            'title'=>(string)$moduleData['title'],
            'position'=>(int)$moduleData['number'],
        ]];

        foreach ($moduleData['processes'] as $processIndex=>$process) {
            $processCode = (string)$process['code'];
            $topicDefinitions[] = [
                'code'=>$processCode,
                'parent'=>$popCode,
                'type'=>'process',
                'slug'=>'pop-' . (int)$moduleData['number'] . '-processo-' . $processCode,
                'title'=>'Processo ' . $processCode . ' - ' . $process['title'],
                'position'=>$processIndex + 1,
            ];

            foreach ($process['procedures'] as $procedureIndex=>$procedureCode) {
                $procedureCode = (string)$procedureCode;
                $procedureTitle = (string)(
                    $process['procedure_titles'][$procedureCode]
                    ?? ('Procedimento ' . $procedureCode)
                );

                $topicDefinitions[] = [
                    'code'=>$procedureCode,
                    'parent'=>$processCode,
                    'type'=>'procedure',
                    'slug'=>'pop-' . (int)$moduleData['number'] . '-' . str_replace('.', '-', $procedureCode),
                    'title'=>$procedureCode . ' - ' . $procedureTitle,
                    'position'=>$procedureIndex + 1,
                ];
            }
        }

        foreach ($topicDefinitions as $definition) {
            $parentId = $definition['parent'] === null
                ? null
                : ($topicIds[$definition['parent']] ?? null);

            if ($definition['parent'] !== null && $parentId === null) {
                throw new RuntimeException(
                    "Pai não resolvido para tópico {$definition['code']}."
                );
            }

            $selectTopic->execute(['code'=>$definition['code']]);
            $topicId = $selectTopic->fetchColumn();

            $payload = [
                'parent_id'=>$parentId,
                'slug'=>$definition['slug'],
                'topic_type'=>$definition['type'],
                'title'=>$definition['title'],
                'position'=>$definition['position'],
                'source_label'=>(string)$moduleData['source_label'],
            ];

            if ($topicId !== false) {
                $topicId = (int)$topicId;
                $updateTopic->execute($payload + ['id'=>$topicId]);
            } else {
                $insertTopic->execute($payload + ['code'=>$definition['code']]);
                $topicId = (int)$pdo->lastInsertId();
            }

            $topicIds[$definition['code']] = $topicId;
            $stats['topics']++;
        }

        // Questões
        foreach ($moduleData['processes'] as $process) {
            $processCode = (string)$process['code'];
            $lessonId = (int)$processLessonIds[$processCode];

            foreach ($process['questions'] as $item) {
                $sourceKey = (string)$item['source_key'];
                $statement = trim((string)$item['statement']);

                $findKey->execute(['source_key'=>$sourceKey]);
                $keyQuestionId = $findKey->fetchColumn();

                $questionId = 0;
                $mode = 'new';

                if ($keyQuestionId !== false) {
                    $questionId = (int)$keyQuestionId;
                    $mode = 'existing_key';
                } else {
                    $findExactQuestion->execute([
                        'course_id'=>(int)$course['id'],
                        'module_id'=>(int)$popModule['id'],
                        'statement'=>$statement,
                    ]);
                    $exact = $findExactQuestion->fetchColumn();

                    if ($exact !== false) {
                        $questionId = (int)$exact;
                        $mode = 'reuse_exact';
                    }
                }

                $params = [
                    'phase_id'=>$phaseId,
                    'lesson_id'=>$lessonId,
                    'explanation'=>(string)$item['explanation'],
                    'difficulty'=>(string)$item['difficulty'],
                    'source_label'=>(string)$item['source_label'],
                ];

                if ($questionId === 0) {
                    $insertQuestion->execute($params + [
                        'course_id'=>(int)$course['id'],
                        'module_id'=>(int)$popModule['id'],
                        'statement'=>$statement,
                        'created_by'=>$adminId,
                    ]);
                    $questionId = (int)$pdo->lastInsertId();
                    $stats['questions_new']++;

                    foreach ($item['alternatives'] as $altIndex=>$alt) {
                        $insertAlternative->execute([
                            'question_id'=>$questionId,
                            'label'=>(string)$alt['label'],
                            'text'=>(string)$alt['text'],
                            'is_correct'=>!empty($alt['correct']) ? 1 : 0,
                            'position'=>$altIndex + 1,
                        ]);
                    }
                } else {
                    $updateImportedQuestion->execute(
                        $params + ['id'=>$questionId]
                    );

                    $countAlternatives->execute(['question_id'=>$questionId]);
                    $altStats = $countAlternatives->fetch(PDO::FETCH_ASSOC);
                    $altTotal = (int)($altStats['total'] ?? 0);
                    $correctTotal = (int)($altStats['corrects'] ?? 0);

                    if ($mode === 'existing_key') {
                        // Questão deste próprio importador: sincroniza alternativas
                        // com a fonte curada para corrigir eventual edição parcial.
                        $deleteAlternatives->execute(['question_id'=>$questionId]);
                        foreach ($item['alternatives'] as $altIndex=>$alt) {
                            $insertAlternative->execute([
                                'question_id'=>$questionId,
                                'label'=>(string)$alt['label'],
                                'text'=>(string)$alt['text'],
                                'is_correct'=>!empty($alt['correct']) ? 1 : 0,
                                'position'=>$altIndex + 1,
                            ]);
                        }
                        $stats['questions_updated']++;
                    } else {
                        if ($altTotal < 4 || $correctTotal !== 1) {
                            throw new RuntimeException(
                                "Questão existente #{$questionId} não pode ser reutilizada: "
                                . "{$altTotal} alternativas / {$correctTotal} correta(s)."
                            );
                        }
                        $stats['questions_reused']++;
                    }
                }

                if ($mode === 'existing_key') {
                    $updateKey->execute([
                        'question_id'=>$questionId,
                        'source_name'=>'POP 5 e POP 6 - banco literal V1',
                        'source_key'=>$sourceKey,
                    ]);
                } else {
                    $insertKey->execute([
                        'source_key'=>$sourceKey,
                        'question_id'=>$questionId,
                        'source_name'=>'POP 5 e POP 6 - banco literal V1',
                    ]);
                }

                // Liga ao procedimento primário, processo e raiz POP.
                $primaryCode = (string)$item['primary_topic'];
                if (!isset($topicIds[$primaryCode])) {
                    throw new RuntimeException(
                        "Tópico primário não resolvido: {$primaryCode}."
                    );
                }

                $primaryId = (int)$topicIds[$primaryCode];
                $ancestorIds = [];
                $current = $primaryId;
                $seen = [];

                while ($current > 0 && !isset($seen[$current])) {
                    $seen[$current] = true;
                    $ancestorIds[] = $current;
                    $topicParent->execute(['id'=>$current]);
                    $parentId = $topicParent->fetchColumn();
                    $current = ($parentId === false || $parentId === null)
                        ? 0
                        : (int)$parentId;
                }

                foreach ($ancestorIds as $topicId) {
                    $linkTopic->execute([
                        'question_id'=>$questionId,
                        'topic_id'=>$topicId,
                        'is_primary'=>$topicId === $primaryId ? 1 : 0,
                    ]);
                    $stats['topic_links']++;
                }
            }
        }

        // Fases ainda opcionais nesta etapa; disponibiliza biblioteca para
        // matrículas que já possuem acesso ao módulo POP.
        if (tableExists($pdo, 'user_module_progress')
            && tableExists($pdo, 'user_phase_progress')
            && tableExists($pdo, 'user_lesson_progress')) {

            $openPhase = $pdo->prepare(
                "INSERT INTO user_phase_progress
                    (user_id,phase_id,status,best_score,attempts_count,progress_pct,unlocked_at)
                 SELECT ump.user_id,:phase_id,'available',0,0,0,NOW()
                 FROM user_module_progress ump
                 WHERE ump.module_id=:module_id
                   AND ump.status IN ('available','in_progress','completed')
                 ON DUPLICATE KEY UPDATE
                    status=IF(
                        user_phase_progress.status IN ('completed','in_progress'),
                        user_phase_progress.status,
                        'available'
                    ),
                    unlocked_at=COALESCE(user_phase_progress.unlocked_at,NOW())"
            );
            $openPhase->execute([
                'phase_id'=>$phaseId,
                'module_id'=>(int)$popModule['id'],
            ]);

            $openLesson = $pdo->prepare(
                "INSERT INTO user_lesson_progress
                    (user_id,lesson_id,status,current_block,blocks_viewed,progress_pct)
                 SELECT ump.user_id,:lesson_id,'available',1,0,0
                 FROM user_module_progress ump
                 WHERE ump.module_id=:module_id
                   AND ump.status IN ('available','in_progress','completed')
                 ON DUPLICATE KEY UPDATE
                    status=IF(
                        user_lesson_progress.status IN ('completed','in_progress'),
                        user_lesson_progress.status,
                        'available'
                    )"
            );

            $lessonIds = array_values($processLessonIds);
            $lessonIds[] = (int)$overviewId;

            foreach ($lessonIds as $lessonId) {
                $openLesson->execute([
                    'lesson_id'=>$lessonId,
                    'module_id'=>(int)$popModule['id'],
                ]);
            }
        }
    }

    $pdo->commit();

    echo "\n[OK] POP 5 e POP 6 importados com conteúdo, taxonomia e banco literal.\n";
    printf(
        "[OK] Fases: criadas=%d | atualizadas=%d\n",
        $stats['phases_created'],
        $stats['phases_updated']
    );
    printf(
        "[OK] Aulas: criadas=%d | atualizadas=%d | blocos=%d\n",
        $stats['lessons_created'],
        $stats['lessons_updated'],
        $stats['blocks']
    );
    printf(
        "[OK] Tópicos processados=%d | links de tópicos=%d\n",
        $stats['topics'],
        $stats['topic_links']
    );
    printf(
        "[OK] Questões: novas=%d | atualizadas=%d | reutilizadas=%d\n",
        $stats['questions_new'],
        $stats['questions_updated'],
        $stats['questions_reused']
    );
    echo "[PRÓXIMO] Rode database/aplicar_modulos_4a8_progressao_v2.php para integrar POP 5 e POP 6 à progressão obrigatória e gerar fixações/checkpoints.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(7);
}
