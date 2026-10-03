<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

final class SimulationService
{
    private const ALLOWED_LIMITS = [10, 20, 30, 50];
    private const REQUIRED_SCORE = 70.0;
    private const MIN_ALTERNATIVES = 4;

    public function dashboard(int $userId): array
    {
        $pdo = Database::connection();
        $this->finishExpiredAttempts($pdo, $userId);

        $activeStmt = $pdo->prepare(
            "SELECT sa.id, sa.started_at, sa.question_limit, sa.time_limit_minutes,
                    sa.selection_mode, sa.scope_module_id,
                    s.title AS simulation_title, c.title AS course_title,
                    m.title AS module_title,
                    COALESCE(ans.answered, 0) AS answered
             FROM simulation_attempts sa
             INNER JOIN simulations s ON s.id = sa.simulation_id
             LEFT JOIN courses c ON c.id = s.course_id
             LEFT JOIN modules m ON m.id = sa.scope_module_id
             LEFT JOIN (
                SELECT attempt_id, COUNT(*) AS answered
                FROM simulation_answers
                GROUP BY attempt_id
             ) ans ON ans.attempt_id = sa.id
             WHERE sa.user_id = :user_id
               AND sa.status = 'in_progress'
             ORDER BY sa.started_at DESC"
        );
        $activeStmt->execute(['user_id' => $userId]);
        $active = $activeStmt->fetchAll();

        foreach ($active as &$row) {
            $row['remaining_seconds'] = $this->remainingSeconds($row);
        }
        unset($row);

        $historyStmt = $pdo->prepare(
            "SELECT sa.id, sa.attempt_number, sa.started_at, sa.finished_at,
                    sa.question_limit, sa.score, sa.max_score, sa.percentage,
                    sa.passed, sa.selection_mode,
                    c.title AS course_title,
                    m.title AS module_title
             FROM simulation_attempts sa
             INNER JOIN simulations s ON s.id = sa.simulation_id
             LEFT JOIN courses c ON c.id = s.course_id
             LEFT JOIN modules m ON m.id = sa.scope_module_id
             WHERE sa.user_id = :user_id
               AND sa.status = 'finished'
             ORDER BY sa.finished_at DESC, sa.id DESC
             LIMIT 30"
        );
        $historyStmt->execute(['user_id' => $userId]);
        $history = $historyStmt->fetchAll();

        $stats = [
            'attempts' => count($history),
            'average' => 0.0,
            'best' => 0.0,
            'latest' => 0.0,
            'questions' => 0,
            'trend' => 0.0,
        ];

        if ($history !== []) {
            $sum = 0.0;
            $best = 0.0;
            $questions = 0;
            foreach ($history as $row) {
                $percentage = (float) $row['percentage'];
                $sum += $percentage;
                $best = max($best, $percentage);
                $questions += (int) $row['question_limit'];
            }

            $stats['average'] = round($sum / count($history), 1);
            $stats['best'] = round($best, 1);
            $stats['latest'] = round((float) $history[0]['percentage'], 1);
            $stats['questions'] = $questions;
            if (isset($history[1])) {
                $stats['trend'] = round(
                    (float) $history[0]['percentage'] - (float) $history[1]['percentage'],
                    1
                );
            }
        }

        $subjectStmt = $pdo->prepare(
            "SELECT COALESCE(m.id, 0) AS module_id,
                    COALESCE(m.title, 'Questões gerais') AS module_title,
                    COUNT(*) AS total,
                    SUM(CASE WHEN COALESCE(sa2.is_correct, 0) = 1 THEN 1 ELSE 0 END) AS correct
             FROM simulation_attempt_questions saq
             INNER JOIN simulation_attempts sa ON sa.id = saq.attempt_id
             INNER JOIN questions q ON q.id = saq.question_id
             LEFT JOIN modules m ON m.id = q.module_id
             LEFT JOIN simulation_answers sa2
               ON sa2.attempt_id = sa.id
              AND sa2.question_id = q.id
             WHERE sa.user_id = :user_id
               AND sa.status = 'finished'
             GROUP BY m.id, m.title, m.position
             ORDER BY m.position, m.id"
        );
        $subjectStmt->execute(['user_id' => $userId]);
        $subjects = $subjectStmt->fetchAll();

        foreach ($subjects as &$row) {
            $total = (int) $row['total'];
            $correct = (int) $row['correct'];
            $row['percentage'] = $total > 0 ? round(($correct / $total) * 100, 1) : 0.0;
        }
        unset($row);

        $chart = array_reverse(array_slice($history, 0, 10));

        return [
            'activeAttempts' => $active,
            'history' => $history,
            'stats' => $stats,
            'subjects' => $subjects,
            'chart' => $chart,
        ];
    }

