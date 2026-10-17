<?php

declare(strict_types=1);

/**
 * Garante um piso de 4 questões válidas por PROCESSO dos POPs 1, 2, 3 e 4.
 *
 * O script é incremental e idempotente:
 * - não apaga nem altera questões antigas;
 * - conta apenas questões válidas para o gerador de exercícios;
 * - cria somente a quantidade que falta para cada processo chegar a 4;
 * - deriva as novas questões exclusivamente dos JSONs de conteúdo POP já revisados;
 * - vincula a questão ao processo/procedimento e aos seus ancestrais;
 * - reutiliza chaves próprias em reexecuções.
 *
 * Uso:
 *   php database/importar_pop_minimo4_v1.php
 *   php database/importar_pop_minimo4_v1.php --apply
 *   php database/importar_pop_minimo4_v1.php --apply --force-production
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só pode ser executado via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
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
    return (int)$stmt->fetchColumn() > 0;
}

function resolveCourse(PDO $pdo): array
{
    $slug = (string)envv('SIMULATION_COURSE_SLUG', 'preparacao-pmmt-merito-intelectual-e-caoc');
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

function firstAdminId(PDO $pdo): ?int
{
    $id = $pdo->query(
        "SELECT u.id FROM users u
         INNER JOIN roles r ON r.id=u.role_id
         WHERE r.slug='admin' AND u.status='active'
         ORDER BY u.id LIMIT 1"
    )->fetchColumn();
    return $id ? (int)$id : null;
}

function topicId(PDO $pdo, string $code): int
{
    $stmt = $pdo->prepare(
        "SELECT id FROM study_topics
         WHERE domain='pop' AND code=:code AND active=1 LIMIT 1"
    );
    $stmt->execute(['code' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("Tópico POP não encontrado: {$code}");
    }
    return (int)$id;
}

function ancestorTopicIds(PDO $pdo, int $topicId): array
{
    $ids = [];
    $seen = [];
    $stmt = $pdo->prepare('SELECT id,parent_id FROM study_topics WHERE id=:id LIMIT 1');
    $current = $topicId;

    while ($current > 0 && !isset($seen[$current])) {
        $seen[$current] = true;
        $stmt->execute(['id' => $current]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) break;
        $ids[] = (int)$row['id'];
        $current = (int)($row['parent_id'] ?? 0);
    }

    return $ids;
}

function validQuestionCountForTopic(PDO $pdo, int $topicId): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT q.id)
         FROM question_topics qt
         INNER JOIN questions q ON q.id=qt.question_id
         INNER JOIN (
            SELECT question_id,
                   COUNT(*) AS alternatives_count,
                   SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS correct_count
            FROM alternatives
            GROUP BY question_id
         ) quality ON quality.question_id=q.id
         WHERE qt.topic_id=:topic_id
           AND q.active=1
           AND q.question_type IN ('multiple_choice','true_false')
           AND q.difficulty IN ('medium','hard')
           AND ((q.question_type='multiple_choice' AND quality.alternatives_count>=4)
                OR (q.question_type='true_false' AND quality.alternatives_count>=2))
           AND quality.correct_count=1"
    );
    $stmt->execute(['topic_id' => $topicId]);
    return (int)$stmt->fetchColumn();
}

function phaseIdForPop(PDO $pdo, int $moduleId, int $pop): ?int
{
    $slugs = [
        1 => '1-pop-modulo-i-niveis-do-uso-da-forca-e-equipamentos',
        2 => 'pop-2-modulo-ii-abordagens-policiais-atualizado',
        3 => 'pop-3-modulo-iii-procedimentos-diversos-atualizado',
        4 => 'pop-4-modulo-iv-ocorrencias-policiais-atualizado',
    ];

    $slug = $slugs[$pop] ?? null;
    if ($slug === null) return null;

    $stmt = $pdo->prepare(
        "SELECT id FROM phases
         WHERE module_id=:module_id AND slug=:slug AND status='published'
         LIMIT 1"
    );
    $stmt->execute(['module_id' => $moduleId, 'slug' => $slug]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int)$id;
}

function cleanText(string $text): string
{
    $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
    return trim($text, " \t\n\r\0\x0B-–—;:");
}

function procedureTitle(array $process, string $procedureCode): string
{
    foreach (($process['blocks'] ?? []) as $block) {
        if (!is_array($block)) continue;
        $title = cleanText((string)($block['title'] ?? ''));
        if ($title !== '' && str_starts_with($title, $procedureCode)) {
            return $title;
        }
    }
    return $procedureCode;
}

function firstOperationalItem(array $process): ?string
{
    $headers = [
        'RESULTADOS ESPERADOS',
        'ATIVIDADES CRÍTICAS',
        'SEQUÊNCIA DE AÇÕES',
        'SEQUÊNCIA DAS AÇÕES',
        'AÇÕES CORRETIVAS',
    ];

    foreach (($process['blocks'] ?? []) as $block) {
        if (!is_array($block)) continue;
        $content = str_replace("\r", '', (string)($block['content'] ?? ''));
        if ($content === '') continue;
        $lines = array_map('trim', explode("\n", $content));

        foreach ($headers as $header) {
            foreach ($lines as $i => $line) {
                $upper = mb_strtoupper($line, 'UTF-8');
                if (!str_starts_with($upper, $header)) continue;

                for ($j = $i + 1, $n = count($lines); $j < $n && $j <= $i + 8; $j++) {
                    $candidate = cleanText($lines[$j]);
                    if ($candidate === '') continue;

                    $candidate = preg_replace('/^\d+[\.\)]\s*/u', '', $candidate) ?? $candidate;
                    $candidate = preg_replace('/^[a-z][\.\)]\s*/ui', '', $candidate) ?? $candidate;
                    $candidate = cleanText($candidate);

                    if (mb_strlen($candidate, 'UTF-8') >= 28 && mb_strlen($candidate, 'UTF-8') <= 220) {
                        return $candidate;
                    }
                }
            }
        }
    }

    return null;
}

