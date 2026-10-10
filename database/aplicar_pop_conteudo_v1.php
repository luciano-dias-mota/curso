<?php

declare(strict_types=1);

/**
 * POP 2 - Conteúdo organizado V1
 *
 * Cria/atualiza uma fase OPCIONAL do Módulo 7 para o POP 2:
 * - 1 aula de visão geral;
 * - 11 aulas de processo (201 a 211);
 * - preserva fases/aulas já existentes;
 * - não reseta progresso;
 * - libera a biblioteca POP2 somente para estudantes que já podem acessar o módulo POP.
 *
 * Segurança:
 *   php database/aplicar_pop2_conteudo_v1.php
 *   php database/aplicar_pop2_conteudo_v1.php --apply
 *   php database/aplicar_pop2_conteudo_v1.php --apply --force-production
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só pode ser executado via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';
$jsonFile = __DIR__ . '/sources/pop2_conteudo_organizado.json';

if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
    exit(1);
}
if (!is_file($jsonFile)) {
    fwrite(STDERR, "Erro: database/sources/pop2_conteudo_organizado.json não encontrado.\n");
    exit(1);
}

require $autoload;
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) return $default;
    return is_string($value) ? trim($value, "\"'") : $value;
}

function hasFlag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv ?? [], true);
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
    );
    $stmt->execute(['table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function resolveCourse(PDO $pdo): array
{
    $slug = (string) envv('SIMULATION_COURSE_SLUG', 'preparacao-pmmt-merito-intelectual-e-caoc');
    $stmt = $pdo->prepare(
        "SELECT id,title,slug FROM courses
         WHERE slug=:slug AND status='published' LIMIT 1"
    );
    $stmt->execute(['slug' => $slug]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) throw new RuntimeException("Curso publicado não encontrado: {$slug}");
    return $course;
}

function resolvePopModule(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        "SELECT id,title,slug FROM modules
         WHERE course_id=:course_id
           AND status='published'
           AND (slug LIKE '%procedimentos-operacionais-padrao%' OR title LIKE '%Procedimentos Operacionais%')
         ORDER BY position,id LIMIT 1"
    );
    $stmt->execute(['course_id' => $courseId]);
    $module = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$module) throw new RuntimeException('Módulo de Procedimentos Operacionais Padrão não encontrado.');
    return $module;
}

function allowedBlockType(string $type): string
{
    $allowed = ['theory','example','warning','summary','comparison','memory','case_study','image','video','question'];
    return in_array($type, $allowed, true) ? $type : 'theory';
}

$apply = hasFlag('--apply');
$forceProduction = hasFlag('--force-production');
$appEnv = strtolower((string) envv('APP_ENV', 'local'));

if ($apply && $appEnv === 'production' && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: APP_ENV=production. Use --apply --force-production somente após backup.\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
if (($data['format_version'] ?? null) !== 1) {
    throw new RuntimeException('JSON POP2 incompatível: format_version esperado = 1.');
}

$processes = $data['processes'] ?? [];
$expectedCodes = ['201','202','203','204','205','206','207','208','209','210','211'];
$codes = is_array($processes)
    ? array_map(static fn(array $p): string => (string)($p['code'] ?? ''), $processes)
    : [];

if ($codes !== $expectedCodes) {
    throw new RuntimeException('JSON POP2 inválido: esperado Processos 201 a 211 em sequência.');
}

$procedureCount = 0;
foreach ($processes as $process) {
    $procedureCount += count($process['procedures'] ?? []);
}
if ($procedureCount !== 30) {
    throw new RuntimeException("JSON POP2 inválido: esperado total de 30 procedimentos; encontrados {$procedureCount}.");
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        (int) envv('DB_PORT', 3306),
        envv('DB_DATABASE', 'curso')
    ),
    (string) envv('DB_USERNAME', 'root'),
    (string) envv('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

foreach (['courses','modules','phases','lessons','lesson_blocks'] as $required) {
    if (!tableExists($pdo, $required)) {
        throw new RuntimeException("Tabela obrigatória ausente: {$required}");
    }
}

$course = resolveCourse($pdo);
$module = resolvePopModule($pdo, (int) $course['id']);
$phaseMeta = $data['phase'] ?? [];
$phaseSlug = (string)($phaseMeta['slug'] ?? 'pop-2-modulo-ii-abordagens-policiais-atualizado');

$phaseStmt = $pdo->prepare(
    "SELECT * FROM phases
     WHERE module_id=:module_id AND slug=:slug
     LIMIT 1"
);
$phaseStmt->execute(['module_id'=>(int)$module['id'], 'slug'=>$phaseSlug]);
$phase = $phaseStmt->fetch(PDO::FETCH_ASSOC) ?: null;

printf("=============================================================\n");
printf(" POP 2 - CONTEÚDO ORGANIZADO V1\n");
printf("=============================================================\n");
printf("Banco:        %s\n", (string) envv('DB_DATABASE','curso'));
printf("Curso:        #%d - %s\n", (int)$course['id'], $course['title']);
printf("Módulo POP:   #%d - %s\n", (int)$module['id'], $module['title']);
printf("Fase POP2:    %s\n", $phase ? '#'.(int)$phase['id'].' - '.$phase['title'] : 'não existe; será criada');
printf("Fonte:        %s (%d páginas)\n", (string)($data['source']['file'] ?? 'pop-2--.pdf'), (int)($data['source']['pdf_pages'] ?? 0));
printf("Processos:    %d\n", count($processes));
printf("Procedimentos:%d\n", $procedureCount);
printf("Modo:         %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

foreach ($processes as $process) {
    printf("  - %s | procedimentos=%d | blocos=%d | páginas %d-%d\n",
        (string)$process['code'],
        count($process['procedures'] ?? []),
        count($process['blocks'] ?? []),
        (int)($process['source_pages'][0] ?? 0),
        (int)($process['source_pages'][1] ?? 0)
    );
}

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, aplique com: php database/aplicar_pop2_conteudo_v1.php --apply" .
         ($appEnv === 'production' ? " --force-production" : '') . "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    if (!$phase) {
        $maxStmt = $pdo->prepare('SELECT COALESCE(MAX(position),0) FROM phases WHERE module_id=:module_id');
        $maxStmt->execute(['module_id'=>(int)$module['id']]);
        $position = (int)$maxStmt->fetchColumn() + 1;

        $insertPhase = $pdo->prepare(
            "INSERT INTO phases
               (module_id,title,slug,description,phase_type,is_required,position,required_score,required_lessons_pct,max_attempts,xp_reward,status)
             VALUES
               (:module_id,:title,:slug,:description,'normal',0,:position,70,0,NULL,0,'published')"
        );
        $insertPhase->execute([
            'module_id'=>(int)$module['id'],
            'title'=>(string)($phaseMeta['title'] ?? 'POP 2 - Módulo II: Abordagens Policiais (atualizado)'),
            'slug'=>$phaseSlug,
            'description'=>(string)($phaseMeta['description'] ?? 'POP 2 atualizado organizado por Processos 201 a 211.'),
            'position'=>$position,
        ]);
        $phaseId = (int)$pdo->lastInsertId();
        $phaseCreated = true;
    } else {
        $phaseId = (int)$phase['id'];
        $phaseCreated = false;
        $pdo->prepare(
            "UPDATE phases
             SET title=:title, description=:description, phase_type='normal',
                 is_required=0, xp_reward=0, status='published'
             WHERE id=:id"
        )->execute([
            'title'=>(string)($phaseMeta['title'] ?? 'POP 2 - Módulo II: Abordagens Policiais (atualizado)'),
            'description'=>(string)($phaseMeta['description'] ?? 'POP 2 atualizado organizado por Processos 201 a 211.'),
            'id'=>$phaseId,
        ]);
    }

    $insertBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
           (lesson_id,block_type,title,content,media_url,media_id,position,is_required)
         VALUES
           (:lesson_id,:block_type,:title,:content,NULL,NULL,:position,0)"
    );

    $findLesson = $pdo->prepare(
        "SELECT id,position FROM lessons
         WHERE phase_id=:phase_id AND slug=:slug LIMIT 1"
    );
    $insertLesson = $pdo->prepare(
        "INSERT INTO lessons
           (phase_id,title,slug,summary,is_required,position,estimated_minutes,xp_reward,status)
         VALUES
           (:phase_id,:title,:slug,:summary,0,:position,:estimated_minutes,0,'published')"
    );
    $updateLesson = $pdo->prepare(
        "UPDATE lessons
         SET title=:title, summary=:summary, is_required=0,
             estimated_minutes=:estimated_minutes, xp_reward=0, status='published'
         WHERE id=:id"
    );

    $managedLessonIds = [];
    $created = 0;
    $updated = 0;
    $blocksWritten = 0;

    // Próxima posição livre: evita colisão caso a fase tenha sido criada/manipulada anteriormente.
    $maxLessonPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position),0) FROM lessons WHERE phase_id=:phase_id');
    $maxLessonPosStmt->execute(['phase_id'=>$phaseId]);
    $nextPosition = (int)$maxLessonPosStmt->fetchColumn() + 1;

    // Aula de visão geral
    $overviewSlug = 'pop2-visao-geral-atualizada';
    $findLesson->execute(['phase_id'=>$phaseId,'slug'=>$overviewSlug]);
    $overview = $findLesson->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($overview) {
        $overviewId = (int)$overview['id'];
        $updateLesson->execute([
            'title'=>'POP 2 - Visão geral e mapa dos Processos 201 a 211',
            'summary'=>'Mapa do Módulo II - Abordagens Policiais: 11 processos e 30 procedimentos formais.',
            'estimated_minutes'=>8,
            'id'=>$overviewId,
        ]);
        $updated++;
    } else {
        $insertLesson->execute([
            'phase_id'=>$phaseId,
            'title'=>'POP 2 - Visão geral e mapa dos Processos 201 a 211',
            'slug'=>$overviewSlug,
            'summary'=>'Mapa do Módulo II - Abordagens Policiais: 11 processos e 30 procedimentos formais.',
            'position'=>$nextPosition++,
            'estimated_minutes'=>8,
        ]);
        $overviewId = (int)$pdo->lastInsertId();
        $created++;
    }
    $managedLessonIds[] = $overviewId;
    $pdo->prepare('DELETE FROM lesson_blocks WHERE lesson_id=:lesson_id')->execute(['lesson_id'=>$overviewId]);

    $intro = $data['intro'] ?? [];
    $introText = trim((string)($intro['content'] ?? ''));
    if ($introText !== '') {
        $insertBlock->execute([
            'lesson_id'=>$overviewId,
            'block_type'=>'theory',
            'title'=>(string)($intro['title'] ?? 'POP 2 - Visão geral'),
            'content'=>$introText,
            'position'=>1,
        ]);
        $blocksWritten++;
    }

    $mapLines = [
        'POP 2 - Módulo II: Abordagens Policiais',
        '',
        'Mapa de estudo:',
    ];
    foreach ($processes as $process) {
        $mapLines[] = sprintf(
            '%s - %s | Procedimentos: %s',
            (string)$process['code'],
            (string)$process['title'],
            implode(', ', array_map('strval', $process['procedures'] ?? []))
        );
    }
    $mapLines[] = '';
    $mapLines[] = 'Esta fase é opcional e serve como biblioteca de estudo organizada; não participa do bloqueio da progressão obrigatória.';

    $insertBlock->execute([
        'lesson_id'=>$overviewId,
        'block_type'=>'comparison',
        'title'=>'Mapa completo do POP 2',
        'content'=>implode("\n",$mapLines),
        'position'=>2,
    ]);
    $blocksWritten++;

    foreach ($processes as $process) {
        $code = (string)$process['code'];
        $slug = 'pop2-processo-'.$code.'-atualizado';
        $title = 'POP 2 - Processo '.$code.': '.(string)$process['title'];
        $pageRange = $process['source_pages'] ?? [0,0];
        $summary = sprintf(
            'Estudo integral organizado do Processo %s e seus procedimentos (%s). Fonte: pop-2--.pdf, páginas %d a %d.',
            $code,
            implode(', ', array_map('strval',$process['procedures'] ?? [])),
            (int)($pageRange[0] ?? 0),
            (int)($pageRange[1] ?? 0)
        );
        $estimated = max(8, min(60, 5 + count($process['blocks'] ?? []) * 4));

        $findLesson->execute(['phase_id'=>$phaseId,'slug'=>$slug]);
        $existing = $findLesson->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($existing) {
            $lessonId = (int)$existing['id'];
            $updateLesson->execute([
                'title'=>$title,
                'summary'=>$summary,
                'estimated_minutes'=>$estimated,
                'id'=>$lessonId,
            ]);
            $updated++;
        } else {
            $insertLesson->execute([
                'phase_id'=>$phaseId,
                'title'=>$title,
                'slug'=>$slug,
                'summary'=>$summary,
                'position'=>$nextPosition++,
                'estimated_minutes'=>$estimated,
            ]);
            $lessonId = (int)$pdo->lastInsertId();
            $created++;
        }
        $managedLessonIds[] = $lessonId;

        $pdo->prepare('DELETE FROM lesson_blocks WHERE lesson_id=:lesson_id')->execute(['lesson_id'=>$lessonId]);
        $blockPos = 1;
        foreach (($process['blocks'] ?? []) as $block) {
            if (!is_array($block)) continue;
            $text = trim((string)($block['content'] ?? ''));
            if ($text === '') continue;
            $insertBlock->execute([
                'lesson_id'=>$lessonId,
                'block_type'=>allowedBlockType((string)($block['type'] ?? 'theory')),
                'title'=>trim((string)($block['title'] ?? '')) ?: null,
                'content'=>$text,
                'position'=>$blockPos++,
            ]);
            $blocksWritten++;
        }
    }

    // Disponibiliza a fase e as aulas opcionais apenas para quem já tem acesso ao módulo POP.
    if (tableExists($pdo, 'user_module_progress') &&
        tableExists($pdo, 'user_phase_progress') &&
        tableExists($pdo, 'user_lesson_progress')) {

        $openPhase = $pdo->prepare(
            "INSERT INTO user_phase_progress
                (user_id,phase_id,status,best_score,attempts_count,progress_pct,unlocked_at)
             SELECT ump.user_id,:phase_id,'available',0,0,0,NOW()
             FROM user_module_progress ump
             WHERE ump.module_id=:module_id
               AND ump.status IN ('available','in_progress','completed')
             ON DUPLICATE KEY UPDATE
                status=IF(status IN ('completed','in_progress'),status,'available'),
                unlocked_at=COALESCE(unlocked_at,NOW())"
        );
        $openPhase->execute(['phase_id'=>$phaseId,'module_id'=>(int)$module['id']]);

        $openLesson = $pdo->prepare(
            "INSERT INTO user_lesson_progress
                (user_id,lesson_id,status,current_block,blocks_viewed,progress_pct)
             SELECT ump.user_id,:lesson_id,'available',1,0,0
             FROM user_module_progress ump
             WHERE ump.module_id=:module_id
               AND ump.status IN ('available','in_progress','completed')
             ON DUPLICATE KEY UPDATE
                status=IF(status IN ('completed','in_progress'),status,'available')"
        );
        foreach ($managedLessonIds as $lessonId) {
            $openLesson->execute(['lesson_id'=>$lessonId,'module_id'=>(int)$module['id']]);
        }
    }

    $pdo->commit();

    printf("\n[OK] Fase POP2 %s: #%d\n", $phaseCreated ? 'criada' : 'atualizada', $phaseId);
    printf("[OK] Aulas gerenciadas: %d (visão geral + 11 processos)\n", count($managedLessonIds));
    printf("[OK] Aulas criadas=%d | atualizadas=%d | blocos gravados=%d\n", $created,$updated,$blocksWritten);
    echo "[OK] Nenhuma fase/aula preexistente foi apagada. Progresso obrigatório não foi resetado.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(3);
}