    public function configuration(int $userId): array
    {
        $pdo = Database::connection();

        $courseStmt = $pdo->prepare(
            "SELECT c.id, c.title,
                    SUM(CASE WHEN vp.difficulty = 'medium' THEN 1 ELSE 0 END) AS medium_count,
                    SUM(CASE WHEN vp.difficulty = 'hard' THEN 1 ELSE 0 END) AS hard_count,
                    COUNT(vp.id) AS total_count
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN (
                SELECT q.id, q.course_id, q.module_id, q.difficulty
                FROM questions q
                INNER JOIN (
                    SELECT question_id,
                           COUNT(*) AS alternatives_count,
                           SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                    FROM alternatives
                    GROUP BY question_id
                ) qa ON qa.question_id = q.id
                WHERE q.active = 1
                  AND q.question_type = 'multiple_choice'
                  AND q.difficulty IN ('medium', 'hard')
                  AND qa.alternatives_count >= " . self::MIN_ALTERNATIVES . "
                  AND qa.correct_count = 1
             ) vp ON vp.course_id = c.id
             WHERE e.user_id = :user_id
               AND e.status = 'active'
               AND c.status = 'published'
             GROUP BY c.id, c.title, c.position
             ORDER BY c.position, c.id"
        );
        $courseStmt->execute(['user_id' => $userId]);
        $courses = $courseStmt->fetchAll();

        if ($courses === []) {
            throw new RuntimeException('Nenhum curso ativo foi encontrado para este estudante.');
        }

        $courseIds = array_map('intval', array_column($courses, 'id'));
        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

        $moduleStmt = $pdo->prepare(
            "SELECT m.id, m.course_id, m.title, m.position,
                    SUM(CASE WHEN vp.difficulty = 'medium' THEN 1 ELSE 0 END) AS medium_count,
                    SUM(CASE WHEN vp.difficulty = 'hard' THEN 1 ELSE 0 END) AS hard_count,
                    COUNT(vp.id) AS total_count
             FROM modules m
             LEFT JOIN (
                SELECT q.id, q.module_id, q.difficulty
                FROM questions q
                INNER JOIN (
                    SELECT question_id,
                           COUNT(*) AS alternatives_count,
                           SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                    FROM alternatives
                    GROUP BY question_id
                ) qa ON qa.question_id = q.id
                WHERE q.active = 1
                  AND q.question_type = 'multiple_choice'
                  AND q.difficulty IN ('medium', 'hard')
                  AND qa.alternatives_count >= " . self::MIN_ALTERNATIVES . "
                  AND qa.correct_count = 1
             ) vp ON vp.module_id = m.id
             WHERE m.course_id IN ({$placeholders})
               AND m.status = 'published'
             GROUP BY m.id, m.course_id, m.title, m.position
             ORDER BY m.course_id, m.position, m.id"
        );
        $moduleStmt->execute($courseIds);

        return [
            'courses' => $courses,
            'modules' => $moduleStmt->fetchAll(),
            'allowedLimits' => self::ALLOWED_LIMITS,
            'requiredScore' => self::REQUIRED_SCORE,
        ];
    }

