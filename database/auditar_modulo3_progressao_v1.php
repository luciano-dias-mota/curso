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

$moduleSlug = 'modulo-3-policia-judiciaria-militar-codigo-penal-militar-cppm-e-rdpm-mt';

$sourceFile = BASE_PATH . '/database/sources/modulo3_progressao_v2.json';
$source = is_file($sourceFile)
    ? json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR)
    : [];
$expectedRequired = count(is_array($source['required_phase_slugs'] ?? null) ? $source['required_phase_slugs'] : []);
$expectedOptional = count(is_array($source['optional_phase_slugs'] ?? null) ? $source['optional_phase_slugs'] : []);

$moduleStmt = $pdo->prepare(
    "SELECT m.id,m.course_id,m.title,c.title AS course_title
     FROM modules m
     INNER JOIN courses c ON c.id=m.course_id
     WHERE m.slug=:slug
       AND m.status='published'
       AND c.status='published'
     LIMIT 1"
);
$moduleStmt->execute(['slug'=>$moduleSlug]);
$module = $moduleStmt->fetch(\PDO::FETCH_ASSOC);

if (!$module) {
    fwrite(STDERR, "[FALHA] Módulo 3 publicado não encontrado.\n");
    exit(2);
}

$moduleId = (int)$module['id'];

echo "=============================================================\n";
echo " AUDITORIA MÓDULO 3 - PJM / CPM / CPPM / RDPM V2\n";
echo "=============================================================\n";
printf("Banco:  %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:  #%d - %s\n", (int)$module['course_id'], $module['course_title']);
printf("Módulo: #%d - %s\n\n", $moduleId, $module['title']);

$phasesStmt=$pdo->prepare(
    "SELECT id,title,slug,position,is_required
     FROM phases
     WHERE module_id=:module_id
       AND status='published'
     ORDER BY position,id"
);
$phasesStmt->execute(['module_id'=>$moduleId]);
$phases=$phasesStmt->fetchAll(\PDO::FETCH_ASSOC);

$lessonsStmt=$pdo->prepare(
    "SELECT l.id,l.title,l.is_required,
            COUNT(lb.id) AS block_count
     FROM lessons l
     LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id
     WHERE l.phase_id=:phase_id
       AND l.status='published'
     GROUP BY l.id,l.title,l.is_required
     ORDER BY l.position,l.id"
);

$fixStmt=$pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS question_count
     FROM quizzes q
     LEFT JOIN quiz_questions qq ON qq.quiz_id=q.id
     WHERE q.lesson_id=:lesson_id
       AND q.quiz_type='lesson_fixation'
       AND q.active=1
     GROUP BY q.id,q.required_score,q.question_limit
     ORDER BY q.id
     LIMIT 1"
);

$phaseQuizStmt=$pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS question_count
     FROM quizzes q
     LEFT JOIN quiz_questions qq ON qq.quiz_id=q.id
     WHERE q.phase_id=:phase_id
       AND q.lesson_id IS NULL
       AND q.quiz_type='phase_exam'
       AND q.active=1
     GROUP BY q.id,q.required_score,q.question_limit
     ORDER BY q.id
     LIMIT 1"
);

$qCountStmt=$pdo->prepare(
    "SELECT COUNT(*)
     FROM questions
     WHERE module_id=:module_id
       AND phase_id=:phase_id
       AND active=1"
);

$problems=[];
$required=0;
$optional=0;
$validFix=0;
$validCheck=0;
$thinRequired=0;

foreach($phases as $phase){
    $phaseId=(int)$phase['id'];
    $isRequired=(int)$phase['is_required']===1;
    $isRequired ? $required++ : $optional++;

    echo "-------------------------------------------------------------\n";
    printf(
        "FASE %02d | #%d | %s | %s\n",
        (int)$phase['position'],
        $phaseId,
        $isRequired ? 'OBRIGATÓRIA' : 'COMPLEMENTAR',
        $phase['title']
    );

    $lessonsStmt->execute(['phase_id'=>$phaseId]);
    $lessons=$lessonsStmt->fetchAll(\PDO::FETCH_ASSOC);

    foreach($lessons as $lesson){
        $lessonId=(int)$lesson['id'];
        $blocks=(int)$lesson['block_count'];

        if($isRequired && (int)$lesson['is_required']===1 && $blocks<=1){
            $thinRequired++;
            $problems[]="Aula obrigatória #{$lessonId} '{$lesson['title']}' possui apenas {$blocks} bloco(s).";
        }

        $fixStmt->execute(['lesson_id'=>$lessonId]);
        $fix=$fixStmt->fetch(\PDO::FETCH_ASSOC);

        if($isRequired && (int)$lesson['is_required']===1){
            if(!$fix){
                $problems[]="Aula obrigatória #{$lessonId} sem fixação.";
                $fixText='AUSENTE';
            }else{
                $fixText=sprintf(
                    '#%d %d/%d nota %.0f%%',
                    (int)$fix['id'],
                    (int)$fix['question_count'],
                    (int)$fix['question_limit'],
                    (float)$fix['required_score']
                );
                if((int)$fix['question_count']===3
                    && (int)$fix['question_limit']===3
                    && abs((float)$fix['required_score']-100.0)<0.01){
                    $validFix++;
                }else{
                    $problems[]="Fixação fora do padrão na aula #{$lessonId}: {$fixText}.";
                }
            }
        }else{
            $fixText='não obrigatória';
        }

        printf(
            "  Aula #%d | blocos=%d | %s\n",
            $lessonId,
            $blocks,
            $fixText
        );
    }

    $qCountStmt->execute(['module_id'=>$moduleId,'phase_id'=>$phaseId]);
    $phaseQuestions=(int)$qCountStmt->fetchColumn();
    printf("Questões associadas à fase: %d\n",$phaseQuestions);

    if($isRequired){
        if($phaseQuestions<5){
            $problems[]="Fase #{$phaseId} possui apenas {$phaseQuestions} questões associadas.";
        }

        $phaseQuizStmt->execute(['phase_id'=>$phaseId]);
        $check=$phaseQuizStmt->fetch(\PDO::FETCH_ASSOC);

        if(!$check){
            $problems[]="Fase obrigatória #{$phaseId} sem checkpoint.";
            echo "Checkpoint: AUSENTE\n";
        }else{
            printf(
                "Checkpoint: #%d | %d/%d | nota %.0f%%\n",
                (int)$check['id'],
                (int)$check['question_count'],
                (int)$check['question_limit'],
                (float)$check['required_score']
            );
            if((int)$check['question_count']===5
                && (int)$check['question_limit']===5
                && abs((float)$check['required_score']-80.0)<0.01){
                $validCheck++;
            }else{
                $problems[]="Checkpoint fora do padrão na fase #{$phaseId}.";
            }
        }
    }else{
        echo "Checkpoint: não obrigatório\n";
    }
}

