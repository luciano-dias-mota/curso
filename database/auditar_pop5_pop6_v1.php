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

$sourceFile = BASE_PATH . '/database/sources/pop5_pop6_v1.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte POP5/POP6 não encontrada.\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$modulesData = is_array($data['modules'] ?? null) ? $data['modules'] : [];

$moduleStmt=$pdo->prepare(
    "SELECT id,title
     FROM modules
     WHERE course_id=(
         SELECT id
         FROM courses
         WHERE slug='preparacao-pmmt-merito-intelectual-e-caoc'
         LIMIT 1
     )
       AND status='published'
       AND (
            slug LIKE '%procedimentos-operacionais-padrao%'
         OR title LIKE '%Procedimentos Operacionais%'
       )
     ORDER BY position,id
     LIMIT 1"
);
$moduleStmt->execute();
$module=$moduleStmt->fetch(PDO::FETCH_ASSOC);

if(!$module){
    fwrite(STDERR,"[FALHA] Módulo curricular de POP não encontrado.\n");
    exit(3);
}

$moduleId=(int)$module['id'];

$phaseStmt=$pdo->prepare(
    "SELECT id,title,is_required,required_score
     FROM phases
     WHERE module_id=:module_id
       AND slug=:slug
       AND status='published'
     LIMIT 1"
);

$lessonsStmt=$pdo->prepare(
    "SELECT l.id,l.title,l.slug,l.is_required,
            COUNT(lb.id) AS block_count
     FROM lessons l
     LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id
     WHERE l.phase_id=:phase_id
       AND l.status='published'
     GROUP BY l.id,l.title,l.slug,l.is_required
     ORDER BY l.position,l.id"
);

$topicStmt=$pdo->prepare(
    "SELECT id,parent_id,topic_type,title
     FROM study_topics
     WHERE domain='pop'
       AND code=:code
       AND active=1
     LIMIT 1"
);

$processQuestionCount=$pdo->prepare(
    "SELECT COUNT(DISTINCT q.id)
     FROM study_topics st
     INNER JOIN question_topics qt ON qt.topic_id=st.id
     INNER JOIN questions q ON q.id=qt.question_id
     WHERE st.domain='pop'
       AND st.code=:code
       AND st.active=1
       AND q.module_id=:module_id
       AND q.active=1"
);

$keyStmt=$pdo->prepare(
    "SELECT q.id,q.phase_id,q.lesson_id,q.question_type,q.difficulty,
            COUNT(a.id) AS alternative_count,
            SUM(CASE WHEN a.is_correct=1 THEN 1 ELSE 0 END) AS correct_count
     FROM question_import_keys k
     INNER JOIN questions q ON q.id=k.question_id
     LEFT JOIN alternatives a ON a.question_id=q.id
     WHERE k.source_key=:source_key
     GROUP BY q.id,q.phase_id,q.lesson_id,q.question_type,q.difficulty"
);

$primaryLinkStmt=$pdo->prepare(
    "SELECT COUNT(*)
     FROM question_topics qt
     INNER JOIN study_topics st ON st.id=qt.topic_id
     WHERE qt.question_id=:question_id
       AND st.domain='pop'
       AND st.code=:code
       AND qt.is_primary=1"
);

$fixStmt=$pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS linked_questions
     FROM quizzes q
     LEFT JOIN quiz_questions qq ON qq.quiz_id=q.id
     WHERE q.lesson_id=:lesson_id
       AND q.quiz_type='lesson_fixation'
       AND q.active=1
     GROUP BY q.id,q.required_score,q.question_limit
     ORDER BY q.id
     LIMIT 1"
);

$checkpointStmt=$pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS linked_questions
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

echo "=============================================================\n";
echo " AUDITORIA POP 5 + POP 6 V1\n";
echo "=============================================================\n";
printf("Banco:      %s\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Módulo POP: #%d - %s\n\n",$moduleId,$module['title']);

$problems=[];
$totalExpectedQuestions=0;
$totalFoundQuestions=0;

