<?php

declare(strict_types=1);

/**
 * Lei de Tortura - banco de questões de bancas V1.
 *
 * Importa somente as questões curadas em database/sources/lei_tortura_questoes_bancas_v1.json.
 * A fonte já elimina itens redundantes, ambíguos ou inconsistentes.
 *
 * Antiduplicidade:
 *  - source_key já importada;
 *  - enunciado normalizado idêntico (ignora banca/ano, acentos e pontuação);
 *  - enunciado quase idêntico (>= 98,5% de similaridade e comprimentos próximos).
 *
 * Questão detectada como duplicada NÃO é recriada; a source_key é associada
 * à questão já existente para manter idempotência.
 *
 * Uso:
 *   php database/importar_lei_tortura_questoes_v1.php
 *   php database/importar_lei_tortura_questoes_v1.php --apply
 *   php database/importar_lei_tortura_questoes_v1.php --apply --force-production
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
        "ABORTADO: APP_ENV=production. Faça backup e use --apply --force-production somente após revisar o dry-run.\n"
    );
    exit(1);
}

$sourceFile = BASE_PATH . '/database/sources/lei_tortura_questoes_bancas_v1.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte não encontrada: {$sourceFile}\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
if ((int)($data['format_version'] ?? 0) !== 1) {
    throw new RuntimeException('Fonte incompatível: format_version esperado = 1.');
}

$items = is_array($data['items'] ?? null) ? $data['items'] : [];
if (count($items) !== 12) {
    throw new RuntimeException(
        'Fonte curada inválida: esperadas 12 questões; encontradas ' . count($items) . '.'
    );
}

$normalize = static function (string $value): string {
    $value = trim($value);

    // Remove prefixo de banca/ano para detectar a mesma questão com outro cabeçalho.
    $value = preg_replace('/^\s*\([^\)]{1,100}\)\s*/u', '', $value) ?? $value;

    $value = mb_strtolower($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = strtolower($ascii);
    }

    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    return trim($value);
};

$isNearDuplicate = static function (string $a, string $b): bool {
    if ($a === '' || $b === '') {
        return false;
    }

    if ($a === $b) {
        return true;
    }

    $la = strlen($a);
    $lb = strlen($b);
    $ratio = min($la, $lb) / max($la, $lb);

    if ($ratio < 0.92) {
        return false;
    }

    similar_text($a, $b, $percent);
    return $percent >= 98.5;
};

$pdo = Database::connection();

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA=DATABASE()
           AND TABLE_NAME=:table"
    );
    $stmt->execute(['table'=>$table]);
    return (int)$stmt->fetchColumn() > 0;
};

foreach (['courses','modules','phases','lessons','questions','alternatives'] as $required) {
    if (!$tableExists($pdo, $required)) {
        throw new RuntimeException("Tabela obrigatória ausente: {$required}");
    }
}

$hasImportKeys = $tableExists($pdo, 'question_import_keys');

if (!$hasImportKeys) {
    if (!$apply) {
        echo "[CRIAR] question_import_keys\n";
    } else {
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
        $hasImportKeys = true;
    }
}

$target = $data['target'] ?? [];
$moduleSlug = (string)($target['module_slug'] ?? '');
$phaseSlug = (string)($target['phase_slug'] ?? '');

$moduleStmt = $pdo->prepare(
    "SELECT m.id,m.course_id,m.title,m.slug
     FROM modules m
     INNER JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug
       AND m.status='published'
       AND c.status='published'
     LIMIT 1"
);
$moduleStmt->execute(['slug'=>$moduleSlug]);
$module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    throw new RuntimeException("Módulo publicado não encontrado: {$moduleSlug}");
}

$phaseStmt = $pdo->prepare(
    "SELECT id,title,slug
     FROM phases
     WHERE module_id=:module_id
       AND slug=:slug
       AND status='published'
     LIMIT 1"
);
$phaseStmt->execute([
    'module_id'=>(int)$module['id'],
    'slug'=>$phaseSlug,
]);
$phase = $phaseStmt->fetch(PDO::FETCH_ASSOC);

if (!$phase) {
    throw new RuntimeException(
        "Fase publicada da Lei de Tortura não encontrada: {$phaseSlug}"
    );
}