    public function generate(
        int $userId,
        int $courseId,
        int $questionLimit,
        ?int $moduleId = null
    ): int {
        if (!in_array($questionLimit, self::ALLOWED_LIMITS, true)) {
            throw new RuntimeException('Quantidade de questões inválida.');
        }

        $pdo = Database::connection();
        $this->assertEnrollment($pdo, $userId, $courseId);
        $this->finishExpiredAttempts($pdo, $userId);

        if ($moduleId !== null && $moduleId > 0) {
            $moduleStmt = $pdo->prepare(
                "SELECT id
                 FROM modules
                 WHERE id = :module_id
                   AND course_id = :course_id
                   AND status = 'published'
                 LIMIT 1"
            );
            $moduleStmt->execute([
                'module_id' => $moduleId,
                'course_id' => $courseId,
            ]);
            if (!$moduleStmt->fetchColumn()) {
                throw new RuntimeException('Módulo inválido para o curso selecionado.');
            }
        } else {
            $moduleId = null;
        }

        $existingStmt = $pdo->prepare(
            "SELECT sa.id
             FROM simulation_attempts sa
             INNER JOIN simulations s ON s.id = sa.simulation_id
             WHERE sa.user_id = :user_id
               AND s.course_id = :course_id
               AND sa.status = 'in_progress'
             ORDER BY sa.id DESC
             LIMIT 1"
        );
        $existingStmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);
        $existingId = (int) ($existingStmt->fetchColumn() ?: 0);
        if ($existingId > 0) {
            return $existingId;
        }

        $candidates = $this->questionCandidates($pdo, $userId, $courseId, $moduleId);
        if (count($candidates) < $questionLimit) {
            throw new RuntimeException(
                'O banco possui apenas ' . count($candidates)
                . ' questões válidas para este filtro. Escolha uma quantidade menor ou outro conteúdo.'
            );
        }

        $picked = $this->selectBalancedQuestions($candidates, $questionLimit);
        if (count($picked) !== $questionLimit) {
            throw new RuntimeException('Não foi possível montar o simulado com o balanceamento solicitado.');
        }

        $timeLimit = $questionLimit * 2;