function fourAlternatives(string $correct, array $pool): array
{
    $correct = cleanText($correct);
    $unique = [];

    foreach ($pool as $item) {
        $item = cleanText((string)$item);
        if ($item === '' || $item === $correct) continue;
        $unique[$item] = true;
    }

    $wrong = array_slice(array_keys($unique), 0, 3);
    if (count($wrong) < 3) {
        throw new RuntimeException('Não foi possível montar três distratores distintos.');
    }

    $items = [
        ['text' => $correct, 'correct' => true],
        ['text' => $wrong[0], 'correct' => false],
        ['text' => $wrong[1], 'correct' => false],
        ['text' => $wrong[2], 'correct' => false],
    ];

    // Embaralhamento determinístico: evita gabarito sempre em A sem depender de aleatoriedade.
    return [$items[1], $items[0], $items[3], $items[2]];
}

function buildCandidates(int $pop, array $process, array $allProcesses, string $sourceFile): array
{
    $code = (string)($process['code'] ?? '');
    $title = cleanText((string)($process['title'] ?? ''));
    $procedures = array_values(array_map('strval', $process['procedures'] ?? []));
    if ($code === '' || $title === '' || $procedures === []) {
        throw new RuntimeException("Processo POP{$pop} incompleto no JSON: {$code}");
    }

    $otherTitles = [];
    $otherProcedureLabels = [];
    $otherOperational = [];

    foreach ($allProcesses as $other) {
        if (!is_array($other) || (string)($other['code'] ?? '') === $code) continue;
        $otherTitle = cleanText((string)($other['title'] ?? ''));
        if ($otherTitle !== '') $otherTitles[] = $otherTitle;

        $otherProcedures = array_values(array_map('strval', $other['procedures'] ?? []));
        if ($otherProcedures !== []) {
            $pc = $otherProcedures[0];
            $otherProcedureLabels[] = procedureTitle($other, $pc);
        }

        $op = firstOperationalItem($other);
        if ($op !== null) $otherOperational[] = $op;
    }

    $primaryProcedure = $procedures[0];
    $primaryProcedureTitle = procedureTitle($process, $primaryProcedure);
    $operational = firstOperationalItem($process);

    $sourceLabel = "Complemento mínimo 4 questões por processo | POP {$pop} | {$sourceFile}";
    $pages = $process['source_pages'] ?? [];
    $pageText = is_array($pages) && count($pages) >= 2
        ? 'páginas '.(int)$pages[0].' a '.(int)$pages[1]
        : 'páginas indicadas no material';

    $candidates = [];

    if ($operational !== null && count(array_unique($otherOperational)) >= 3) {
        $candidates[] = [
            'source_key' => "POP-MIN4-{$code}-Q01",
            'difficulty' => 'hard',
            'primary_topic' => $primaryProcedure,
            'statement' => "Segundo o material oficial organizado do Processo {$code} - {$title}, qual alternativa reproduz um item expressamente previsto no conteúdo operacional desse processo?",
            'explanation' => "O item correto consta expressamente no Processo {$code} do {$sourceFile}, {$pageText}.",
            'source_label' => $sourceLabel,
            'alternatives' => fourAlternatives($operational, $otherOperational),
        ];
    }

    $candidates[] = [
        'source_key' => "POP-MIN4-{$code}-Q02",
        'difficulty' => 'medium',
        'primary_topic' => $primaryProcedure,
        'statement' => "Qual dos procedimentos abaixo integra formalmente o Processo {$code} - {$title}?",
        'explanation' => "O procedimento {$primaryProcedureTitle} integra o Processo {$code}, conforme a estrutura do {$sourceFile}.",
        'source_label' => $sourceLabel,
        'alternatives' => fourAlternatives($primaryProcedureTitle, $otherProcedureLabels),
    ];

    $candidates[] = [
        'source_key' => "POP-MIN4-{$code}-Q03",
        'difficulty' => 'medium',
        'primary_topic' => $code,
        'statement' => "No POP {$pop}, qual é a denominação correta do Processo {$code}?",
        'explanation' => "No {$sourceFile}, o Processo {$code} é denominado {$title}.",
        'source_label' => $sourceLabel,
        'alternatives' => fourAlternatives($title, $otherTitles),
    ];

    $count = count($procedures);
    $numberPool = [];
    foreach ([$count - 2, $count - 1, $count + 1, $count + 2, $count + 3, $count + 4] as $n) {
        if ($n > 0 && $n !== $count) $numberPool[] = "{$n} procedimento(s)";
    }
    $candidates[] = [
        'source_key' => "POP-MIN4-{$code}-Q04",
        'difficulty' => 'medium',
        'primary_topic' => $code,
        'statement' => "Quantos procedimentos formais estão mapeados no Processo {$code} - {$title} no conteúdo organizado do POP {$pop}?",
        'explanation' => "O Processo {$code} possui {$count} procedimento(s) formal(is) no {$sourceFile}.",
        'source_label' => $sourceLabel,
        'alternatives' => fourAlternatives("{$count} procedimento(s)", $numberPool),
    ];

    return $candidates;
}

