<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);
$pdo = Database::connection();

$sourceFile = BASE_PATH . '/database/sources/ctb_questoes_cebraspe_snt_v1.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte da Lei de Tortura não encontrada.\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$items = is_array($data['items'] ?? null) ? $data['items'] : [];

$target = $data['target'] ?? [];
$moduleSlug = (string)($target['module_slug'] ?? '');
$phaseSlug = (string)($target['phase_slug'] ?? '');

$moduleStmt = $pdo->prepare(
    "SELECT id,course_id,title
     FROM modules
     WHERE slug=:slug
       AND status='published'
     LIMIT 1"
);
$moduleStmt->execute(['slug'=>$moduleSlug]);
$module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(STDERR,"[FALHA] Módulo 8 não encontrado.\n");
    exit(3);
}

$phaseStmt = $pdo->prepare(
    "SELECT id,title
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
    fwrite(STDERR,"[FALHA] Fase do CTB não encontrada.\n");
    exit(4);
}

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

if (!$tableExists($pdo,'question_import_keys')) {
    fwrite(STDERR,"[FALHA] Tabela question_import_keys ausente.\n");
    exit(5);
}

$lookup = $pdo->prepare(
    "SELECT k.source_key,q.id,q.module_id,q.phase_id,q.lesson_id,
            q.question_type,q.statement,q.difficulty,q.active,
            COUNT(a.id) AS alternative_count,
            SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) AS correct_count
     FROM question_import_keys k
     INNER JOIN questions q ON q.id=k.question_id
     LEFT JOIN alternatives a ON a.question_id=q.id
     WHERE k.source_key=:source_key
     GROUP BY
        k.source_key,q.id,q.module_id,q.phase_id,q.lesson_id,
        q.question_type,q.statement,q.difficulty,q.active"
);

echo "=============================================================\n";
echo " AUDITORIA - CTB - QUESTÕES CEBRASPE SNT V1\n";
echo "=============================================================\n";
printf("Banco:  %s\n",(string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Módulo: #%d - %s\n",(int)$module['id'],$module['title']);
printf("Fase:   #%d - %s\n\n",(int)$phase['id'],$phase['title']);

$problems = [];
$found = 0;
$mc = 0;
$tf = 0;
$questionIds = [];

foreach ($items as $item) {
    $sourceKey = (string)$item['source_key'];
    $lookup->execute(['source_key'=>$sourceKey]);
    $row = $lookup->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $problems[] = "Chave ausente: {$sourceKey}";
        continue;
    }

    $found++;
    $questionId = (int)$row['id'];
    $questionIds[] = $questionId;

    if ((int)$row['module_id'] !== (int)$module['id']) {
        $problems[] = "{$sourceKey}: questão #{$questionId} fora do Módulo 5.";
    }

    if ((int)$row['active'] !== 1) {
        $problems[] = "{$sourceKey}: questão #{$questionId} inativa.";
    }

    $type = (string)$row['question_type'];
    $altCount = (int)$row['alternative_count'];
    $correctCount = (int)$row['correct_count'];

    if ($type === 'multiple_choice') {
        $mc++;
        if ($altCount < 4) {
            $problems[] = "{$sourceKey}: múltipla escolha com {$altCount} alternativas.";
        }
    } elseif ($type === 'true_false') {
        $tf++;
        if ($altCount !== 2) {
            $problems[] = "{$sourceKey}: certo/errado com {$altCount} alternativas.";
        }
    } else {
        $problems[] = "{$sourceKey}: tipo inválido {$type}.";
    }

    if ($correctCount !== 1) {
        $problems[] = "{$sourceKey}: {$correctCount} alternativas corretas.";
    }

    if (!in_array((string)$row['difficulty'],['medium','hard'],true)) {
        $problems[] = "{$sourceKey}: dificuldade fora do padrão.";
    }

    printf(
        "[OK] %-32s -> questão #%d | %s | alternativas=%d | correta=%d\n",
        $sourceKey,
        $questionId,
        $type,
        $altCount,
        $correctCount
    );
}

$uniqueQuestionIds = array_values(array_unique($questionIds));

echo "\n-------------------------------------------------------------\n";
printf("Fonte curada:          %d\n",count($items));
printf("Chaves encontradas:    %d / %d\n",$found,count($items));
printf("Múltipla escolha:      %d\n",$mc);
printf("Certo/Errado:          %d\n",$tf);
printf("Questões físicas únicas:%d\n",count($uniqueQuestionIds));
printf(
    "Itens recebidos/excluídos na curadoria: %d / %d\n",
    (int)($data['selection']['received'] ?? 0),
    count($data['selection']['excluded'] ?? [])
);

if ($problems !== []) {
    echo "\nPROBLEMAS ENCONTRADOS: ".count($problems)."\n";
    foreach ($problems as $i=>$problem) {
        printf("%02d. %s\n",$i+1,$problem);
    }
    fwrite(STDERR,"\n[FALHA] Banco de questões de Tortura inconsistente.\n");
    exit(6);
}

echo "\n[OK] Banco curado do CTB consistente e sem recriação das questões reconhecidas como duplicadas.\n";
