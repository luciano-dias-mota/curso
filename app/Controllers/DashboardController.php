<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use PDO;

final class DashboardController extends Controller
{
    public function index(): void
    {
        if (Auth::isAdmin()) {
            $this->redirect('/admin');
        }

        $pdo = Database::connection();
        $userId = (int) Auth::id();
        $user = Auth::user() ?? [];

        $coursesStmt = $pdo->prepare(
            "SELECT c.id, c.title, c.slug, c.short_description, c.difficulty,
                    COALESCE(p.progress_pct, 0) AS progress_pct,
                    COALESCE(p.average_score, 0) AS average_score,
                    p.last_accessed_at,
                    (SELECT COUNT(*)
                       FROM modules m
                      WHERE m.course_id = c.id
                        AND m.status = 'published') AS module_count
             FROM enrollments e
             INNER JOIN courses c ON c.id = e.course_id
             LEFT JOIN user_course_progress p
                    ON p.course_id = c.id
                   AND p.user_id = e.user_id
             WHERE e.user_id = :user_id
               AND e.status = 'active'
               AND c.status = 'published'
             ORDER BY COALESCE(p.last_accessed_at, e.enrolled_at, c.published_at) DESC,
                      c.position, c.id"
        );
        $coursesStmt->execute(['user_id' => $userId]);
        $courses = $coursesStmt->fetchAll(PDO::FETCH_ASSOC);

        $levelStmt = $pdo->prepare(
            "SELECT id, level_number, title, min_xp, max_xp
               FROM levels
              WHERE level_number = :level
              LIMIT 1"
        );
        $levelStmt->execute(['level' => (int) ($user['current_level'] ?? 1)]);
        $currentLevel = $levelStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'level_number' => (int) ($user['current_level'] ?? 1),
            'title' => 'Estudante',
            'min_xp' => 0,
            'max_xp' => null,
        ];

        $nextLevelStmt = $pdo->prepare(
            "SELECT id, level_number, title, min_xp, max_xp
               FROM levels
              WHERE level_number > :level
              ORDER BY level_number
              LIMIT 1"
        );
        $nextLevelStmt->execute(['level' => (int) $currentLevel['level_number']]);
        $nextLevel = $nextLevelStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $xpTotal = (int) ($user['xp_total'] ?? 0);
        $levelMinXp = (int) ($currentLevel['min_xp'] ?? 0);
        $nextMinXp = $nextLevel ? (int) $nextLevel['min_xp'] : max($xpTotal, $levelMinXp + 1);
        $xpRange = max(1, $nextMinXp - $levelMinXp);
        $xpIntoLevel = max(0, $xpTotal - $levelMinXp);
        $xpProgressPct = $nextLevel
            ? min(100, round(($xpIntoLevel / $xpRange) * 100, 1))
            : 100.0;

        $continueStmt = $pdo->prepare(
            "SELECT l.id AS lesson_id,
                    l.title AS lesson_title,
                    p.title AS phase_title,
                    m.title AS module_title,
                    c.id AS course_id,
                    c.title AS course_title,
                    ulp.status AS lesson_status,
                    COALESCE(ulp.progress_pct, 0) AS progress_pct,
                    COALESCE(ulp.last_accessed_at, ulp.started_at) AS last_accessed_at
             FROM user_lesson_progress ulp
             INNER JOIN lessons l ON l.id = ulp.lesson_id AND l.status = 'published'
             INNER JOIN phases p ON p.id = l.phase_id AND p.status = 'published'
             INNER JOIN modules m ON m.id = p.module_id AND m.status = 'published'
             INNER JOIN courses c ON c.id = m.course_id AND c.status = 'published'
             INNER JOIN enrollments e
                     ON e.course_id = c.id
                    AND e.user_id = :enrollment_user
                    AND e.status = 'active'
             WHERE ulp.user_id = :progress_user
               AND ulp.status IN ('in_progress', 'available')
             ORDER BY (ulp.status = 'in_progress') DESC,
                      COALESCE(ulp.last_accessed_at, ulp.started_at, '1970-01-01 00:00:00') DESC,
                      m.position, p.position, l.position
             LIMIT 1"
        );
        $continueStmt->execute([
            'enrollment_user' => $userId,
            'progress_user' => $userId,
        ]);
        $continueLesson = $continueStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        $achievementsStmt = $pdo->prepare(
            "SELECT a.id, a.name, a.slug, a.description, a.achievement_type,
                    a.requirement_value, a.xp_reward,
                    ua.unlocked_at,
                    CASE WHEN ua.id IS NULL THEN 0 ELSE 1 END AS unlocked
               FROM achievements a
               LEFT JOIN user_achievements ua
                      ON ua.achievement_id = a.id
                     AND ua.user_id = :user_id
              WHERE a.active = 1
              ORDER BY (ua.id IS NULL), ua.unlocked_at DESC, a.id
              LIMIT 8"
        );
        $achievementsStmt->execute(['user_id' => $userId]);
        $achievements = $achievementsStmt->fetchAll(PDO::FETCH_ASSOC);

