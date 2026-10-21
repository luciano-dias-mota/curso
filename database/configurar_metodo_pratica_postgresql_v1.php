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

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Revise o dry-run e use --apply --force-production somente após backup.\n"
    );
    exit(1);
}

$pdo = Database::connection();

$courseSlug = 'postgresql-zero-intermediario';
$lessonSlug = 'banco-de-dados-e-postgresql';

$lessonStmt = $pdo->prepare(
    "SELECT l.id AS lesson_id,
            l.title AS lesson_title,
            l.status AS lesson_status,
            c.id AS course_id,
            c.title AS course_title,
            c.status AS course_status
       FROM lessons l
       INNER JOIN phases p ON p.id = l.phase_id
       INNER JOIN modules m ON m.id = p.module_id
       INNER JOIN courses c ON c.id = m.course_id
      WHERE c.slug = :course_slug
        AND l.slug = :lesson_slug
      LIMIT 1"
);
$lessonStmt->execute([
    'course_slug' => $courseSlug,
    'lesson_slug' => $lessonSlug,
]);
$lesson = $lessonStmt->fetch(PDO::FETCH_ASSOC);

if (!$lesson) {
    fwrite(STDERR, "ABORTADO: aula inicial do PostgreSQL não encontrada.\n");
    exit(2);
}

$guidedStmt = $pdo->prepare(
    "SELECT *
       FROM lab_activities
      WHERE lesson_id = :lesson_id
        AND position = 1
      LIMIT 1"
);
$guidedStmt->execute(['lesson_id' => (int) $lesson['lesson_id']]);
$guided = $guidedStmt->fetch(PDO::FETCH_ASSOC);

if (!$guided) {
    fwrite(STDERR, "ABORTADO: laboratório posição 1 não encontrado para a aula inicial.\n");
    exit(3);
}

$challengeStmt = $pdo->prepare(
    "SELECT id, title, activity_type, status
       FROM lab_activities
      WHERE lesson_id = :lesson_id
        AND position = 2
      LIMIT 1"
);
$challengeStmt->execute(['lesson_id' => (int) $lesson['lesson_id']]);
$challenge = $challengeStmt->fetch(PDO::FETCH_ASSOC) ?: null;

$reinforcementStmt = $pdo->prepare(
    "SELECT id, title, activity_type, status
       FROM lab_activities
      WHERE lesson_id = :lesson_id
        AND position = 3
      LIMIT 1"
);
$reinforcementStmt->execute(['lesson_id' => (int) $lesson['lesson_id']]);
$reinforcement = $reinforcementStmt->fetch(PDO::FETCH_ASSOC) ?: null;

echo "=================================================================\n";
echo " LD ACADEMY - METODO GUIADO + DESAFIO POSTGRESQL V1\n";
echo "=================================================================\n";
printf("Banco:       %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso:       #%d - %s [%s]\n", (int) $lesson['course_id'], (string) $lesson['course_title'], (string) $lesson['course_status']);
printf("Aula:        #%d - %s [%s]\n", (int) $lesson['lesson_id'], (string) $lesson['lesson_title'], (string) $lesson['lesson_status']);
printf("Prática #1:  #%d | tipo=%s | status=%s\n", (int) $guided['id'], (string) $guided['activity_type'], (string) $guided['status']);
printf(
    "Desafio #2: %s\n",
    $challenge
        ? ('#' . (int) $challenge['id'] . ' | tipo=' . (string) $challenge['activity_type'] . ' | status=' . (string) $challenge['status'])
        : 'CRIAR'
);
printf(
    "Reforço #3: %s\n",
    $reinforcement
        ? ('#' . (int) $reinforcement['id'] . ' | tipo=' . (string) $reinforcement['activity_type'] . ' | status=' . (string) $reinforcement['status'])
        : 'CRIAR'
);
printf("Modo:        %s\n", $apply ? 'APLICAR' : 'DRY-RUN');

echo "\nPrática guiada:\n";
echo "  O aluno verá: SELECT 'HD Ferramentas' AS empresa;\n";
echo "  O editor abrirá vazio para ele digitar manualmente.\n";
echo "  Após acertar, o sistema exibirá uma explicação prática do comando.\n";

echo "\nDesafio sem modelo:\n";
echo "  O aluno deverá retornar LD Informática em uma coluna chamada empresa.\n";
echo "  O comando correto NÃO será mostrado na tela.\n";
echo "  Após acertar, o sistema exibirá a explicação e liberará o exercício de reforço.\n";

echo "\nExercício de reforço sem modelo:\n";
echo "  O aluno deverá retornar Tech Center em uma coluna chamada cliente.\n";
echo "  O comando correto NÃO será mostrado na tela.\n";
echo "  Somente após concluir este reforço a próxima aula será liberada.\n";

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup, execute:\n";
    echo "php database/configurar_metodo_pratica_postgresql_v1.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$guidedValidator = [
    'columns' => ['empresa'],
    'rows' => [
        ['empresa' => 'HD Ferramentas'],
    ],
    'row_count' => 1,
    'ordered' => true,
];

