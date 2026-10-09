<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}


define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
$jsonFile = __DIR__ . '/mega_apostila_content_profissional.json';

if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: execute composer install primeiro.\n");
    exit(1);
}

if (!is_file($jsonFile)) {
    fwrite(STDERR, "Erro: mega_apostila_content_profissional.json não encontrado em database/.\n");
    exit(1);
}

require $autoload;
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed {
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null) return $default;
    return is_string($v) ? trim($v, "\"'") : $v;
}

function hasColumn(\PDO $pdo, string $table, string $column): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $s->execute(['t'=>$table,'c'=>$column]);
    return (int)$s->fetchColumn() > 0;
}

$apply = in_array('--apply', $argv ?? [], true);
if (!$apply) {
    fwrite(STDOUT, "MODO SEGURO: nenhuma alteração foi realizada. Use --apply para executar este script.\n");
    exit(0);
}

$isProduction = strtolower((string) envv('APP_ENV', 'production')) === 'production';
$forceProduction = in_array('--force-production', $argv ?? [], true);
if ($isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: este script altera dados e APP_ENV=production. Use --force-production somente após backup e revisão.\n");
    exit(1);
}

$pdo = new \PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', envv('DB_HOST','127.0.0.1'), (int)envv('DB_PORT',3306), envv('DB_DATABASE','curso')),
    (string)envv('DB_USERNAME','root'),
    (string)envv('DB_PASSWORD',''),
    [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC, \PDO::ATTR_EMULATE_PREPARES=>false]
);

if (!hasColumn($pdo,'phases','is_required') || !hasColumn($pdo,'lessons','is_required')) {
    fwrite(STDERR, "Execute primeiro database/migrations/20260930_pedagogy.sql no phpMyAdmin.\n");
    exit(1);
}

$data = json_decode(file_get_contents($jsonFile), true, 512, JSON_THROW_ON_ERROR);
if (($data['format_version'] ?? 0) !== 3) {
    throw new \RuntimeException('JSON incompatível.');
}

$course = $data['course'];
$replace = in_array('--replace', $argv, true);

$adminId = (int)$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='admin' AND u.status='active' ORDER BY u.id LIMIT 1")->fetchColumn();
if (!$adminId) throw new \RuntimeException('Administrador não encontrado.');

