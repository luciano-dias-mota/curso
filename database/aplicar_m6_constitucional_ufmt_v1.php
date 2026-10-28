<?php

declare(strict_types=1);

/**
 * Módulo 6 - CF/88 e Constituição MT - complemento UFMT V1.
 * CLI dry-run por padrão. Nenhuma alteração sem --apply.
 *
 * php database/aplicar_m6_constitucional_ufmt_v1.php
 * php database/aplicar_m6_constitucional_ufmt_v1.php --apply
 * php database/aplicar_m6_constitucional_ufmt_v1.php --audit
 * php database/aplicar_m6_constitucional_ufmt_v1.php --apply --force-production
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas no CLI.\n");
}
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$audit = in_array('--audit', $argv ?? [], true);
$force = in_array('--force-production', $argv ?? [], true);
$production = strtolower((string)config('app.env', 'production')) === 'production';

if ($apply && $audit) {
    throw new RuntimeException('Use --audit OU --apply.');
}
if ($apply && $production && !$force) {
    fwrite(STDERR, "[BLOQUEADO] Produção exige backup, homologação e --force-production.\n");
    exit(1);
}

$read = static function (string $file): array {
    $path = BASE_PATH . '/database/sources/' . $file;
    if (!is_file($path)) {
        throw new RuntimeException("Fonte ausente: {$file}");
    }
    return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
};
$curriculum = $read('m6_cf_cemt_aulas_v1.json');
$bank = $read('m6_cf_cemt_questoes_v1.json');
$errata = $read('m6_cf_cemt_errata_juridica_v1.json');

$moduleSlug = 'modulo-6-direito-constitucional-e-legislacao-institucional-da-pmmt';
$counts = ['cf5'=>10, 'seguranca'=>10, 'legislativo'=>9, 'tce'=>10, 'instituicoes'=>8, 'justica'=>9];
$lessons = $curriculum['lessons'] ?? null;
$questions = $bank['questions'] ?? null;
$items = $errata['items'] ?? null;
if (($curriculum['format_version'] ?? null) !== 1
    || ($bank['format_version'] ?? null) !== 1
    || ($errata['format_version'] ?? null) !== 1
    || ($curriculum['module_slug'] ?? null) !== $moduleSlug
    || ($bank['module_slug'] ?? null) !== $moduleSlug
    || !is_array($lessons) || count($lessons) !== 6
    || !is_array($questions) || count($questions) !== 56
    || !is_array($items) || count($items) !== 8) {
    throw new RuntimeException('Fontes inválidas: esperados 6 reforços, 56 questões e 8 erratas.');
}
$lessonMap = [];
foreach ($lessons as $lesson) {
    $topic = (string)($lesson['topic'] ?? '');
    if (!isset($counts[$topic]) || isset($lessonMap[$topic])
        || !is_array($lesson['blocks'] ?? null)
        || count($lesson['blocks']) !== 6
        || !str_starts_with((string)($lesson['slug'] ?? ''), 'reforco-m6-ufmt-')
        || trim((string)($lesson['title'] ?? '')) === ''
        || trim((string)($lesson['phase_slug'] ?? '')) === '') {
        throw new RuntimeException("Aula inválida {$topic}");
    }
    foreach ($lesson['blocks'] as $block) {
        if (!in_array($block['type'] ?? '', ['theory','comparison','case_study','memory','warning'], true)
            || trim((string)($block['title'] ?? '')) === ''
            || trim((string)($block['content'] ?? '')) === '') {
            throw new RuntimeException("Bloco inválido da aula {$topic}");
        }
    }
    $lessonMap[$topic] = $lesson;
}
$totals = array_fill_keys(array_keys($counts), 0);
$sourceKeys = [];
foreach ($questions as $q) {
    $topic = (string)($q['topic'] ?? '');
    $key = (string)($q['source_key'] ?? '');
    $alts = $q['alternatives'] ?? [];
    if (!isset($lessonMap[$topic]) || $key === '' || strlen($key) > 120 || isset($sourceKeys[$key])
        || !is_array($alts) || count($alts) !== 5
        || ($q['difficulty'] ?? '') !== 'medium'
        || trim((string)($q['statement'] ?? '')) === ''
        || trim((string)($q['explanation'] ?? '')) === '') {
        throw new RuntimeException("Questão inválida {$key}");
    }
    $correct = 0;
    foreach ($alts as $i => $a) {
        if (($a['label'] ?? '') !== chr(65+$i) || trim((string)($a['text'] ?? '')) === '') {
            throw new RuntimeException("Alternativa inválida {$key}");
        }
        $correct += !empty($a['is_correct']) ? 1 : 0;
    }
    if ($correct !== 1) {
        throw new RuntimeException("Questão sem gabarito único: {$key}");
    }
    $sourceKeys[$key] = true;
    ++$totals[$topic];
}
if ($totals !== $counts) {
    throw new RuntimeException('Distribuição de questões inesperada: ' . json_encode($totals));
}

$pdo = Database::connection();
$columnExists = static function (string $table, string $column) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c"
    );
    $stmt->execute(['t'=>$table,'c'=>$column]);
    return (int)$stmt->fetchColumn() > 0;
};
$tableExists = static function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t"
    );
    $stmt->execute(['t'=>$table]);
    return (int)$stmt->fetchColumn() > 0;
};
foreach (['courses','modules','phases','lessons','lesson_blocks','questions',
          'alternatives','question_import_keys','enrollments',
          'user_phase_progress','user_lesson_progress'] as $table) {
    if (!$tableExists($table)) {
        throw new RuntimeException("Tabela ausente {$table}");
    }
}
if (!$columnExists('lessons','is_required')
    || !$columnExists('lesson_blocks','is_required')) {
    throw new RuntimeException('Ausência de is_required nas aulas/blocos. Aplique primeiro migração de progressão.');
}

$moduleStmt = $pdo->prepare(
    "SELECT m.id, m.course_id, c.title AS course_title
     FROM modules m JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug AND m.status='published' AND c.status='published'"
);
$moduleStmt->execute(['slug'=>$moduleSlug]);
$matches = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);
if ($columnExists('courses','experience_key')) {
    $expStmt = $pdo->prepare("SELECT experience_key FROM courses WHERE id=:id");
    $matches = array_values(array_filter($matches,static function(array $row) use($expStmt):bool {
        $expStmt->execute(['id'=>(int)$row['course_id']]);
        return (string)$expStmt->fetchColumn() === 'pmmt';
    }));
} else {
    $matches = array_values(array_filter($matches,static fn(array $row):bool =>
        stripos((string)$row['course_title'],'PMMT') !== false
    ));
}
if (count($matches) !== 1) {
    throw new RuntimeException('Módulo 6 publicado do PMMT não encontrado univocamente.');
}
$moduleId=(int)$matches[0]['id'];
$courseId=(int)$matches[0]['course_id'];

$phaseStmt=$pdo->prepare(
    "SELECT id FROM phases WHERE module_id=:module AND slug=:slug AND status='published'"
);
$lessonStmt=$pdo->prepare(
    "SELECT id,status,is_required FROM lessons WHERE phase_id=:phase AND slug=:slug"
);
$blockStmt=$pdo->prepare(
    "SELECT block_type,title,content,is_required FROM lesson_blocks WHERE lesson_id=:lesson ORDER BY position,id"
);
$phaseIds=[];
$lessonIds=[];
$pendingLessons=0;
foreach ($lessonMap as $topic=>$lesson) {
    $phaseStmt->execute(['module'=>$moduleId,'slug'=>$lesson['phase_slug']]);
    $ids=$phaseStmt->fetchAll(PDO::FETCH_COLUMN);
    if (count($ids) !== 1) {
        throw new RuntimeException("Fase PMMT indisponível ou ambígua: {$lesson['phase_slug']}");
    }
    $phaseId=(int)$ids[0];
    $phaseIds[$topic]=$phaseId;
    $lessonStmt->execute(['phase'=>$phaseId,'slug'=>$lesson['slug']]);
    $records=$lessonStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($records) > 1) {
        throw new RuntimeException("Aula duplicada {$lesson['slug']}");
    }
    if (!$records) {
        $lessonIds[$topic]=null;
        ++$pendingLessons;
        continue;
    }
    $actual=$records[0];
    $lessonIds[$topic]=(int)$actual['id'];
    if ($actual['status'] !== 'published' || (int)$actual['is_required'] !== 0) {
        throw new RuntimeException("Aula já existente com requisitos divergentes: {$lesson['slug']}");
    }
    $blockStmt->execute(['lesson'=>$lessonIds[$topic]]);
    $saved=$blockStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($saved) !== count($lesson['blocks'])) {
        throw new RuntimeException("Blocos divergentes de {$lesson['slug']}");
    }
    foreach ($lesson['blocks'] as $i=>$block) {
        if ((string)$saved[$i]['block_type'] !== $block['type']
            || (string)$saved[$i]['title'] !== $block['title']
            || (string)$saved[$i]['content'] !== $block['content']
            || (int)$saved[$i]['is_required'] !== 1) {
            throw new RuntimeException("Bloco já existente divergente: {$lesson['slug']}");
        }
    }
}

$keyStmt=$pdo->prepare(
    "SELECT q.id,q.course_id,q.module_id,q.phase_id,q.lesson_id,q.statement,
            q.explanation,q.difficulty,q.active
     FROM question_import_keys k JOIN questions q ON q.id=k.question_id
     WHERE k.source_key=:source_key"
);
$dupStmt=$pdo->prepare(
    "SELECT id FROM questions WHERE course_id=:course AND module_id=:module AND statement=:statement"
);
$altStmt=$pdo->prepare(
    "SELECT label,text,is_correct FROM alternatives WHERE question_id=:question ORDER BY position,id"
);
$pendingQuestions=0;
foreach ($questions as $q) {
    $keyStmt->execute(['source_key'=>$q['source_key']]);
    $matches=$keyStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($matches)>1) {
        throw new RuntimeException("Chave duplicada {$q['source_key']}");
    }
    if (!$matches) {
        $dupStmt->execute([
            'course'=>$courseId,'module'=>$moduleId,'statement'=>$q['statement']
        ]);
        if ($dupStmt->fetchColumn()) {
            throw new RuntimeException("Questão sem chave de importação já existente {$q['source_key']}");
        }
        ++$pendingQuestions;
        continue;
    }
    $saved=$matches[0];
    if ((int)$saved['course_id']!==$courseId || (int)$saved['module_id']!==$moduleId
        || (int)$saved['phase_id']!==$phaseIds[$q['topic']]
        || (string)$saved['statement']!==$q['statement']
        || (string)$saved['explanation']!==$q['explanation']
        || (string)$saved['difficulty']!=='medium'
        || (int)$saved['active']!==1
        || ($lessonIds[$q['topic']]!==null
            && (int)$saved['lesson_id']!==$lessonIds[$q['topic']])) {
        throw new RuntimeException("Questão existente divergente {$q['source_key']}");
    }
    $altStmt->execute(['question'=>(int)$saved['id']]);
    $alts=$altStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($alts)!==5) {
        throw new RuntimeException("Alternativas divergentes {$q['source_key']}");
    }
    foreach ($q['alternatives'] as $i=>$a) {
        if ((string)$alts[$i]['label']!==$a['label']
            || (string)$alts[$i]['text']!==$a['text']
            || (int)$alts[$i]['is_correct']!==(int)!empty($a['is_correct'])) {
            throw new RuntimeException("Gabarito existente divergente {$q['source_key']}");
        }
    }
}

echo "========================================================\n";
echo " MODULO 6 / CF + CONSTITUICAO MT - UFMT V1\n";
echo "========================================================\n";
printf("Banco:        %s\n",(string)$pdo->query("SELECT DATABASE()")->fetchColumn());
printf("Curso:        #%d\nMódulo:       #%d\n",$courseId,$moduleId);
printf("Modo:         %s\n",$audit?'AUDITORIA':($apply?'APLICAÇÃO':'DRY-RUN'));
printf("Errata:       %d notas jurídicas revisadas\n",count($items));
printf("Aulas:        %d | novas %d | blocos 36\n",count($lessons),$pendingLessons);
printf("Questões:     %d | novas %d | existentes %d\n",
    count($questions),$pendingQuestions,count($questions)-$pendingQuestions);
printf("Distribuição: %s\n",json_encode($totals,JSON_UNESCAPED_UNICODE));

if ($audit) {
    if ($pendingLessons || $pendingQuestions) {
        fwrite(STDERR,"[PENDENTE] Conteúdo ainda não importado integralmente.\n");
        exit(6);
    }
    echo "[OK] 6 aulas opcionais, 36 blocos, 56 questões validadas no banco.\n";
    exit(0);
}
if (!$apply) {
    echo "[DRY-RUN] Nenhum registro alterado. Exija backup e homologação antes de --apply";
    if ($production) { echo " --force-production"; }
    echo ".\n";
    exit(0);
}

$posStmt=$pdo->prepare("SELECT COALESCE(MAX(position),0)+1 FROM lessons WHERE phase_id=:phase");
$insertLesson=$pdo->prepare(
    "INSERT INTO lessons(phase_id,title,slug,summary,position,estimated_minutes,xp_reward,status,is_required)
     VALUES(:phase,:title,:slug,:summary,:pos,:minutes,0,'published',0)"
);
$insertBlock=$pdo->prepare(
    "INSERT INTO lesson_blocks(lesson_id,block_type,title,content,media_url,position,is_required)
     VALUES(:lesson,:type,:title,:content,NULL,:pos,1)"
);
$insertQuestion=$pdo->prepare(
    "INSERT INTO questions(course_id,module_id,phase_id,lesson_id,question_type,
      statement,explanation,difficulty,source_label,active)
     VALUES(:course,:module,:phase,:lesson,'multiple_choice',:statement,:explanation,
      'medium',:source,1)"
);
$insertAlt=$pdo->prepare(
    "INSERT INTO alternatives(question_id,label,text,is_correct,explanation,position)
     VALUES(:question,:label,:text,:correct,NULL,:pos)"
);
$insertKey=$pdo->prepare(
    "INSERT INTO question_import_keys(source_key,question_id,source_name)
     VALUES(:key,:question,'M6 UFMT Constituição CF/CE-MT V1')"
);
$unlock=$pdo->prepare(
    "INSERT IGNORE INTO user_lesson_progress(user_id,lesson_id,status,current_block,blocks_viewed,progress_pct)
     SELECT e.user_id,:lesson,'available',1,0,0
     FROM enrollments e JOIN user_phase_progress p
       ON p.user_id=e.user_id AND p.phase_id=:phase
      AND p.status IN ('available','in_progress','completed')
     WHERE e.course_id=:course AND e.status IN ('active','completed')"
);
$createdLessons=0;
$createdQuestions=0;
$unlocked=0;
$pdo->beginTransaction();
try {
    foreach ($lessonMap as $topic=>$lesson) {
        if ($lessonIds[$topic]!==null) { continue; }
        $posStmt->execute(['phase'=>$phaseIds[$topic]]);
        $insertLesson->execute([
            'phase'=>$phaseIds[$topic],'title'=>$lesson['title'],'slug'=>$lesson['slug'],
            'summary'=>'Reforço opcional de literalidade da Constituição Federal e de MT.',
            'pos'=>(int)$posStmt->fetchColumn(),'minutes'=>(int)$lesson['estimated_minutes']
        ]);
        $id=(int)$pdo->lastInsertId();
        $lessonIds[$topic]=$id;
        foreach ($lesson['blocks'] as $i=>$block) {
            $insertBlock->execute([
                'lesson'=>$id,'type'=>$block['type'],'title'=>$block['title'],
                'content'=>$block['content'],'pos'=>$i+1
            ]);
        }
        ++$createdLessons;
    }
    foreach ($questions as $q) {
        $keyStmt->execute(['source_key'=>$q['source_key']]);
        if ($keyStmt->fetch(PDO::FETCH_ASSOC)) { continue; }
        $insertQuestion->execute([
            'course'=>$courseId,'module'=>$moduleId,'phase'=>$phaseIds[$q['topic']],
            'lesson'=>$lessonIds[$q['topic']],'statement'=>$q['statement'],
            'explanation'=>$q['explanation'],'source'=>$bank['source_label']
        ]);
        $id=(int)$pdo->lastInsertId();
        foreach ($q['alternatives'] as $i=>$a) {
            $insertAlt->execute([
                'question'=>$id,'label'=>$a['label'],'text'=>$a['text'],
                'correct'=>!empty($a['is_correct'])?1:0,'pos'=>$i+1
            ]);
        }
        $insertKey->execute(['key'=>$q['source_key'],'question'=>$id]);
        ++$createdQuestions;
    }
    foreach ($lessonMap as $topic=>$lesson) {
        $unlock->execute([
            'lesson'=>$lessonIds[$topic],'phase'=>$phaseIds[$topic],'course'=>$courseId
        ]);
        $unlocked += $unlock->rowCount();
    }
    $pdo->commit();
    printf("[OK] Aulas novas: %d; questões novas: %d; liberações novas: %d.\n",
        $createdLessons,$createdQuestions,$unlocked);
    echo "[PRÓXIMO] php database/aplicar_m6_constitucional_ufmt_v1.php --audit\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    fwrite(STDERR,"[ERRO] Alterações revertidas: {$e->getMessage()}\n");
    exit(7);
}