$challengeValidator = [
    'columns' => ['empresa'],
    'rows' => [
        ['empresa' => 'LD Informática'],
    ],
    'row_count' => 1,
    'ordered' => true,
];

$reinforcementValidator = [
    'columns' => ['cliente'],
    'rows' => [
        ['cliente' => 'Tech Center'],
    ],
    'row_count' => 1,
    'ordered' => true,
];

$guidedBootstrap = [
    'sql' => 'SELECT 1;',
    'statement_timeout_ms' => 10000,
    'max_result_rows' => 100,
    'success_title' => 'Parabéns, você acertou!',
    'success_explanation' => 'O SELECT é o comando usado para pedir informações ao PostgreSQL. Neste exemplo, você pediu que o banco retornasse o texto HD Ferramentas. O trecho AS empresa deu o nome empresa à coluna exibida no resultado. Isso ajuda a deixar consultas e relatórios mais claros.',
    'practical_context' => 'No dia a dia, DBAs e desenvolvedores usam SELECT para consultar e conferir dados antes de tomar uma decisão ou fazer uma alteração. Por exemplo: SELECT nome, telefone FROM clientes WHERE id = 10; localiza os dados de um cliente específico. Antes de UPDATE ou DELETE, é comum executar um SELECT com o mesmo filtro para confirmar exatamente quais registros serão afetados.',
];

$challengeBootstrap = [
    'sql' => 'SELECT 1;',
    'statement_timeout_ms' => 10000,
    'max_result_rows' => 100,
    'success_title' => 'Muito bem! Você resolveu sem copiar.',
    'success_explanation' => 'Você montou sozinho uma consulta SELECT que retorna um valor e definiu o nome da coluna com AS empresa. Isso confirma que você entendeu a estrutura básica do comando e não apenas reproduziu o exemplo anterior.',
    'practical_context' => 'No trabalho real, essa mesma lógica aparece em consultas muito maiores. Um DBA ou desenvolvedor usa SELECT para escolher o que deseja visualizar e aliases com AS para deixar nomes de colunas mais claros em relatórios, consultas de suporte e resultados enviados para aplicações.',
];

$reinforcementBootstrap = [
    'sql' => 'SELECT 1;',
    'statement_timeout_ms' => 10000,
    'max_result_rows' => 100,
    'success_title' => 'Excelente! Conceito fixado.',
    'success_explanation' => 'Neste reforço você aplicou novamente a estrutura SELECT valor AS alias, mas precisou trocar tanto o valor retornado quanto o nome da coluna. Isso mostra que você compreendeu a lógica do comando e consegue adaptá-la a outra situação.',
    'practical_context' => 'Na rotina profissional, a estrutura de uma consulta é reaproveitada o tempo todo com valores, tabelas, filtros e aliases diferentes. O importante é reconhecer o padrão e saber adaptá-lo ao dado que precisa consultar ou apresentar.',
];

$pdo->beginTransaction();