try {
    $pdo->beginTransaction();

    $s = $pdo->prepare('SELECT id FROM courses WHERE slug=:slug LIMIT 1');
    $s->execute(['slug'=>$course['slug']]);
    $courseId = (int)($s->fetchColumn() ?: 0);

    if ($courseId && !$replace) {
        throw new \RuntimeException('O curso já existe. Execute com --replace.');
    }

    if (!$courseId) {
        $s=$pdo->prepare("INSERT INTO courses (title,slug,short_description,description,status,difficulty,required_score,xp_reward,position,published_at,created_by) VALUES (:title,:slug,:short_description,:description,'published','advanced',70,3000,1,NOW(),:admin)");
        $s->execute(['title'=>$course['title'],'slug'=>$course['slug'],'short_description'=>$course['short_description'],'description'=>$course['description'],'admin'=>$adminId]);
        $courseId=(int)$pdo->lastInsertId();
    } else {
        $pdo->prepare("UPDATE courses SET title=:title,short_description=:sd,description=:d,status='published' WHERE id=:id")
            ->execute(['title'=>$course['title'],'sd'=>$course['short_description'],'d'=>$course['description'],'id'=>$courseId]);
        $pdo->prepare('DELETE FROM modules WHERE course_id=:id')->execute(['id'=>$courseId]);
    }

    $im=$pdo->prepare("INSERT INTO modules (course_id,title,slug,description,position,required_score,xp_reward,status) VALUES (:c,:t,:s,:d,:p,70,250,'published')");
    $ip=$pdo->prepare("INSERT INTO phases (module_id,title,slug,description,phase_type,is_required,position,required_score,required_lessons_pct,xp_reward,status) VALUES (:m,:t,:s,:d,:type,:req,:p,70,100,:xp,'published')");
    $il=$pdo->prepare("INSERT INTO lessons (phase_id,title,slug,summary,is_required,position,estimated_minutes,xp_reward,status) VALUES (:ph,:t,:s,:sum,:req,:p,:min,:xp,'published')");
    $ib=$pdo->prepare("INSERT INTO lesson_blocks (lesson_id,block_type,title,content,media_url,position,is_required) VALUES (:l,:type,:t,:c,:u,:p,:req)");

    $modules=[]; $phases=[]; $lessons=[]; $counts=['m'=>0,'p'=>0,'l'=>0,'b'=>0,'lib'=>0];

    foreach ($course['modules'] as $m) {
        $im->execute(['c'=>$courseId,'t'=>$m['title'],'s'=>$m['slug'],'d'=>$m['description'] ?? '','p'=>$m['position']]);
        $mid=(int)$pdo->lastInsertId();
        $modules[]=['id'=>$mid,'position'=>(int)$m['position']]; $counts['m']++;

        foreach ($m['phases'] as $p) {
            $req=!empty($p['is_required']);
            $ip->execute(['m'=>$mid,'t'=>$p['title'],'s'=>$p['slug'],'d'=>$p['description'] ?? '','type'=>$p['phase_type'] ?? 'normal','req'=>$req?1:0,'p'=>$p['position'],'xp'=>$req?75:0]);
            $pid=(int)$pdo->lastInsertId();
            $phases[]=['id'=>$pid,'module_id'=>$mid,'module_position'=>(int)$m['position'],'position'=>(int)$p['position'],'required'=>$req];
            $counts['p']++; if(!$req)$counts['lib']++;

            foreach ($p['lessons'] as $l) {
                $lreq=$req && !empty($l['is_required']);
                $il->execute(['ph'=>$pid,'t'=>$l['title'],'s'=>$l['slug'],'sum'=>$l['summary'] ?? '','req'=>$lreq?1:0,'p'=>$l['position'],'min'=>$l['estimated_minutes'] ?? 8,'xp'=>$lreq?($l['xp_reward'] ?? 15):0]);
                $lid=(int)$pdo->lastInsertId();
                $lessons[]=['id'=>$lid,'phase_id'=>$pid,'module_id'=>$mid,'module_position'=>(int)$m['position'],'phase_position'=>(int)$p['position'],'phase_required'=>$req,'required'=>$lreq,'position'=>(int)$l['position']];
                $counts['l']++;
                foreach ($l['blocks'] as $i=>$b) {
                    $ib->execute(['l'=>$lid,'type'=>$b['type'] ?? 'theory','t'=>$b['title'] ?? null,'c'=>$b['content'],'u'=>$b['media_url'] ?? null,'p'=>$i+1,'req'=>$lreq && !empty($b['required']) ? 1 : 0]);
                    $counts['b']++;
                }
            }
        }
    }

    $students=$pdo->query("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='student' AND u.status='active'")->fetchAll(\PDO::FETCH_COLUMN);
    $en=$pdo->prepare("INSERT INTO enrollments (user_id,course_id,status,enrolled_at) VALUES (:u,:c,'active',NOW()) ON DUPLICATE KEY UPDATE status='active'");
    $cp=$pdo->prepare("INSERT INTO user_course_progress (user_id,course_id,status,progress_pct,average_score,started_at,last_accessed_at) VALUES (:u,:c,'in_progress',0,0,NOW(),NOW()) ON DUPLICATE KEY UPDATE status='in_progress',progress_pct=0,average_score=0,completed_at=NULL,last_accessed_at=NOW()");
    $mp=$pdo->prepare("INSERT INTO user_module_progress (user_id,module_id,status,best_score,progress_pct,unlocked_at) VALUES (:u,:m,:st,0,0,:dt)");
    $pp=$pdo->prepare("INSERT INTO user_phase_progress (user_id,phase_id,status,best_score,attempts_count,progress_pct,unlocked_at) VALUES (:u,:p,:st,0,0,0,:dt)");
    $lp=$pdo->prepare("INSERT INTO user_lesson_progress (user_id,lesson_id,status,current_block,blocks_viewed,progress_pct) VALUES (:u,:l,:st,1,0,0)");

    foreach($students as $sidRaw){
        $sid=(int)$sidRaw; $en->execute(['u'=>$sid,'c'=>$courseId]); $cp->execute(['u'=>$sid,'c'=>$courseId]);
        foreach($modules as $m){$open=$m['position']===1; $mp->execute(['u'=>$sid,'m'=>$m['id'],'st'=>$open?'available':'locked','dt'=>$open?date('Y-m-d H:i:s'):null]);}
        foreach($phases as $p){
            $moduleOpen=$p['module_position']===1;
            $firstReq=null; foreach($phases as $x){if($x['module_id']===$p['module_id'] && $x['required']){$firstReq=$x['position'];break;}}
            $open=$moduleOpen && (!$p['required'] || $p['position']===$firstReq);
            $pp->execute(['u'=>$sid,'p'=>$p['id'],'st'=>$open?'available':'locked','dt'=>$open?date('Y-m-d H:i:s'):null]);
        }
        foreach($lessons as $l){
            if(!$l['phase_required']){$open=$l['module_position']===1;}
            else {
                $firstReqPhase=null; foreach($phases as $p){if($p['module_id']===$l['module_id'] && $p['required']){$firstReqPhase=$p['position'];break;}}
                $open=$l['module_position']===1 && $l['phase_position']===$firstReqPhase && $l['required'] && $l['position']===1;
            }
            $lp->execute(['u'=>$sid,'l'=>$l['id'],'st'=>$open?'available':'locked']);
        }
    }

    $pdo->commit();
    echo "Importação concluída.\nMódulos: {$counts['m']}\nFases: {$counts['p']}\nBibliotecas: {$counts['lib']}\nAulas: {$counts['l']}\nTelas: {$counts['b']}\n";
} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, "Erro: {$e->getMessage()}\n");
    exit(1);
}
