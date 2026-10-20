<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Session;
use App\Services\CourseAccessService;
use App\Services\CourseExperienceRegistry;
use PDO;
use RuntimeException;

final class PmmtHomeController extends Controller
{
    public function show(int $id): void
    {
        $pdo = Database::connection();
        $userId = (int) Auth::id();
        $user = Auth::user() ?? [];

        try {
            $course = (new CourseAccessService())->requireCourseEnrollment(
                $userId,
                $id,
                $pdo
            );
        } catch (RuntimeException) {
            http_response_code(404);
            $this->view('errors/404');
            return;
        }

        if ((new CourseExperienceRegistry())->get(
            (string) ($course['experience_key'] ?? 'generic')
        )['key'] !== 'pmmt') {
            $this->redirect('/curso/' . $id);
        }

        $progressStmt = $pdo->prepare(
            "SELECT COALESCE(progress_pct, 0) AS progress_pct,
                    COALESCE(average_score, 0) AS average_score,
                    last_accessed_at
               FROM user_course_progress
              WHERE course_id = :course_id
                AND user_id = :user_id
              LIMIT 1"
        );
        $progressStmt->execute([
            'course_id' => $id,
            'user_id' => $userId,
        ]);
        $course = array_merge(
            $course,
            $progressStmt->fetch(PDO::FETCH_ASSOC) ?: [
                'progress_pct' => 0,
                'average_score' => 0,
                'last_accessed_at' => null,
            ]
        );

        $moduleCountStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM modules
              WHERE course_id = :course_id
                AND status = 'published'"
        );
        $moduleCountStmt->execute(['course_id' => $id]);
        $course['module_count'] = (int) $moduleCountStmt->fetchColumn();

        Session::put('active_course_id', $id);
        Session::put('active_course_experience', 'pmmt');