try {
    $updateGuided = $pdo->prepare(
        "UPDATE lab_activities
            SET activity_type = 'sql_guided',
                title = :title,
                instructions = :instructions,
                starter_code = :starter_code,
                validator_type = 'query_result',
                validator_config = :validator_config,
                bootstrap_config = :bootstrap_config,
                is_required = 1,
                updated_at = NOW()
          WHERE id = :id"
    );
    $updateGuided->execute([
        'title' => 'Primeira consulta guiada',
        'instructions' => 'Observe o comando abaixo. Digite-o manualmente no editor e execute. O objetivo é se acostumar com a estrutura SELECT valor AS nome_da_coluna.',
        'starter_code' => "SELECT 'HD Ferramentas' AS empresa;",
        'validator_config' => json_encode(
            $guidedValidator,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'bootstrap_config' => json_encode(
            $guidedBootstrap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'id' => (int) $guided['id'],
    ]);

    $upsertChallenge = $pdo->prepare(
        "INSERT INTO lab_activities
            (lesson_id, engine_key, activity_type, title, instructions, starter_code,
             validator_type, validator_config, bootstrap_config, unlock_rule,
             state_version, xp_reward, is_required, max_attempts, position, status)
         VALUES
            (:lesson_id, 'postgresql_pglite', 'sql_challenge', :title, :instructions, NULL,
             'query_result', :validator_config, :bootstrap_config, 'reading_complete',
             :state_version, :xp_reward, 1, NULL, 2, :status)
         ON DUPLICATE KEY UPDATE
            engine_key = VALUES(engine_key),
            activity_type = VALUES(activity_type),
            title = VALUES(title),
            instructions = VALUES(instructions),
            starter_code = NULL,
            validator_type = VALUES(validator_type),
            validator_config = VALUES(validator_config),
            bootstrap_config = VALUES(bootstrap_config),
            unlock_rule = VALUES(unlock_rule),
            state_version = VALUES(state_version),
            xp_reward = VALUES(xp_reward),
            is_required = 1,
            max_attempts = NULL,
            status = VALUES(status),
            updated_at = NOW()"
    );
    $upsertChallenge->execute([
        'lesson_id' => (int) $lesson['lesson_id'],
        'title' => 'Desafio: agora sem copiar o exemplo',
        'instructions' => 'Sem o comando pronto: escreva uma consulta que retorne o texto LD Informática em uma coluna chamada empresa.',
        'validator_config' => json_encode(
            $challengeValidator,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'bootstrap_config' => json_encode(
            $challengeBootstrap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'state_version' => max(1, (int) $guided['state_version']),
        'xp_reward' => max(20, (int) $guided['xp_reward']),
        'status' => (string) $guided['status'],
    ]);

    $challengeIdStmt = $pdo->prepare(
        "SELECT id
           FROM lab_activities
          WHERE lesson_id = :lesson_id
            AND position = 2
          LIMIT 1"
    );
    $challengeIdStmt->execute(['lesson_id' => (int) $lesson['lesson_id']]);
    $challengeId = (int) ($challengeIdStmt->fetchColumn() ?: 0);

    if ($challengeId <= 0) {
        throw new RuntimeException('Não foi possível localizar o desafio criado.');
    }

    $upsertReinforcement = $pdo->prepare(
        "INSERT INTO lab_activities
            (lesson_id, engine_key, activity_type, title, instructions, starter_code,
             validator_type, validator_config, bootstrap_config, unlock_rule,
             state_version, xp_reward, is_required, max_attempts, position, status)
         VALUES
            (:lesson_id, 'postgresql_pglite', 'sql_challenge', :title, :instructions, NULL,
             'query_result', :validator_config, :bootstrap_config, 'reading_complete',
             :state_version, :xp_reward, 1, NULL, 3, :status)
         ON DUPLICATE KEY UPDATE
            engine_key = VALUES(engine_key),
            activity_type = VALUES(activity_type),
            title = VALUES(title),
            instructions = VALUES(instructions),
            starter_code = NULL,
            validator_type = VALUES(validator_type),
            validator_config = VALUES(validator_config),
            bootstrap_config = VALUES(bootstrap_config),
            unlock_rule = VALUES(unlock_rule),
            state_version = VALUES(state_version),
            xp_reward = VALUES(xp_reward),
            is_required = 1,
            max_attempts = NULL,
            status = VALUES(status),
            updated_at = NOW()"
    );
    $upsertReinforcement->execute([
        'lesson_id' => (int) $lesson['lesson_id'],
        'title' => 'Reforço: aplique o mesmo conceito sozinho',
        'instructions' => 'Sem consultar um código pronto: escreva uma consulta que retorne o texto Tech Center em uma coluna chamada cliente.',
        'validator_config' => json_encode(
            $reinforcementValidator,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'bootstrap_config' => json_encode(
            $reinforcementBootstrap,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ),
        'state_version' => max(1, (int) $guided['state_version']),
        'xp_reward' => max(20, (int) $guided['xp_reward']),
        'status' => (string) $guided['status'],
    ]);

    $reinforcementIdStmt = $pdo->prepare(
        "SELECT id
           FROM lab_activities
          WHERE lesson_id = :lesson_id
            AND position = 3
          LIMIT 1"
    );
    $reinforcementIdStmt->execute(['lesson_id' => (int) $lesson['lesson_id']]);
    $reinforcementId = (int) ($reinforcementIdStmt->fetchColumn() ?: 0);

    if ($reinforcementId <= 0) {
        throw new RuntimeException('Não foi possível localizar o exercício de reforço criado.');
    }

    // Desafios sem modelo devem ser resolvidos sem dicas prontas.
    $deleteHints = $pdo->prepare(
        'DELETE FROM lab_activity_hints WHERE activity_id = :activity_id'
    );
    $deleteHints->execute(['activity_id' => $challengeId]);
    $deleteHints->execute(['activity_id' => $reinforcementId]);

    $pdo->commit();

    echo "\n[OK] Prática guiada configurada na atividade #" . (int) $guided['id'] . ".\n";
    echo "[OK] Desafio sem modelo configurado na atividade #{$challengeId}.\n";
    echo "[OK] Reforço sem modelo configurado na atividade #{$reinforcementId}.\n";
    echo "[OK] A próxima aula só será concluída/liberada após as 3 atividades obrigatórias.\n";
    echo "[OK] O status atual do laboratório foi preservado: " . (string) $guided['status'] . ".\n";
    echo "[OK] Nenhum conteúdo do curso PMMT foi alterado.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(4);
}