        $pdo->beginTransaction();
        try {
            $simulationId = $this->ensureSimulation($pdo, $courseId);

            $numberStmt = $pdo->prepare(
                "SELECT COALESCE(MAX(attempt_number), 0) + 1
                 FROM simulation_attempts
                 WHERE simulation_id = :simulation_id
                   AND user_id = :user_id"
            );
            $numberStmt->execute([
                'simulation_id' => $simulationId,
                'user_id' => $userId,
            ]);
            $attemptNumber = (int) $numberStmt->fetchColumn();

            $insertAttempt = $pdo->prepare(
                "INSERT INTO simulation_attempts
                    (simulation_id, user_id, attempt_number,
                     question_limit, time_limit_minutes, scope_module_id, selection_mode,
                     started_at, score, max_score, percentage, passed, xp_earned, status)
                 VALUES
                    (:simulation_id, :user_id, :attempt_number,
                     :question_limit, :time_limit_minutes, :scope_module_id, :selection_mode,
                     NOW(), 0, :max_score, 0, 0, 0, 'in_progress')"
            );
            $insertAttempt->execute([
                'simulation_id' => $simulationId,
                'user_id' => $userId,
                'attempt_number' => $attemptNumber,
                'question_limit' => $questionLimit,
                'time_limit_minutes' => $timeLimit,
                'scope_module_id' => $moduleId,
                'selection_mode' => $moduleId ? 'module' : 'general',
                'max_score' => $questionLimit,
            ]);
            $attemptId = (int) $pdo->lastInsertId();

            shuffle($picked);
            $insertQuestion = $pdo->prepare(
                "INSERT INTO simulation_attempt_questions
                    (attempt_id, question_id, position, points)
                 VALUES
                    (:attempt_id, :question_id, :position, 1)"
            );

            foreach ($picked as $position => $question) {
                $insertQuestion->execute([
                    'attempt_id' => $attemptId,
                    'question_id' => (int) $question['id'],
                    'position' => $position + 1,
                ]);
            }

            $pdo->commit();
            return $attemptId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function attemptData(int $attemptId, int $userId): array
    {
        $pdo = Database::connection();
        $attempt = $this->attemptBase($pdo, $attemptId, $userId);

        if ($attempt['status'] === 'in_progress' && $this->remainingSeconds($attempt) <= 0) {
            $this->finishAttempt($attemptId, $userId);
            $attempt = $this->attemptBase($pdo, $attemptId, $userId);
        }

        if ($attempt['status'] !== 'in_progress') {
            return $attempt;
        }

        $questionStmt = $pdo->prepare(
            "SELECT saq.position, q.id, q.statement, q.difficulty,
                    COALESCE(m.title, 'Conteúdo geral') AS module_title,
                    ans.alternative_id AS selected_alternative_id
             FROM simulation_attempt_questions saq
             INNER JOIN questions q ON q.id = saq.question_id
             LEFT JOIN modules m ON m.id = q.module_id
             LEFT JOIN simulation_answers ans
               ON ans.attempt_id = saq.attempt_id
              AND ans.question_id = q.id
             WHERE saq.attempt_id = :attempt_id
             ORDER BY saq.position"
        );
        $questionStmt->execute(['attempt_id' => $attemptId]);
        $questions = $questionStmt->fetchAll();

        if (count($questions) !== (int) $attempt['question_limit']) {
            throw new RuntimeException('A tentativa possui uma quantidade inconsistente de questões.');
        }

        $ids = array_map('intval', array_column($questions, 'id'));
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $altStmt = $pdo->prepare(
            "SELECT id, question_id, label, text, position
             FROM alternatives
             WHERE question_id IN ({$ph})
             ORDER BY question_id, position, id"
        );
        $altStmt->execute($ids);

        $byQuestion = [];
        foreach ($altStmt->fetchAll() as $alt) {
            $byQuestion[(int) $alt['question_id']][] = $alt;
        }

        foreach ($questions as &$question) {
            $question['alternatives'] = $byQuestion[(int) $question['id']] ?? [];
        }
        unset($question);

        $attempt['questions'] = $questions;
        $attempt['remaining_seconds'] = $this->remainingSeconds($attempt);
        $attempt['answered_count'] = count(array_filter(
            $questions,
            static fn (array $q): bool => !empty($q['selected_alternative_id'])
        ));

        return $attempt;
    }

    public function saveAnswer(
        int $attemptId,
        int $userId,
        int $questionId,
        int $alternativeId
    ): array {
        $pdo = Database::connection();
        $attempt = $this->attemptBase($pdo, $attemptId, $userId);

        if ($attempt['status'] !== 'in_progress') {
            throw new RuntimeException('Esta tentativa não está mais em andamento.');
        }

        if ($this->remainingSeconds($attempt) <= 0) {
            $this->finishAttempt($attemptId, $userId);
            throw new RuntimeException('O tempo do simulado terminou.');
        }

        $questionStmt = $pdo->prepare(
            "SELECT 1
             FROM simulation_attempt_questions
             WHERE attempt_id = :attempt_id
               AND question_id = :question_id
             LIMIT 1"
        );
        $questionStmt->execute([
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
        ]);
        if (!$questionStmt->fetchColumn()) {
            throw new RuntimeException('Questão inválida para esta tentativa.');
        }

        $altStmt = $pdo->prepare(
            "SELECT id, is_correct
             FROM alternatives
             WHERE id = :alternative_id
               AND question_id = :question_id
             LIMIT 1"
        );
        $altStmt->execute([
            'alternative_id' => $alternativeId,
            'question_id' => $questionId,
        ]);
        $alternative = $altStmt->fetch();
        if (!$alternative) {
            throw new RuntimeException('Alternativa inválida.');
        }

        $isCorrect = (int) $alternative['is_correct'] === 1;
        $save = $pdo->prepare(
            "INSERT INTO simulation_answers
                (attempt_id, question_id, alternative_id, is_correct, points_awarded, answered_at)
             VALUES
                (:attempt_id, :question_id, :alternative_id, :is_correct, :points, NOW())
             ON DUPLICATE KEY UPDATE
                alternative_id = VALUES(alternative_id),
                is_correct = VALUES(is_correct),
                points_awarded = VALUES(points_awarded),
                answered_at = NOW()"
        );
        $save->execute([
            'attempt_id' => $attemptId,
            'question_id' => $questionId,
            'alternative_id' => $alternativeId,
            'is_correct' => $isCorrect ? 1 : 0,
            'points' => $isCorrect ? 1 : 0,
        ]);

        $countStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM simulation_answers
             WHERE attempt_id = :attempt_id
               AND alternative_id IS NOT NULL"
        );
        $countStmt->execute(['attempt_id' => $attemptId]);

        return [
            'saved' => true,
            'answered' => (int) $countStmt->fetchColumn(),
            'total' => (int) $attempt['question_limit'],
            'remaining_seconds' => $this->remainingSeconds($attempt),
        ];
    }