$lessonStmt = $pdo->prepare(
    "SELECT id,title
     FROM lessons
     WHERE phase_id=:phase_id
       AND status='published'
     ORDER BY position,id
     LIMIT 1"
);
$lessonStmt->execute(['phase_id'=>(int)$phase['id']]);
$lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);

if (!$lesson) {
    throw new RuntimeException('A fase da Lei de Tortura não possui aula publicada.');
}

$existingStmt = $pdo->prepare(
    "SELECT q.id,q.statement,q.phase_id,q.lesson_id,q.question_type,
            COUNT(a.id) AS alternative_count,
            SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) AS correct_count
     FROM questions q
     LEFT JOIN alternatives a ON a.question_id=q.id
     WHERE q.module_id=:module_id
       AND q.active=1
     GROUP BY q.id,q.statement,q.phase_id,q.lesson_id,q.question_type
     ORDER BY q.id"
);
$existingStmt->execute(['module_id'=>(int)$module['id']]);
$existing = $existingStmt->fetchAll(PDO::FETCH_ASSOC);

$existingNormalized = [];
foreach ($existing as $row) {
    $existingNormalized[] = [
        'id'=>(int)$row['id'],
        'normalized'=>$normalize((string)$row['statement']),
        'row'=>$row,
    ];
}

$keyStmt = $hasImportKeys
    ? $pdo->prepare(
        "SELECT question_id
         FROM question_import_keys
         WHERE source_key=:source_key
         LIMIT 1"
    )
    : null;

$prepared = [];
$stats = [
    'new'=>0,
    'duplicate'=>0,
    'existing_key'=>0,
    'invalid'=>0,
];

foreach ($items as $item) {
    $sourceKey = trim((string)($item['source_key'] ?? ''));
    $statement = trim((string)($item['statement'] ?? ''));
    $type = (string)($item['question_type'] ?? 'multiple_choice');
    $alternatives = is_array($item['alternatives'] ?? null) ? $item['alternatives'] : [];

    $corrects = 0;
    foreach ($alternatives as $alt) {
        if (!empty($alt['is_correct'])) {
            $corrects++;
        }
    }

    $minimum = $type === 'true_false' ? 2 : 4;
    if ($sourceKey === ''
        || $statement === ''
        || !in_array($type, ['multiple_choice','true_false'], true)
        || count($alternatives) < $minimum
        || $corrects !== 1) {
        $stats['invalid']++;
        continue;
    }

    $keyQuestionId = false;
    if ($keyStmt instanceof PDOStatement) {
        $keyStmt->execute(['source_key'=>$sourceKey]);
        $keyQuestionId = $keyStmt->fetchColumn();
    }

    if ($keyQuestionId !== false) {
        $stats['existing_key']++;
        $prepared[] = [
            'mode'=>'existing_key',
            'question_id'=>(int)$keyQuestionId,
            'item'=>$item,
        ];
        continue;
    }

    $normalized = $normalize($statement);
    $duplicateId = 0;

    foreach ($existingNormalized as $candidate) {
        if ($isNearDuplicate($normalized, (string)$candidate['normalized'])) {
            $row = $candidate['row'];
            $altCount = (int)($row['alternative_count'] ?? 0);
            $correctCount = (int)($row['correct_count'] ?? 0);
            $candidateMinimum = (string)$row['question_type'] === 'true_false' ? 2 : 4;

            if ($altCount >= $candidateMinimum && $correctCount === 1) {
                $duplicateId = (int)$candidate['id'];
                break;
            }
        }
    }

    if ($duplicateId > 0) {
        $stats['duplicate']++;
        $prepared[] = [
            'mode'=>'duplicate',
            'question_id'=>$duplicateId,
            'item'=>$item,
        ];
        continue;
    }

    $stats['new']++;
    $prepared[] = [
        'mode'=>'new',
        'question_id'=>0,
        'item'=>$item,
    ];
}

