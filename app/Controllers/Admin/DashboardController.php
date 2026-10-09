<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use DateTimeImmutable;
use PDO;

final class DashboardController extends Controller
{
    private const ALLOWED_PERIODS = [7, 30, 90];

    public function index(Request $request): void
    {
        $pdo = Database::connection();

        $period = (int) $request->input('period', 30);
        if (!in_array($period, self::ALLOWED_PERIODS, true)) {
            $period = 30;
        }

        $startDate = (new DateTimeImmutable('today'))
            ->modify('-' . ($period - 1) . ' days')
            ->format('Y-m-d 00:00:00');

        $hasExercises = $this->tableExists($pdo, 'exercise_sessions')
            && $this->tableExists($pdo, 'exercise_answers')
            && $this->tableExists($pdo, 'exercise_session_questions');

        $statusRow = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(u.status = 'active') AS active_count,
                SUM(u.status = 'inactive') AS inactive_count,
                SUM(u.status = 'blocked') AS blocked_count
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'student'"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $questionRow = $pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(difficulty = 'easy') AS easy_count,
                SUM(difficulty = 'medium') AS medium_count,
                SUM(difficulty = 'hard') AS hard_count
             FROM questions
             WHERE active = 1"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $quizFinished = (int) $pdo->query(
            "SELECT COUNT(*) FROM quiz_attempts WHERE status = 'finished'"
        )->fetchColumn();
        $simulationFinished = (int) $pdo->query(
            "SELECT COUNT(*) FROM simulation_attempts WHERE status = 'finished'"
        )->fetchColumn();
        $exerciseFinished = $hasExercises
            ? (int) $pdo->query("SELECT COUNT(*) FROM exercise_sessions WHERE status = 'finished'")->fetchColumn()
            : 0;

        $stats = [
            'students' => (int) ($statusRow['total'] ?? 0),
            'active_students' => (int) ($statusRow['active_count'] ?? 0),
            'inactive_students' => (int) ($statusRow['inactive_count'] ?? 0),
            'blocked_students' => (int) ($statusRow['blocked_count'] ?? 0),
            'courses' => (int) $pdo->query("SELECT COUNT(*) FROM courses WHERE status <> 'archived'")->fetchColumn(),
            'modules' => (int) $pdo->query("SELECT COUNT(*) FROM modules WHERE status = 'published'")->fetchColumn(),
            'lessons' => (int) $pdo->query("SELECT COUNT(*) FROM lessons WHERE status = 'published'")->fetchColumn(),
            'questions' => (int) ($questionRow['total'] ?? 0),
            'questions_easy' => (int) ($questionRow['easy_count'] ?? 0),
            'questions_medium' => (int) ($questionRow['medium_count'] ?? 0),
            'questions_hard' => (int) ($questionRow['hard_count'] ?? 0),
            'quizzes' => $quizFinished,
            'simulations' => $simulationFinished,
            'exercises' => $exerciseFinished,
            'assessments' => $quizFinished + $simulationFinished + $exerciseFinished,
        ];

        $recentStudents = $pdo->query(
            "SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE r.slug = 'student'
             ORDER BY u.created_at DESC, u.id DESC
             LIMIT 4"
        )->fetchAll(PDO::FETCH_ASSOC);

        $buckets = $this->buildBuckets($period);
        $activity = $this->activitySeries($pdo, $startDate, $buckets, $hasExercises);
        $performance = $this->performanceSeries($pdo, $startDate, $buckets, $hasExercises);
        $contentDifficulty = $this->contentDifficulty($pdo, $startDate, $hasExercises);
        $attentionStudents = $this->attentionStudents($pdo, $hasExercises);