    public function finishAttempt(int $attemptId, int $userId): array
    {
        $pdo = Database::connection();
        $attempt = $this->attemptBase($pdo, $attemptId, $userId);

        if ($attempt['status'] === 'finished') {
            return [
                'percentage' => (float) $attempt['percentage'],
                'passed' => (int) $attempt['passed'] === 1,
            ];
        }

        if ($attempt['status'] !== 'in_progress') {
            throw new RuntimeException('Esta tentativa não pode ser finalizada.');
        }

        $scoreStmt = $pdo->prepare(
            "SELECT COUNT(saq.question_id) AS total,
                    SUM(CASE WHEN COALESCE(sa.is_correct, 0) = 1 THEN 1 ELSE 0 END) AS correct,
                    SUM(CASE WHEN sa.alternative_id IS NOT NULL THEN 1 ELSE 0 END) AS answered
             FROM simulation_attempt_questions saq
             LEFT JOIN simulation_answers sa
               ON sa.attempt_id = saq.attempt_id
              AND sa.question_id = saq.question_id
             WHERE saq.attempt_id = :attempt_id"
        );
        $scoreStmt->execute(['attempt_id' => $attemptId]);
        $score = $scoreStmt->fetch();

        $total = (int) ($score['total'] ?? 0);
        $correct = (int) ($score['correct'] ?? 0);
        $answered = (int) ($score['answered'] ?? 0);
        if ($total <= 0) {
            throw new RuntimeException('Não existem questões associadas a esta tentativa.');
        }

        $percentage = round(($correct / $total) * 100, 2);
        $required = (float) $attempt['required_score'];
        $passed = $percentage >= $required;

        $update = $pdo->prepare(
            "UPDATE simulation_attempts
             SET finished_at = NOW(),
                 score = :score,
                 max_score = :max_score,
                 percentage = :percentage,
                 passed = :passed,
                 xp_earned = 0,
                 status = 'finished'
             WHERE id = :attempt_id
               AND user_id = :user_id
               AND status = 'in_progress'"
        );
        $update->execute([
            'score' => $correct,
            'max_score' => $total,
            'percentage' => $percentage,
            'passed' => $passed ? 1 : 0,
            'attempt_id' => $attemptId,
            'user_id' => $userId,
        ]);

        return [
            'correct' => $correct,
            'answered' => $answered,
            'total' => $total,
            'percentage' => $percentage,
            'passed' => $passed,
        ];
    }

