<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

final class ExerciseService
{
    private const ALLOWED_LIMITS = [5, 10, 15, 20, 30];
    private const MIN_ALTERNATIVES_MULTIPLE_CHOICE = 4;
    private const MIN_ALTERNATIVES_TRUE_FALSE = 2;
    private const ALLOWED_DIFFICULTIES = ['all', 'medium', 'hard'];

    public function dashboard(int $userId): array
    {
        $pdo = Database::connection();

        return array_merge(
            $this->configuration($userId, $pdo),
            [
                'history' => $this->history($userId, $pdo),
                'stats' => $this->stats($userId, $pdo),
            ]
        );
    }

    public function configuration(int $userId, ?PDO $pdo = null): array
    {
        $pdo ??= Database::connection();

        $courseStmt = $pdo->prepare(
            "SELECT c.id, c.title
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :user_id
               AND e.status = 'active'
               AND c.status = 'published'
             ORDER BY c.position, c.id"
        );
        $courseStmt->execute(['user_id' => $userId]);
        $courses = $courseStmt->fetchAll(PDO::FETCH_ASSOC);

        $courseIds = array_map(static fn (array $row): int => (int) $row['id'], $courses);
        if ($courseIds === []) {
            return [
                'courses' => [],
                'modules' => [],
                'phases' => [],
                'topics' => [],
                'taxonomyAvailable' => false,
                'allowedLimits' => self::ALLOWED_LIMITS,
                'allowedDifficulties' => self::ALLOWED_DIFFICULTIES,
            ];
        }

        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));

        $moduleStmt = $pdo->prepare(
            "SELECT m.id, m.course_id, m.title, m.slug, m.position
             FROM modules m
             WHERE m.course_id IN ({$placeholders})
               AND m.status = 'published'
             ORDER BY m.course_id, m.position, m.id"
        );
        $moduleStmt->execute($courseIds);
        $modules = $moduleStmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($modules as &$module) {
            $module['is_pop'] = $this->isPopModule($module) ? 1 : 0;
        }
        unset($module);

        $phaseStmt = $pdo->prepare(
            "SELECT p.id, p.module_id, p.title, p.position, m.course_id
             FROM phases p
             INNER JOIN modules m ON m.id = p.module_id
             WHERE m.course_id IN ({$placeholders})
               AND m.status = 'published'
               AND p.status = 'published'
             ORDER BY m.course_id, m.position, p.position, p.id"
        );
        $phaseStmt->execute($courseIds);
        $phases = $phaseStmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = $this->practiceQuestionCounts($pdo, $courseIds);

        foreach ($courses as &$course) {
            $course['question_count'] = (int) ($counts['courses'][(int) $course['id']] ?? 0);
        }
        unset($course);

        foreach ($modules as &$module) {
            $module['question_count'] = (int) ($counts['modules'][(int) $module['id']] ?? 0);
        }
        unset($module);

        foreach ($phases as &$phase) {
            $phase['question_count'] = (int) ($counts['phases'][(int) $phase['id']] ?? 0);
        }
        unset($phase);

        $taxonomyAvailable = $this->tableExists($pdo, 'study_topics')
            && $this->tableExists($pdo, 'question_topics');
        $topics = $taxonomyAvailable ? $this->studyTopics($pdo, $courseIds) : [];

        return [
            'courses' => $courses,
            'modules' => $modules,
            'phases' => $phases,
            'topics' => $topics,
            'taxonomyAvailable' => $taxonomyAvailable,
            'allowedLimits' => self::ALLOWED_LIMITS,
            'allowedDifficulties' => self::ALLOWED_DIFFICULTIES,
        ];
    }

    public function generate(
        int $userId,
        int $courseId,
        int $questionLimit,
        ?int $moduleId = null,
        ?int $phaseId = null,
        ?int $topicId = null,
        string $difficulty = 'all'
    ): int {
        if (!in_array($questionLimit, self::ALLOWED_LIMITS, true)) {
            throw new RuntimeException('Quantidade de exercícios inválida.');
        }

        $difficulty = strtolower(trim($difficulty));
        if (!in_array($difficulty, self::ALLOWED_DIFFICULTIES, true)) {
            throw new RuntimeException('Dificuldade inválida.');
        }

        $pdo = Database::connection();
        $course = $this->assertEnrollment($pdo, $userId, $courseId);

        $module = null;
        if ($moduleId !== null && $moduleId > 0) {
            $moduleStmt = $pdo->prepare(
                "SELECT id, course_id, title, slug
                 FROM modules
                 WHERE id = :id
                   AND course_id = :course_id
                   AND status = 'published'
                 LIMIT 1"
            );
            $moduleStmt->execute(['id' => $moduleId, 'course_id' => $courseId]);
            $module = $moduleStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$module) {
                throw new RuntimeException('Disciplina inválida para o curso selecionado.');
            }
        } else {
            $moduleId = null;
        }

        $phase = null;
        if ($phaseId !== null && $phaseId > 0) {
            if ($moduleId === null) {
                throw new RuntimeException('Selecione a disciplina antes de escolher o tema.');
            }

            $phaseStmt = $pdo->prepare(
                "SELECT p.id, p.module_id, p.title
                 FROM phases p
                 INNER JOIN modules m ON m.id = p.module_id
                 WHERE p.id = :id
                   AND p.module_id = :module_id
                   AND m.course_id = :course_id
                   AND p.status = 'published'
                   AND m.status = 'published'
                 LIMIT 1"
            );
            $phaseStmt->execute([
                'id' => $phaseId,
                'module_id' => $moduleId,
                'course_id' => $courseId,
            ]);
            $phase = $phaseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$phase) {
                throw new RuntimeException('Tema inválido para a disciplina selecionada.');
            }
        } else {
            $phaseId = null;
        }

        $topic = null;
        if ($topicId !== null && $topicId > 0) {
            if ($module === null || !$this->isPopModule($module)) {
                throw new RuntimeException('Os filtros POP só podem ser usados na disciplina de Procedimentos Operacionais Padrão.');
            }
            if (!$this->tableExists($pdo, 'study_topics') || !$this->tableExists($pdo, 'question_topics')) {
                throw new RuntimeException('A taxonomia POP ainda não foi instalada no banco.');
            }

            $topicStmt = $pdo->prepare(
                "SELECT id, parent_id, code, title, topic_type
                 FROM study_topics
                 WHERE id = :id
                   AND domain = 'pop'
                   AND active = 1
                 LIMIT 1"
            );
            $topicStmt->execute(['id' => $topicId]);
            $topic = $topicStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$topic) {
                throw new RuntimeException('POP, processo ou procedimento inválido.');
            }
        } else {
            $topicId = null;
        }

        $candidates = $this->questionCandidates(
            $pdo,
            $userId,
            $courseId,
            $moduleId,
            $phaseId,
            $topicId,
            $difficulty
        );

        if ($candidates === []) {
            throw new RuntimeException('Não existem questões válidas para este filtro. Escolha outro conteúdo ou dificuldade.');
        }

        $picked = $this->selectQuestions($candidates, $questionLimit);
        $uniqueQuestionIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['id'],
            $picked
        )));
        $alternatives = $this->alternativesByQuestion($pdo, $uniqueQuestionIds);

        foreach ($picked as $question) {
            $questionId = (int) $question['id'];
            $minimum = ($question['question_type'] ?? 'multiple_choice') === 'true_false'
                ? self::MIN_ALTERNATIVES_TRUE_FALSE
                : self::MIN_ALTERNATIVES_MULTIPLE_CHOICE;
            if (count($alternatives[$questionId] ?? []) < $minimum) {
                throw new RuntimeException('Uma das questões selecionadas não possui alternativas suficientes.');
            }
        }

        $pdo->beginTransaction();
        try {
            $insertSession = $pdo->prepare(
                "INSERT INTO exercise_sessions
                    (user_id, course_id, module_id, phase_id, topic_id,
                     course_title_snapshot, module_title_snapshot, phase_title_snapshot, topic_title_snapshot,
                     difficulty_filter, question_limit, correct_count, percentage, status, started_at)
                 VALUES
                    (:user_id, :course_id, :module_id, :phase_id, :topic_id,
                     :course_title, :module_title, :phase_title, :topic_title,
                     :difficulty_filter, :question_limit, 0, 0, 'in_progress', NOW())"
            );
            $insertSession->execute([
                'user_id' => $userId,
                'course_id' => $courseId,
                'module_id' => $moduleId,
                'phase_id' => $phaseId,
                'topic_id' => $topicId,
                'course_title' => (string) $course['title'],
                'module_title' => $module['title'] ?? null,
                'phase_title' => $phase['title'] ?? null,
                'topic_title' => $topic['title'] ?? null,
                'difficulty_filter' => $difficulty,
                'question_limit' => $questionLimit,
            ]);
            $sessionId = (int) $pdo->lastInsertId();

            $insertQuestion = $pdo->prepare(
                "INSERT INTO exercise_session_questions
                    (session_id, question_id, position,
                     statement_snapshot, explanation_snapshot,
                     source_label_snapshot, difficulty_snapshot,
                     alternatives_snapshot, created_at)
                 VALUES
                    (:session_id, :question_id, :position,
                     :statement_snapshot, :explanation_snapshot,
                     :source_label_snapshot, :difficulty_snapshot,
                     :alternatives_snapshot, NOW())"
            );

            foreach ($picked as $index => $question) {
                $questionId = (int) $question['id'];
                $snapshotAlternatives = $alternatives[$questionId] ?? [];
                shuffle($snapshotAlternatives);

                $insertQuestion->execute([
                    'session_id' => $sessionId,
                    'question_id' => $questionId,
                    'position' => $index + 1,
                    'statement_snapshot' => (string) $question['statement'],
                    'explanation_snapshot' => $question['explanation'],
                    'source_label_snapshot' => $question['source_label'],
                    'difficulty_snapshot' => (string) $question['difficulty'],
                    'alternatives_snapshot' => json_encode(
                        $snapshotAlternatives,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                    ),
                ]);
            }

            $pdo->commit();
            return $sessionId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function sessionData(int $sessionId, int $userId): array
    {
        $pdo = Database::connection();
        $session = $this->session($pdo, $sessionId, $userId);

        if (($session['status'] ?? '') === 'finished') {
            return ['session' => $session, 'current' => null, 'answered' => (int) $session['question_limit']];
        }

        $currentStmt = $pdo->prepare(
            "SELECT esq.*
             FROM exercise_session_questions esq
             LEFT JOIN exercise_answers ea ON ea.session_question_id = esq.id
             WHERE esq.session_id = :session_id
               AND ea.id IS NULL
             ORDER BY esq.position
             LIMIT 1"
        );
        $currentStmt->execute(['session_id' => $sessionId]);
        $current = $currentStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $answeredStmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM exercise_answers ea
             INNER JOIN exercise_session_questions esq ON esq.id = ea.session_question_id
             WHERE esq.session_id = :session_id"
        );
        $answeredStmt->execute(['session_id' => $sessionId]);
        $answered = (int) $answeredStmt->fetchColumn();

        if ($current === null) {
            $this->finishSession($pdo, $sessionId);
            $session = $this->session($pdo, $sessionId, $userId);
            return ['session' => $session, 'current' => null, 'answered' => $answered];
        }

        $alternatives = json_decode((string) $current['alternatives_snapshot'], true);
        if (!is_array($alternatives)) {
            throw new RuntimeException('Não foi possível carregar as alternativas deste exercício.');
        }

        $safeAlternatives = [];
        foreach ($alternatives as $alternative) {
            if (!is_array($alternative)) {
                continue;
            }
            $safeAlternatives[] = [
                'id' => (int) ($alternative['id'] ?? 0),
                'label' => (string) ($alternative['label'] ?? ''),
                'text' => (string) ($alternative['text'] ?? ''),
            ];
        }

        $current['alternatives'] = $safeAlternatives;
        unset($current['alternatives_snapshot']);

        return [
            'session' => $session,
            'current' => $current,
            'answered' => $answered,
        ];
    }

    public function answer(
        int $sessionId,
        int $userId,
        int $sessionQuestionId,
        int $alternativeId
    ): array {
        if ($sessionQuestionId <= 0 || $alternativeId <= 0) {
            throw new RuntimeException('Selecione uma alternativa válida.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();

        try {
            $sessionStmt = $pdo->prepare(
                "SELECT *
                 FROM exercise_sessions
                 WHERE id = :id
                   AND user_id = :user_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $sessionStmt->execute(['id' => $sessionId, 'user_id' => $userId]);
            $session = $sessionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$session) {
                throw new RuntimeException('Sessão de exercícios não encontrada.');
            }
            if (($session['status'] ?? '') !== 'in_progress') {
                throw new RuntimeException('Este exercício já foi finalizado.');
            }

            $questionStmt = $pdo->prepare(
                "SELECT *
                 FROM exercise_session_questions
                 WHERE id = :id
                   AND session_id = :session_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $questionStmt->execute([
                'id' => $sessionQuestionId,
                'session_id' => $sessionId,
            ]);
            $question = $questionStmt->fetch(PDO::FETCH_ASSOC);
            if (!$question) {
                throw new RuntimeException('Questão inválida para esta sessão.');
            }

            $alreadyStmt = $pdo->prepare(
                'SELECT id FROM exercise_answers WHERE session_question_id = :id LIMIT 1'
            );
            $alreadyStmt->execute(['id' => $sessionQuestionId]);
            if ($alreadyStmt->fetchColumn()) {
                throw new RuntimeException('Esta questão já foi respondida.');
            }

            $alternatives = json_decode((string) $question['alternatives_snapshot'], true);
            if (!is_array($alternatives)) {
                throw new RuntimeException('Alternativas inválidas nesta sessão.');
            }

            $selected = null;
            $correct = null;
            foreach ($alternatives as $alternative) {
                if (!is_array($alternative)) {
                    continue;
                }
                if ((int) ($alternative['id'] ?? 0) === $alternativeId) {
                    $selected = $alternative;
                }
                if ((int) ($alternative['is_correct'] ?? 0) === 1) {
                    $correct = $alternative;
                }
            }

            if ($selected === null || $correct === null) {
                throw new RuntimeException('Alternativa inválida para esta questão.');
            }

            $isCorrect = (int) ($selected['id'] ?? 0) === (int) ($correct['id'] ?? 0);

            $insert = $pdo->prepare(
                "INSERT INTO exercise_answers
                    (session_question_id, selected_alternative_id, selected_label, is_correct, answered_at)
                 VALUES
                    (:session_question_id, :selected_alternative_id, :selected_label, :is_correct, NOW())"
            );
            $insert->execute([
                'session_question_id' => $sessionQuestionId,
                'selected_alternative_id' => $alternativeId,
                'selected_label' => (string) ($selected['label'] ?? ''),
                'is_correct' => $isCorrect ? 1 : 0,
            ]);

            if ($isCorrect) {
                $pdo->prepare(
                    'UPDATE exercise_sessions SET correct_count = correct_count + 1 WHERE id = :id'
                )->execute(['id' => $sessionId]);
            }

            $countStmt = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM exercise_answers ea
                 INNER JOIN exercise_session_questions esq ON esq.id = ea.session_question_id
                 WHERE esq.session_id = :session_id"
            );
            $countStmt->execute(['session_id' => $sessionId]);
            $answered = (int) $countStmt->fetchColumn();
            $total = (int) $session['question_limit'];
            $finished = $answered >= $total;

            $score = null;
            if ($finished) {
                $score = $this->finishSession($pdo, $sessionId);
            }

            $pdo->commit();

            return [
                'ok' => true,
                'correct' => $isCorrect,
                'message' => $isCorrect
                    ? 'Resposta correta. Ótimo trabalho!'
                    : 'Resposta incorreta. Use a explicação para fixar o conceito.',
                'correct_alternative' => [
                    'label' => (string) ($correct['label'] ?? ''),
                    'text' => (string) ($correct['text'] ?? ''),
                ],
                'explanation' => (string) ($question['explanation_snapshot'] ?? ''),
                'source' => (string) ($question['source_label_snapshot'] ?? ''),
                'finished' => $finished,
                'answered' => $answered,
                'total' => $total,
                'score' => $score,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function resultData(int $sessionId, int $userId): array
    {
        $pdo = Database::connection();
        $session = $this->session($pdo, $sessionId, $userId);

        if (($session['status'] ?? '') === 'in_progress') {
            throw new RuntimeException('Conclua o exercício antes de abrir o resultado.');
        }

        $stmt = $pdo->prepare(
            "SELECT esq.*, ea.selected_alternative_id, ea.selected_label, ea.is_correct, ea.answered_at
             FROM exercise_session_questions esq
             LEFT JOIN exercise_answers ea ON ea.session_question_id = esq.id
             WHERE esq.session_id = :session_id
             ORDER BY esq.position"
        );
        $stmt->execute(['session_id' => $sessionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $review = [];
        foreach ($rows as $row) {
            $alternatives = json_decode((string) $row['alternatives_snapshot'], true);
            $alternatives = is_array($alternatives) ? $alternatives : [];

            $selected = null;
            $correct = null;
            foreach ($alternatives as $alternative) {
                if (!is_array($alternative)) {
                    continue;
                }
                if ((int) ($alternative['id'] ?? 0) === (int) ($row['selected_alternative_id'] ?? 0)) {
                    $selected = $alternative;
                }
                if ((int) ($alternative['is_correct'] ?? 0) === 1) {
                    $correct = $alternative;
                }
            }

            $review[] = [
                'position' => (int) $row['position'],
                'statement' => (string) $row['statement_snapshot'],
                'is_correct' => (int) ($row['is_correct'] ?? 0) === 1,
                'selected' => $selected,
                'correct' => $correct,
                'explanation' => (string) ($row['explanation_snapshot'] ?? ''),
                'source' => (string) ($row['source_label_snapshot'] ?? ''),
            ];
        }

        return [
            'session' => $session,
            'review' => $review,
        ];
    }

    private function stats(int $userId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COALESCE(AVG(percentage), 0) AS average,
                    COALESCE(MAX(percentage), 0) AS best,
                    COALESCE(SUM(correct_count), 0) AS correct_answers
             FROM exercise_sessions
             WHERE user_id = :user_id
               AND status = 'finished'"
        );
        $stmt->execute(['user_id' => $userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'total' => (int) ($stats['total'] ?? 0),
            'average' => (float) ($stats['average'] ?? 0),
            'best' => (float) ($stats['best'] ?? 0),
            'correct_answers' => (int) ($stats['correct_answers'] ?? 0),
        ];
    }

    private function history(int $userId, PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, course_title_snapshot, module_title_snapshot, phase_title_snapshot,
                    topic_title_snapshot, difficulty_filter,
                    question_limit, correct_count, percentage, status, started_at, finished_at
             FROM exercise_sessions
             WHERE user_id = :user_id
             ORDER BY id DESC
             LIMIT 12"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function assertEnrollment(PDO $pdo, int $userId, int $courseId): array
    {
        $stmt = $pdo->prepare(
            "SELECT c.id, c.title
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             WHERE e.user_id = :user_id
               AND e.course_id = :course_id
               AND e.status = 'active'
               AND c.status = 'published'
             LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId, 'course_id' => $courseId]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            throw new RuntimeException('Curso inválido ou matrícula inativa.');
        }

        return $course;
    }

    private function questionCandidates(
        PDO $pdo,
        int $userId,
        int $courseId,
        ?int $moduleId,
        ?int $phaseId,
        ?int $topicId,
        string $difficulty
    ): array {
        $where = [
            'q.course_id = :course_id',
            'q.active = 1',
            "q.question_type IN ('multiple_choice','true_false')",
            "q.difficulty IN ('medium','hard')",
            "((q.question_type = 'multiple_choice' AND quality.alternatives_count >= " . self::MIN_ALTERNATIVES_MULTIPLE_CHOICE . ")
              OR (q.question_type = 'true_false' AND quality.alternatives_count >= " . self::MIN_ALTERNATIVES_TRUE_FALSE . '))',
            'quality.correct_count = 1',
        ];
        $params = [
            'course_id' => $courseId,
            'history_user_id' => $userId,
        ];

        if ($moduleId !== null) {
            $where[] = 'q.module_id = :module_id';
            $params['module_id'] = $moduleId;
        }
        if ($phaseId !== null) {
            $where[] = 'q.phase_id = :phase_id';
            $params['phase_id'] = $phaseId;
        }
        if ($topicId !== null) {
            $where[] = 'EXISTS (SELECT 1 FROM question_topics qt WHERE qt.question_id = q.id AND qt.topic_id = :topic_id)';
            $params['topic_id'] = $topicId;
        }
        if ($difficulty !== 'all') {
            $where[] = 'q.difficulty = :difficulty';
            $params['difficulty'] = $difficulty;
        }

        $stmt = $pdo->prepare(
            "SELECT q.id, q.question_type, q.statement, q.explanation, q.source_label, q.difficulty,
                    COALESCE(hist.seen_count, 0) AS seen_count,
                    hist.last_seen_at
             FROM questions q
             INNER JOIN (
                SELECT question_id,
                       COUNT(*) AS alternatives_count,
                       SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                FROM alternatives
                GROUP BY question_id
             ) quality ON quality.question_id = q.id
             LEFT JOIN (
                SELECT esq.question_id,
                       COUNT(*) AS seen_count,
                       MAX(es.started_at) AS last_seen_at
                FROM exercise_session_questions esq
                INNER JOIN exercise_sessions es ON es.id = esq.session_id
                WHERE es.user_id = :history_user_id
                  AND esq.question_id IS NOT NULL
                GROUP BY esq.question_id
             ) hist ON hist.question_id = q.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY q.id"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['_rand'] = random_int(1, PHP_INT_MAX);
        }
        unset($row);

        return $rows;
    }

    private function selectQuestions(array $candidates, int $limit): array
    {
        usort($candidates, static function (array $a, array $b): int {
            $seenCompare = (int) ($a['seen_count'] ?? 0) <=> (int) ($b['seen_count'] ?? 0);
            if ($seenCompare !== 0) {
                return $seenCompare;
            }

            $aDate = (string) ($a['last_seen_at'] ?? '');
            $bDate = (string) ($b['last_seen_at'] ?? '');
            if ($aDate !== $bDate) {
                if ($aDate === '') {
                    return -1;
                }
                if ($bDate === '') {
                    return 1;
                }
                return strcmp($aDate, $bDate);
            }

            return (int) $a['_rand'] <=> (int) $b['_rand'];
        });

        if (count($candidates) >= $limit) {
            return array_slice($candidates, 0, $limit);
        }

        // Se o filtro tiver menos questões do que o solicitado, usa todas primeiro
        // e só então inicia novos ciclos embaralhados. Assim a repetição acontece
        // apenas depois de esgotar o pool disponível.
        $selected = $candidates;
        $pool = $candidates;

        while (count($selected) < $limit) {
            shuffle($pool);

            if (count($pool) > 1 && $selected !== []) {
                $lastId = (int) $selected[array_key_last($selected)]['id'];
                if ((int) $pool[0]['id'] === $lastId) {
                    [$pool[0], $pool[1]] = [$pool[1], $pool[0]];
                }
            }

            foreach ($pool as $row) {
                $selected[] = $row;
                if (count($selected) >= $limit) {
                    break;
                }
            }
        }

        return $selected;
    }

    private function alternativesByQuestion(PDO $pdo, array $questionIds): array
    {
        if ($questionIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($questionIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, question_id, label, text, is_correct, position
             FROM alternatives
             WHERE question_id IN ({$placeholders})
             ORDER BY question_id, position, id"
        );
        $stmt->execute($questionIds);

        $grouped = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $grouped[(int) $row['question_id']][] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'text' => (string) $row['text'],
                'is_correct' => (int) $row['is_correct'],
            ];
        }

        return $grouped;
    }

    private function session(PDO $pdo, int $sessionId, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT *
             FROM exercise_sessions
             WHERE id = :id
               AND user_id = :user_id
             LIMIT 1"
        );
        $stmt->execute(['id' => $sessionId, 'user_id' => $userId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            throw new RuntimeException('Sessão de exercícios não encontrada.');
        }

        return $session;
    }

    private function finishSession(PDO $pdo, int $sessionId): float
    {
        $stmt = $pdo->prepare(
            "SELECT question_limit, correct_count, status
             FROM exercise_sessions
             WHERE id = :id
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->execute(['id' => $sessionId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new RuntimeException('Sessão de exercícios não encontrada.');
        }

        if (($session['status'] ?? '') === 'finished') {
            return (float) ($pdo->query(
                'SELECT percentage FROM exercise_sessions WHERE id = ' . (int) $sessionId
            )->fetchColumn() ?: 0);
        }

        $total = max(1, (int) $session['question_limit']);
        $correct = (int) $session['correct_count'];
        $percentage = round(($correct / $total) * 100, 2);

        $update = $pdo->prepare(
            "UPDATE exercise_sessions
             SET percentage = :percentage,
                 status = 'finished',
                 finished_at = NOW()
             WHERE id = :id"
        );
        $update->execute(['percentage' => $percentage, 'id' => $sessionId]);

        return $percentage;
    }

    private function practiceQuestionCounts(PDO $pdo, array $courseIds): array
    {
        if ($courseIds === []) {
            return ['courses' => [], 'modules' => [], 'phases' => []];
        }

        $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT q.course_id, q.module_id, q.phase_id, COUNT(*) AS qty
             FROM questions q
             INNER JOIN (
                SELECT question_id,
                       COUNT(*) AS alternatives_count,
                       SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                FROM alternatives
                GROUP BY question_id
             ) quality ON quality.question_id = q.id
             WHERE q.course_id IN ({$placeholders})
               AND q.active = 1
               AND q.question_type IN ('multiple_choice','true_false')
               AND q.difficulty IN ('medium','hard')
               AND ((q.question_type = 'multiple_choice' AND quality.alternatives_count >= " . self::MIN_ALTERNATIVES_MULTIPLE_CHOICE . ")
                    OR (q.question_type = 'true_false' AND quality.alternatives_count >= " . self::MIN_ALTERNATIVES_TRUE_FALSE . "))
               AND quality.correct_count = 1
             GROUP BY q.course_id, q.module_id, q.phase_id"
        );
        $stmt->execute($courseIds);

        $courseCounts = [];
        $moduleCounts = [];
        $phaseCounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $qty = (int) $row['qty'];
            $courseId = (int) $row['course_id'];
            $moduleId = (int) ($row['module_id'] ?? 0);
            $phaseId = (int) ($row['phase_id'] ?? 0);

            $courseCounts[$courseId] = ($courseCounts[$courseId] ?? 0) + $qty;
            if ($moduleId > 0) {
                $moduleCounts[$moduleId] = ($moduleCounts[$moduleId] ?? 0) + $qty;
            }
            if ($phaseId > 0) {
                $phaseCounts[$phaseId] = ($phaseCounts[$phaseId] ?? 0) + $qty;
            }
        }

        return [
            'courses' => $courseCounts,
            'modules' => $moduleCounts,
            'phases' => $phaseCounts,
        ];
    }

    private function studyTopics(PDO $pdo, array $courseIds): array
    {
        $counts = [];
        if ($courseIds !== []) {
            $placeholders = implode(',', array_fill(0, count($courseIds), '?'));
            $stmt = $pdo->prepare(
                "SELECT qt.topic_id, COUNT(DISTINCT q.id) AS qty
                 FROM question_topics qt
                 INNER JOIN questions q ON q.id = qt.question_id
                 INNER JOIN (
                    SELECT question_id,
                           COUNT(*) AS alternatives_count,
                           SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS correct_count
                    FROM alternatives
                    GROUP BY question_id
                 ) quality ON quality.question_id = q.id
                 WHERE q.course_id IN ({$placeholders})
                   AND q.active = 1
                   AND q.question_type IN ('multiple_choice','true_false')
                   AND q.difficulty IN ('medium','hard')
                   AND ((q.question_type = 'multiple_choice' AND quality.alternatives_count >= " . self::MIN_ALTERNATIVES_MULTIPLE_CHOICE . ")
                        OR (q.question_type = 'true_false' AND quality.alternatives_count >= " . self::MIN_ALTERNATIVES_TRUE_FALSE . "))
                   AND quality.correct_count = 1
                 GROUP BY qt.topic_id"
            );
            $stmt->execute($courseIds);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[(int) $row['topic_id']] = (int) $row['qty'];
            }
        }

        $rows = $pdo->query(
            "SELECT id,parent_id,domain,code,slug,topic_type,title,position
             FROM study_topics
             WHERE domain='pop' AND active=1
             ORDER BY CASE topic_type WHEN 'pop' THEN 1 WHEN 'general' THEN 2 WHEN 'process' THEN 3 ELSE 4 END,
                      position,id"
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['question_count'] = (int) ($counts[(int)$row['id']] ?? 0);
        }
        unset($row);
        return $rows;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function isPopModule(array $module): bool
    {
        $slug = mb_strtolower((string)($module['slug'] ?? ''));
        $title = mb_strtolower((string)($module['title'] ?? ''));
        return str_contains($slug, 'procedimentos-operacionais-padrao')
            || str_contains($title, 'procedimentos operacionais');
    }

}