        $this->view('admin/dashboard', [
            'title' => 'Administração',
            'period' => $period,
            'stats' => $stats,
            'studentStatus' => [
                'active' => (int) ($statusRow['active_count'] ?? 0),
                'inactive' => (int) ($statusRow['inactive_count'] ?? 0),
                'blocked' => (int) ($statusRow['blocked_count'] ?? 0),
            ],
            'recentStudents' => $recentStudents,
            'attentionStudents' => $attentionStudents,
            'activity' => $activity,
            'performance' => $performance,
            'contentDifficulty' => $contentDifficulty,
            'hasExercises' => $hasExercises,
        ], 'layouts/admin');
    }

    private function activitySeries(PDO $pdo, string $startDate, array $buckets, bool $hasExercises): array
    {
        $series = [
            'lessons' => $this->countByDate(
                $pdo,
                "SELECT DATE(completed_at) AS day, COUNT(*) AS total
                 FROM user_lesson_progress
                 WHERE status = 'completed'
                   AND completed_at IS NOT NULL
                   AND completed_at >= :start_date
                 GROUP BY DATE(completed_at)",
                $startDate
            ),
            'quizzes' => $this->countByDate(
                $pdo,
                "SELECT DATE(finished_at) AS day, COUNT(*) AS total
                 FROM quiz_attempts
                 WHERE status = 'finished'
                   AND finished_at IS NOT NULL
                   AND finished_at >= :start_date
                 GROUP BY DATE(finished_at)",
                $startDate
            ),
            'simulations' => $this->countByDate(
                $pdo,
                "SELECT DATE(finished_at) AS day, COUNT(*) AS total
                 FROM simulation_attempts
                 WHERE status = 'finished'
                   AND finished_at IS NOT NULL
                   AND finished_at >= :start_date
                 GROUP BY DATE(finished_at)",
                $startDate
            ),
            'exercises' => [],
        ];

        if ($hasExercises) {
            $series['exercises'] = $this->countByDate(
                $pdo,
                "SELECT DATE(finished_at) AS day, COUNT(*) AS total
                 FROM exercise_sessions
                 WHERE status = 'finished'
                   AND finished_at IS NOT NULL
                   AND finished_at >= :start_date
                 GROUP BY DATE(finished_at)",
                $startDate
            );
        }

        return $this->bucketCounts($series, $buckets);
    }

    private function performanceSeries(PDO $pdo, string $startDate, array $buckets, bool $hasExercises): array
    {
        $series = [
            'quizzes' => $this->scoreByDate(
                $pdo,
                "SELECT DATE(finished_at) AS day,
                        SUM(percentage) AS score_sum,
                        COUNT(*) AS total
                 FROM quiz_attempts
                 WHERE status = 'finished'
                   AND finished_at IS NOT NULL
                   AND finished_at >= :start_date
                 GROUP BY DATE(finished_at)",
                $startDate
            ),
            'simulations' => $this->scoreByDate(
                $pdo,
                "SELECT DATE(finished_at) AS day,
                        SUM(percentage) AS score_sum,
                        COUNT(*) AS total
                 FROM simulation_attempts
                 WHERE status = 'finished'
                   AND finished_at IS NOT NULL
                   AND finished_at >= :start_date
                 GROUP BY DATE(finished_at)",
                $startDate
            ),
            'exercises' => [],
        ];

        if ($hasExercises) {
            $series['exercises'] = $this->scoreByDate(
                $pdo,
                "SELECT DATE(finished_at) AS day,
                        SUM(percentage) AS score_sum,
                        COUNT(*) AS total
                 FROM exercise_sessions
                 WHERE status = 'finished'
                   AND finished_at IS NOT NULL
                   AND finished_at >= :start_date
                 GROUP BY DATE(finished_at)",
                $startDate
            );
        }

        return $this->bucketScores($series, $buckets);
    }

    private function contentDifficulty(PDO $pdo, string $startDate, bool $hasExercises): array
    {
        $parts = [
            "SELECT q.module_id,
                    SUM(qa.is_correct) AS correct_count,
                    COUNT(*) AS answer_count
             FROM quiz_answers qa
             INNER JOIN quiz_attempts qat ON qat.id = qa.attempt_id AND qat.status = 'finished'
             INNER JOIN questions q ON q.id = qa.question_id
             WHERE q.module_id IS NOT NULL
               AND qa.answered_at >= :quiz_start
             GROUP BY q.module_id",
            "SELECT q.module_id,
                    SUM(sa.is_correct) AS correct_count,
                    COUNT(*) AS answer_count
             FROM simulation_answers sa
             INNER JOIN simulation_attempts sat ON sat.id = sa.attempt_id AND sat.status = 'finished'
             INNER JOIN questions q ON q.id = sa.question_id
             WHERE q.module_id IS NOT NULL
               AND sa.answered_at >= :simulation_start
             GROUP BY q.module_id",
        ];
        $params = [
            'quiz_start' => $startDate,
            'simulation_start' => $startDate,
        ];

        if ($hasExercises) {
            $parts[] = "SELECT es.module_id,
                               SUM(ea.is_correct) AS correct_count,
                               COUNT(*) AS answer_count
                        FROM exercise_answers ea
                        INNER JOIN exercise_session_questions esq ON esq.id = ea.session_question_id
                        INNER JOIN exercise_sessions es ON es.id = esq.session_id AND es.status = 'finished'
                        WHERE es.module_id IS NOT NULL
                          AND ea.answered_at >= :exercise_start
                        GROUP BY es.module_id";
            $params['exercise_start'] = $startDate;
        }

        $sql = "SELECT m.id, m.title,
                       SUM(x.correct_count) AS correct_count,
                       SUM(x.answer_count) AS answer_count,
                       ROUND((SUM(x.correct_count) / NULLIF(SUM(x.answer_count), 0)) * 100, 1) AS accuracy
                FROM (" . implode(' UNION ALL ', $parts) . ") x
                INNER JOIN modules m ON m.id = x.module_id
                GROUP BY m.id, m.title
                HAVING SUM(x.answer_count) >= 3
                ORDER BY accuracy ASC, answer_count DESC
                LIMIT 4";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function attentionStudents(PDO $pdo, bool $hasExercises): array
    {
        $students = $pdo->query(
            "SELECT u.id, u.name, u.email, u.last_login_at, u.created_at,
                    COALESCE(AVG(ucp.progress_pct), 0) AS progress_pct
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN user_course_progress ucp ON ucp.user_id = u.id
             WHERE r.slug = 'student'
               AND u.status = 'active'
             GROUP BY u.id, u.name, u.email, u.last_login_at, u.created_at
             ORDER BY u.id"
        )->fetchAll(PDO::FETCH_ASSOC);

        if ($students === []) {
            return [];
        }

        $performanceParts = [
            "SELECT user_id, percentage FROM quiz_attempts WHERE status = 'finished'",
            "SELECT user_id, percentage FROM simulation_attempts WHERE status = 'finished'",
        ];
        if ($hasExercises) {
            $performanceParts[] = "SELECT user_id, percentage FROM exercise_sessions WHERE status = 'finished'";
        }

        $perfRows = $pdo->query(
            "SELECT user_id, COUNT(*) AS attempts, AVG(percentage) AS average_score
             FROM (" . implode(' UNION ALL ', $performanceParts) . ") p
             GROUP BY user_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        $performance = [];
        foreach ($perfRows as $row) {
            $performance[(int) $row['user_id']] = [
                'attempts' => (int) $row['attempts'],
                'average' => (float) $row['average_score'],
            ];
        }

        $today = new DateTimeImmutable('today');
        $result = [];

        foreach ($students as $student) {
            $created = new DateTimeImmutable((string) $student['created_at']);
            $reference = !empty($student['last_login_at'])
                ? new DateTimeImmutable((string) $student['last_login_at'])
                : $created;
            $daysInactive = max(0, (int) $reference->diff($today)->format('%r%a'));
            $accountDays = max(0, (int) $created->diff($today)->format('%r%a'));
            $perf = $performance[(int) $student['id']] ?? ['attempts' => 0, 'average' => 0.0];
            $progress = (float) $student['progress_pct'];

            $reasons = [];
            $priority = 0;

            if ($daysInactive >= 14) {
                $reasons[] = 'Sem acesso há ' . $daysInactive . ' dias';
                $priority += 5;
            } elseif ($daysInactive >= 7) {
                $reasons[] = 'Sem acesso há ' . $daysInactive . ' dias';
                $priority += 3;
            }

            if ($perf['attempts'] >= 2 && $perf['average'] < 70) {
                $reasons[] = 'Média de avaliações ' . number_format($perf['average'], 0, ',', '.') . '%';
                $priority += $perf['average'] < 55 ? 5 : 3;
            }

            if ($accountDays >= 7 && $progress <= 5.0) {
                $reasons[] = 'Progresso médio ' . number_format($progress, 0, ',', '.') . '%';
                $priority += 2;
            }

            if ($reasons === []) {
                continue;
            }

            $result[] = [
                'id' => (int) $student['id'],
                'name' => (string) $student['name'],
                'email' => (string) $student['email'],
                'reason' => $reasons[0],
                'priority' => $priority,
            ];
        }

        usort($result, static function (array $a, array $b): int {
            return $b['priority'] <=> $a['priority'];
        });

        return array_slice($result, 0, 4);
    }

    private function countByDate(PDO $pdo, string $sql, string $startDate): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['start_date' => $startDate]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['day']] = (int) $row['total'];
        }

        return $result;
    }

    private function scoreByDate(PDO $pdo, string $sql, string $startDate): array
    {
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['start_date' => $startDate]);

        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['day']] = [
                'sum' => (float) $row['score_sum'],
                'count' => (int) $row['total'],
            ];
        }

        return $result;
    }

    private function buildBuckets(int $period): array
    {
        $today = new DateTimeImmutable('today');
        $start = $today->modify('-' . ($period - 1) . ' days');
        $targetBuckets = min(10, $period);
        $bucketSize = (int) ceil($period / $targetBuckets);
        $buckets = [];

        for ($offset = 0; $offset < $period; $offset += $bucketSize) {
            $bucketStart = $start->modify('+' . $offset . ' days');
            $lastOffset = min($period - 1, $offset + $bucketSize - 1);
            $bucketEnd = $start->modify('+' . $lastOffset . ' days');
            $buckets[] = [
                'start' => $bucketStart->format('Y-m-d'),
                'end' => $bucketEnd->format('Y-m-d'),
                'label' => $bucketStart->format('d/m') === $bucketEnd->format('d/m')
                    ? $bucketStart->format('d/m')
                    : $bucketStart->format('d/m') . '–' . $bucketEnd->format('d/m'),
            ];
        }

        return $buckets;
    }

    private function bucketCounts(array $series, array $buckets): array
    {
        $result = ['labels' => array_column($buckets, 'label')];

        foreach ($series as $name => $daily) {
            $values = array_fill(0, count($buckets), 0);
            foreach ($daily as $day => $count) {
                $index = $this->bucketIndex((string) $day, $buckets);
                if ($index !== null) {
                    $values[$index] += (int) $count;
                }
            }
            $result[$name] = $values;
        }

        return $result;
    }

    private function bucketScores(array $series, array $buckets): array
    {
        $result = ['labels' => array_column($buckets, 'label')];

        foreach ($series as $name => $daily) {
            $sums = array_fill(0, count($buckets), 0.0);
            $counts = array_fill(0, count($buckets), 0);

            foreach ($daily as $day => $score) {
                $index = $this->bucketIndex((string) $day, $buckets);
                if ($index === null) {
                    continue;
                }
                $sums[$index] += (float) ($score['sum'] ?? 0);
                $counts[$index] += (int) ($score['count'] ?? 0);
            }

            $values = [];
            foreach ($sums as $index => $sum) {
                $values[] = $counts[$index] > 0
                    ? round($sum / $counts[$index], 1)
                    : null;
            }
            $result[$name] = $values;
        }

        return $result;
    }

    private function bucketIndex(string $day, array $buckets): ?int
    {
        foreach ($buckets as $index => $bucket) {
            if ($day >= $bucket['start'] && $day <= $bucket['end']) {
                return $index;
            }
        }

        return null;
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
}
