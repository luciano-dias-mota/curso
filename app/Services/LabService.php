<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Lab\LabEngineRegistry;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class LabService
{
    public function __construct(
        private readonly ?LabEngineRegistry $registry = null
    ) {
    }

    public function manifest(int $userId, int $activityId): array
    {
        $pdo = Database::connection();
        $activity = $this->activityForStudent($pdo, $userId, $activityId);
        $engine = $this->registry()->get((string) $activity['engine_key']);

        $activity['validator_config'] = $this->decodeJsonObject(
            $activity['validator_config'] ?? null,
            'validator_config'
        );
        $activity['bootstrap_config'] = $this->decodeJsonObject(
            $activity['bootstrap_config'] ?? null,
            'bootstrap_config'
        );

        $engine->validateActivityDefinition($activity);

        $progress = $this->activityProgress($pdo, $userId, $activityId);
        $hintStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM lab_activity_hints WHERE activity_id = :activity_id'
        );
        $hintStmt->execute(['activity_id' => $activityId]);

        $context = [
            'user_id' => $userId,
            'course_id' => (int) $activity['course_id'],
            'lesson_id' => (int) $activity['lesson_id'],
            'state' => $this->courseState(
                $pdo,
                $userId,
                (int) $activity['course_id'],
                (string) $activity['engine_key']
            ),
        ];

        return [
            'activity' => $this->publicActivity($activity),
            'engine' => [
                'key' => $engine->key(),
                'label' => $engine->label(),
                'execution_mode' => $engine->executionMode(),
                'verification_mode' => $engine->verificationMode(),
                'assets' => $engine->clientAssets(),
                'config' => $engine->clientManifest($activity, $context),
            ],
            'progress' => $progress,
            'hints_count' => (int) $hintStmt->fetchColumn(),
        ];
    }

    public function submit(int $userId, int $activityId, array $payload): array
    {
        $pdo = Database::connection();
        $activity = $this->activityForStudent($pdo, $userId, $activityId);
        $engine = $this->registry()->get((string) $activity['engine_key']);

        $activity['validator_config'] = $this->decodeJsonObject(
            $activity['validator_config'] ?? null,
            'validator_config'
        );
        $activity['bootstrap_config'] = $this->decodeJsonObject(
            $activity['bootstrap_config'] ?? null,
            'bootstrap_config'
        );
        $engine->validateActivityDefinition($activity);

        $encodedSubmission = $this->encodeBounded(
            $payload,
            max(1024, (int) config('lab.max_submission_bytes', 262144)),
            'A submissão do laboratório excede o limite permitido.'
        );

        $progress = $this->activityProgress($pdo, $userId, $activityId);
        $maxAttempts = isset($activity['max_attempts']) && $activity['max_attempts'] !== null
            ? (int) $activity['max_attempts']
            : null;

        if (
            $maxAttempts !== null
            && $maxAttempts > 0
            && (int) ($progress['attempts_count'] ?? 0) >= $maxAttempts
            && ($progress['status'] ?? '') !== 'completed'
        ) {
            throw new RuntimeException('O limite de tentativas desta atividade foi atingido.');
        }

        $context = [
            'user_id' => $userId,
            'course_id' => (int) $activity['course_id'],
            'lesson_id' => (int) $activity['lesson_id'],
            'state' => $this->courseState(
                $pdo,
                $userId,
                (int) $activity['course_id'],
                (string) $activity['engine_key']
            ),
        ];

        // A engine é a única responsável por transformar a submissão/evidência
        // em aprovação. O núcleo não aceita um "passed=true" diretamente do cliente.
        $evaluation = $engine->evaluate($activity, $payload, $context);

        $resultPayload = $this->encodeBounded(
            $evaluation->result,
            max(1024, (int) config('lab.max_result_bytes', 262144)),
            'O resultado do laboratório excede o limite permitido.'
        );

        $executionMs = max(0, min(
            3600000,
            (int) ($payload['execution_ms'] ?? 0)
        ));

        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                "INSERT INTO lab_activity_progress
                    (user_id, activity_id, status, attempts_count, hints_used, best_score,
                     completed_at, last_attempt_at, updated_at)
                 VALUES
                    (:user_id, :activity_id, 'available', 0, 0, 0, NULL, NULL, NOW())
                 ON DUPLICATE KEY UPDATE updated_at = updated_at"
            )->execute([
                'user_id' => $userId,
                'activity_id' => $activityId,
            ]);

            $lock = $pdo->prepare(
                "SELECT *
                 FROM lab_activity_progress
                 WHERE user_id = :user_id
                   AND activity_id = :activity_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $lock->execute([
                'user_id' => $userId,
                'activity_id' => $activityId,
            ]);
            $lockedProgress = $lock->fetch(PDO::FETCH_ASSOC) ?: [];
            $attemptNumber = (int) ($lockedProgress['attempts_count'] ?? 0) + 1;
            $hintsUsed = (int) ($lockedProgress['hints_used'] ?? 0);

            if (
                $maxAttempts !== null
                && $maxAttempts > 0
                && $attemptNumber > $maxAttempts
                && ($lockedProgress['status'] ?? '') !== 'completed'
            ) {
                throw new RuntimeException('O limite de tentativas desta atividade foi atingido.');
            }

            $xpEarned = 0;
            if ($evaluation->passed && ($lockedProgress['status'] ?? '') !== 'completed') {
                $xpEarned = $this->activityXpAfterHints(
                    $pdo,
                    $activityId,
                    (int) ($activity['xp_reward'] ?? 0),
                    $hintsUsed
                );
            }

            $insert = $pdo->prepare(
                "INSERT INTO lab_attempts
                    (activity_id, user_id, attempt_number, submission_payload,
                     result_payload, passed, score, verification_source,
                     execution_ms, hints_used, xp_earned, created_at)
                 VALUES
                    (:activity_id, :user_id, :attempt_number, :submission_payload,
                     :result_payload, :passed, :score, :verification_source,
                     :execution_ms, :hints_used, :xp_earned, NOW())"
            );
            $insert->execute([
                'activity_id' => $activityId,
                'user_id' => $userId,
                'attempt_number' => $attemptNumber,
                'submission_payload' => $encodedSubmission,
                'result_payload' => $resultPayload,
                'passed' => $evaluation->passed ? 1 : 0,
                'score' => round($evaluation->score, 2),
                'verification_source' => $evaluation->verificationSource,
                'execution_ms' => $executionMs,
                'hints_used' => $hintsUsed,
                'xp_earned' => $xpEarned,
            ]);
            $attemptId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                "UPDATE lab_activity_progress
                 SET status = CASE
                        WHEN status = 'completed' THEN 'completed'
                        WHEN :passed = 1 THEN 'completed'
                        ELSE 'in_progress'
                     END,
                     attempts_count = attempts_count + 1,
                     best_score = GREATEST(best_score, :score),
                     completed_at = CASE
                        WHEN :passed2 = 1 THEN COALESCE(completed_at, NOW())
                        ELSE completed_at
                     END,
                     last_attempt_at = NOW(),
                     updated_at = NOW()
                 WHERE user_id = :user_id
                   AND activity_id = :activity_id"
            )->execute([
                'passed' => $evaluation->passed ? 1 : 0,
                'passed2' => $evaluation->passed ? 1 : 0,
                'score' => round($evaluation->score, 2),
                'user_id' => $userId,
                'activity_id' => $activityId,
            ]);

            $lessonCompleted = false;

            if ($evaluation->passed) {
                $this->awardActivityXp(
                    $pdo,
                    $userId,
                    $activityId,
                    $xpEarned,
                    'Atividade prática concluída'
                );

                $stateVersion = max(
                    (int) ($activity['state_version'] ?? 0),
                    (int) ($evaluation->stateVersion ?? 0)
                );

                $pdo->prepare(
                    "INSERT INTO lab_course_state
                        (user_id, course_id, engine_key, state_version,
                         last_activity_id, updated_at)
                     VALUES
                        (:user_id, :course_id, :engine_key, :state_version,
                         :last_activity_id, NOW())
                     ON DUPLICATE KEY UPDATE
                        state_version = GREATEST(state_version, VALUES(state_version)),
                        last_activity_id = VALUES(last_activity_id),
                        updated_at = NOW()"
                )->execute([
                    'user_id' => $userId,
                    'course_id' => (int) $activity['course_id'],
                    'engine_key' => (string) $activity['engine_key'],
                    'state_version' => $stateVersion,
                    'last_activity_id' => $activityId,
                ]);

                if (
                    (new LessonCompletionPolicyService())->modeForLesson(
                        (int) $activity['lesson_id'],
                        $pdo
                    ) === 'lab'
                    && $this->allRequiredActivitiesComplete(
                        $pdo,
                        $userId,
                        (int) $activity['lesson_id']
                    )
                ) {
                    (new LessonCompletionService())->complete(
                        $pdo,
                        $userId,
                        (int) $activity['lesson_id'],
                        'Aula concluída após laboratório prático'
                    );
                    $lessonCompleted = true;
                }
            }

            $pdo->commit();

            return [
                'ok' => true,
                'attempt_id' => $attemptId,
                'attempt_number' => $attemptNumber,
                'passed' => $evaluation->passed,
                'score' => round($evaluation->score, 2),
                'message' => $evaluation->message,
                'result' => $evaluation->result,
                'xp_earned' => $xpEarned,
                'lesson_completed' => $lessonCompleted,
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function nextHint(int $userId, int $activityId): array
    {
        $pdo = Database::connection();
        $this->activityForStudent($pdo, $userId, $activityId);

        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                "INSERT INTO lab_activity_progress
                    (user_id, activity_id, status, attempts_count, hints_used, best_score,
                     completed_at, last_attempt_at, updated_at)
                 VALUES
                    (:user_id, :activity_id, 'available', 0, 0, 0, NULL, NULL, NOW())
                 ON DUPLICATE KEY UPDATE updated_at = updated_at"
            )->execute([
                'user_id' => $userId,
                'activity_id' => $activityId,
            ]);

            $progressStmt = $pdo->prepare(
                "SELECT *
                 FROM lab_activity_progress
                 WHERE user_id = :user_id
                   AND activity_id = :activity_id
                 LIMIT 1
                 FOR UPDATE"
            );
            $progressStmt->execute([
                'user_id' => $userId,
                'activity_id' => $activityId,
            ]);
            $progress = $progressStmt->fetch(PDO::FETCH_ASSOC) ?: [];

            $nextPosition = (int) ($progress['hints_used'] ?? 0) + 1;
            $attempts = (int) ($progress['attempts_count'] ?? 0);

            $hintStmt = $pdo->prepare(
                "SELECT id, position, title, content, xp_penalty, unlock_after_attempts
                 FROM lab_activity_hints
                 WHERE activity_id = :activity_id
                   AND position = :position
                   AND unlock_after_attempts <= :attempts
                 LIMIT 1"
            );
            $hintStmt->execute([
                'activity_id' => $activityId,
                'position' => $nextPosition,
                'attempts' => $attempts,
            ]);
            $hint = $hintStmt->fetch(PDO::FETCH_ASSOC);

            if (!$hint) {
                $pdo->rollBack();
                throw new RuntimeException(
                    'Nenhuma nova dica está disponível neste momento. Tente resolver o desafio novamente.'
                );
            }

            $pdo->prepare(
                "UPDATE lab_activity_progress
                 SET hints_used = GREATEST(hints_used, :position),
                     status = CASE
                        WHEN status = 'completed' THEN 'completed'
                        ELSE 'in_progress'
                     END,
                     updated_at = NOW()
                 WHERE user_id = :user_id
                   AND activity_id = :activity_id"
            )->execute([
                'position' => (int) $hint['position'],
                'user_id' => $userId,
                'activity_id' => $activityId,
            ]);

            $pdo->commit();

            return [
                'position' => (int) $hint['position'],
                'title' => (string) ($hint['title'] ?: 'Dica ' . (int) $hint['position']),
                'content' => (string) $hint['content'],
                'xp_penalty' => (int) $hint['xp_penalty'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function firstPendingRequiredActivity(int $userId, int $lessonId): ?int
    {
        $pdo = Database::connection();

        if (!$this->tableExists($pdo, 'lab_activities')) {
            return null;
        }

        $stmt = $pdo->prepare(
            "SELECT la.id
             FROM lab_activities la
             LEFT JOIN lab_activity_progress lap
               ON lap.activity_id = la.id
              AND lap.user_id = :user_id
             WHERE la.lesson_id = :lesson_id
               AND la.status = 'published'
               AND la.is_required = 1
               AND COALESCE(lap.status, 'available') <> 'completed'
             ORDER BY la.position, la.id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);

        $id = (int) ($stmt->fetchColumn() ?: 0);
        return $id > 0 ? $id : null;
    }

    private function activityForStudent(PDO $pdo, int $userId, int $activityId): array
    {
        if (!$this->tableExists($pdo, 'lab_activities')) {
            throw new RuntimeException('O motor de laboratórios ainda não foi instalado no banco.');
        }

        $stmt = $pdo->prepare(
            "SELECT la.*,
                    l.title AS lesson_title,
                    p.id AS phase_id,
                    p.title AS phase_title,
                    m.id AS module_id,
                    m.title AS module_title,
                    m.course_id,
                    c.title AS course_title,
                    ulp.status AS lesson_progress_status,
                    ulp.progress_pct AS lesson_progress_pct
             FROM lab_activities la
             INNER JOIN lessons l
               ON l.id = la.lesson_id
              AND l.status = 'published'
             INNER JOIN phases p
               ON p.id = l.phase_id
              AND p.status = 'published'
             INNER JOIN modules m
               ON m.id = p.module_id
              AND m.status = 'published'
             INNER JOIN courses c
               ON c.id = m.course_id
              AND c.status = 'published'
             INNER JOIN enrollments e
               ON e.course_id = c.id
              AND e.user_id = :user_id
              AND e.status = 'active'
             INNER JOIN user_lesson_progress ulp
               ON ulp.lesson_id = l.id
              AND ulp.user_id = :progress_user_id
              AND ulp.status <> 'locked'
             WHERE la.id = :activity_id
               AND la.status = 'published'
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'progress_user_id' => $userId,
            'activity_id' => $activityId,
        ]);
        $activity = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$activity) {
            throw new RuntimeException('Atividade prática indisponível para sua matrícula.');
        }

        if (!(new CourseSettingsService())->enabled(
            (int) $activity['course_id'],
            'lab_enabled',
            $pdo,
            false
        )) {
            throw new RuntimeException('O laboratório está desabilitado para este curso.');
        }

        if (
            ($activity['unlock_rule'] ?? 'reading_complete') === 'reading_complete'
            && (float) ($activity['lesson_progress_pct'] ?? 0) < 100
        ) {
            throw new RuntimeException('Conclua a leitura da aula antes de iniciar o laboratório.');
        }

        return $activity;
    }

    private function activityProgress(PDO $pdo, int $userId, int $activityId): array
    {
        $stmt = $pdo->prepare(
            "SELECT status, attempts_count, hints_used, best_score,
                    completed_at, last_attempt_at
             FROM lab_activity_progress
             WHERE user_id = :user_id
               AND activity_id = :activity_id
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'activity_id' => $activityId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'status' => 'available',
            'attempts_count' => 0,
            'hints_used' => 0,
            'best_score' => 0,
            'completed_at' => null,
            'last_attempt_at' => null,
        ];
    }

    private function courseState(PDO $pdo, int $userId, int $courseId, string $engineKey): array
    {
        $stmt = $pdo->prepare(
            "SELECT state_version, last_activity_id, updated_at
             FROM lab_course_state
             WHERE user_id = :user_id
               AND course_id = :course_id
               AND engine_key = :engine_key
             LIMIT 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
            'engine_key' => $engineKey,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'state_version' => 0,
            'last_activity_id' => null,
            'updated_at' => null,
        ];
    }

    private function allRequiredActivitiesComplete(
        PDO $pdo,
        int $userId,
        int $lessonId
    ): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(la.id) AS total,
                    SUM(CASE WHEN lap.status = 'completed' THEN 1 ELSE 0 END) AS completed
             FROM lab_activities la
             LEFT JOIN lab_activity_progress lap
               ON lap.activity_id = la.id
              AND lap.user_id = :user_id
             WHERE la.lesson_id = :lesson_id
               AND la.status = 'published'
               AND la.is_required = 1"
        );
        $stmt->execute([
            'user_id' => $userId,
            'lesson_id' => $lessonId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $total = (int) ($row['total'] ?? 0);
        return $total > 0 && $total === (int) ($row['completed'] ?? 0);
    }

    private function activityXpAfterHints(
        PDO $pdo,
        int $activityId,
        int $baseXp,
        int $hintsUsed
    ): int {
        if ($baseXp <= 0 || $hintsUsed <= 0) {
            return max(0, $baseXp);
        }

        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(xp_penalty), 0)
             FROM lab_activity_hints
             WHERE activity_id = :activity_id
               AND position <= :hints_used"
        );
        $stmt->execute([
            'activity_id' => $activityId,
            'hints_used' => $hintsUsed,
        ]);

        return max(0, $baseXp - (int) $stmt->fetchColumn());
    }

    private function awardActivityXp(
        PDO $pdo,
        int $userId,
        int $activityId,
        int $xp,
        string $description
    ): void {
        if ($xp <= 0) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                "INSERT INTO xp_events
                    (user_id, event_type, reference_id, xp_amount, description)
                 VALUES
                    (:user_id, 'lab_activity_completed', :reference_id, :xp, :description)"
            );
            $stmt->execute([
                'user_id' => $userId,
                'reference_id' => $activityId,
                'xp' => $xp,
                'description' => $description,
            ]);
        } catch (PDOException $e) {
            $driverCode = (int) ($e->errorInfo[1] ?? 0);
            if ($e->getCode() === '23000' && $driverCode === 1062) {
                return;
            }
            throw $e;
        }

        $pdo->prepare(
            'UPDATE users SET xp_total = xp_total + :xp WHERE id = :user_id'
        )->execute([
            'xp' => $xp,
            'user_id' => $userId,
        ]);

        $xpStmt = $pdo->prepare('SELECT xp_total FROM users WHERE id = :user_id LIMIT 1');
        $xpStmt->execute(['user_id' => $userId]);
        $totalXp = (int) ($xpStmt->fetchColumn() ?: 0);

        $levelStmt = $pdo->prepare(
            "SELECT level_number
             FROM levels
             WHERE min_xp <= :xp
               AND (max_xp IS NULL OR max_xp >= :xp_max)
             ORDER BY level_number DESC
             LIMIT 1"
        );
        $levelStmt->execute([
            'xp' => $totalXp,
            'xp_max' => $totalXp,
        ]);
        $level = (int) ($levelStmt->fetchColumn() ?: 0);

        if ($level > 0) {
            $pdo->prepare(
                'UPDATE users SET current_level = :level WHERE id = :user_id'
            )->execute([
                'level' => $level,
                'user_id' => $userId,
            ]);
        }
    }

    private function publicActivity(array $activity): array
    {
        return [
            'id' => (int) $activity['id'],
            'lesson_id' => (int) $activity['lesson_id'],
            'course_id' => (int) $activity['course_id'],
            'engine_key' => (string) $activity['engine_key'],
            'activity_type' => (string) $activity['activity_type'],
            'title' => (string) $activity['title'],
            'instructions' => (string) $activity['instructions'],
            'starter_code' => (string) ($activity['starter_code'] ?? ''),
            'validator_type' => (string) $activity['validator_type'],
            // validator_config e bootstrap_config ficam no servidor. A engine
            // decide explicitamente o que pode sair em clientManifest().
            'state_version' => (int) $activity['state_version'],
            'xp_reward' => (int) $activity['xp_reward'],
            'is_required' => (int) $activity['is_required'] === 1,
            'max_attempts' => $activity['max_attempts'] !== null
                ? (int) $activity['max_attempts']
                : null,
            'position' => (int) $activity['position'],
            'course_title' => (string) $activity['course_title'],
            'module_title' => (string) $activity['module_title'],
            'phase_title' => (string) $activity['phase_title'],
            'lesson_title' => (string) $activity['lesson_title'],
        ];
    }

    private function decodeJsonObject(mixed $value, string $field): array
    {
        if ($value === null || trim((string) $value) === '') {
            return [];
        }

        $decoded = json_decode((string) $value, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Configuração inválida em ' . $field . '.');
        }

        return $decoded;
    }

    private function encodeBounded(array $value, int $limit, string $message): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
            | JSON_THROW_ON_ERROR
        );

        if (strlen($encoded) > $limit) {
            throw new RuntimeException($message);
        }

        return $encoded;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
             LIMIT 1"
        );
        $stmt->execute(['table_name' => $table]);

        return (bool) $stmt->fetchColumn();
    }

    private function registry(): LabEngineRegistry
    {
        return $this->registry ?? new LabEngineRegistry();
    }
}
