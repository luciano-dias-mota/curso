<?php

declare(strict_types=1);

/**
 * Sindicância PMMT — conteúdo UFMT e correção das fontes (versão 1).
 *
 * php database/aplicar_sindicancia_ufmt_v1.php
 * php database/aplicar_sindicancia_ufmt_v1.php --apply
 * php database/aplicar_sindicancia_ufmt_v1.php --audit
 * php database/aplicar_sindicancia_ufmt_v1.php --apply --force-production
 *
 * Seguro: CLI somente, dry-run por padrão, bloqueio em produção, transação,
 * scope restrito ao curso PMMT e fase de sindicância, sem reescrever quizzes.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Somente CLI.\n");
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);
$args = $argv ?? [];
$apply = in_array('--apply', $args, true);
$audit = in_array('--audit', $args, true);
$forceProduction = in_array('--force-production', $args, true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';
if ($apply && $audit) {
    throw new RuntimeException('--apply e --audit são mutuamente exclusivos.');
}
if ($apply && $isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: produção exige backup e --apply --force-production, após dry-run.\n");
    exit(1);
}

$load = static function (string $file): array {
    $path = BASE_PATH . '/database/sources/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException("Fonte ausente: {$path}");
    }
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (($data['format_version'] ?? null) !== 1) {
        throw new RuntimeException("Fonte incompatível: {$file}");
    }
    return $data;
};
$curriculum = $load('sindicancia_ufmt_aulas_v1.json');
$bank = $load('sindicancia_ufmt_questoes_v1.json');
$corrections = $load('sindicancia_ufmt_correcoes_v1.json');
$lessons = $curriculum['lessons'] ?? [];
$questions = $bank['questions'] ?? [];
$patches = $corrections['patches'] ?? [];
$expectedTopics = ['fundamentos','defesa','oitivas','demissoria','prazos'];

if (!is_array($lessons) || !is_array($questions) || !is_array($patches)
    || count($lessons) !== 5 || count($questions) !== 25 || count($patches) !== 8) {
    throw new RuntimeException('Fonte inválida: esperadas 5 aulas, 25 questões e 8 correções.');
}
$lessonByTopic = [];
$blockCount = 0;
foreach ($lessons as $lesson) {
    $topic = (string) ($lesson['topic'] ?? '');
    if (!in_array($topic, $expectedTopics, true) || isset($lessonByTopic[$topic])
        || ($lesson['phase_slug'] ?? '') !== '2-sindicancia-pmmt'
        || !is_array($lesson['blocks'] ?? null) || count($lesson['blocks']) < 4
        || trim((string) ($lesson['slug'] ?? '')) === '') {
        throw new RuntimeException("Aula inválida: {$topic}");
    }
    foreach ($lesson['blocks'] as $block) {
        if (!in_array($block['type'] ?? '', ['theory','comparison','case_study','warning','memory'], true)
            || trim((string) ($block['title'] ?? '')) === ''
            || trim((string) ($block['content'] ?? '')) === '') {
            throw new RuntimeException("Bloco inválido: {$topic}");
        }
        ++$blockCount;
    }
    $lessonByTopic[$topic] = $lesson;
}
if (count($lessonByTopic) !== 5 || $blockCount !== 28) {
    throw new RuntimeException('Distribuição de aulas e blocos incompatível.');
}
$keys = [];
$topicCounts = array_fill_keys($expectedTopics, 0);
foreach ($questions as $question) {
    $key = (string) ($question['source_key'] ?? '');
    $topic = (string) ($question['topic'] ?? '');
    $alternatives = $question['alternatives'] ?? [];
    if ($key === '' || strlen($key) > 120 || isset($keys[$key])
        || !isset($lessonByTopic[$topic])
        || !is_array($alternatives) || count($alternatives) !== 5
        || !in_array($question['difficulty'] ?? '', ['medium','hard'], true)
        || trim((string)($question['statement'] ?? '')) === ''
        || trim((string)($question['explanation'] ?? '')) === '') {
        throw new RuntimeException("Questão inválida: {$key}");
    }
    $correct = 0;
    foreach ($alternatives as $i => $alt) {
        if (($alt['label'] ?? '') !== chr(65 + $i)
            || trim((string)($alt['text'] ?? '')) === '') {
            throw new RuntimeException("Alternativa inválida em: {$key}");
        }
        $correct += !empty($alt['is_correct']) ? 1 : 0;
    }
    if ($correct !== 1) {
        throw new RuntimeException("Gabarito inválido: {$key}");
    }
    $keys[$key] = true;
    ++$topicCounts[$topic];
}
foreach ($topicCounts as $topic => $count) {
    if ($count !== 5) {
        throw new RuntimeException("Esperadas 5 questões em {$topic}, recebidas {$count}");
    }
}

$pdo = Database::connection();
$has = static function (string $table, ?string $column = null) use ($pdo): bool {
    $sql = $column === null
        ? "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t"
        : "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c";
    $stmt = $pdo->prepare($sql);
    $values = ['t'=>$table];
    if ($column !== null) { $values['c'] = $column; }
    $stmt->execute($values);
    return (int)$stmt->fetchColumn() > 0;
};
foreach (['courses','modules','phases','lessons','lesson_blocks','questions','alternatives',
          'question_import_keys','enrollments','user_phase_progress','user_lesson_progress'] as $table) {
    if (!$has($table)) {
        throw new RuntimeException("Tabela obrigatória ausente: {$table}");
    }
}
if (!$has('lessons','is_required') || !$has('lesson_blocks','is_required')) {
    throw new RuntimeException('É necessário aplicar primeiro a migração lessons/lesson_blocks.is_required.');
}

$moduleStmt = $pdo->prepare(
    "SELECT m.id, m.course_id, c.title AS course_title
     FROM modules m
     INNER JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug AND m.status='published' AND c.status='published'"
);
$moduleStmt->execute(['slug'=>(string)$curriculum['module_slug']]);
$found = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);
if ($has('courses','experience_key')) {
    $experience = $pdo->prepare("SELECT experience_key FROM courses WHERE id=:id");
    $found = array_values(array_filter($found, static function (array $row) use ($experience): bool {
        $experience->execute(['id'=>(int)$row['course_id']]);
        return (string)$experience->fetchColumn() === 'pmmt';
    }));
} else {
    $found = array_values(array_filter($found,
        static fn(array $row): bool => stripos((string)$row['course_title'],'PMMT') !== false
    ));
}
if (count($found) !== 1) {
    throw new RuntimeException('Curso PMMT/Módulo 4 não identificado univocamente; nenhum dado alterado.');
}
$moduleId = (int)$found[0]['id'];
$courseId = (int)$found[0]['course_id'];

$phaseStmt = $pdo->prepare(
    "SELECT id FROM phases WHERE module_id=:module AND slug='2-sindicancia-pmmt' AND status='published'"
);
$phaseStmt->execute(['module'=>$moduleId]);
$phaseId = (int)($phaseStmt->fetchColumn() ?: 0);
if ($phaseId < 1) {
    throw new RuntimeException('Fase publicada 2-sindicancia-pmmt ausente.');
}

$lessonStmt = $pdo->prepare(
    "SELECT id,status,is_required FROM lessons WHERE phase_id=:phase AND slug=:slug"
);
$blocksStmt = $pdo->prepare(
    "SELECT block_type,title,content,is_required FROM lesson_blocks WHERE lesson_id=:id ORDER BY position,id"
);
$lessonIds = [];
$pendingLessons = 0;
foreach ($lessonByTopic as $topic => $lesson) {
    $lessonStmt->execute(['phase'=>$phaseId,'slug'=>$lesson['slug']]);
    $saved = $lessonStmt->fetch(PDO::FETCH_ASSOC);
    if (!$saved) {
        $lessonIds[$topic] = null;
        ++$pendingLessons;
        continue;
    }
    $lessonIds[$topic] = (int)$saved['id'];
    $blocksStmt->execute(['id'=>(int)$saved['id']]);
    $blocks = $blocksStmt->fetchAll(PDO::FETCH_ASSOC);
    if ($saved['status'] !== 'published' || (int)$saved['is_required'] !== 0
        || count($blocks) !== count($lesson['blocks'])) {
        throw new RuntimeException("Aula existente incompatível: {$lesson['slug']}");
    }
    foreach ($lesson['blocks'] as $i => $block) {
        $actual = $blocks[$i];
        if ((string)$actual['block_type'] !== $block['type']
            || (string)$actual['title'] !== $block['title']
            || (string)$actual['content'] !== $block['content']
            || (int)$actual['is_required'] !== 1) {
            throw new RuntimeException("Bloco existente divergente em {$lesson['slug']}");
        }
    }
}

$keyStmt = $pdo->prepare(
    "SELECT q.id,q.course_id,q.module_id,q.phase_id,q.lesson_id,q.statement,q.explanation,q.difficulty,q.active
     FROM question_import_keys k JOIN questions q ON q.id=k.question_id
     WHERE k.source_key=:key"
);
$duplicateStmt = $pdo->prepare(
    "SELECT id FROM questions
     WHERE course_id=:course AND module_id=:module AND statement=:statement"
);
$altsStmt = $pdo->prepare(
    "SELECT label,text,is_correct FROM alternatives WHERE question_id=:id ORDER BY position,id"
);
$existingKeys = 0;
foreach ($questions as $q) {
    $keyStmt->execute(['key'=>$q['source_key']]);
    $saved = $keyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$saved) {
        $duplicateStmt->execute([
            'course'=>$courseId,'module'=>$moduleId,'statement'=>$q['statement']
        ]);
        if ($duplicateStmt->fetchColumn()) {
            throw new RuntimeException("Há questão igual sem source_key: {$q['source_key']}. Revisar antes de importar.");
        }
        continue;
    }
    ++$existingKeys;
    if ((int)$saved['course_id'] !== $courseId || (int)$saved['module_id'] !== $moduleId
        || (int)$saved['phase_id'] !== $phaseId
        || $saved['statement'] !== $q['statement'] || $saved['explanation'] !== $q['explanation']
        || $saved['difficulty'] !== $q['difficulty'] || (int)$saved['active'] !== 1
        || ($lessonIds[$q['topic']] !== null && (int)$saved['lesson_id'] !== $lessonIds[$q['topic']])) {
        throw new RuntimeException("Questão com chave existente divergente: {$q['source_key']}");
    }
    $altsStmt->execute(['id'=>(int)$saved['id']]);
    $actual = $altsStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($actual) !== 5) {
        throw new RuntimeException("Alternativas de questão existente incorretas: {$q['source_key']}");
    }
    foreach ($q['alternatives'] as $i => $alt) {
        if ((string)$actual[$i]['label'] !== (string)$alt['label']
            || (string)$actual[$i]['text'] !== (string)$alt['text']
            || (int)$actual[$i]['is_correct'] !== (!empty($alt['is_correct']) ? 1 : 0)) {
            throw new RuntimeException("Gabarito de questão existente divergente: {$q['source_key']}");
        }
    }
}

$patchStmt = $pdo->prepare(
    "SELECT id,statement,explanation FROM questions
     WHERE module_id=:module AND course_id=:course
       AND (statement=:old_statement OR statement=:new_statement)"
);
$correctAltStmt = $pdo->prepare(
    "SELECT id,text FROM alternatives WHERE question_id=:id AND is_correct=1"
);
$readyPatches = [];
$missingPatches = [];
foreach ($patches as $patch) {
    $idx = (int)($patch['index'] ?? -1);
    $oldStatement = (string)($patch['old_statement'] ?? '');
    $newStatement = (string)($patch['new_statement'] ?? '');
    if ($oldStatement === '' || $newStatement === '' || !in_array($idx,[13,14,15,16,17,31,32,37],true)) {
        throw new RuntimeException("Correção não permitida para índice {$idx}");
    }
    $patchStmt->execute([
        'module'=>$moduleId,'course'=>$courseId,
        'old_statement'=>$oldStatement,'new_statement'=>$newStatement
    ]);
    $rows = $patchStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($rows) > 1) {
        throw new RuntimeException("Múltiplas questões correspondentes à correção #{$idx}");
    }
    if (!$rows) {
        $missingPatches[] = $idx; // A fonte foi corrigida; o item pode nunca ter sido importado.
        continue;
    }
    $record = $rows[0];
    $isOld = (string)$record['statement'] === $oldStatement;
    $expectedExplanation = (string)$patch[$isOld?'old_explanation':'new_explanation'];
    $expectedCorrect = (string)$patch[$isOld?'old_correct':'new_correct'];
    $correctAltStmt->execute(['id'=>(int)$record['id']]);
    $correctRows = $correctAltStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($correctRows) !== 1 || $record['explanation'] !== $expectedExplanation
        || (string)$correctRows[0]['text'] !== $expectedCorrect) {
        throw new RuntimeException("Questão legada #{$idx} diverge: não será sobrescrita.");
    }
    $readyPatches[] = [
        'index'=>$idx,
        'question_id'=>(int)$record['id'],
        'alternative_id'=>(int)$correctRows[0]['id'],
        'needs_update'=>$isOld,
        'data'=>$patch
    ];
}
$pendingCorrections = count(array_filter($readyPatches,static fn($p)=>$p['needs_update']));

echo "========================================================\n";
echo " SINDICÂNCIA PMMT — LITERALIDADE E REVISÃO DA FONTE\n";
echo "========================================================\n";
printf("Banco:         %s\n",(string)$pdo->query("SELECT DATABASE()")->fetchColumn());
printf("Curso:         #%d\nMódulo:        #%d\nFase:          #%d\n",$courseId,$moduleId,$phaseId);
printf("Modo:          %s\n",$audit?'AUDITORIA':($apply?'APLICAÇÃO':'DRY-RUN'));
printf("Aulas/blocos:  5/%d | aulas novas: %d\n",$blockCount,$pendingLessons);
printf("Questões:      25 | já importadas: %d | novas: %d\n",$existingKeys,25-$existingKeys);
printf("Correções:     %d pendentes | %d já corrigidas | %d não encontradas\n",
    $pendingCorrections,count($readyPatches)-$pendingCorrections,count($missingPatches));
if ($missingPatches !== []) {
    echo "[INFO] Questões legadas ausentes no banco (fonte JSON já corrigida): "
        .implode(',',$missingPatches)."\n";
}

if ($audit) {
    if ($pendingLessons > 0 || $existingKeys !== 25 || $pendingCorrections > 0) {
        fwrite(STDERR,"[PENDENTE] Não está totalmente aplicado.\n");
        exit(6);
    }
    echo "[OK] Auditoria de aulas, blocos, questões e correções presentes concluída.\n";
    exit(0);
}
if (!$apply) {
    echo "[DRY-RUN] Banco não modificado. Após backup e homologação, --apply";
    if ($isProduction) { echo " --force-production"; }
    echo ".\n";
    exit(0);
}

$nextPos = $pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM lessons WHERE phase_id=:phase");
$insertLesson = $pdo->prepare(
    "INSERT INTO lessons
      (phase_id,title,slug,summary,position,estimated_minutes,xp_reward,status,is_required)
     VALUES (:phase,:title,:slug,'Reforço opcional de literalidade do Manual de Sindicância.',:pos,:minutes,0,'published',0)"
);
$insertBlock = $pdo->prepare(
    "INSERT INTO lesson_blocks
      (lesson_id,block_type,title,content,media_url,position,is_required)
     VALUES (:lesson,:type,:title,:content,NULL,:pos,1)"
);
$insertQ = $pdo->prepare(
    "INSERT INTO questions
      (course_id,module_id,phase_id,lesson_id,question_type,statement,explanation,difficulty,source_label,active)
     VALUES (:course,:module,:phase,:lesson,'multiple_choice',:statement,:explanation,:difficulty,:source,1)"
);
$insertAlt = $pdo->prepare(
    "INSERT INTO alternatives
      (question_id,label,text,is_correct,explanation,position)
     VALUES (:question,:label,:text,:correct,NULL,:pos)"
);
$insertKey = $pdo->prepare(
    "INSERT INTO question_import_keys
      (source_key,question_id,source_name)
     VALUES (:key,:question,'Manual de Sindicância PMMT — UFMT V1')"
);
$updateLegacy = $pdo->prepare(
    "UPDATE questions SET statement=:statement,explanation=:explanation,updated_at=NOW()
     WHERE id=:id AND statement=:old"
);
$updateCorrect = $pdo->prepare(
    "UPDATE alternatives SET text=:text,updated_at=NOW() WHERE id=:id AND is_correct=1"
);
$unlock = $pdo->prepare(
    "INSERT IGNORE INTO user_lesson_progress
     (user_id,lesson_id,status,current_block,blocks_viewed,progress_pct)
     SELECT e.user_id,:lesson,'available',1,0,0
     FROM enrollments e
     INNER JOIN user_phase_progress upp
       ON upp.user_id=e.user_id AND upp.phase_id=:phase
      AND upp.status IN ('available','in_progress','completed')
     WHERE e.course_id=:course AND e.status='active'"
);
$createdLessons = 0;
$createdQuestions = 0;
$updatedQuestions = 0;
$unlocks = 0;
$pdo->beginTransaction();
try {
    foreach ($lessonByTopic as $topic=>$lesson) {
        if ($lessonIds[$topic] !== null) { continue; }
        $nextPos->execute(['phase'=>$phaseId]);
        $insertLesson->execute([
            'phase'=>$phaseId,'title'=>$lesson['title'],'slug'=>$lesson['slug'],
            'pos'=>(int)$nextPos->fetchColumn(),'minutes'=>(int)$lesson['estimated_minutes']
        ]);
        $lessonId = (int)$pdo->lastInsertId();
        $lessonIds[$topic] = $lessonId;
        foreach ($lesson['blocks'] as $i=>$block) {
            $insertBlock->execute([
                'lesson'=>$lessonId,'type'=>$block['type'],'title'=>$block['title'],
                'content'=>$block['content'],'pos'=>$i+1
            ]);
        }
        ++$createdLessons;
    }
    foreach ($questions as $q) {
        $keyStmt->execute(['key'=>$q['source_key']]);
        if ($keyStmt->fetch(PDO::FETCH_ASSOC)) { continue; }
        $insertQ->execute([
            'course'=>$courseId,'module'=>$moduleId,'phase'=>$phaseId,
            'lesson'=>$lessonIds[$q['topic']],
            'statement'=>$q['statement'],'explanation'=>$q['explanation'],
            'difficulty'=>$q['difficulty'],'source'=>$bank['source_label']
        ]);
        $id = (int)$pdo->lastInsertId();
        foreach ($q['alternatives'] as $i=>$alt) {
            $insertAlt->execute([
                'question'=>$id,'label'=>$alt['label'],'text'=>$alt['text'],
                'correct'=>!empty($alt['is_correct'])?1:0,'pos'=>$i+1
            ]);
        }
        $insertKey->execute(['key'=>$q['source_key'],'question'=>$id]);
        ++$createdQuestions;
    }
    foreach ($readyPatches as $patch) {
        if (!$patch['needs_update']) { continue; }
        $data = $patch['data'];
        $updateLegacy->execute([
            'id'=>$patch['question_id'],
            'old'=>$data['old_statement'],
            'statement'=>$data['new_statement'],
            'explanation'=>$data['new_explanation']
        ]);
        if ($updateLegacy->rowCount() !== 1) {
            throw new RuntimeException("Conflito concorrente na correção #{$patch['index']}");
        }
        if ($data['old_correct'] !== $data['new_correct']) {
            $updateCorrect->execute([
                'id'=>$patch['alternative_id'],
                'text'=>$data['new_correct']
            ]);
            if ($updateCorrect->rowCount() !== 1) {
                throw new RuntimeException("Falha na correção da alternativa #{$patch['index']}");
            }
        }
        ++$updatedQuestions;
    }
    foreach ($lessonByTopic as $topic=>$lesson) {
        $unlock->execute(['lesson'=>$lessonIds[$topic],'phase'=>$phaseId,'course'=>$courseId]);
        $unlocks += $unlock->rowCount();
    }
    $pdo->commit();
    printf("[OK] Aulas novas=%d; questões novas=%d; questões legadas corrigidas=%d; acessos novos=%d.\n",
        $createdLessons,$createdQuestions,$updatedQuestions,$unlocks);
    echo "[INFO] Questões antigas não encontradas não foram criadas ou reescritas.\n";
    echo "[PRÓXIMO] php database/aplicar_sindicancia_ufmt_v1.php --audit\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR,"[FALHA] Transação revertida: {$e->getMessage()}\n");
    exit(7);
}
