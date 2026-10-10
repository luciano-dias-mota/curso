<?php

declare(strict_types=1);

/**
 * Importa o banco CURADO do POP 2 atualizado.
 *
 * - 28 questões revisadas a partir de exercicio-pop-2.docx;
 * - gabarito definido pelo pop-2--.pdf atualizado fornecido pelo usuário;
 * - vincula cada questão à taxonomia POP -> processo -> procedimento;
 * - não altera questões antigas;
 * - usa question_import_keys para idempotência.
 *
 * Uso:
 *   php database/importar_pop2_questoes_v1.php                         # dry-run
 *   php database/importar_pop2_questoes_v1.php --apply
 *   php database/importar_pop2_questoes_v1.php --apply --force-production
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Este script só pode ser executado via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
$autoload = BASE_PATH . '/vendor/autoload.php';
$jsonFile = __DIR__ . '/sources/pop2_questoes_revisadas.json';

if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
    exit(1);
}
if (!is_file($jsonFile)) {
    fwrite(STDERR, "Erro: {$jsonFile} não encontrado.\n");
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
        "SELECT id, title, slug FROM courses
         WHERE slug=:slug AND status='published' LIMIT 1"
    );
    $stmt->execute(['slug' => $slug]);
    $course = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$course) {
        throw new RuntimeException("Curso publicado não encontrado pelo slug esperado: {$slug}");
    }
    return $course;
}

function resolvePopModule(PDO $pdo, int $courseId): array
{
    $stmt = $pdo->prepare(
        "SELECT id, title, slug FROM modules
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

function resolvePop2PhaseId(PDO $pdo, int $moduleId): ?int
{
    $stmt = $pdo->prepare(
        "SELECT id FROM phases
         WHERE module_id=:module_id
           AND slug='pop-2-modulo-ii-abordagens-policiais-atualizado'
         LIMIT 1"
    );
    $stmt->execute(['module_id' => $moduleId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

function firstAdminId(PDO $pdo): ?int
{
    $id = $pdo->query(
        "SELECT u.id FROM users u
         INNER JOIN roles r ON r.id=u.role_id
         WHERE r.slug='admin' AND u.status='active'
         ORDER BY u.id LIMIT 1"
    )->fetchColumn();
    return $id ? (int) $id : null;
}

function topicId(PDO $pdo, string $code): int
{
    $stmt = $pdo->prepare("SELECT id FROM study_topics WHERE domain='pop' AND code=:code AND active=1 LIMIT 1");
    $stmt->execute(['code' => $code]);
    $id = $stmt->fetchColumn();
    if ($id === false) throw new RuntimeException("Tópico não encontrado: {$code}. Execute aplicar_pop2_taxonomia_v1.php primeiro.");
    return (int) $id;
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
        $ids[] = (int) $row['id'];
        $current = (int) ($row['parent_id'] ?? 0);
    }
    return $ids;
}

$apply = hasFlag('--apply');
$forceProduction = hasFlag('--force-production');
$appEnv = strtolower((string) envv('APP_ENV', 'local'));
if ($apply && $appEnv === 'production' && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: APP_ENV=production. Use --apply --force-production somente após backup.\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
$items = $data['items'] ?? [];
if (!is_array($items) || count($items) !== 28) {
    throw new RuntimeException('JSON de questões inválido: esperado total de 28 questões.');
}

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', envv('DB_HOST','127.0.0.1'), (int) envv('DB_PORT',3306), envv('DB_DATABASE','curso')),
    (string) envv('DB_USERNAME','root'),
    (string) envv('DB_PASSWORD',''),
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
);

foreach (['study_topics','question_topics','question_import_keys','questions','alternatives'] as $required) {
    if (!tableExists($pdo, $required)) {
        throw new RuntimeException("Tabela obrigatória ausente: {$required}");
    }
}

$course = resolveCourse($pdo);
$module = resolvePopModule($pdo, (int) $course['id']);
$phaseId = resolvePop2PhaseId($pdo, (int) $module['id']);
$adminId = firstAdminId($pdo);

$findKey = $pdo->prepare('SELECT question_id FROM question_import_keys WHERE source_key=:source_key LIMIT 1');
$findExact = $pdo->prepare(
    "SELECT id FROM questions WHERE course_id=:course_id AND module_id=:module_id AND statement=:statement LIMIT 1"
);
$insertQ = $pdo->prepare(
    "INSERT INTO questions
       (course_id,module_id,phase_id,lesson_id,question_type,statement,explanation,difficulty,source_label,active,created_by)
     VALUES
       (:course_id,:module_id,:phase_id,NULL,:question_type,:statement,:explanation,:difficulty,:source_label,1,:created_by)"
);
$insertAlt = $pdo->prepare(
    "INSERT INTO alternatives (question_id,label,text,is_correct,explanation,position)
     VALUES (:question_id,:label,:text,:is_correct,NULL,:position)"
);
$insertKey = $pdo->prepare(
    "INSERT INTO question_import_keys (source_key,question_id,source_name)
     VALUES (:source_key,:question_id,:source_name)"
);
$linkTopic = $pdo->prepare(
    "INSERT INTO question_topics (question_id,topic_id,is_primary)
     VALUES (:question_id,:topic_id,:is_primary)
     ON DUPLICATE KEY UPDATE is_primary=GREATEST(is_primary,VALUES(is_primary))"
);

$stats = ['new'=>0,'reuse_exact'=>0,'existing_key'=>0,'invalid'=>0,'links'=>0];
$prepared = [];

foreach ($items as $item) {
    $statement = trim((string) ($item['statement'] ?? ''));
    $alts = $item['alternatives'] ?? [];
    $qtype = (string) ($item['question_type'] ?? 'multiple_choice');
    $corrects = 0;
    if (!is_array($alts)) $alts = [];
    foreach ($alts as $alt) if (!empty($alt['correct'])) $corrects++;
    $min = $qtype === 'true_false' ? 2 : 4;
    if ($statement === '' || count($alts) < $min || $corrects !== 1) {
        $stats['invalid']++;
        continue;
    }

    $sourceKey = (string) ($item['source_key'] ?? '');
    $findKey->execute(['source_key' => $sourceKey]);
    $existingByKey = $findKey->fetchColumn();
    if ($existingByKey !== false) {
        $stats['existing_key']++;
        $questionId = (int) $existingByKey;
        $mode = 'existing_key';
    } else {
        $findExact->execute([
            'course_id' => (int) $course['id'],
            'module_id' => (int) $module['id'],
            'statement' => $statement,
        ]);
        $exactId = $findExact->fetchColumn();
        if ($exactId !== false) {
            $questionId = (int) $exactId;
            $mode = 'reuse_exact';
            $stats['reuse_exact']++;
        } else {
            $questionId = 0;
            $mode = 'new';
            $stats['new']++;
        }
    }

    $topicCodes = array_values(array_unique(array_map('strval', $item['topics'] ?? [])));
    $primaryCode = (string) ($item['primary_topic'] ?? ($topicCodes[0] ?? ''));
    $allTopicIds = [];
    $primaryTopicId = null;
    foreach ($topicCodes as $code) {
        $tid = topicId($pdo, $code);
        if ($code === $primaryCode) $primaryTopicId = $tid;
        foreach (ancestorTopicIds($pdo, $tid) as $ancestorId) {
            $allTopicIds[$ancestorId] = true;
        }
    }

    $prepared[] = compact('item','statement','alts','sourceKey','questionId','mode','allTopicIds','primaryTopicId');
}

printf("=============================================================\n");
printf(" IMPORTAÇÃO POP 2 - BANCO CURADO V1\n");
printf("=============================================================\n");
printf("Banco:  %s\n", (string) envv('DB_DATABASE','curso'));
printf("Curso:  #%d - %s\n", (int) $course['id'], $course['title']);
printf("Módulo: #%d - %s\n", (int) $module['id'], $module['title']);
printf("Fase POP2 atual: %s\n", $phaseId === null ? 'não encontrada (phase_id ficará NULL)' : '#'.$phaseId);
printf("Modo:   %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');
printf("Questões no JSON:         %d\n", count($items));
printf("Novas planejadas:         %d\n", $stats['new']);
printf("Reuso exato planejado:    %d\n", $stats['reuse_exact']);
printf("Já importadas pela chave: %d\n", $stats['existing_key']);
printf("Inválidas:                 %d\n", $stats['invalid']);

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, aplique com: php database/importar_pop2_questoes_v1.php --apply" . ($appEnv === 'production' ? " --force-production" : '') . "\n";
    exit($stats['invalid'] > 0 ? 4 : 0);
}

if ($stats['invalid'] > 0) {
    throw new RuntimeException('Importação recusada: existem questões inválidas no JSON.');
}

$pdo->beginTransaction();
try {
    foreach ($prepared as $row) {
        $item = $row['item'];
        $questionId = (int) $row['questionId'];
        $mode = $row['mode'];

        if ($mode === 'new') {
            $insertQ->execute([
                'course_id' => (int) $course['id'],
                'module_id' => (int) $module['id'],
                'phase_id' => $phaseId,
                'question_type' => (string) $item['question_type'],
                'statement' => (string) $item['statement'],
                'explanation' => (string) $item['explanation'],
                'difficulty' => (string) $item['difficulty'],
                'source_label' => (string) $item['source_label'],
                'created_by' => $adminId,
            ]);
            $questionId = (int) $pdo->lastInsertId();
            foreach ($item['alternatives'] as $i => $alt) {
                $insertAlt->execute([
                    'question_id' => $questionId,
                    'label' => chr(65 + $i),
                    'text' => (string) $alt['text'],
                    'is_correct' => !empty($alt['correct']) ? 1 : 0,
                    'position' => $i + 1,
                ]);
            }
        }

        if ($mode !== 'existing_key') {
            $insertKey->execute([
                'source_key' => (string) $row['sourceKey'],
                'question_id' => $questionId,
                'source_name' => 'POP 2 atualizado - banco curado V1',
            ]);
        }

        foreach (array_keys($row['allTopicIds']) as $tid) {
            $isPrimary = ((int) $tid === (int) $row['primaryTopicId']) ? 1 : 0;
            $linkTopic->execute(['question_id'=>$questionId,'topic_id'=>(int)$tid,'is_primary'=>$isPrimary]);
            $stats['links']++;
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
    exit(5);
}

printf("\n[OK] Importação concluída. Novas=%d | Reuso exato=%d | Já existentes=%d | Links=%d\n",
    $stats['new'],$stats['reuse_exact'],$stats['existing_key'],$stats['links']);
echo "[OK] Questões antigas não foram alteradas.\n";
