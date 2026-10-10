<?php

declare(strict_types=1);

/**
 * POP 1 - Conteúdo organizado V1
 *
 * Reorganiza a fase já existente do POP Módulo I sem apagar progresso:
 * - preserva o ID da fase existente;
 * - preserva o ID da primeira aula (visão geral), quando existente;
 * - atualiza somente os blocos da aula de visão geral;
 * - cria/atualiza 8 aulas opcionais, uma para cada processo 101 a 108;
 * - não remove outras aulas já existentes na fase;
 * - usa exclusivamente database/sources/pop1_conteudo_organizado.json.
 *
 * Segurança:
 *   php database/aplicar_pop1_conteudo_v1.php
 *   php database/aplicar_pop1_conteudo_v1.php --apply
 *   php database/aplicar_pop1_conteudo_v1.php --apply --force-production
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só pode ser executado via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';
$jsonFile = __DIR__ . '/sources/pop1_conteudo_organizado.json';

if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
    exit(1);
}
if (!is_file($jsonFile)) {
    fwrite(STDERR, "Erro: database/sources/pop1_conteudo_organizado.json não encontrado.\n");
    exit(1);
}

require $autoload;
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
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
    if (!$course) {
        throw new RuntimeException("Curso publicado não encontrado com o slug esperado: {$slug}");
    }
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
    if (!$module) {
        throw new RuntimeException('Módulo de Procedimentos Operacionais Padrão não encontrado.');
    }
    return $module;
}

function findPop1Phase(PDO $pdo, int $moduleId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT * FROM phases
         WHERE module_id=:module_id
           AND slug='1-pop-modulo-i-niveis-do-uso-da-forca-e-equipamentos'
         LIMIT 1"
    );
    $stmt->execute(['module_id' => $moduleId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
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
    throw new RuntimeException('JSON de conteúdo POP1 incompatível: format_version esperado = 1.');
}
$processes = $data['processes'] ?? [];
if (!is_array($processes) || count($processes) !== 8) {
    throw new RuntimeException('JSON de conteúdo POP1 inválido: esperado total de 8 processos (101 a 108).');
}

$codes = array_map(static fn(array $p): string => (string)($p['code'] ?? ''), $processes);
if ($codes !== ['101','102','103','104','105','106','107','108']) {
    throw new RuntimeException('JSON de conteúdo POP1 inválido: sequência de processos deve ser 101 a 108.');
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
$phase = findPop1Phase($pdo, (int) $module['id']);
$intro = $data['intro'] ?? [];
$phaseMeta = $data['phase'] ?? [];

$existingProcessLessons = 0;
if ($phase) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM lessons
         WHERE phase_id=:phase_id AND slug LIKE 'pop1-processo-%-atualizado'"
    );
    $stmt->execute(['phase_id' => (int) $phase['id']]);
    $existingProcessLessons = (int) $stmt->fetchColumn();
}

printf("=============================================================\n");
printf(" POP 1 - CONTEÚDO ORGANIZADO V1\n");
printf("=============================================================\n");
printf("Banco:   %s\n", (string) envv('DB_DATABASE', 'curso'));
printf("Curso:   #%d - %s\n", (int) $course['id'], $course['title']);
printf("Módulo:  #%d - %s\n", (int) $module['id'], $module['title']);
printf("Fase:    %s\n", $phase ? '#'.(int)$phase['id'].' - '.$phase['title'] : 'não encontrada; será criada como fase opcional');
printf("Fonte:   %s (%d páginas)\n", (string)($data['source']['file'] ?? 'POP-1---.pdf'), (int)($data['source']['pdf_pages'] ?? 0));
printf("Processos organizados: %d\n", count($processes));
printf("Aulas de processo já existentes: %d\n", $existingProcessLessons);
printf("Modo:    %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

foreach ($processes as $process) {
    printf("  - %s | %s | procedimentos=%d | blocos=%d\n",
        (string)$process['code'],
        (string)$process['title'],
        count($process['procedures'] ?? []),
        count($process['blocks'] ?? [])
    );
}

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, aplique com: php database/aplicar_pop1_conteudo_v1.php --apply" . ($appEnv === 'production' ? " --force-production" : '') . "\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    if (!$phase) {
        $maxPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position),0) FROM phases WHERE module_id=:module_id');
        $maxPosStmt->execute(['module_id' => (int) $module['id']]);
        $position = (int) $maxPosStmt->fetchColumn() + 1;

        $insertPhase = $pdo->prepare(
            "INSERT INTO phases
               (module_id,title,slug,description,phase_type,is_required,position,required_score,required_lessons_pct,max_attempts,xp_reward,status)
             VALUES
               (:module_id,:title,:slug,:description,'normal',0,:position,70,0,NULL,0,'published')"
        );
        $insertPhase->execute([
            'module_id' => (int) $module['id'],
            'title' => (string)($phaseMeta['title'] ?? 'POP 1 - Módulo I: Níveis do Uso da Força Policial (atualizado)'),
            'slug' => '1-pop-modulo-i-niveis-do-uso-da-forca-e-equipamentos',
            'description' => (string)($phaseMeta['description'] ?? 'POP 1 atualizado organizado por processos 101 a 108.'),
            'position' => $position,
        ]);
        $phaseId = (int) $pdo->lastInsertId();
        $phase = ['id' => $phaseId, 'title' => $phaseMeta['title'] ?? 'POP 1'];
    } else {
        $phaseId = (int) $phase['id'];
        $updatePhase = $pdo->prepare(
            "UPDATE phases
             SET title=:title,
                 description=:description,
                 status='published'
             WHERE id=:id"
        );
        $updatePhase->execute([
            'title' => (string)($phaseMeta['title'] ?? 'POP 1 - Módulo I: Níveis do Uso da Força Policial (atualizado)'),
            'description' => (string)($phaseMeta['description'] ?? 'POP 1 atualizado organizado por processos 101 a 108.'),
            'id' => $phaseId,
        ]);
    }

    // Reutiliza a primeira aula para não quebrar user_lesson_progress já existente.
    $overviewStmt = $pdo->prepare(
        "SELECT * FROM lessons WHERE phase_id=:phase_id ORDER BY position,id LIMIT 1"
    );
    $overviewStmt->execute(['phase_id' => $phaseId]);
    $overview = $overviewStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($overview) {
        $overviewId = (int) $overview['id'];
        $pdo->prepare(
            "UPDATE lessons
             SET title=:title, summary=:summary, is_required=1,
                 estimated_minutes=12, status='published'
             WHERE id=:id"
        )->execute([
            'title' => 'POP 1 - Visão geral e mapa dos Processos 101 a 108',
            'summary' => 'Visão geral atualizada do Módulo I e mapa de estudo dos oito processos. Fonte: POP-1---.pdf.',
            'id' => $overviewId,
        ]);
    } else {
        $insertOverview = $pdo->prepare(
            "INSERT INTO lessons
               (phase_id,title,slug,summary,is_required,position,estimated_minutes,xp_reward,status)
             VALUES
               (:phase_id,:title,'pop1-visao-geral-atualizada',:summary,1,1,12,10,'published')"
        );
        $insertOverview->execute([
            'phase_id' => $phaseId,
            'title' => 'POP 1 - Visão geral e mapa dos Processos 101 a 108',
            'summary' => 'Visão geral atualizada do Módulo I e mapa de estudo dos oito processos. Fonte: POP-1---.pdf.',
        ]);
        $overviewId = (int) $pdo->lastInsertId();
    }

    $pdo->prepare('DELETE FROM lesson_blocks WHERE lesson_id=:lesson_id')->execute(['lesson_id' => $overviewId]);
    $insertBlock = $pdo->prepare(
        "INSERT INTO lesson_blocks
           (lesson_id,block_type,title,content,media_url,media_id,position,is_required)
         VALUES
           (:lesson_id,:block_type,:title,:content,NULL,NULL,:position,:is_required)"
    );

    $introContent = trim((string)($intro['content'] ?? ''));
    if ($introContent !== '') {
        $insertBlock->execute([
            'lesson_id' => $overviewId,
            'block_type' => 'theory',
            'title' => (string)($intro['title'] ?? 'Comentários iniciais do POP 1'),
            'content' => $introContent,
            'position' => 1,
            'is_required' => 1,
        ]);
    }

    $mapLines = [
        'POP 1 - Módulo I: Níveis do Uso da Força Policial',
        '',
        'Organização para estudo:',
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
    $mapLines[] = 'As oito aulas detalhadas abaixo são opcionais para não alterar a progressão já conquistada; servem como biblioteca organizada de revisão.';

    $insertBlock->execute([
        'lesson_id' => $overviewId,
        'block_type' => 'comparison',
        'title' => 'Mapa completo do POP 1',
        'content' => implode("\n", $mapLines),
        'position' => 2,
        'is_required' => 1,
    ]);

    $findLesson = $pdo->prepare('SELECT id,position FROM lessons WHERE phase_id=:phase_id AND slug=:slug LIMIT 1');
    $insertLesson = $pdo->prepare(
        "INSERT INTO lessons
           (phase_id,title,slug,summary,is_required,position,estimated_minutes,xp_reward,status)
         VALUES
           (:phase_id,:title,:slug,:summary,0,:position,:estimated_minutes,0,'published')"
    );
    $updateLesson = $pdo->prepare(
        "UPDATE lessons SET title=:title,summary=:summary,is_required=0,
                estimated_minutes=:estimated_minutes,xp_reward=0,status='published'
         WHERE id=:id"
    );

    // Posiciona as aulas gerenciadas pelo pacote depois de tudo que já existe,
    // evitando colisões com índices únicos de posição e sem reordenar aulas legadas.
    $maxLessonPosStmt = $pdo->prepare('SELECT COALESCE(MAX(position),0) FROM lessons WHERE phase_id=:phase_id');
    $maxLessonPosStmt->execute(['phase_id' => $phaseId]);
    $nextPosition = (int) $maxLessonPosStmt->fetchColumn() + 1;

    $created = 0;
    $updated = 0;
    $blocksWritten = 2;

    foreach ($processes as $process) {
        $code = (string)$process['code'];
        $slug = 'pop1-processo-' . $code . '-atualizado';
        $title = 'POP 1 - Processo ' . $code . ': ' . (string)$process['title'];
        $sourcePages = $process['source_pages'] ?? [];
        $pageText = is_array($sourcePages) && count($sourcePages) >= 2
            ? sprintf('páginas %d a %d', (int)$sourcePages[0], (int)$sourcePages[1])
            : 'páginas indicadas no material';
        $summary = sprintf(
            'Estudo organizado do Processo %s e seus procedimentos (%s). Fonte atualizada: POP-1---.pdf, %s.',
            $code,
            implode(', ', array_map('strval', $process['procedures'] ?? [])),
            $pageText
        );

        $findLesson->execute(['phase_id' => $phaseId, 'slug' => $slug]);
        $existing = $findLesson->fetch(PDO::FETCH_ASSOC) ?: null;
        $estimated = max(5, min(60, 4 + count($process['blocks'] ?? []) * 3));

        if ($existing) {
            $lessonId = (int)$existing['id'];
            $updateLesson->execute([
                'title' => $title,
                'summary' => $summary,
                'estimated_minutes' => $estimated,
                'id' => $lessonId,
            ]);
            $updated++;
        } else {
            $insertLesson->execute([
                'phase_id' => $phaseId,
                'title' => $title,
                'slug' => $slug,
                'summary' => $summary,
                'position' => $nextPosition++,
                'estimated_minutes' => $estimated,
            ]);
            $lessonId = (int)$pdo->lastInsertId();
            $created++;
        }

        $pdo->prepare('DELETE FROM lesson_blocks WHERE lesson_id=:lesson_id')->execute(['lesson_id' => $lessonId]);

        $position = 1;
        foreach (($process['blocks'] ?? []) as $block) {
            if (!is_array($block)) {
                continue;
            }
            $content = trim((string)($block['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $insertBlock->execute([
                'lesson_id' => $lessonId,
                'block_type' => allowedBlockType((string)($block['type'] ?? 'theory')),
                'title' => trim((string)($block['title'] ?? '')) ?: null,
                'content' => $content,
                'position' => $position++,
                'is_required' => 0,
            ]);
            $blocksWritten++;
        }
    }

    $pdo->commit();

    printf("\n[OK] Fase POP1 atualizada: #%d\n", $phaseId);
    printf("[OK] Aula de visão geral preservada/atualizada: #%d\n", $overviewId);
    printf("[OK] Aulas de processo: criadas=%d | atualizadas=%d\n", $created, $updated);
    printf("[OK] Blocos do pacote gravados nesta execução: %d\n", $blocksWritten);
    echo "[OK] Nenhuma outra aula da fase foi excluída. Progresso existente não foi resetado.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(3);
}