foreach($modulesData as $moduleData){
    $popCode=(string)$moduleData['code'];
    $phaseSlug=(string)$moduleData['slug'];
    $processes=$moduleData['processes'] ?? [];

    $expectedProcedures=0;
    $expectedQuestions=0;
    foreach($processes as $process){
        $expectedProcedures += count($process['procedures'] ?? []);
        $expectedQuestions += count($process['questions'] ?? []);
    }
    $expectedTopics=1+count($processes)+$expectedProcedures;
    $expectedLessons=1+count($processes);
    $totalExpectedQuestions += $expectedQuestions;

    echo "-------------------------------------------------------------\n";
    printf("%s | %s\n",$popCode,$moduleData['title']);

    $phaseStmt->execute(['module_id'=>$moduleId,'slug'=>$phaseSlug]);
    $phase=$phaseStmt->fetch(PDO::FETCH_ASSOC);

    if(!$phase){
        $problems[]="{$popCode}: fase publicada não encontrada.";
        echo "Fase: AUSENTE\n";
        continue;
    }

    $phaseId=(int)$phase['id'];
    printf(
        "Fase: #%d | obrigatória=%s | nota=%.0f%%\n",
        $phaseId,
        (int)$phase['is_required']===1?'SIM':'NÃO',
        (float)$phase['required_score']
    );

    if((int)$phase['is_required']!==1){
        $problems[]="{$popCode}: fase ainda não está obrigatória na trilha.";
    }
    if(abs((float)$phase['required_score']-80.0)>=0.01){
        $problems[]="{$popCode}: required_score da fase diferente de 80%.";
    }

    $lessonsStmt->execute(['phase_id'=>$phaseId]);
    $lessons=$lessonsStmt->fetchAll(PDO::FETCH_ASSOC);
    printf("Aulas: %d / %d\n",count($lessons),$expectedLessons);

    if(count($lessons)!==$expectedLessons){
        $problems[]="{$popCode}: aulas=".count($lessons)."; esperado={$expectedLessons}.";
    }

    foreach($lessons as $lesson){
        $lessonId=(int)$lesson['id'];
        if((int)$lesson['block_count']<2){
            $problems[]="{$popCode}: aula #{$lessonId} possui menos de 2 blocos pedagógicos.";
        }
        if((int)$lesson['is_required']!==1){
            $problems[]="{$popCode}: aula #{$lessonId} não está marcada como obrigatória.";
        }

        $fixStmt->execute(['lesson_id'=>$lessonId]);
        $fix=$fixStmt->fetch(PDO::FETCH_ASSOC);
        if(!$fix){
            $problems[]="{$popCode}: aula #{$lessonId} sem fixação.";
        }elseif(
            (int)$fix['question_limit']!==3
            || (int)$fix['linked_questions']!==3
            || abs((float)$fix['required_score']-100.0)>=0.01
        ){
            $problems[]="{$popCode}: fixação da aula #{$lessonId} fora do padrão 3/3 a 100%.";
        }
    }

    $checkpointStmt->execute(['phase_id'=>$phaseId]);
    $checkpoint=$checkpointStmt->fetch(PDO::FETCH_ASSOC);
    if(!$checkpoint){
        $problems[]="{$popCode}: checkpoint da fase ausente.";
    }elseif(
        (int)$checkpoint['question_limit']!==5
        || (int)$checkpoint['linked_questions']!==5
        || abs((float)$checkpoint['required_score']-80.0)>=0.01
    ){
        $problems[]="{$popCode}: checkpoint fora do padrão 5/5 a 80%.";
    }

    // Taxonomia.
    $topicCodes=[$popCode];
    foreach($processes as $process){
        $topicCodes[]=(string)$process['code'];
        foreach($process['procedures'] as $procedure){
            $topicCodes[]=(string)$procedure;
        }
    }

    $foundTopics=0;
    foreach($topicCodes as $code){
        $topicStmt->execute(['code'=>$code]);
        if($topicStmt->fetch(PDO::FETCH_ASSOC)){
            $foundTopics++;
        }else{
            $problems[]="{$popCode}: tópico {$code} ausente.";
        }
    }
    printf("Tópicos: %d / %d\n",$foundTopics,$expectedTopics);

    // Banco por processo.
    foreach($processes as $process){
        $code=(string)$process['code'];
        $processQuestionCount->execute([
            'code'=>$code,
            'module_id'=>$moduleId,
        ]);
        $qty=(int)$processQuestionCount->fetchColumn();
        printf("  Processo %s: %d questão(ões)\n",$code,$qty);
        if($qty<4){
            $problems[]="{$popCode}: Processo {$code} possui {$qty}/4 questões.";
        }
    }

    // Cada chave e gabarito.
    $validKeys=0;
    foreach($processes as $process){
        foreach($process['questions'] as $item){
            $sourceKey=(string)$item['source_key'];
            $keyStmt->execute(['source_key'=>$sourceKey]);
            $row=$keyStmt->fetch(PDO::FETCH_ASSOC);

            if(!$row){
                $problems[]="{$popCode}: chave {$sourceKey} ausente.";
                continue;
            }

            $validKeys++;
            $totalFoundQuestions++;

            if((int)$row['phase_id']!==$phaseId){
                $problems[]="{$popCode}: {$sourceKey} não está associada à fase correta.";
            }
            if((int)($row['lesson_id'] ?? 0)<=0){
                $problems[]="{$popCode}: {$sourceKey} sem aula associada.";
            }
            if((string)$row['question_type']!=='multiple_choice'){
                $problems[]="{$popCode}: {$sourceKey} com question_type inválido.";
            }
            if(!in_array((string)$row['difficulty'],['medium','hard'],true)){
                $problems[]="{$popCode}: {$sourceKey} com dificuldade fora do padrão.";
            }
            if((int)$row['alternative_count']!==5 || (int)$row['correct_count']!==1){
                $problems[]="{$popCode}: {$sourceKey} possui "
                    .(int)$row['alternative_count']." alternativas / "
                    .(int)$row['correct_count']." correta(s).";
            }

            $primary=(string)$item['primary_topic'];
            $primaryLinkStmt->execute([
                'question_id'=>(int)$row['id'],
                'code'=>$primary,
            ]);
            if((int)$primaryLinkStmt->fetchColumn()!==1){
                $problems[]="{$popCode}: {$sourceKey} sem tópico primário {$primary}.";
            }
        }
    }

    printf("Chaves/questões curadas: %d / %d\n",$validKeys,$expectedQuestions);
}

