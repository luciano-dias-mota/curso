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

$sourceFile = BASE_PATH . '/database/sources/modulos_4a8_progressao_v2.json';
if (!is_file($sourceFile)) {
    fwrite(STDERR, "[FALHA] Fonte não encontrada.\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);
$configs = is_array($data['modules'] ?? null) ? $data['modules'] : [];

$normalize = static function (string $value): string {
    $value = mb_strtolower($value, 'UTF-8');
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return is_string($ascii) ? strtolower($ascii) : $value;
};

$selectorMatches = static function (array $phase, array $selector) use ($normalize): bool {
    if (isset($selector['slug'])) {
        return (string)$phase['slug'] === (string)$selector['slug'];
    }
    if (isset($selector['title_prefix'])) {
        return str_starts_with(
            $normalize((string)$phase['title']),
            $normalize((string)$selector['title_prefix'])
        );
    }
    if (isset($selector['title_contains'])) {
        return str_contains(
            $normalize((string)$phase['title']),
            $normalize((string)$selector['title_contains'])
        );
    }
    return false;
};

$moduleStmt=$pdo->prepare(
    "SELECT id,title,slug
     FROM modules
     WHERE slug=:slug
       AND status='published'
     LIMIT 1"
);

$phaseStmt=$pdo->prepare(
    "SELECT id,title,slug,position,is_required
     FROM phases
     WHERE module_id=:module_id
       AND status='published'
     ORDER BY position,id"
);

$lessonsStmt=$pdo->prepare(
    "SELECT l.id,l.title,l.position,l.is_required,
            COUNT(lb.id) AS block_count,
            SUM(CASE WHEN lb.is_required=1 THEN 1 ELSE 0 END) AS required_blocks
     FROM lessons l
     LEFT JOIN lesson_blocks lb ON lb.lesson_id=l.id
     WHERE l.phase_id=:phase_id
       AND l.status='published'
     GROUP BY l.id,l.title,l.position,l.is_required
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

$checkStmt=$pdo->prepare(
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

$phaseQuestionCount=$pdo->prepare(
    "SELECT COUNT(DISTINCT q.id)
     FROM questions q
     INNER JOIN (
        SELECT question_id,
               COUNT(*) AS alternatives_count,
               SUM(CASE WHEN is_correct=1 THEN 1 ELSE 0 END) AS correct_count
        FROM alternatives
        GROUP BY question_id
     ) quality ON quality.question_id=q.id
     WHERE q.module_id=:module_id
       AND q.phase_id=:phase_id
       AND q.active=1
       AND q.question_type='multiple_choice'
       AND quality.alternatives_count>=4
       AND quality.correct_count=1"
);

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

echo "=============================================================\n";
echo " AUDITORIA MÓDULOS 4, 5, 6, 7 E 8 - PROGRESSÃO V2\n";
echo "=============================================================\n";
printf("Banco: %s\n\n", (string)$pdo->query('SELECT DATABASE()')->fetchColumn());

$allProblems=[];
$moduleSummaries=[];

foreach($configs as $config){
    $moduleStmt->execute(['slug'=>(string)$config['module_slug']]);
    $module=$moduleStmt->fetch(\PDO::FETCH_ASSOC);

    if(!$module){
        $allProblems[]='Módulo não encontrado: '.(string)$config['module_slug'];
        continue;
    }

    $moduleId=(int)$module['id'];
    $phaseStmt->execute(['module_id'=>$moduleId]);
    $phases=$phaseStmt->fetchAll(\PDO::FETCH_ASSOC);

    $requiredExpected=count($config['required_phases'] ?? []);
    $thematicExpected=max(0,$requiredExpected-1);
    $expectedBossQuestions=max(10,$thematicExpected);
    $requiredActual=0;
    $optionalActual=0;
    $validFixations=0;
    $requiredLessons=0;
    $validCheckpoints=0;
    $introBlocks=0;
    $moduleProblems=[];

    echo "-------------------------------------------------------------\n";
    printf("#%d | %s\n",$moduleId,$module['title']);

    foreach($phases as $phase){
        $phaseId=(int)$phase['id'];
        $required=(int)$phase['is_required']===1;

        if($required) $requiredActual++; else $optionalActual++;

        $lessonsStmt->execute(['phase_id'=>$phaseId]);
        $lessons=$lessonsStmt->fetchAll(\PDO::FETCH_ASSOC);

        $phaseQuestionCount->execute([
            'module_id'=>$moduleId,
            'phase_id'=>$phaseId,
        ]);
        $questionCount=(int)$phaseQuestionCount->fetchColumn();

        if((string)$phase['slug']==='introducao-e-visao-geral'){
            foreach($lessons as $lesson){
                $introBlocks=max($introBlocks,(int)$lesson['block_count']);
            }
        }

        if($required){
            if($questionCount<5){
                $moduleProblems[]="Fase #{$phaseId} possui {$questionCount}/5 questões válidas.";
            }

            foreach($lessons as $lesson){
                if((int)$lesson['is_required']!==1){
                    $moduleProblems[]="Aula #{$lesson['id']} está dentro de fase obrigatória, mas não está marcada como obrigatória.";
                    continue;
                }

                $requiredLessons++;
                $fixStmt->execute(['lesson_id'=>(int)$lesson['id']]);
                $fix=$fixStmt->fetch(\PDO::FETCH_ASSOC);

                if(!$fix){
                    $moduleProblems[]="Aula obrigatória #{$lesson['id']} sem fixação.";
                    continue;
                }

                if((int)$fix['question_count']===3
                    && (int)$fix['question_limit']===3
                    && abs((float)$fix['required_score']-100.0)<0.01){
                    $validFixations++;
                }else{
                    $moduleProblems[]=
                        "Fixação #{$fix['id']} da aula #{$lesson['id']} fora do padrão 3/3 a 100%.";
                }
            }

            $checkStmt->execute(['phase_id'=>$phaseId]);
            $check=$checkStmt->fetch(\PDO::FETCH_ASSOC);

            if(!$check){
                $moduleProblems[]="Fase obrigatória #{$phaseId} sem checkpoint.";
            }elseif((int)$check['question_count']===5
                && (int)$check['question_limit']===5
                && abs((float)$check['required_score']-80.0)<0.01){
                $validCheckpoints++;
            }else{
                $moduleProblems[]="Checkpoint #{$check['id']} da fase #{$phaseId} fora do padrão 5/5 a 80%.";
            }
        }

        printf(
            "  %s FASE %02d | #%d | questões=%d | aulas=%d | %s\n",
            $required?'[OBR.]':'[OPC.]',
            (int)$phase['position'],
            $phaseId,
            $questionCount,
            count($lessons),
            $phase['title']
        );
    }

    if($requiredActual!==$requiredExpected){
        $moduleProblems[]="Fases obrigatórias: {$requiredActual}; esperado {$requiredExpected}.";
    }

    if($introBlocks<2){
        $moduleProblems[]="Introdução ainda possui apenas {$introBlocks} bloco(s); esperado pelo menos 2.";
    }

    $bossStmt->execute(['module_id'=>$moduleId]);
    $boss=$bossStmt->fetch(\PDO::FETCH_ASSOC);

    if(!$boss){
        $moduleProblems[]='Avaliação final ausente.';
        $bossText='AUSENTE';
    }else{
        $bossText=sprintf(
            '#%d %d/%d nota %.0f%%',
            (int)$boss['id'],
            (int)$boss['question_count'],
            (int)$boss['question_limit'],
            (float)$boss['required_score']
        );

        if((int)$boss['question_count']!==$expectedBossQuestions
            || (int)$boss['question_limit']!==$expectedBossQuestions
            || abs((float)$boss['required_score']-80.0)>=0.01){
            $moduleProblems[]=
                "Avaliação final fora do padrão {$expectedBossQuestions}/{$expectedBossQuestions} a 80%.";
        }
    }

    printf("Fases obrigatórias:              %d / %d\n",$requiredActual,$requiredExpected);
    printf("Aulas obrigatórias:              %d\n",$requiredLessons);
    printf("Fixações válidas 3/3 a 100%%:     %d / %d\n",$validFixations,$requiredLessons);
    printf("Checkpoints válidos 5/5 a 80%%:   %d / %d\n",$validCheckpoints,$requiredActual);
    printf("Blocos da introdução:            %d\n",$introBlocks);
    printf("Avaliação final:                 %s\n",$bossText);

    if($moduleProblems!==[]){
        echo "Problemas:\n";
        foreach($moduleProblems as $problem){
            echo "  - {$problem}\n";
            $allProblems[]="Módulo #{$moduleId}: {$problem}";
        }
    }else{
        echo "[OK] Módulo consistente.\n";
    }

    $moduleSummaries[]=[
        'id'=>$moduleId,
        'title'=>$module['title'],
        'required'=>$requiredActual,
        'lessons'=>$requiredLessons,
        'fixations'=>$validFixations,
        'checkpoints'=>$validCheckpoints,
        'intro_blocks'=>$introBlocks,
        'problems'=>count($moduleProblems),
    ];
}

echo "\n=============================================================\n";
echo " RESUMO GERAL\n";
echo "=============================================================\n";
foreach($moduleSummaries as $row){
    printf(
        "#%d | obrigatórias=%d | aulas=%d | fixações=%d | checkpoints=%d | intro=%d blocos | problemas=%d\n",
        $row['id'],
        $row['required'],
        $row['lessons'],
        $row['fixations'],
        $row['checkpoints'],
        $row['intro_blocks'],
        $row['problems']
    );
}

if($allProblems!==[]){
    printf("\nProblemas encontrados: %d\n",count($allProblems));
    fwrite(STDERR,"[FALHA] Ainda existem inconsistências.\n");
    exit(3);
}

echo "\n[OK] Módulos 4, 5, 6, 7 e 8 consistentes: introduções reforçadas, exercícios obrigatórios, checkpoints e avaliações finais válidas.\n";