$apply = hasFlag('--apply');
$forceProduction = hasFlag('--force-production');
$appEnv = strtolower((string)envv('APP_ENV', 'local'));

if ($apply && $appEnv === 'production' && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: APP_ENV=production. Use --apply --force-production somente após backup.\n");
    exit(2);
}

$sources = [
    1 => __DIR__ . '/sources/pop1_conteudo_organizado.json',
    2 => __DIR__ . '/sources/pop2_conteudo_organizado.json',
    3 => __DIR__ . '/sources/pop3_conteudo_organizado.json',
    4 => __DIR__ . '/sources/pop4_conteudo_organizado.json',
];

$expectedCodes = [
    1 => ['101','102','103','104','105','106','107','108'],
    2 => ['201','202','203','204','205','206','207','208','209','210','211'],
    3 => ['301','302','303','304','305','306','307'],
    4 => ['401','402','403','404','405','406','407','408','409','410','411','412','413','414'],
];

$dataByPop = [];
foreach ($sources as $pop => $file) {
    if (!is_file($file)) {
        throw new RuntimeException("Fonte ausente: {$file}");
    }
    $data = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $processes = $data['processes'] ?? [];
    if (!is_array($processes)) {
        throw new RuntimeException("JSON POP{$pop} sem processos válidos.");
    }
    $codes = array_map(static fn(array $p): string => (string)($p['code'] ?? ''), $processes);
    if ($codes !== $expectedCodes[$pop]) {
        throw new RuntimeException("Sequência de processos inesperada no POP{$pop}.");
    }
    $dataByPop[$pop] = $data;
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        (int)envv('DB_PORT', 3306),
        envv('DB_DATABASE', 'curso')
    ),
    (string)envv('DB_USERNAME', 'root'),
    (string)envv('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

foreach (['courses','modules','phases','study_topics','question_topics','question_import_keys','questions','alternatives'] as $required) {
    if (!tableExists($pdo, $required)) {
        throw new RuntimeException("Tabela obrigatória ausente: {$required}");
    }
}

$course = resolveCourse($pdo);
$module = resolvePopModule($pdo, (int)$course['id']);
$adminId = firstAdminId($pdo);

$findKey = $pdo->prepare('SELECT question_id FROM question_import_keys WHERE source_key=:source_key LIMIT 1');
$findExact = $pdo->prepare(
    "SELECT id FROM questions
     WHERE course_id=:course_id AND module_id=:module_id AND statement=:statement
     LIMIT 1"
);
$insertQ = $pdo->prepare(
    "INSERT INTO questions
       (course_id,module_id,phase_id,lesson_id,question_type,statement,explanation,difficulty,source_label,active,created_by)
     VALUES
       (:course_id,:module_id,:phase_id,NULL,'multiple_choice',:statement,:explanation,:difficulty,:source_label,1,:created_by)"
);
$insertAlt = $pdo->prepare(
    "INSERT INTO alternatives (question_id,label,text,is_correct,explanation,position)
     VALUES (:question_id,:label,:text,:is_correct,NULL,:position)"
);
$insertKey = $pdo->prepare(
    "INSERT INTO question_import_keys (source_key,question_id,source_name)
     VALUES (:source_key,:question_id,'Complemento POP - mínimo 4 questões por processo V1')"
);
$linkTopic = $pdo->prepare(
    "INSERT INTO question_topics (question_id,topic_id,is_primary)
     VALUES (:question_id,:topic_id,:is_primary)
     ON DUPLICATE KEY UPDATE is_primary=GREATEST(is_primary,VALUES(is_primary))"
);
$attachPhase = $pdo->prepare(
    "UPDATE questions SET phase_id=:phase_id
     WHERE id=:question_id AND phase_id IS NULL"
);

$plan = [];
$totalMissing = 0;

foreach ($dataByPop as $pop => $data) {
    foreach (($data['processes'] ?? []) as $process) {
        $code = (string)$process['code'];
        $processTopicId = topicId($pdo, $code);
        $current = validQuestionCountForTopic($pdo, $processTopicId);
        $missing = max(0, 4 - $current);
        $totalMissing += $missing;

        $candidates = buildCandidates(
            (int)$pop,
            $process,
            $data['processes'],
            (string)($data['source']['file'] ?? "POP {$pop}")
        );

        if (count($candidates) < $missing) {
            throw new RuntimeException("Banco complementar insuficiente para o Processo {$code}: faltam {$missing}, candidatos=".count($candidates));
        }

        $plan[] = [
            'pop' => (int)$pop,
            'process' => $process,
            'process_topic_id' => $processTopicId,
            'current' => $current,
            'missing' => $missing,
            'candidates' => $candidates,
        ];
    }
}

echo "=============================================================\n";
echo " POPs 1-4 - GARANTIA DE MÍNIMO 4 QUESTÕES POR PROCESSO V1\n";
echo "=============================================================\n";
printf("Banco:       %s\n", (string)envv('DB_DATABASE', 'curso'));
printf("Curso:       #%d - %s\n", (int)$course['id'], $course['title']);
printf("Módulo POP:  #%d - %s\n", (int)$module['id'], $module['title']);
printf("Modo:        %s\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Déficit total atual: %d questão(ões)\n\n", $totalMissing);

foreach ($plan as $row) {
    $code = (string)$row['process']['code'];
    printf(
        "POP%d / %s -> atual=%d | faltam=%d | após aplicação=%d\n",
        (int)$row['pop'],
        $code,
        (int)$row['current'],
        (int)$row['missing'],
        max(4, (int)$row['current'])
    );
}

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, aplique com: php database/importar_pop_minimo4_v1.php --apply" .
         ($appEnv === 'production' ? " --force-production" : '') . "\n";
    exit(0);
}