    public function resultData(int $attemptId, int $userId): array
    {
        $pdo = Database::connection();
        $attempt = $this->attemptBase($pdo, $attemptId, $userId);

        if ($attempt['status'] === 'in_progress' && $this->remainingSeconds($attempt) <= 0) {
            $this->finishAttempt($attemptId, $userId);
            $attempt = $this->attemptBase($pdo, $attemptId, $userId);
        }

        if ($attempt['status'] !== 'finished') {
            throw new RuntimeException('Finalize o simulado antes de visualizar o resultado.');
        }

        $questionsStmt = $pdo->prepare(
            "SELECT saq.position, q.id, q.statement, q.explanation, q.difficulty,
                    q.source_label,
                    COALESCE(m.id, 0) AS module_id,
                    COALESCE(m.title, 'Conteúdo geral') AS module_title,
                    sa.alternative_id AS selected_alternative_id,
                    COALESCE(sa.is_correct, 0) AS answer_is_correct
             FROM simulation_attempt_questions saq
             INNER JOIN questions q ON q.id = saq.question_id
             LEFT JOIN modules m ON m.id = q.module_id
             LEFT JOIN simulation_answers sa
               ON sa.attempt_id = saq.attempt_id
              AND sa.question_id = q.id
             WHERE saq.attempt_id = :attempt_id
             ORDER BY saq.position"
        );
        $questionsStmt->execute(['attempt_id' => $attemptId]);
        $questions = $questionsStmt->fetchAll();

        $ids = array_map('intval', array_column($questions, 'id'));
        if ($ids !== []) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $altStmt = $pdo->prepare(
                "SELECT id, question_id, label, text, position, is_correct, explanation
                 FROM alternatives
                 WHERE question_id IN ({$ph})
                 ORDER BY question_id, position, id"
            );
            $altStmt->execute($ids);

            $byQuestion = [];
            foreach ($altStmt->fetchAll() as $alt) {
                $byQuestion[(int) $alt['question_id']][] = $alt;
            }

            foreach ($questions as &$question) {
                $question['alternatives'] = $byQuestion[(int) $question['id']] ?? [];
            }
            unset($question);
        }

        $performance = [];
        foreach ($questions as $question) {
            $key = (int) $question['module_id'];
            if (!isset($performance[$key])) {
                $performance[$key] = [
                    'module_id' => $key,
                    'module_title' => $question['module_title'],
                    'total' => 0,
                    'correct' => 0,
                    'percentage' => 0.0,
                ];
            }
            $performance[$key]['total']++;
            if ((int) $question['answer_is_correct'] === 1) {
                $performance[$key]['correct']++;
            }
        }

        foreach ($performance as &$row) {
            $row['percentage'] = $row['total'] > 0
                ? round(($row['correct'] / $row['total']) * 100, 1)
                : 0.0;
        }
        unset($row);

        $attempt['questions'] = $questions;
        $attempt['performance'] = array_values($performance);
        $attempt['correct_count'] = (int) round((float) $attempt['score']);
        $attempt['wrong_count'] = max(
            0,
            (int) $attempt['question_limit'] - (int) $attempt['correct_count']
        );
        $attempt['answered_count'] = count(array_filter(
            $questions,
            static fn (array $q): bool => !empty($q['selected_alternative_id'])
        ));
        $attempt['unanswered_count'] = max(
            0,
            (int) $attempt['question_limit'] - (int) $attempt['answered_count']
        );
        $attempt['duration_seconds'] = $this->durationSeconds($attempt);

