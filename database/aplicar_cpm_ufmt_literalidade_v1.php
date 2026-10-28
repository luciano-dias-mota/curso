<?php

declare(strict_types=1);

/**
 * CPM / UFMT: complemento de literalidade, sem alteração de aulas/questões existentes.
 *
 * Comandos:
 *   php database/aplicar_cpm_ufmt_literalidade_v1.php
 *   php database/aplicar_cpm_ufmt_literalidade_v1.php --audit
 *   php database/aplicar_cpm_ufmt_literalidade_v1.php --apply
 *   php database/aplicar_cpm_ufmt_literalidade_v1.php --apply --force-production
 *
 * --apply só insere quatro aulas opcionais com seus blocos e 24 questões novas.
 * Reexecuções validam os registros já inseridos, sem duplicar nem sobrescrever.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Disponível apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv, true);
$audit = in_array('--audit', $argv, true);
$forceProduction = in_array('--force-production', $argv, true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $audit) {
    throw new RuntimeException('Use --apply ou --audit, nunca os dois juntos.');
}
if ($apply && $isProduction && !$forceProduction) {
    fwrite(STDERR, "[BLOQUEADO] Ambiente de produção. Faça backup e use --apply --force-production após revisar o dry-run.\n");
    exit(1);
}

$lessonsFile = BASE_PATH . '/database/sources/cpm_ufmt_aulas_v1.json';
$questionsFile = BASE_PATH . '/database/sources/cpm_ufmt_questoes_v1.json';
foreach ([$lessonsFile, $questionsFile] as $file) {
    if (!is_file($file)) {
        throw new RuntimeException("Fonte ausente: {$file}");
    }
}
$curriculum = json_decode((string) file_get_contents($lessonsFile), true, 512, JSON_THROW_ON_ERROR);
$questionBank = json_decode((string) file_get_contents($questionsFile), true, 512, JSON_THROW_ON_ERROR);
$lessons = $curriculum['lessons'] ?? null;
$questions = $questionBank['questions'] ?? null;

if (($curriculum['format_version'] ?? null) !== 1
    || ($questionBank['format_version'] ?? null) !== 1
    || !is_array($lessons) || count($lessons) !== 4
    || !is_array($questions) || count($questions) !== 24) {
    throw new RuntimeException('Fontes incompatíveis. Esperados quatro reforços e 24 questões.');
}

$expectedTopics = ['art9' => 10, 'art195' => 5, 'art187' => 5, 'art149' => 4];
$expectedBlocks = ['art9' => 10, 'art195' => 5, 'art187' => 5, 'art149' => 6];
$topicLessons = [];
foreach ($lessons as $lesson) {
    $topic = (string) ($lesson['topic'] ?? '');
    $blocks = $lesson['blocks'] ?? [];
    if (!array_key_exists($topic, $expectedBlocks)
        || isset($topicLessons[$topic])
        || !is_array($blocks) || count($blocks) !== $expectedBlocks[$topic]
        || trim((string) ($lesson['slug'] ?? '')) === ''
        || trim((string) ($lesson['phase_slug'] ?? '')) === '') {
        throw new RuntimeException("Aula inválida ou repetida: {$topic}");
    }
    foreach ($blocks as $block) {
        if (!in_array($block['type'] ?? '', ['theory', 'example', 'warning', 'summary', 'comparison', 'memory', 'case_study'], true)
            || trim((string) ($block['title'] ?? '')) === ''
            || trim((string) ($block['content'] ?? '')) === '') {
            throw new RuntimeException("Bloco incompleto em {$topic}.");
        }
    }
    $topicLessons[$topic] = $lesson;
}

if (count($topicLessons) !== 4) {
    throw new RuntimeException('Faltam assuntos nas aulas.');
}
$questionKeys = [];
$topicTotals = array_fill_keys(array_keys($expectedTopics), 0);
foreach ($questions as $question) {
    $topic = (string) ($question['topic'] ?? '');
    $key = (string) ($question['source_key'] ?? '');
    $alternatives = $question['alternatives'] ?? [];
    if (!isset($topicLessons[$topic]) || $key === '' || isset($questionKeys[$key])
        || !is_array($alternatives) || count($alternatives) !== 5
        || trim((string) ($question['statement'] ?? '')) === ''
        || trim((string) ($question['explanation'] ?? '')) === ''
        || !in_array($question['difficulty'] ?? '', ['medium', 'hard'], true)) {
        throw new RuntimeException("Questão inválida: {$key}");
    }
    $correctCount = 0;
    foreach ($alternatives as $i => $alternative) {
        if (($alternative['label'] ?? '') !== chr(65 + $i)
            || trim((string) ($alternative['text'] ?? '')) === '') {
            throw new RuntimeException("Alternativa inválida: {$key}");
        }
        $correctCount += !empty($alternative['is_correct']) ? 1 : 0;
    }
    if ($correctCount !== 1) {
        throw new RuntimeException("Gabarito inválido: {$key}");
    }
    $questionKeys[$key] = true;
    ++$topicTotals[$topic];
}
if ($topicTotals !== $expectedTopics) {
    throw new RuntimeException('Distribuição das questões incorreta.');
}

$pdo = Database::connection();
$hasColumn = static function (string $table, string $column) use ($pdo): bool {
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c"
    );
    $statement->execute(['t' => $table, 'c' => $column]);
    return (int) $statement->fetchColumn() > 0;
};
$hasTable = static function (string $table) use ($pdo): bool {
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t"
    );
    $statement->execute(['t' => $table]);
    return (int) $statement->fetchColumn() > 0;
};
foreach (['courses','modules','phases','lessons','lesson_blocks','questions','alternatives','question_import_keys','enrollments','user_phase_progress','user_lesson_progress'] as $table) {
    if (!$hasTable($table)) {
        throw new RuntimeException("Tabela ausente: {$table}. Não criar estruturas automaticamente.");
    }
}
if (!$hasColumn('lessons', 'is_required')) {
    throw new RuntimeException('Coluna lessons.is_required ausente; aplique primeiro as migrações de progressão. Nenhuma gravação executada.');
}

$moduleStmt = $pdo->prepare(
    "SELECT m.id, m.course_id, m.title, c.title AS course_title
     FROM modules m INNER JOIN courses c ON c.id = m.course_id
     WHERE m.slug = :slug AND m.status = 'published' AND c.status = 'published'"
);
$moduleStmt->execute(['slug' => (string) ($curriculum['module_slug'] ?? '')]);
$candidates = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);

if ($hasColumn('courses','experience_key')) {
    $checkExperience = $pdo->prepare("SELECT experience_key FROM courses WHERE id = :id");
    $candidates = array_values(array_filter($candidates, static function (array $row) use ($checkExperience): bool {
        $checkExperience->execute(['id' => (int) $row['course_id']]);
        return (string) $checkExperience->fetchColumn() === 'pmmt';
    }));
} else {
    $candidates = array_values(array_filter($candidates, static fn(array $row): bool =>
        stripos((string) $row['course_title'], 'PMMT') !== false
    ));
}
if (count($candidates) !== 1) {
    throw new RuntimeException('O módulo do curso PMMT não foi identificado de forma inequívoca.');
}
$module = $candidates[0];
$moduleId = (int) $module['id'];
$courseId = (int) $module['course_id'];

$phaseStmt = $pdo->prepare(
    "SELECT id, slug FROM phases
     WHERE module_id = :module AND slug = :slug AND status = 'published'
     LIMIT 1"
);
$lessonStmt = $pdo->prepare(
    "SELECT id, is_required, status FROM lessons WHERE phase_id = :phase AND slug = :slug LIMIT 1"
);
$keyStmt = $pdo->prepare(
    "SELECT q.id,q.course_id,q.module_id,q.phase_id,q.lesson_id,q.statement,q.difficulty,q.explanation,q.active
     FROM question_import_keys k JOIN questions q ON q.id = k.question_id
     WHERE k.source_key = :source_key LIMIT 1"
);
$existingStatementStmt = $pdo->prepare(
    "SELECT id,course_id,module_id,phase_id,lesson_id,statement,difficulty,explanation,active
     FROM questions
     WHERE course_id = :course AND module_id = :module AND statement = :statement"
);
$alternativeStmt = $pdo->prepare(
    "SELECT label,text,is_correct FROM alternatives WHERE question_id = :id ORDER BY position,id"
);
$blockStmt = $pdo->prepare(
    "SELECT block_type,title,content,is_required FROM lesson_blocks WHERE lesson_id = :id ORDER BY position,id"
);
$phases = [];
$existingLessons = [];
$conflicts = [];
foreach ($topicLessons as $topic => $lesson) {
    $phaseStmt->execute(['module'=>$moduleId,'slug'=>(string)$lesson['phase_slug']]);
    $phase = $phaseStmt->fetch(PDO::FETCH_ASSOC);
    if (!$phase) {
        throw new RuntimeException("Fase não encontrada: {$lesson['phase_slug']}");
    }
    $phases[$topic] = (int) $phase['id'];
    $lessonStmt->execute(['phase'=>$phases[$topic],'slug'=>(string)$lesson['slug']]);
    $existing = $lessonStmt->fetch(PDO::FETCH_ASSOC);
    if (!$existing) {
        $existingLessons[$topic] = null;
        continue;
    }
    $lessonId = (int)$existing['id'];
    $blockStmt->execute(['id'=>$lessonId]);
    $savedBlocks = $blockStmt->fetchAll(PDO::FETCH_ASSOC);
    if ((int)$existing['is_required'] !== 0
        || $existing['status'] !== 'published'
        || count($savedBlocks) !== count($lesson['blocks'])) {
        $conflicts[] = "Aula já existe com estrutura diferente: {$lesson['slug']}";
        continue;
    }
    foreach ($savedBlocks as $index => $saved) {
        $expected = $lesson['blocks'][$index];
        if ($saved['block_type'] !== $expected['type']
            || $saved['title'] !== $expected['title']
            || $saved['content'] !== $expected['content']
            || (int)$saved['is_required'] !== 1) {
            $conflicts[] = "Conteúdo da aula existente difere da fonte: {$lesson['slug']}";
            break;
        }
    }
    $existingLessons[$topic] = $lessonId;
}

$verifyAlternatives = static function (int $id, array $expected) use ($alternativeStmt): bool {
    $alternativeStmt->execute(['id'=>$id]);
    $saved = $alternativeStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($saved) !== count($expected)) {
        return false;
    }
    foreach ($expected as $i => $item) {
        if ((string)$saved[$i]['label'] !== (string)$item['label']
            || (string)$saved[$i]['text'] !== (string)$item['text']
            || (int)$saved[$i]['is_correct'] !== (!empty($item['is_correct']) ? 1 : 0)) {
            return false;
        }
    }
    return true;
};
$knownQuestions = [];
$duplicates = 0;
foreach ($questions as $question) {
    $key = (string)$question['source_key'];
    $keyStmt->execute(['source_key'=>$key]);
    $existing = $keyStmt->fetch(PDO::FETCH_ASSOC);
    $mode = 'new';
    if ($existing) {
        $mode = 'key';
    } else {
        $existingStatementStmt->execute([
            'course'=>$courseId,'module'=>$moduleId,'statement'=>(string)$question['statement']
        ]);
        $sameStatements = $existingStatementStmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($sameStatements) > 1) {
            $conflicts[] = "Enunciado duplicado várias vezes: {$key}";
        } elseif (count($sameStatements) === 1) {
            $mode = 'duplicate';
            $existing = $sameStatements[0];
            ++$duplicates;
        }
    }
    if ($existing) {
        if ((int)$existing['course_id'] !== $courseId
            || (int)$existing['module_id'] !== $moduleId
            || (int)$existing['phase_id'] !== $phases[$question['topic']]
            || (string)$existing['statement'] !== $question['statement']
            || (string)$existing['difficulty'] !== $question['difficulty']
            || (string)$existing['explanation'] !== $question['explanation']
            || (int)$existing['active'] !== 1
            || !$verifyAlternatives((int)$existing['id'],$question['alternatives'])) {
            $conflicts[] = "Questão existente divergente: {$key}";
        }
        if ($existingLessons[$question['topic']] !== null
            && (int)$existing['lesson_id'] !== (int)$existingLessons[$question['topic']]) {
            $conflicts[] = "Questão vinculada a outra aula: {$key}";
        }
    }
    $knownQuestions[$key] = ['mode'=>$mode,'id'=>(int)($existing['id'] ?? 0)];
}

echo "==============================================================\n";
echo " CPM UFMT — LITERALIDADE DA LEI — V1\n";
echo "==============================================================\n";
printf("Banco:      %s\n",(string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:      #%d - %s\n",$courseId,$module['course_title']);
printf("Módulo:     #%d - %s\n",$moduleId,$module['title']);
printf("Modo:       %s\n",$audit?'AUDITORIA':($apply?'APLICAÇÃO':'DRY-RUN'));
printf("Aulas:      %d | existentes: %d\n",count($lessons),count(array_filter($existingLessons,static fn($v)=>$v!==null)));
printf("Blocos:     %d\n",array_sum($expectedBlocks));
printf("Questões:   %d | importadas: %d | iguais reaproveitáveis: %d\n",
    count($questions),
    count(array_filter($knownQuestions,static fn($row)=>$row['mode']==='key')),
    $duplicates
);
printf("Distribuição:%s\n",json_encode($topicTotals,JSON_UNESCAPED_UNICODE));

if ($conflicts !== []) {
    foreach ($conflicts as $conflict) {
        fwrite(STDERR,"[CONFLITO] {$conflict}\n");
    }
    throw new RuntimeException('Inconsistências encontradas: nenhuma gravação permitida.');
}

if ($audit) {
    $missing = count(array_filter($existingLessons,static fn($v)=>$v===null))
        + count(array_filter($knownQuestions,static fn($row)=>$row['mode']!=='key'));
    if ($missing !== 0) {
        fwrite(STDERR,"[PENDENTE] Aulas ou chaves das questões ausentes: {$missing}\n");
        exit(6);
    }
    echo "[OK] Auditoria: 4 aulas opcionais, 26 blocos e 24 questões com 5 alternativas e um único gabarito por item.\n";
    exit(0);
}

if (!$apply) {
    echo "[DRY-RUN] Nenhum registro alterado. Após backup, use --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo ".\n";
    exit(0);
}

$insertLesson = $pdo->prepare(
    "INSERT INTO lessons
       (phase_id,title,slug,summary,position,estimated_minutes,xp_reward,status,is_required)
     VALUES
       (:phase,:title,:slug,:summary,:position,:minutes,0,'published',0)"
);
$nextLessonPosition = $pdo->prepare(
    "SELECT COALESCE(MAX(position),0)+1 FROM lessons WHERE phase_id = :phase"
);
$insertBlock = $pdo->prepare(
    "INSERT INTO lesson_blocks
       (lesson_id,block_type,title,content,media_url,position,is_required)
     VALUES
       (:lesson,:type,:title,:content,NULL,:position,1)"
);
$insertQuestion = $pdo->prepare(
    "INSERT INTO questions
       (course_id,module_id,phase_id,lesson_id,question_type,statement,explanation,difficulty,source_label,active)
     VALUES
       (:course,:module,:phase,:lesson,'multiple_choice',:statement,:explanation,:difficulty,:source,1)"
);
$insertAlternative = $pdo->prepare(
    "INSERT INTO alternatives
       (question_id,label,text,is_correct,explanation,position)
     VALUES
       (:question,:label,:text,:correct,NULL,:position)"
);
$insertKey = $pdo->prepare(
    "INSERT INTO question_import_keys(source_key,question_id,source_name)
     VALUES (:source_key,:question_id,:source_name)"
);
$createdLessons = 0;
$createdQuestions = 0;
$createdKeys = 0;
$pdo->beginTransaction();
try {
    foreach ($topicLessons as $topic=>$lesson) {
        if ($existingLessons[$topic] !== null) {
            continue;
        }
        $nextLessonPosition->execute(['phase'=>$phases[$topic]]);
        $position = (int)$nextLessonPosition->fetchColumn();
        $insertLesson->execute([
            'phase'=>$phases[$topic],
            'title'=>(string)$lesson['title'],
            'slug'=>(string)$lesson['slug'],
            'summary'=>'Complemento opcional para fixação literal do CPM (UFMT).',
            'position'=>$position,
            'minutes'=>(int)$lesson['estimated_minutes']
        ]);
        $lessonId = (int)$pdo->lastInsertId();
        $existingLessons[$topic]=$lessonId;
        foreach ($lesson['blocks'] as $index=>$block) {
            $insertBlock->execute([
                'lesson'=>$lessonId,
                'type'=>(string)$block['type'],
                'title'=>(string)$block['title'],
                'content'=>(string)$block['content'],
                'position'=>$index+1,
            ]);
        }
        ++$createdLessons;
    }
    foreach ($questions as $question) {
        $key=(string)$question['source_key'];
        $previous=$knownQuestions[$key];
        if ($previous['mode']==='key') {
            continue;
        }
        $questionId=(int)$previous['id'];
        if ($previous['mode']==='new') {
            $insertQuestion->execute([
                'course'=>$courseId,
                'module'=>$moduleId,
                'phase'=>$phases[$question['topic']],
                'lesson'=>$existingLessons[$question['topic']],
                'statement'=>(string)$question['statement'],
                'explanation'=>(string)$question['explanation'],
                'difficulty'=>(string)$question['difficulty'],
                'source'=>(string)$questionBank['source_label'],
            ]);
            $questionId=(int)$pdo->lastInsertId();
            foreach ($question['alternatives'] as $index=>$alternative) {
                $insertAlternative->execute([
                    'question'=>$questionId,
                    'label'=>(string)$alternative['label'],
                    'text'=>(string)$alternative['text'],
                    'correct'=>!empty($alternative['is_correct']) ? 1 : 0,
                    'position'=>$index+1,
                ]);
            }
            ++$createdQuestions;
        } else {
            // Reuso permitido somente com a mesma aula de destino.
            $linked = $pdo->prepare("SELECT lesson_id FROM questions WHERE id = :id");
            $linked->execute(['id'=>$questionId]);
            if ((int)$linked->fetchColumn() !== (int)$existingLessons[$question['topic']]) {
                throw new RuntimeException("Aula diferente na duplicata: {$key}");
            }
        }
        $insertKey->execute([
            'source_key'=>$key,
            'question_id'=>$questionId,
            'source_name'=>'CPM UFMT - Literalidade V1',
        ]);
        ++$createdKeys;
    }
    // Liberar APENAS as novas aulas opcionais para alunos PMMT que já
    // alcançaram a fase. Inserção nova e idempotente: sem reabrir/alterar
    // progresso anteriormente concluído, quizzes ou checkpoints.
    $unlockOptional = $pdo->prepare(
        "INSERT IGNORE INTO user_lesson_progress
            (user_id, lesson_id, status, current_block, blocks_viewed, progress_pct)
         SELECT e.user_id, :lesson, 'available', 1, 0, 0
         FROM enrollments e
         INNER JOIN user_phase_progress upp
           ON upp.user_id = e.user_id
          AND upp.phase_id = :phase
          AND upp.status IN ('available','in_progress','completed')
         WHERE e.course_id = :course
           AND e.status IN ('active','completed')"
    );
    $newUnlocks = 0;
    foreach ($topicLessons as $topic => $lesson) {
        $unlockOptional->execute([
            'lesson' => $existingLessons[$topic],
            'phase' => $phases[$topic],
            'course' => $courseId,
        ]);
        $newUnlocks += $unlockOptional->rowCount();
    }

    $pdo->commit();
    printf("[OK] Acessos opcionais liberados: %d (sem alterar progresso existente).\n", $newUnlocks);
    printf("[OK] Importação: aulas criadas=%d, questões criadas=%d, chaves novas=%d.\n",
        $createdLessons,$createdQuestions,$createdKeys);
    echo "[OK] Aulas antigas, gabaritos, quizzes, checkpoints e progresso dos alunos não foram atualizados.\n";
    echo "[PRÓXIMO] Execute: php database/aplicar_cpm_ufmt_literalidade_v1.php --audit\n";
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR,"[FALHA] Transação revertida: {$error->getMessage()}\n");
    exit(7);
}