$stats = ['new'=>0,'reuse_key'=>0,'reuse_exact'=>0,'links'=>0];
$pdo->beginTransaction();

try {
    foreach ($plan as $row) {
        $missing = (int)$row['missing'];
        if ($missing <= 0) continue;

        $pop = (int)$row['pop'];
        $phaseId = phaseIdForPop($pdo, (int)$module['id'], $pop);
        if ($phaseId === null) {
            throw new RuntimeException("Fase publicada do POP{$pop} não encontrada.");
        }

        foreach ($row['candidates'] as $candidate) {
            if ($missing <= 0) break;

            $sourceKey = (string)$candidate['source_key'];
            $findKey->execute(['source_key' => $sourceKey]);
            $existingByKey = $findKey->fetchColumn();

            if ($existingByKey !== false) {
                $questionId = (int)$existingByKey;
                $stats['reuse_key']++;
            } else {
                $findExact->execute([
                    'course_id' => (int)$course['id'],
                    'module_id' => (int)$module['id'],
                    'statement' => (string)$candidate['statement'],
                ]);
                $exact = $findExact->fetchColumn();

                if ($exact !== false) {
                    $questionId = (int)$exact;
                    $stats['reuse_exact']++;
                } else {
                    $insertQ->execute([
                        'course_id' => (int)$course['id'],
                        'module_id' => (int)$module['id'],
                        'phase_id' => $phaseId,
                        'statement' => (string)$candidate['statement'],
                        'explanation' => (string)$candidate['explanation'],
                        'difficulty' => (string)$candidate['difficulty'],
                        'source_label' => (string)$candidate['source_label'],
                        'created_by' => $adminId,
                    ]);
                    $questionId = (int)$pdo->lastInsertId();

                    foreach ($candidate['alternatives'] as $i => $alt) {
                        $insertAlt->execute([
                            'question_id' => $questionId,
                            'label' => chr(65 + $i),
                            'text' => (string)$alt['text'],
                            'is_correct' => !empty($alt['correct']) ? 1 : 0,
                            'position' => $i + 1,
                        ]);
                    }
                    $stats['new']++;
                }

                $insertKey->execute([
                    'source_key' => $sourceKey,
                    'question_id' => $questionId,
                ]);
            }

            $attachPhase->execute([
                'phase_id' => $phaseId,
                'question_id' => $questionId,
            ]);

            $primaryTopicId = topicId($pdo, (string)$candidate['primary_topic']);
            foreach (ancestorTopicIds($pdo, $primaryTopicId) as $ancestorId) {
                $linkTopic->execute([
                    'question_id' => $questionId,
                    'topic_id' => $ancestorId,
                    'is_primary' => $ancestorId === $primaryTopicId ? 1 : 0,
                ]);
                $stats['links']++;
            }

            $missing--;
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(3);
}

printf(
    "\n[OK] Complementação concluída. Novas=%d | Reuso por chave=%d | Reuso exato=%d | Links=%d\n",
    $stats['new'],
    $stats['reuse_key'],
    $stats['reuse_exact'],
    $stats['links']
);
echo "[OK] Nenhuma questão antiga foi apagada ou reescrita.\n";
echo "Execute agora: php database/auditar_pop_minimo4_v1.php\n";
