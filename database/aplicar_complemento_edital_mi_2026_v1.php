<?php

declare(strict_types=1);

/**
 * Complemento do Edital MI 2026 - V1
 *
 * Cria as matérias ausentes identificadas no Anexo D oficial enviado pelo usuário,
 * usando somente conteúdo previamente curado de fontes oficiais/institucionais.
 *
 * Não inclui a Portaria nº 025/QCG/2025 enquanto não houver cópia oficial validada.
 *
 * Uso:
 *   php database/aplicar_complemento_edital_mi_2026_v1.php
 *   php database/aplicar_complemento_edital_mi_2026_v1.php --apply
 *   php database/aplicar_complemento_edital_mi_2026_v1.php --apply --force-production
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;
use App\Services\ProgressInitializer;

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

$sourceFile = BASE_PATH . '/database/sources/complemento_edital_mi_2026_v1.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte não encontrada: {$sourceFile}\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$phasesData = is_array($data['phases'] ?? null) ? $data['phases'] : [];

if ((int)($data['format_version'] ?? 0) !== 1 || count($phasesData) !== 11) {
    fwrite(STDERR, "[FALHA] Fonte curricular incompatível. Esperadas 11 fases.\n");
    exit(3);
}

$totalQuestions = 0;
foreach ($phasesData as $phaseData) {
    $questions = is_array($phaseData['questions'] ?? null) ? $phaseData['questions'] : [];
    if (count($questions) < 5) {
        throw new RuntimeException(
            "Fase {$phaseData['slug']} possui menos de 5 questões curadas."
        );
    }

    foreach ($questions as $question) {
        $alts = is_array($question['alternatives'] ?? null) ? $question['alternatives'] : [];
        $correct = 0;
        foreach ($alts as $alt) {
            if (!empty($alt['is_correct'])) {
                $correct++;
            }
        }

        if (trim((string)($question['source_key'] ?? '')) === ''
            || trim((string)($question['statement'] ?? '')) === ''
            || count($alts) !== 5
            || $correct !== 1) {
            throw new RuntimeException(
                'Questão inválida na fonte: ' . (string)($question['source_key'] ?? '[sem chave]')
            );
        }
        $totalQuestions++;
    }
}

if ($totalQuestions !== 66) {
    throw new RuntimeException("Fonte inválida: esperadas 66 questões; encontradas {$totalQuestions}.");
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

foreach ([
    'courses','modules','phases','lessons','lesson_blocks',
    'questions','alternatives','quizzes','quiz_questions'
] as $requiredTable) {
    if (!tableExists($pdo, $requiredTable)) {
        throw new RuntimeException("Tabela obrigatória ausente: {$requiredTable}");
    }
}

$needsImportKeys = !tableExists($pdo, 'question_import_keys');

echo "=============================================================\n";
echo " COMPLEMENTO OFICIAL DO EDITAL MI 2026 - V1\n";
echo "=============================================================\n";
printf("Banco:     %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Modo:      %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Fases:     %d\n", count($phasesData));
printf("Questões:  %d\n\n", $totalQuestions);

$moduleStmt = $pdo->prepare(
    "SELECT m.id,m.course_id,m.title,m.slug
     FROM modules m
     INNER JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug
       AND m.status='published'
       AND c.status='published'
     LIMIT 1"
);

$resolved = [];
$errors = [];

foreach ($phasesData as $phaseData) {
    $moduleStmt->execute(['slug'=>(string)$phaseData['module_slug']]);
    $module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$module) {
        $errors[] = 'Módulo não encontrado: ' . (string)$phaseData['module_slug'];
        continue;
    }

    $resolved[] = ['module'=>$module,'phase'=>$phaseData];
    printf(
        "[OK] Módulo #%d | %-70s | %d questão(ões)\n",
        (int)$module['id'],
        (string)$phaseData['title'],
        count($phaseData['questions'] ?? [])
    );
}

if ($errors !== []) {
    foreach ($errors as $error) {
        echo "[FALHA] {$error}\n";
    }
    exit(4);
}

if ($needsImportKeys) {
    echo "[CRIAR] question_import_keys\n";
}

echo "\n[PENDENTE FONTE OFICIAL] Portaria nº 025/QCG/2025 não será criada por este script.\n";

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, execute: php database/aplicar_complemento_edital_mi_2026_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

if ($needsImportKeys) {
    $pdo->exec(
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );
}

$pdo->beginTransaction();

try {
    $findPhase = $pdo->prepare(
        "SELECT id
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
            (:module_id,:title,:slug,:description,'normal',1,:position,
             :required_score,100,NULL,100,'published')"
    );

    $updatePhase = $pdo->prepare(
        "UPDATE phases
         SET title=:title,
             description=:description,
             phase_type='normal',
             is_required=1,
             required_score=:required_score,
             required_lessons_pct=100,
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
            (:phase_id,:title,:slug,:summary,1,:position,:estimated_minutes,20,'published')"
    );

    $updateLesson = $pdo->prepare(
        "UPDATE lessons
         SET title=:title,
             summary=:summary,
             is_required=1,
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

    $findKey = $pdo->prepare(
        "SELECT question_id
         FROM question_import_keys
         WHERE source_key=:source_key
         LIMIT 1"
    );

    $findExact = $pdo->prepare(
        "SELECT id
         FROM questions
         WHERE module_id=:module_id
           AND statement=:statement
         LIMIT 1"
    );

    $insertQuestion = $pdo->prepare(
        "INSERT INTO questions
            (course_id,module_id,phase_id,lesson_id,question_type,
             statement,explanation,difficulty,source_label,active)
         VALUES
            (:course_id,:module_id,:phase_id,:lesson_id,'multiple_choice',
             :statement,:explanation,:difficulty,:source_label,1)"
    );

    $updateQuestion = $pdo->prepare(
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

    $findLessonQuiz = $pdo->prepare(
        "SELECT id
         FROM quizzes
         WHERE module_id=:module_id
           AND lesson_id=:lesson_id
           AND quiz_type='lesson_fixation'
         ORDER BY id
         LIMIT 1"
    );

    $findPhaseQuiz = $pdo->prepare(
        "SELECT id
         FROM quizzes
         WHERE module_id=:module_id
           AND phase_id=:phase_id
           AND lesson_id IS NULL
           AND quiz_type='phase_exam'
         ORDER BY id
         LIMIT 1"
    );

    $insertQuiz = $pdo->prepare(
        "INSERT INTO quizzes
            (course_id,module_id,phase_id,lesson_id,title,description,quiz_type,
             required_score,question_limit,time_limit_minutes,shuffle_questions,
             shuffle_answers,show_feedback,max_attempts,xp_reward,active)
         VALUES
            (:course_id,:module_id,:phase_id,:lesson_id,:title,:description,:quiz_type,
             :required_score,:question_limit,NULL,0,0,1,NULL,:xp_reward,1)"
    );

    $updateQuiz = $pdo->prepare(
        "UPDATE quizzes
         SET course_id=:course_id,
             phase_id=:phase_id,
             lesson_id=:lesson_id,
             title=:title,
             description=:description,
             required_score=:required_score,
             question_limit=:question_limit,
             shuffle_questions=0,
             shuffle_answers=0,
             show_feedback=1,
             max_attempts=NULL,
             xp_reward=:xp_reward,
             active=1,
             updated_at=NOW()
         WHERE id=:id"
    );

    $clearQuizQuestions = $pdo->prepare(
        "DELETE FROM quiz_questions WHERE quiz_id=:quiz_id"
    );

    $linkQuestion = $pdo->prepare(
        "INSERT INTO quiz_questions
            (quiz_id,question_id,position,points)
         VALUES
            (:quiz_id,:question_id,:position,1.00)"
    );

    $stats = [
        'phases_created'=>0,
        'phases_updated'=>0,
        'lessons_created'=>0,
        'lessons_updated'=>0,
        'questions_created'=>0,
        'questions_updated'=>0,
        'questions_reused'=>0,
        'quizzes'=>0,
    ];

    foreach ($resolved as $entry) {
        $module = $entry['module'];
        $phaseData = $entry['phase'];
        $moduleId = (int)$module['id'];
        $courseId = (int)$module['course_id'];

        $findPhase->execute([
            'module_id'=>$moduleId,
            'slug'=>(string)$phaseData['slug'],
        ]);
        $phaseId = (int)($findPhase->fetchColumn() ?: 0);

        $description =
            'Conteúdo obrigatório do Anexo D do Exame Intelectual • '
            . (string)$phaseData['source_label']
            . ' • fonte oficial validada em '
            . (string)($data['verified_at'] ?? '2026-10-18');

        if ($phaseId > 0) {
            $updatePhase->execute([
                'title'=>(string)$phaseData['title'],
                'description'=>$description,
                'required_score'=>(float)($phaseData['required_score'] ?? 80),
                'id'=>$phaseId,
            ]);
            $stats['phases_updated']++;
        } else {
            $maxPhasePosition->execute(['module_id'=>$moduleId]);
            $position = (int)$maxPhasePosition->fetchColumn() + 1;

            $insertPhase->execute([
                'module_id'=>$moduleId,
                'title'=>(string)$phaseData['title'],
                'slug'=>(string)$phaseData['slug'],
                'description'=>$description,
                'position'=>$position,
                'required_score'=>(float)($phaseData['required_score'] ?? 80),
            ]);
            $phaseId = (int)$pdo->lastInsertId();
            $stats['phases_created']++;
        }

        $lessonIds = [];
        foreach (($phaseData['lessons'] ?? []) as $lessonData) {
            $findLesson->execute([
                'phase_id'=>$phaseId,
                'slug'=>(string)$lessonData['slug'],
            ]);
            $lessonId = (int)($findLesson->fetchColumn() ?: 0);

            if ($lessonId > 0) {
                $updateLesson->execute([
                    'title'=>(string)$lessonData['title'],
                    'summary'=>(string)$lessonData['summary'],
                    'estimated_minutes'=>(int)($lessonData['estimated_minutes'] ?? 25),
                    'id'=>$lessonId,
                ]);
                $stats['lessons_updated']++;
            } else {
                $maxLessonPosition->execute(['phase_id'=>$phaseId]);
                $lessonPosition = (int)$maxLessonPosition->fetchColumn() + 1;

                $insertLesson->execute([
                    'phase_id'=>$phaseId,
                    'title'=>(string)$lessonData['title'],
                    'slug'=>(string)$lessonData['slug'],
                    'summary'=>(string)$lessonData['summary'],
                    'position'=>$lessonPosition,
                    'estimated_minutes'=>(int)($lessonData['estimated_minutes'] ?? 25),
                ]);
                $lessonId = (int)$pdo->lastInsertId();
                $stats['lessons_created']++;
            }

            $lessonIds[] = $lessonId;

            $deleteBlocks->execute(['lesson_id'=>$lessonId]);
            $blockPosition = 1;

            foreach (($lessonData['blocks'] ?? []) as $block) {
                $insertBlock->execute([
                    'lesson_id'=>$lessonId,
                    'block_type'=>(string)($block['type'] ?? 'theory'),
                    'title'=>(string)($block['title'] ?? 'Conteúdo'),
                    'content'=>(string)($block['content'] ?? ''),
                    'position'=>$blockPosition++,
                ]);
            }

            $insertBlock->execute([
                'lesson_id'=>$lessonId,
                'block_type'=>'summary',
                'title'=>'Fonte oficial utilizada',
                'content'=>(string)$phaseData['source_label'] . "\n" . (string)$phaseData['source_url'],
                'position'=>$blockPosition++,
            ]);
        }

        if ($lessonIds === []) {
            throw new RuntimeException("Fase {$phaseData['slug']} sem aula definida.");
        }

        $primaryLessonId = (int)$lessonIds[0];
        $phaseQuestionIds = [];

        foreach (($phaseData['questions'] ?? []) as $question) {
            $sourceKey = (string)$question['source_key'];
            $statement = trim((string)$question['statement']);

            $findKey->execute(['source_key'=>$sourceKey]);
            $questionId = (int)($findKey->fetchColumn() ?: 0);
            $mode = $questionId > 0 ? 'key' : 'new';

            if ($questionId === 0) {
                $findExact->execute([
                    'module_id'=>$moduleId,
                    'statement'=>$statement,
                ]);
                $questionId = (int)($findExact->fetchColumn() ?: 0);
                if ($questionId > 0) {
                    $mode = 'reuse';
                }
            }

            $params = [
                'phase_id'=>$phaseId,
                'lesson_id'=>$primaryLessonId,
                'explanation'=>(string)($question['explanation'] ?? ''),
                'difficulty'=>(string)($question['difficulty'] ?? 'medium'),
                'source_label'=>mb_strimwidth(
                    (string)$phaseData['source_label'] . ' | Edital MI 2026',
                    0,
                    255,
                    ''
                ),
            ];

            if ($questionId === 0) {
                $insertQuestion->execute($params + [
                    'course_id'=>$courseId,
                    'module_id'=>$moduleId,
                    'statement'=>$statement,
                ]);
                $questionId = (int)$pdo->lastInsertId();
                $stats['questions_created']++;
            } else {
                $updateQuestion->execute($params + ['id'=>$questionId]);
                if ($mode === 'reuse') {
                    $stats['questions_reused']++;
                } else {
                    $stats['questions_updated']++;
                }
            }

            // Para itens de nossa própria fonte, alternativas devem espelhar
            // exatamente a versão curada. Questões externas reaproveitadas
            // também são sincronizadas porque o enunciado é idêntico.
            $deleteAlternatives->execute(['question_id'=>$questionId]);
            foreach (($question['alternatives'] ?? []) as $idx=>$alt) {
                $insertAlternative->execute([
                    'question_id'=>$questionId,
                    'label'=>(string)($alt['label'] ?? chr(65+$idx)),
                    'text'=>(string)($alt['text'] ?? ''),
                    'is_correct'=>!empty($alt['is_correct']) ? 1 : 0,
                    'position'=>$idx+1,
                ]);
            }

            $countAlternatives->execute(['question_id'=>$questionId]);
            $quality = $countAlternatives->fetch(PDO::FETCH_ASSOC);
            if ((int)($quality['total'] ?? 0) !== 5
                || (int)($quality['corrects'] ?? 0) !== 1) {
                throw new RuntimeException(
                    "Questão {$sourceKey} inválida após gravação."
                );
            }

            if ($mode === 'key') {
                $updateKey->execute([
                    'question_id'=>$questionId,
                    'source_name'=>'Complemento Edital MI 2026 V1',
                    'source_key'=>$sourceKey,
                ]);
            } else {
                $insertKey->execute([
                    'source_key'=>$sourceKey,
                    'question_id'=>$questionId,
                    'source_name'=>'Complemento Edital MI 2026 V1',
                ]);
            }

            $phaseQuestionIds[] = $questionId;
        }

        $phaseQuestionIds = array_values(array_unique($phaseQuestionIds));
        if (count($phaseQuestionIds) < 5) {
            throw new RuntimeException(
                "Fase {$phaseData['slug']} ficou com menos de 5 questões únicas."
            );
        }

        // Cada aula nova recebe uma fixação obrigatória 3/3.
        foreach ($lessonIds as $lessonIndex=>$lessonId) {
            $findLessonQuiz->execute([
                'module_id'=>$moduleId,
                'lesson_id'=>$lessonId,
            ]);
            $quizId = (int)($findLessonQuiz->fetchColumn() ?: 0);

            $quizParams = [
                'course_id'=>$courseId,
                'phase_id'=>$phaseId,
                'lesson_id'=>$lessonId,
                'title'=>'Fixação — ' . (string)($phaseData['lessons'][$lessonIndex]['title'] ?? $phaseData['title']),
                'description'=>'Exercício obrigatório: 3 questões. Acerte 3 de 3 para concluir a aula.',
                'required_score'=>100.0,
                'question_limit'=>3,
                'xp_reward'=>20,
            ];

            if ($quizId > 0) {
                $updateQuiz->execute($quizParams + ['id'=>$quizId]);
            } else {
                $insertQuiz->execute($quizParams + [
                    'module_id'=>$moduleId,
                    'quiz_type'=>'lesson_fixation',
                ]);
                $quizId = (int)$pdo->lastInsertId();
            }

            $clearQuizQuestions->execute(['quiz_id'=>$quizId]);
            for ($i=0; $i<3; $i++) {
                $linkQuestion->execute([
                    'quiz_id'=>$quizId,
                    'question_id'=>$phaseQuestionIds[($lessonIndex * 3 + $i) % count($phaseQuestionIds)],
                    'position'=>$i+1,
                ]);
            }
            $stats['quizzes']++;
        }

        // Checkpoint obrigatório 5/5 a 80%.
        $findPhaseQuiz->execute([
            'module_id'=>$moduleId,
            'phase_id'=>$phaseId,
        ]);
        $checkpointId = (int)($findPhaseQuiz->fetchColumn() ?: 0);

        $checkpointParams = [
            'course_id'=>$courseId,
            'phase_id'=>$phaseId,
            'lesson_id'=>null,
            'title'=>'Checkpoint — ' . (string)$phaseData['title'],
            'description'=>'Avaliação obrigatória da fase: 5 questões. Aprovação com pelo menos 4 acertos.',
            'required_score'=>80.0,
            'question_limit'=>5,
            'xp_reward'=>80,
        ];

        if ($checkpointId > 0) {
            $updateQuiz->execute($checkpointParams + ['id'=>$checkpointId]);
        } else {
            $insertQuiz->execute($checkpointParams + [
                'module_id'=>$moduleId,
                'quiz_type'=>'phase_exam',
            ]);
            $checkpointId = (int)$pdo->lastInsertId();
        }

        $clearQuizQuestions->execute(['quiz_id'=>$checkpointId]);
        foreach (array_slice($phaseQuestionIds,0,5) as $idx=>$questionId) {
            $linkQuestion->execute([
                'quiz_id'=>$checkpointId,
                'question_id'=>$questionId,
                'position'=>$idx+1,
            ]);
        }
        $stats['quizzes']++;
    }

    // O Módulo 8 agora extrapola as seis leis especiais antigas.
    $pdo->prepare(
        "UPDATE modules
         SET title='Módulo 8 - Legislação Profissional, Penal e Processual Penal',
             description='ECA, Maria da Penha, Juizados, CTB, Crimes Ambientais, Drogas, Crime Racial, Desarmamento, Contravenções, TCO, Código Penal e CPP.',
             updated_at=NOW()
         WHERE slug='modulo-8-legislacao-profissional-eca-maria-da-penha-juizados-ctb-crimes-ambientais-e-lei-de-drogas'"
    )->execute();

    $pdo->commit();

    // Reconciliar matrículas para que a primeira nova fase pendente fique acessível
    // quando as fases anteriores já estiverem concluídas.
    $initializer = new ProgressInitializer();
    $enrollments = $pdo->query(
        "SELECT DISTINCT user_id,course_id
         FROM enrollments
         WHERE status='active'
         ORDER BY user_id,course_id"
    )->fetchAll(PDO::FETCH_ASSOC);

    $reconciled = 0;
    foreach ($enrollments as $enrollment) {
        $initializer->initializeEnrollment(
            (int)$enrollment['user_id'],
            (int)$enrollment['course_id'],
            $pdo
        );
        $reconciled++;
    }

    echo "\n[OK] Complemento do edital aplicado.\n";
    printf(
        "Fases: criadas=%d | atualizadas=%d\n",
        $stats['phases_created'],
        $stats['phases_updated']
    );
    printf(
        "Aulas: criadas=%d | atualizadas=%d\n",
        $stats['lessons_created'],
        $stats['lessons_updated']
    );
    printf(
        "Questões: novas=%d | atualizadas=%d | reaproveitadas=%d\n",
        $stats['questions_created'],
        $stats['questions_updated'],
        $stats['questions_reused']
    );
    printf("Quizzes configurados: %d\n", $stats['quizzes']);
    printf("Matrículas reconciliadas: %d\n", $reconciled);
    echo "[PENDENTE] Portaria nº 025/QCG/2025: envie a cópia oficial para integração literal.\n";
    echo "[PRÓXIMO] Execute os scripts de progressão do Módulo 3 e dos Módulos 4 a 8 para ordenar a trilha e reconstruir as avaliações finais.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(7);
}
