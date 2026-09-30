-- ============================================================================
-- PMMT ACADEMY - database/schema.sql
-- PHP MVC + MySQL/MariaDB
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS curso
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE curso;

-- ============================================================================
-- ACESSO E USUÁRIOS
-- ============================================================================

CREATE TABLE roles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    slug VARCHAR(50) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    avatar VARCHAR(255) NULL,
    status ENUM('active','inactive','blocked') NOT NULL DEFAULT 'active',
    theme ENUM('light','dark','system') NOT NULL DEFAULT 'dark',
    xp_total INT UNSIGNED NOT NULL DEFAULT 0,
    current_level INT UNSIGNED NOT NULL DEFAULT 1,
    current_streak INT UNSIGNED NOT NULL DEFAULT 0,
    best_streak INT UNSIGNED NOT NULL DEFAULT 0,
    last_study_date DATE NULL,
    last_login_at DATETIME NULL,
    email_verified_at DATETIME NULL,
    remember_token VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    INDEX idx_users_role_status (role_id, status),
    INDEX idx_users_last_study (last_study_date)
) ENGINE=InnoDB;

CREATE TABLE password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_password_reset_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_password_reset_expires (expires_at)
) ENGINE=InnoDB;

-- ============================================================================
-- CURSO > MÓDULO > FASE > AULA > TELA/BLOCO
-- ============================================================================

CREATE TABLE courses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL UNIQUE,
    short_description VARCHAR(500) NULL,
    description LONGTEXT NULL,
    cover_image VARCHAR(255) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    difficulty ENUM('beginner','intermediate','advanced') NOT NULL DEFAULT 'intermediate',
    required_score DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
    position INT UNSIGNED NOT NULL DEFAULT 1,
    published_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_courses_created_by FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_courses_status_position (status, position)
) ENGINE=InnoDB;

CREATE TABLE modules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description TEXT NULL,
    icon VARCHAR(100) NULL,
    position INT UNSIGNED NOT NULL DEFAULT 1,
    required_score DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_modules_course FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_modules_course_slug (course_id, slug),
    UNIQUE KEY uq_modules_course_position (course_id, position),
    INDEX idx_modules_course_status (course_id, status)
) ENGINE=InnoDB;

CREATE TABLE phases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    module_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    description TEXT NULL,
    phase_type ENUM('normal','checkpoint','boss','review') NOT NULL DEFAULT 'normal',
    position INT UNSIGNED NOT NULL DEFAULT 1,
    required_score DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    required_lessons_pct DECIMAL(5,2) NOT NULL DEFAULT 100.00,
    max_attempts INT UNSIGNED NULL,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 50,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_phases_module FOREIGN KEY (module_id) REFERENCES modules(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_phases_module_slug (module_id, slug),
    UNIQUE KEY uq_phases_module_position (module_id, position),
    INDEX idx_phases_module_status (module_id, status)
) ENGINE=InnoDB;

CREATE TABLE lessons (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phase_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    slug VARCHAR(210) NOT NULL,
    summary TEXT NULL,
    position INT UNSIGNED NOT NULL DEFAULT 1,
    estimated_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 10,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 10,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lessons_phase FOREIGN KEY (phase_id) REFERENCES phases(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_lessons_phase_slug (phase_id, slug),
    UNIQUE KEY uq_lessons_phase_position (phase_id, position),
    INDEX idx_lessons_phase_status (phase_id, status)
) ENGINE=InnoDB;

-- Cada registro abaixo é UMA TELA de aula.
-- Assim o estudante usa Anterior/Próximo sem precisar rolar uma página enorme.
CREATE TABLE lesson_blocks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    lesson_id BIGINT UNSIGNED NOT NULL,
    block_type ENUM(
        'theory','example','warning','summary','comparison',
        'memory','case_study','image','video','question'
    ) NOT NULL DEFAULT 'theory',
    title VARCHAR(200) NULL,
    content LONGTEXT NOT NULL,
    media_url VARCHAR(500) NULL,
    position INT UNSIGNED NOT NULL DEFAULT 1,
    is_required TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lesson_blocks_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_lesson_blocks_position (lesson_id, position),
    INDEX idx_lesson_blocks_type (lesson_id, block_type)
) ENGINE=InnoDB;