        $achievementCountStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM user_achievements WHERE user_id = :user_id'
        );
        $achievementCountStmt->execute(['user_id' => $userId]);
        $achievementCount = (int) $achievementCountStmt->fetchColumn();

        $lessonsTodayStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM user_lesson_progress
              WHERE user_id = :user_id
                AND status = 'completed'
                AND completed_at >= CURDATE()
                AND completed_at < CURDATE() + INTERVAL 1 DAY"
        );
        $lessonsTodayStmt->execute(['user_id' => $userId]);
        $lessonsToday = (int) $lessonsTodayStmt->fetchColumn();

        $questionsTodayStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM quiz_answers qa
               INNER JOIN quiz_attempts qta ON qta.id = qa.attempt_id
              WHERE qta.user_id = :user_id
                AND qa.answered_at >= CURDATE()
                AND qa.answered_at < CURDATE() + INTERVAL 1 DAY"
        );
        $questionsTodayStmt->execute(['user_id' => $userId]);
        $questionsToday = (int) $questionsTodayStmt->fetchColumn();

        $studyTodayStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(duration_seconds), 0)
               FROM study_sessions
              WHERE user_id = :user_id
                AND started_at >= CURDATE()
                AND started_at < CURDATE() + INTERVAL 1 DAY"
        );
        $studyTodayStmt->execute(['user_id' => $userId]);
        $studyMinutesToday = (int) floor(((int) $studyTodayStmt->fetchColumn()) / 60);

        $xpTodayStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(xp_amount), 0)
               FROM xp_events
              WHERE user_id = :user_id
                AND created_at >= CURDATE()
                AND created_at < CURDATE() + INTERVAL 1 DAY"
        );
        $xpTodayStmt->execute(['user_id' => $userId]);
        $xpToday = (int) $xpTodayStmt->fetchColumn();

        $dailyQuests = [
            [
                'icon' => '▶',
                'title' => 'Concluir 2 aulas',
                'current' => $lessonsToday,
                'target' => 2,
                'unit' => 'aulas',
            ],
            [
                'icon' => '?',
                'title' => 'Responder 10 questões',
                'current' => $questionsToday,
                'target' => 10,
                'unit' => 'questões',
            ],
            [
                'icon' => '◷',
                'title' => 'Estudar por 30 minutos',
                'current' => $studyMinutesToday,
                'target' => 30,
                'unit' => 'min',
            ],
        ];

        $this->view(
            'student/dashboard',
            [
                'title' => 'Painel do Estudante',
                'user' => $user,
                'courses' => $courses,
                'currentLevel' => $currentLevel,
                'nextLevel' => $nextLevel,
                'xpProgressPct' => $xpProgressPct,
                'xpIntoLevel' => $xpIntoLevel,
                'xpRange' => $xpRange,
                'continueLesson' => $continueLesson,
                'achievements' => $achievements,
                'achievementCount' => $achievementCount,
                'dailyQuests' => $dailyQuests,
                'xpToday' => $xpToday,
            ],
            'layouts/student'
        );
    }
}