echo "=============================================================\n";
echo " LEI DE TORTURA - QUESTÕES DE BANCAS V1\n";
echo "=============================================================\n";
printf("Banco:       %s\n",(string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Módulo:      #%d - %s\n",(int)$module['id'],$module['title']);
printf("Fase:        #%d - %s\n",(int)$phase['id'],$phase['title']);
printf("Aula alvo:   #%d - %s\n",(int)$lesson['id'],$lesson['title']);
printf("Recebidas:   %d\n",(int)($data['selection']['received'] ?? 0));
printf("Curadas:     %d\n",count($items));
printf("Novas:       %d\n",$stats['new']);
printf("Duplicadas:  %d\n",$stats['duplicate']);
printf("Já import.:  %d\n",$stats['existing_key']);
printf("Inválidas:   %d\n",$stats['invalid']);
printf("Modo:        %s\n\n",$apply ? 'APLICAÇÃO' : 'DRY-RUN');

if ($stats['invalid'] > 0) {
    fwrite(STDERR,"[FALHA] Fonte contém questão estruturalmente inválida.\n");
    exit(4);
}

foreach (($data['selection']['excluded'] ?? []) as $excluded) {
    printf(
        "[NÃO IMPORTAR] %s — %s\n",
        (string)($excluded['origin'] ?? 'item'),
        (string)($excluded['reason'] ?? '')
    );
}

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Após backup, execute: php database/importar_lei_tortura_questoes_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$adminId = $pdo->query(
    "SELECT u.id
     FROM users u
     INNER JOIN roles r ON r.id=u.role_id
     WHERE r.slug='admin'
       AND u.status='active'
     ORDER BY u.id
     LIMIT 1"
)->fetchColumn();
$adminId = $adminId === false ? null : (int)$adminId;

$insertQuestion = $pdo->prepare(
    "INSERT INTO questions
        (course_id,module_id,phase_id,lesson_id,question_type,statement,
         explanation,difficulty,source_label,active,created_by)
     VALUES
        (:course_id,:module_id,:phase_id,:lesson_id,:question_type,:statement,
         :explanation,:difficulty,:source_label,1,:created_by)"
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

$pdo->beginTransaction();

try {
    $created = 0;
    $reused = 0;
    $keysWritten = 0;

    foreach ($prepared as $row) {
        if ($row['mode'] === 'existing_key') {
            continue;
        }

        $item = $row['item'];
        $questionId = (int)$row['question_id'];

        if ($row['mode'] === 'new') {
            $insertQuestion->execute([
                'course_id'=>(int)$module['course_id'],
                'module_id'=>(int)$module['id'],
                'phase_id'=>(int)$phase['id'],
                'lesson_id'=>(int)$lesson['id'],
                'question_type'=>(string)$item['question_type'],
                'statement'=>(string)$item['statement'],
                'explanation'=>(string)$item['explanation'],
                'difficulty'=>(string)($item['difficulty'] ?? 'medium'),
                'source_label'=>mb_strimwidth(
                    'Lei nº 9.455/1997 | ' . (string)($item['origin'] ?? 'Banca')
                    . ' | material fornecido pelo usuário',
                    0,
                    255,
                    ''
                ),
                'created_by'=>$adminId,
            ]);
            $questionId = (int)$pdo->lastInsertId();

            foreach ($item['alternatives'] as $index=>$alt) {
                $insertAlternative->execute([
                    'question_id'=>$questionId,
                    'label'=>(string)($alt['label'] ?? chr(65+$index)),
                    'text'=>(string)($alt['text'] ?? ''),
                    'is_correct'=>!empty($alt['is_correct']) ? 1 : 0,
                    'position'=>$index+1,
                ]);
            }
            $created++;
        } else {
            $reused++;
        }

        $insertKey->execute([
            'source_key'=>(string)$item['source_key'],
            'question_id'=>$questionId,
            'source_name'=>'Lei de Tortura - questões de bancas V1',
        ]);
        $keysWritten++;
    }

    $pdo->commit();

    printf(
        "\n[OK] Importação concluída. Criadas=%d | Duplicadas reaproveitadas=%d | Chaves novas=%d | Já existentes=%d\n",
        $created,
        $reused,
        $keysWritten,
        $stats['existing_key']
    );
    echo "[OK] Nenhuma questão existente foi apagada ou sobrescrita.\n";
    echo "[INFO] Questões Certo/Errado entram no gerador de exercícios; as fixações obrigatórias continuam priorizando múltipla escolha.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR,"ERRO: {$e->getMessage()}\n");
    exit(5);
}