CREATE TABLE enrollments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    CONSTRAINT fk_enrollments_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_enrollments_course FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_enrollment_user_course (user_id, course_id),
    INDEX idx_enrollments_course_status (course_id, status)
) ENGINE=InnoDB;

-- ============================================================================
-- BANCO DE QUESTÕES
-- ============================================================================

CREATE TABLE questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NULL,
    module_id BIGINT UNSIGNED NULL,
    phase_id BIGINT UNSIGNED NULL,
    lesson_id BIGINT UNSIGNED NULL,
    question_type ENUM('multiple_choice','true_false') NOT NULL DEFAULT 'multiple_choice',
    statement LONGTEXT NOT NULL,
    explanation LONGTEXT NULL,
    difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
    source_label VARCHAR(255) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_questions_course FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_questions_module FOREIGN KEY (module_id) REFERENCES modules(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_questions_phase FOREIGN KEY (phase_id) REFERENCES phases(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_questions_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_questions_created_by FOREIGN KEY (created_by) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_questions_scope (course_id, module_id, phase_id, lesson_id),
    INDEX idx_questions_active_difficulty (active, difficulty)
) ENGINE=InnoDB;

CREATE TABLE alternatives (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    label VARCHAR(5) NULL,
    text LONGTEXT NOT NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    explanation LONGTEXT NULL,
    position TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_alternatives_question FOREIGN KEY (question_id) REFERENCES questions(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_alternatives_question_position (question_id, position),
    INDEX idx_alternatives_correct (question_id, is_correct)
) ENGINE=InnoDB;

-- ============================================================================
-- QUIZZES / PROVAS DE FASE / BOSS
-- ============================================================================

CREATE TABLE quizzes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NULL,
    module_id BIGINT UNSIGNED NULL,
    phase_id BIGINT UNSIGNED NULL,
    lesson_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    quiz_type ENUM('lesson_fixation','phase_exam','module_boss','review') NOT NULL,
    required_score DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    question_limit SMALLINT UNSIGNED NULL,
    time_limit_minutes SMALLINT UNSIGNED NULL,
    shuffle_questions TINYINT(1) NOT NULL DEFAULT 1,
    shuffle_answers TINYINT(1) NOT NULL DEFAULT 1,
    show_feedback TINYINT(1) NOT NULL DEFAULT 1,
    max_attempts INT UNSIGNED NULL,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_quizzes_course FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_quizzes_module FOREIGN KEY (module_id) REFERENCES modules(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_quizzes_phase FOREIGN KEY (phase_id) REFERENCES phases(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_quizzes_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_quizzes_scope (course_id, module_id, phase_id, lesson_id, active)
) ENGINE=InnoDB;

CREATE TABLE quiz_questions (
    quiz_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    points DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    PRIMARY KEY (quiz_id, question_id),
    CONSTRAINT fk_quiz_questions_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_quiz_questions_question FOREIGN KEY (question_id) REFERENCES questions(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_quiz_questions_position (quiz_id, position)
) ENGINE=InnoDB;

CREATE TABLE quiz_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    quiz_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    attempt_number INT UNSIGNED NOT NULL DEFAULT 1,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    max_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    passed TINYINT(1) NOT NULL DEFAULT 0,
    xp_earned INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('in_progress','finished','abandoned') NOT NULL DEFAULT 'in_progress',
    CONSTRAINT fk_quiz_attempts_quiz FOREIGN KEY (quiz_id) REFERENCES quizzes(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_quiz_attempts_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_quiz_attempt_number (quiz_id, user_id, attempt_number),
    INDEX idx_quiz_attempts_user_status (user_id, status),
    INDEX idx_quiz_attempts_quiz_passed (quiz_id, passed)
) ENGINE=InnoDB;

CREATE TABLE quiz_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    alternative_id BIGINT UNSIGNED NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    points_awarded DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_quiz_answers_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_quiz_answers_question FOREIGN KEY (question_id) REFERENCES questions(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_quiz_answers_alternative FOREIGN KEY (alternative_id) REFERENCES alternatives(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_attempt_question (attempt_id, question_id),
    INDEX idx_quiz_answers_correct (attempt_id, is_correct)
) ENGINE=InnoDB;

-- ============================================================================
-- PROGRESSO
-- ============================================================================

CREATE TABLE user_lesson_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    status ENUM('locked','available','in_progress','completed') NOT NULL DEFAULT 'locked',
    current_block INT UNSIGNED NOT NULL DEFAULT 1,
    blocks_viewed INT UNSIGNED NOT NULL DEFAULT 0,
    progress_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_accessed_at DATETIME NULL,
    CONSTRAINT fk_user_lesson_progress_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_lesson_progress_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_lesson_progress (user_id, lesson_id),
    INDEX idx_user_lesson_status (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE user_lesson_block_views (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_block_id BIGINT UNSIGNED NOT NULL,
    first_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_viewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    view_count INT UNSIGNED NOT NULL DEFAULT 1,
    CONSTRAINT fk_block_views_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_block_views_block FOREIGN KEY (lesson_block_id) REFERENCES lesson_blocks(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_block_view (user_id, lesson_block_id)
) ENGINE=InnoDB;

CREATE TABLE user_phase_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    phase_id BIGINT UNSIGNED NOT NULL,
    status ENUM('locked','available','in_progress','completed') NOT NULL DEFAULT 'locked',
    best_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    attempts_count INT UNSIGNED NOT NULL DEFAULT 0,
    progress_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    unlocked_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    CONSTRAINT fk_user_phase_progress_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_phase_progress_phase FOREIGN KEY (phase_id) REFERENCES phases(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_phase_progress (user_id, phase_id),
    INDEX idx_user_phase_status (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE user_module_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    module_id BIGINT UNSIGNED NOT NULL,
    status ENUM('locked','available','in_progress','completed') NOT NULL DEFAULT 'locked',
    best_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    progress_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    unlocked_at DATETIME NULL,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    CONSTRAINT fk_user_module_progress_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_module_progress_module FOREIGN KEY (module_id) REFERENCES modules(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_module_progress (user_id, module_id),
    INDEX idx_user_module_status (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE user_course_progress (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    status ENUM('not_started','in_progress','completed') NOT NULL DEFAULT 'not_started',
    progress_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    average_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_accessed_at DATETIME NULL,
    CONSTRAINT fk_user_course_progress_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_course_progress_course FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_course_progress (user_id, course_id),
    INDEX idx_user_course_status (user_id, status)
) ENGINE=InnoDB;

-- ============================================================================
-- GAMIFICAÇÃO
-- ============================================================================

CREATE TABLE levels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    level_number INT UNSIGNED NOT NULL UNIQUE,
    title VARCHAR(100) NOT NULL,
    min_xp INT UNSIGNED NOT NULL,
    max_xp INT UNSIGNED NULL,
    icon VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE xp_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    event_type ENUM(
        'lesson_completed','phase_completed','module_completed',
        'quiz_passed','simulation_completed','achievement',
        'streak_bonus','admin_adjustment'
    ) NOT NULL,
    reference_id BIGINT UNSIGNED NULL,
    xp_amount INT NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_xp_events_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_xp_events_user_date (user_id, created_at),
    INDEX idx_xp_events_type_reference (event_type, reference_id)
) ENGINE=InnoDB;

CREATE TABLE achievements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(160) NOT NULL UNIQUE,
    description VARCHAR(500) NOT NULL,
    icon VARCHAR(255) NULL,
    achievement_type ENUM(
        'lesson','phase','module','score','streak','xp','simulation','special'
    ) NOT NULL,
    requirement_value INT UNSIGNED NULL,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE user_achievements (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    achievement_id BIGINT UNSIGNED NOT NULL,
    unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_achievements_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES achievements(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_achievement (user_id, achievement_id)
) ENGINE=InnoDB;

CREATE TABLE study_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NULL,
    lesson_id BIGINT UNSIGNED NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ended_at DATETIME NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_study_sessions_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_study_sessions_course FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_study_sessions_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_study_sessions_user_started (user_id, started_at)
) ENGINE=InnoDB;

-- ============================================================================
-- SIMULADOS GERAIS
-- ============================================================================

CREATE TABLE simulations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    required_score DECIMAL(5,2) NOT NULL DEFAULT 70.00,
    question_limit SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    time_limit_minutes SMALLINT UNSIGNED NULL,
    shuffle_questions TINYINT(1) NOT NULL DEFAULT 1,
    shuffle_answers TINYINT(1) NOT NULL DEFAULT 1,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_simulations_course FOREIGN KEY (course_id) REFERENCES courses(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_simulations_course_active (course_id, active)
) ENGINE=InnoDB;

CREATE TABLE simulation_questions (
    simulation_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    position SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    points DECIMAL(8,2) NOT NULL DEFAULT 1.00,
    PRIMARY KEY (simulation_id, question_id),
    CONSTRAINT fk_simulation_questions_simulation FOREIGN KEY (simulation_id) REFERENCES simulations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_simulation_questions_question FOREIGN KEY (question_id) REFERENCES questions(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_simulation_questions_position (simulation_id, position)
) ENGINE=InnoDB;

CREATE TABLE simulation_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    simulation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    attempt_number INT UNSIGNED NOT NULL DEFAULT 1,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at DATETIME NULL,
    score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    max_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    passed TINYINT(1) NOT NULL DEFAULT 0,
    xp_earned INT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('in_progress','finished','abandoned') NOT NULL DEFAULT 'in_progress',
    CONSTRAINT fk_simulation_attempts_simulation FOREIGN KEY (simulation_id) REFERENCES simulations(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_simulation_attempts_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_simulation_attempt_number (simulation_id, user_id, attempt_number),
    INDEX idx_sim_attempts_user_status (user_id, status)
) ENGINE=InnoDB;

CREATE TABLE simulation_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    attempt_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    alternative_id BIGINT UNSIGNED NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    points_awarded DECIMAL(8,2) NOT NULL DEFAULT 0.00,
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_sim_answers_attempt FOREIGN KEY (attempt_id) REFERENCES simulation_attempts(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sim_answers_question FOREIGN KEY (question_id) REFERENCES questions(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_sim_answers_alternative FOREIGN KEY (alternative_id) REFERENCES alternatives(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    UNIQUE KEY uq_sim_attempt_question (attempt_id, question_id)
) ENGINE=InnoDB;

-- ============================================================================
-- RECURSOS DO ESTUDANTE
-- ============================================================================

CREATE TABLE user_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NULL,
    lesson_block_id BIGINT UNSIGNED NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_notes_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_notes_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_notes_block FOREIGN KEY (lesson_block_id) REFERENCES lesson_blocks(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    INDEX idx_user_notes_user_lesson (user_id, lesson_id)
) ENGINE=InnoDB;

CREATE TABLE user_favorites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_user_favorites_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_user_favorites_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    UNIQUE KEY uq_user_favorite_lesson (user_id, lesson_id)
) ENGINE=InnoDB;

-- ============================================================================
-- CONFIGURAÇÕES E AUDITORIA
-- ============================================================================

CREATE TABLE system_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(120) NOT NULL UNIQUE,
    setting_value TEXT NULL,
    value_type ENUM('string','integer','decimal','boolean','json') NOT NULL DEFAULT 'string',
    description VARCHAR(255) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id BIGINT UNSIGNED NULL,
    description TEXT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES users(id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    INDEX idx_audit_logs_entity (entity_type, entity_id),
    INDEX idx_audit_logs_user_date (user_id, created_at)
) ENGINE=InnoDB;

-- ============================================================================
-- DADOS INICIAIS
-- ============================================================================

INSERT INTO roles (name, slug, description) VALUES
('Administrador', 'admin', 'Acesso completo ao painel administrativo.'),
('Estudante', 'student', 'Acesso ao ambiente de estudo e progressão.');

INSERT INTO levels (level_number, title, min_xp, max_xp) VALUES
(1, 'Recruta', 0, 499),
(2, 'Aluno', 500, 1199),
(3, 'Combatente', 1200, 2199),
(4, 'Especialista', 2200, 3499),
(5, 'Veterano', 3500, 4999),
(6, 'Elite', 5000, 6999),
(7, 'Estrategista', 7000, 9499),
(8, 'Mestre da Missão', 9500, 12499),
(9, 'Comandante', 12500, 15999),
(10, 'Lenda da Aprovação', 16000, NULL);

INSERT INTO achievements
(name, slug, description, achievement_type, requirement_value, xp_reward) VALUES
('Primeira Missão', 'primeira-missao', 'Conclua sua primeira aula.', 'lesson', 1, 25),
('Primeira Fase', 'primeira-fase', 'Conclua sua primeira fase.', 'phase', 1, 50),
('Módulo Dominado', 'modulo-dominado', 'Conclua seu primeiro módulo.', 'module', 1, 100),
('Nota Máxima', 'nota-maxima', 'Obtenha 100% em uma prova ou simulado.', 'score', 100, 150),
('Foco de 7 Dias', 'foco-7-dias', 'Mantenha uma sequência de estudos por 7 dias.', 'streak', 7, 100),
('Foco de 30 Dias', 'foco-30-dias', 'Mantenha uma sequência de estudos por 30 dias.', 'streak', 30, 500),
('10K XP', '10k-xp', 'Alcance 10.000 XP.', 'xp', 10000, 250),
('Simulado Vencido', 'simulado-vencido', 'Seja aprovado em um simulado geral.', 'simulation', 1, 200);

INSERT INTO system_settings
(setting_key, setting_value, value_type, description) VALUES
('platform_name', 'PMMT Academy', 'string', 'Nome público da plataforma.'),
('default_theme', 'dark', 'string', 'Tema padrão para novos usuários.'),
('default_passing_score', '70', 'decimal', 'Nota mínima padrão de aprovação.'),
('enable_ranking', '1', 'boolean', 'Ativa o ranking geral.'),
('enable_streak', '1', 'boolean', 'Ativa a sequência diária de estudos.');

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- REGRAS QUE DEVEM SER IMPLEMENTADAS NO BACKEND
-- ============================================================================
-- 1. O estudante conclui todos os blocos obrigatórios da aula.
-- 2. A aula passa a completed.
-- 3. Ao concluir as aulas exigidas, a prova da fase é liberada.
-- 4. QuizService calcula a nota no servidor.
-- 5. Se nota >= phases.required_score:
--      - fase = completed
--      - registra XP
--      - atualiza nível
--      - libera próxima fase
-- 6. Última fase concluída -> recalcula módulo.
-- 7. Último módulo concluído -> recalcula curso.
--
-- SEGURANÇA:
-- - password_hash()/password_verify()
-- - PDO + prepared statements
-- - CSRF em POST/PUT/PATCH/DELETE
-- - session_regenerate_id(true) após login
-- - middleware admin/student
-- - PhaseUnlockedMiddleware em fases/aulas
-- - score e XP SEMPRE calculados pelo servidor
-- ============================================================================