        return $attempt;
    }

    public function abandonAttempt(int $attemptId, int $userId): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            "UPDATE simulation_attempts
             SET status = 'abandoned', finished_at = NOW()
             WHERE id = :attempt_id
               AND user_id = :user_id
               AND status = 'in_progress'"
        );
        $stmt->execute([
            'attempt_id' => $attemptId,
            'user_id' => $userId,
        ]);
    }

    private function assertEnrollment(PDO $pdo, int $userId, int $courseId): void
    {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :user_id
               AND e.course_id = :course_id
               AND e.status = 'active'
               AND c.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Você não possui matrícula ativa neste curso.');
        }
    }

    private function ensureSimulation(PDO $pdo, int $courseId): int
    {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM simulations
             WHERE course_id = :course_id
               AND title = 'Simulado Livre'
               AND active = 1
             ORDER BY id
             LIMIT 1"
        );
        $stmt->execute(['course_id' => $courseId]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }

        $insert = $pdo->prepare(
            "INSERT INTO simulations
                (course_id, title, description, required_score,
                 question_limit, time_limit_minutes,
                 shuffle_questions, shuffle_answers, xp_reward, active)
             VALUES
                (:course_id, 'Simulado Livre',
                 'Treino livre com seleção dinâmica, histórico e baixa repetição.',
                 :required_score, 20, 40, 1, 1, 0, 1)"
        );
        $insert->execute([
            'course_id' => $courseId,
            'required_score' => self::REQUIRED_SCORE,
        ]);

        return (int) $pdo->lastInsertId();
    }

    private function questionCandidates(
        PDO $pdo,
        int $userId,
        int $courseId,
        ?int $moduleId
    ): array {
        $params = [
            'user_id' => $userId,
            'course_id' => $courseId,
        ];
        $moduleSql = '';
        if ($moduleId !== null) {
            $moduleSql = ' AND q.module_id = :module_id';
            $params['module_id'] = $moduleId;
        }

        $stmt = $pdo->prepare(
            "SELECT q.id, q.module_id, q.difficulty,
                    COALESCE(m.position, 999999) AS module_position,
                    COALESCE(m.title, 'Conteúdo geral') AS module_title,
                    hist.last_seen_at,
                    COALESCE(hist.seen_count, 0) AS seen_count
             FROM questions q
             INNER JOIN (
                SELECT question_id,
                       COUNT(*) AS alternatives_count,
                       SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                FROM alternatives
                GROUP BY question_id
             ) quality ON quality.question_id = q.id
             LEFT JOIN modules m ON m.id = q.module_id
             LEFT JOIN (
                SELECT saq.question_id,
                       MAX(sa.started_at) AS last_seen_at,
                       COUNT(*) AS seen_count
                FROM simulation_attempt_questions saq
                INNER JOIN simulation_attempts sa ON sa.id = saq.attempt_id
                WHERE sa.user_id = :user_id
                GROUP BY saq.question_id
             ) hist ON hist.question_id = q.id
             WHERE q.course_id = :course_id
               {$moduleSql}
               AND q.active = 1
               AND q.question_type = 'multiple_choice'
               AND q.difficulty IN ('medium', 'hard')
               AND quality.alternatives_count >= " . self::MIN_ALTERNATIVES . "
               AND quality.correct_count = 1
             ORDER BY COALESCE(m.position, 999999), q.id"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['_rand'] = random_int(1, PHP_INT_MAX);
        }
        unset($row);

        return $rows;
    }

    private function selectBalancedQuestions(array $candidates, int $limit): array
    {
        $queues = [];
        foreach ($candidates as $row) {
            $moduleKey = (int) ($row['module_id'] ?? 0);
            $difficulty = (string) $row['difficulty'];
            $queues[$moduleKey][$difficulty][] = $row;
        }

        foreach ($queues as &$difficultyQueues) {
            foreach ($difficultyQueues as &$queue) {
                usort($queue, function (array $a, array $b): int {
                    $aNever = empty($a['last_seen_at']) ? 0 : 1;
                    $bNever = empty($b['last_seen_at']) ? 0 : 1;
                    if ($aNever !== $bNever) {
                        return $aNever <=> $bNever;
                    }

                    $seenCompare = (int) $a['seen_count'] <=> (int) $b['seen_count'];
                    if ($seenCompare !== 0) {
                        return $seenCompare;
                    }

                    if (!empty($a['last_seen_at']) && !empty($b['last_seen_at'])) {
                        $dateCompare = strcmp((string) $a['last_seen_at'], (string) $b['last_seen_at']);
                        if ($dateCompare !== 0) {
                            return $dateCompare;
                        }
                    }

                    return (int) $a['_rand'] <=> (int) $b['_rand'];
                });
            }
            unset($queue);
        }
        unset($difficultyQueues);

        $selected = [];
        $selectedIds = [];
        $hardTarget = (int) floor($limit * 0.40);
        $mediumTarget = $limit - $hardTarget;

        $this->roundRobinPick($queues, 'medium', $mediumTarget, $selected, $selectedIds);
        $this->roundRobinPick($queues, 'hard', $hardTarget, $selected, $selectedIds);

        // Se uma dificuldade não tiver volume suficiente, completa com a outra,
        // sem reclassificar artificialmente a questão.
        if (count($selected) < $limit) {
            $this->roundRobinPick($queues, 'hard', $limit - count($selected), $selected, $selectedIds);
        }
        if (count($selected) < $limit) {
            $this->roundRobinPick($queues, 'medium', $limit - count($selected), $selected, $selectedIds);
        }

        return array_slice($selected, 0, $limit);
    }

    private function roundRobinPick(
        array &$queues,
        string $difficulty,
        int $target,
        array &$selected,
        array &$selectedIds
    ): void {
        if ($target <= 0 || $queues === []) {
            return;
        }

        $moduleKeys = array_keys($queues);
        usort($moduleKeys, function (int $a, int $b) use ($queues): int {
            $aQueue = $queues[$a]['medium'][0] ?? $queues[$a]['hard'][0] ?? null;
            $bQueue = $queues[$b]['medium'][0] ?? $queues[$b]['hard'][0] ?? null;
            return (int) ($aQueue['module_position'] ?? 999999)
                <=> (int) ($bQueue['module_position'] ?? 999999);
        });

        $picked = 0;
        while ($picked < $target) {
            $progress = false;
            foreach ($moduleKeys as $moduleKey) {
                if ($picked >= $target) {
                    break;
                }

                while (!empty($queues[$moduleKey][$difficulty])) {
                    $candidate = array_shift($queues[$moduleKey][$difficulty]);
                    $id = (int) $candidate['id'];
                    if (isset($selectedIds[$id])) {
                        continue;
                    }

                    $selected[] = $candidate;
                    $selectedIds[$id] = true;
                    $picked++;
                    $progress = true;
                    break;
                }
            }

            if (!$progress) {
                break;
            }
        }
    }

    private function attemptBase(PDO $pdo, int $attemptId, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT sa.*, s.title AS simulation_title, s.required_score,
                    c.id AS course_id, c.title AS course_title,
                    m.title AS module_title
             FROM simulation_attempts sa
             INNER JOIN simulations s ON s.id = sa.simulation_id
             LEFT JOIN courses c ON c.id = s.course_id
             LEFT JOIN modules m ON m.id = sa.scope_module_id
             WHERE sa.id = :attempt_id
               AND sa.user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute([
            'attempt_id' => $attemptId,
            'user_id' => $userId,
        ]);
        $attempt = $stmt->fetch();

        if (!$attempt) {
            throw new RuntimeException('Tentativa de simulado não encontrada.');
        }

        return $attempt;
    }

    private function finishExpiredAttempts(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare(
            "SELECT id
             FROM simulation_attempts
             WHERE user_id = :user_id
               AND status = 'in_progress'
               AND TIMESTAMPDIFF(SECOND, started_at, NOW()) >= (time_limit_minutes * 60)"
        );
        $stmt->execute(['user_id' => $userId]);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($ids as $id) {
            try {
                $this->finishAttempt($id, $userId);
            } catch (Throwable) {
                // Não impede que o painel abra por causa de uma tentativa antiga inconsistente.
            }
        }
    }

    private function remainingSeconds(array $attempt): int
    {
        $start = new DateTimeImmutable((string) $attempt['started_at']);
        $deadline = $start->modify('+' . (int) $attempt['time_limit_minutes'] . ' minutes');
        return max(0, $deadline->getTimestamp() - time());
    }

    private function durationSeconds(array $attempt): int
    {
        if (empty($attempt['started_at']) || empty($attempt['finished_at'])) {
            return 0;
        }

        $start = new DateTimeImmutable((string) $attempt['started_at']);
        $finish = new DateTimeImmutable((string) $attempt['finished_at']);
        return max(0, $finish->getTimestamp() - $start->getTimestamp());
    }
}