$bossStmt=$pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS question_count
     FROM quizzes q
     LEFT JOIN quiz_questions qq ON qq.quiz_id=q.id
     WHERE q.module_id=:module_id
       AND q.quiz_type='module_boss'
       AND q.active=1
     GROUP BY q.id,q.required_score,q.question_limit
     ORDER BY q.id
     LIMIT 1"
);
$bossStmt->execute(['module_id'=>$moduleId]);
$boss=$bossStmt->fetch(\PDO::FETCH_ASSOC);

$totalQStmt=$pdo->prepare(
    "SELECT COUNT(*) FROM questions
     WHERE module_id=:module_id
       AND active=1"
);
$totalQStmt->execute(['module_id'=>$moduleId]);
$totalQuestions=(int)$totalQStmt->fetchColumn();

$unmappedStmt=$pdo->prepare(
    "SELECT COUNT(*) FROM questions
     WHERE module_id=:module_id
       AND active=1
       AND phase_id IS NULL"
);
$unmappedStmt->execute(['module_id'=>$moduleId]);
$unmapped=(int)$unmappedStmt->fetchColumn();

echo "\n=============================================================\n";
echo " RESUMO\n";
echo "=============================================================\n";
printf("Fases obrigatórias:                  %d / %d\n",$required,$expectedRequired);
printf("Fases complementares:                %d / %d\n",$optional,$expectedOptional);
printf("Fixações válidas 3/3 a 100%%:         %d / %d\n",$validFix,$expectedRequired);
printf("Checkpoints válidos 5/5 a 80%%:       %d / %d\n",$validCheck,$expectedRequired);
printf("Aulas obrigatórias com <= 1 bloco:   %d\n",$thinRequired);
printf("Questões ativas no módulo:           %d\n",$totalQuestions);
printf("Questões sem fase associada:         %d\n",$unmapped);

if($boss){
    printf(
        "Avaliação final:                    #%d | %d/%d | nota %.0f%%\n",
        (int)$boss['id'],
        (int)$boss['question_count'],
        (int)$boss['question_limit'],
        (float)$boss['required_score']
    );
    if((int)$boss['question_count']!==10
        || (int)$boss['question_limit']!==10
        || abs((float)$boss['required_score']-80.0)>=0.01){
        $problems[]='Avaliação final fora do padrão 10 questões / 80%.';
    }
}else{
    echo "Avaliação final:                    AUSENTE\n";
    $problems[]='Avaliação final ausente.';
}

if($expectedRequired < 1 || $expectedOptional < 1){
    $problems[]='Fonte curricular sem contagem esperada de fases.';
}else{
    if($required!==$expectedRequired) $problems[]="Quantidade de fases obrigatórias: {$required}; esperado {$expectedRequired}.";
    if($optional!==$expectedOptional) $problems[]="Quantidade de fases complementares: {$optional}; esperado {$expectedOptional}.";
}
if($unmapped!==0) $problems[]="Existem {$unmapped} questões sem fase associada.";

if($problems!==[]){
    echo "\nPROBLEMAS ENCONTRADOS:\n";
    foreach($problems as $i=>$problem){
        printf("%02d. %s\n",$i+1,$problem);
    }
    fwrite(STDERR,"\n[FALHA] Módulo 3 ainda não está consistente.\n");
    exit(3);
}

echo "\n[OK] Módulo 3 consistente: conteúdo reforçado, fixações e checkpoints de todas as fases obrigatórias, banco mapeado e avaliação final válida.\n";
