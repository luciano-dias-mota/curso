<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
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

$pdo = Database::connection();

$tables = [
    'exercise_sessions',
    'exercise_session_questions',
    'exercise_answers',
    'study_notes',
];

printf("=============================================================\n");
printf(" MÓDULOS DE ESTUDO - EXERCÍCIOS + ANOTAÇÕES - V1\n");
printf("=============================================================\n");
printf("Banco: %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Modo:  %s\n\n", $apply ? 'APLICAR' : 'DRY-RUN');

foreach ($tables as $table) {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
         LIMIT 1"
    );
    $stmt->execute(['table_name' => $table]);
    printf("%-30s %s\n", $table, $stmt->fetchColumn() ? 'OK' : 'CRIAR');
}

if (!$apply) {
    echo "\nNenhuma alteração foi realizada.\n";
    echo "Após backup, execute: php database/aplicar_modulos_estudo_v1.php --apply\n";
    if ($isProduction) {
        echo "Em produção: acrescente --force-production após revisar o dry-run.\n";
    }
    exit(0);
}

try {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exercise_sessions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NULL,
            module_id BIGINT UNSIGNED NULL,
            phase_id BIGINT UNSIGNED NULL,
            course_title_snapshot VARCHAR(190) NOT NULL,
            module_title_snapshot VARCHAR(190) NULL,
            phase_title_snapshot VARCHAR(190) NULL,
            question_limit SMALLINT UNSIGNED NOT NULL,
            correct_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            status ENUM('in_progress','finished') NOT NULL DEFAULT 'in_progress',
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY idx_exercise_sessions_user_status (user_id, status, id),
            KEY idx_exercise_sessions_course (course_id),
            KEY idx_exercise_sessions_module (module_id),
            KEY idx_exercise_sessions_phase (phase_id),
            CONSTRAINT fk_exercise_sessions_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_exercise_sessions_course
                FOREIGN KEY (course_id) REFERENCES courses(id)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_exercise_sessions_module
                FOREIGN KEY (module_id) REFERENCES modules(id)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_exercise_sessions_phase
                FOREIGN KEY (phase_id) REFERENCES phases(id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exercise_session_questions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id BIGINT UNSIGNED NOT NULL,
            question_id BIGINT UNSIGNED NULL,
            position SMALLINT UNSIGNED NOT NULL,
            statement_snapshot LONGTEXT NOT NULL,
            explanation_snapshot LONGTEXT NULL,
            source_label_snapshot VARCHAR(255) NULL,
            difficulty_snapshot VARCHAR(20) NOT NULL DEFAULT 'medium',
            alternatives_snapshot LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_exercise_session_position (session_id, position),
            KEY idx_exercise_question_history (question_id, session_id),
            CONSTRAINT fk_exercise_session_questions_session
                FOREIGN KEY (session_id) REFERENCES exercise_sessions(id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_exercise_session_questions_question
                FOREIGN KEY (question_id) REFERENCES questions(id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS exercise_answers (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_question_id BIGINT UNSIGNED NOT NULL,
            selected_alternative_id BIGINT UNSIGNED NULL,
            selected_label VARCHAR(8) NULL,
            is_correct TINYINT(1) NOT NULL DEFAULT 0,
            answered_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_exercise_answer_question (session_question_id),
            CONSTRAINT fk_exercise_answers_session_question
                FOREIGN KEY (session_question_id) REFERENCES exercise_session_questions(id)
                ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS study_notes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            course_id BIGINT UNSIGNED NULL,
            module_id BIGINT UNSIGNED NULL,
            phase_id BIGINT UNSIGNED NULL,
            title VARCHAR(160) NOT NULL,
            content LONGTEXT NOT NULL,
            pinned TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_study_notes_user_updated (user_id, pinned, updated_at),
            KEY idx_study_notes_course (course_id),
            KEY idx_study_notes_module (module_id),
            KEY idx_study_notes_phase (phase_id),
            CONSTRAINT fk_study_notes_user
                FOREIGN KEY (user_id) REFERENCES users(id)
                ON DELETE CASCADE ON UPDATE CASCADE,
            CONSTRAINT fk_study_notes_course
                FOREIGN KEY (course_id) REFERENCES courses(id)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_study_notes_module
                FOREIGN KEY (module_id) REFERENCES modules(id)
                ON DELETE SET NULL ON UPDATE CASCADE,
            CONSTRAINT fk_study_notes_phase
                FOREIGN KEY (phase_id) REFERENCES phases(id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    echo "\n[OK] Tabelas de exercícios criadas/verificadas.\n";
    echo "[OK] Tabela de anotações criada/verificada.\n";
    echo "[OK] Migration concluída.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(1);
}