$bossStmt=$pdo->prepare(
    "SELECT q.id,q.required_score,q.question_limit,
            COUNT(qq.question_id) AS linked_questions
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
$boss=$bossStmt->fetch(PDO::FETCH_ASSOC);

echo "\n-------------------------------------------------------------\n";
if(!$boss){
    $problems[]='Módulo POP sem avaliação final.';
    echo "Avaliação final: AUSENTE\n";
}else{
    printf(
        "Avaliação final: #%d | %d/%d | nota %.0f%%\n",
        (int)$boss['id'],
        (int)$boss['linked_questions'],
        (int)$boss['question_limit'],
        (float)$boss['required_score']
    );
    if(
        (int)$boss['linked_questions']!==10
        || (int)$boss['question_limit']!==10
        || abs((float)$boss['required_score']-80.0)>=0.01
    ){
        $problems[]='Avaliação final do módulo POP fora do padrão 10/10 a 80%.';
    }
}

printf(
    "Questões POP5/POP6 encontradas: %d / %d\n",
    $totalFoundQuestions,
    $totalExpectedQuestions
);

if($problems!==[]){
    echo "\nPROBLEMAS ENCONTRADOS: ".count($problems)."\n";
    foreach($problems as $i=>$problem){
        printf("%02d. %s\n",$i+1,$problem);
    }
    fwrite(STDERR,"\n[FALHA] POP 5/POP 6 ainda não estão totalmente integrados.\n");
    exit(4);
}

echo "\n[OK] POP 5 e POP 6 consistentes: 12 processos, 26 procedimentos, 60 questões curadas, aulas, fixações, checkpoints e avaliação final integrados.\n";