        $courses = [$course];

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
             INNER JOIN lessons l
               ON l.id = ulp.lesson_id
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
              AND e.user_id = :enrollment_user
              AND e.status = 'active'
             WHERE ulp.user_id = :progress_user
               AND c.id = :course_id
               AND ulp.status IN ('in_progress', 'available')
             ORDER BY
                (ulp.status = 'in_progress') DESC,
                COALESCE(ulp.last_accessed_at, ulp.started_at, '1970-01-01 00:00:00') DESC,
                m.position, p.position, l.position
             LIMIT 1"
        );
        $continueStmt->execute([
            'enrollment_user' => $userId,
            'progress_user' => $userId,
            'course_id' => $id,
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
                AND (a.course_id IS NULL OR a.course_id = :course_id)
              ORDER BY (ua.id IS NULL), ua.unlocked_at DESC, a.id
              LIMIT 8"
        );
        $achievementsStmt->execute([
            'user_id' => $userId,
            'course_id' => $id,
        ]);
        $achievements = $achievementsStmt->fetchAll(PDO::FETCH_ASSOC);

        $achievementCountStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM user_achievements ua
               INNER JOIN achievements a ON a.id = ua.achievement_id
              WHERE ua.user_id = :user_id
                AND (a.course_id IS NULL OR a.course_id = :course_id)"
        );
        $achievementCountStmt->execute([
            'user_id' => $userId,
            'course_id' => $id,
        ]);
        $achievementCount = (int) $achievementCountStmt->fetchColumn();

        $lessonsTodayStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM user_lesson_progress ulp
               INNER JOIN lessons l ON l.id = ulp.lesson_id
               INNER JOIN phases p ON p.id = l.phase_id
               INNER JOIN modules m ON m.id = p.module_id
              WHERE ulp.user_id = :user_id
                AND m.course_id = :course_id
                AND ulp.status = 'completed'
                AND ulp.completed_at >= CURDATE()
                AND ulp.completed_at < CURDATE() + INTERVAL 1 DAY"
        );
        $lessonsTodayStmt->execute([
            'user_id' => $userId,
            'course_id' => $id,
        ]);
        $lessonsToday = (int) $lessonsTodayStmt->fetchColumn();

        $questionsTodayStmt = $pdo->prepare(
            "SELECT COUNT(*)
               FROM quiz_answers qa
               INNER JOIN quiz_attempts qta ON qta.id = qa.attempt_id
               INNER JOIN quizzes qz ON qz.id = qta.quiz_id
              WHERE qta.user_id = :user_id
                AND qz.course_id = :course_id
                AND qa.answered_at >= CURDATE()
                AND qa.answered_at < CURDATE() + INTERVAL 1 DAY"
        );
        $questionsTodayStmt->execute([
            'user_id' => $userId,
            'course_id' => $id,
        ]);
        $questionsToday = (int) $questionsTodayStmt->fetchColumn();

        $studyTodayStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(duration_seconds), 0)
               FROM study_sessions
              WHERE user_id = :user_id
                AND course_id = :course_id
                AND started_at >= CURDATE()
                AND started_at < CURDATE() + INTERVAL 1 DAY"
        );
        $studyTodayStmt->execute([
            'user_id' => $userId,
            'course_id' => $id,
        ]);
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

        $lessonOverviewStmt = $pdo->prepare(
            "SELECT COUNT(DISTINCT l.id) AS total,
                    COUNT(DISTINCT CASE WHEN ulp.status = 'completed' THEN l.id END) AS completed
               FROM modules m
               INNER JOIN phases p ON p.module_id = m.id AND p.status = 'published'
               INNER JOIN lessons l ON l.phase_id = p.id AND l.status = 'published'
               LEFT JOIN user_lesson_progress ulp
                 ON ulp.lesson_id = l.id
                AND ulp.user_id = :user_id
              WHERE m.course_id = :course_id
                AND m.status = 'published'"
        );
        $lessonOverviewStmt->execute([
            'user_id' => $userId,
            'course_id' => $id,
        ]);
        $lessonOverview = $lessonOverviewStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0];

        $questionOverviewStmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(DISTINCT qq.question_id)
                   FROM quizzes qz
                   INNER JOIN quiz_questions qq ON qq.quiz_id = qz.id
                  WHERE qz.course_id = :course_id
                    AND qz.active = 1) AS total,
                (SELECT COUNT(DISTINCT qa.question_id)
                   FROM quiz_answers qa
                   INNER JOIN quiz_attempts qta ON qta.id = qa.attempt_id
                   INNER JOIN quizzes qz2 ON qz2.id = qta.quiz_id
                  WHERE qta.user_id = :answered_user
                    AND qz2.course_id = :course_id2) AS completed"
        );
        $questionOverviewStmt->execute([
            'course_id' => $id,
            'answered_user' => $userId,
            'course_id2' => $id,
        ]);
        $questionOverview = $questionOverviewStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0];

        $simulationOverviewStmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(DISTINCT s.id)
                   FROM simulations s
                  WHERE s.course_id = :course_id
                    AND s.active = 1) AS total,
                (SELECT COUNT(DISTINCT sa.simulation_id)
                   FROM simulation_attempts sa
                   INNER JOIN simulations s2 ON s2.id = sa.simulation_id
                  WHERE sa.user_id = :sim_done_user
                    AND sa.status = 'finished'
                    AND s2.course_id = :course_id2) AS completed"
        );
        $simulationOverviewStmt->execute([
            'course_id' => $id,
            'sim_done_user' => $userId,
            'course_id2' => $id,
        ]);
        $simulationOverview = $simulationOverviewStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'completed' => 0];

        $studyTotalStmt = $pdo->prepare(
            "SELECT COALESCE(SUM(duration_seconds), 0)
               FROM study_sessions
              WHERE user_id = :user_id
                AND course_id = :course_id"
        );
        $studyTotalStmt->execute([
            'user_id' => $userId,
            'course_id' => $id,
        ]);
        $studySecondsTotal = (int) $studyTotalStmt->fetchColumn();

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
            'student/pmmt/dashboard',
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
                'lessonOverview' => $lessonOverview,
                'questionOverview' => $questionOverview,
                'simulationOverview' => $simulationOverview,
                'studySecondsTotal' => $studySecondsTotal,
                'activeCourseId' => $id,
            ],
            'layouts/student'
        );
    }
}
