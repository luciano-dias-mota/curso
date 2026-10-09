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

$pdo = Database::connection();

echo "=============================================================\n";
echo " DIAGNÓSTICO DAS CORREÇÕES - V4\n";
echo "=============================================================\n";
echo 'Banco: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n\n";

$checks = [];

$checks['login_attempts'] = (bool) $pdo->query(
    "SELECT 1
     FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'login_attempts'
     LIMIT 1"
)->fetchColumn();

$checks['xp_unique'] = (bool) $pdo->query(
    "SELECT 1
     FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'xp_events'
       AND INDEX_NAME = 'uq_xp_event_once'
     LIMIT 1"
)->fetchColumn();

$duplicateGroups = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM (
         SELECT user_id, event_type, reference_id
         FROM xp_events
         WHERE reference_id IS NOT NULL
         GROUP BY user_id, event_type, reference_id
         HAVING COUNT(*) > 1
     ) AS d"
)->fetchColumn();

$missingModule = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM enrollments e
     INNER JOIN (
         SELECT m1.course_id, m1.id AS module_id
         FROM modules m1
         WHERE m1.status = 'published'
           AND NOT EXISTS (
               SELECT 1
               FROM modules m2
               WHERE m2.course_id = m1.course_id
                 AND m2.status = 'published'
                 AND (m2.position < m1.position
                      OR (m2.position = m1.position AND m2.id < m1.id))
           )
     ) first_module ON first_module.course_id = e.course_id
     LEFT JOIN user_module_progress ump
       ON ump.user_id = e.user_id
      AND ump.module_id = first_module.module_id
     WHERE e.status = 'active'
       AND (ump.id IS NULL OR ump.status = 'locked')"
)->fetchColumn();

$missingPhase = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM enrollments e
     INNER JOIN (
         SELECT m1.course_id, m1.id AS module_id
         FROM modules m1
         WHERE m1.status = 'published'
           AND NOT EXISTS (
               SELECT 1
               FROM modules m2
               WHERE m2.course_id = m1.course_id
                 AND m2.status = 'published'
                 AND (m2.position < m1.position
                      OR (m2.position = m1.position AND m2.id < m1.id))
           )
     ) fm ON fm.course_id = e.course_id
     INNER JOIN (
         SELECT p1.module_id, p1.id AS phase_id
         FROM phases p1
         WHERE p1.status = 'published'
           AND NOT EXISTS (
               SELECT 1
               FROM phases p2
               WHERE p2.module_id = p1.module_id
                 AND p2.status = 'published'
                 AND (p2.position < p1.position
                      OR (p2.position = p1.position AND p2.id < p1.id))
           )
     ) fp ON fp.module_id = fm.module_id
     LEFT JOIN user_phase_progress upp
       ON upp.user_id = e.user_id
      AND upp.phase_id = fp.phase_id
     WHERE e.status = 'active'
       AND (upp.id IS NULL OR upp.status = 'locked')"
)->fetchColumn();

$missingLesson = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM enrollments e
     INNER JOIN (
         SELECT m1.course_id, m1.id AS module_id
         FROM modules m1
         WHERE m1.status = 'published'
           AND NOT EXISTS (
               SELECT 1
               FROM modules m2
               WHERE m2.course_id = m1.course_id
                 AND m2.status = 'published'
                 AND (m2.position < m1.position
                      OR (m2.position = m1.position AND m2.id < m1.id))
           )
     ) fm ON fm.course_id = e.course_id
     INNER JOIN (
         SELECT p1.module_id, p1.id AS phase_id
         FROM phases p1
         WHERE p1.status = 'published'
           AND NOT EXISTS (
               SELECT 1
               FROM phases p2
               WHERE p2.module_id = p1.module_id
                 AND p2.status = 'published'
                 AND (p2.position < p1.position
                      OR (p2.position = p1.position AND p2.id < p1.id))
           )
     ) fp ON fp.module_id = fm.module_id
     INNER JOIN (
         SELECT l1.phase_id, l1.id AS lesson_id
         FROM lessons l1
         WHERE l1.status = 'published'
           AND NOT EXISTS (
               SELECT 1
               FROM lessons l2
               WHERE l2.phase_id = l1.phase_id
                 AND l2.status = 'published'
                 AND (l2.position < l1.position
                      OR (l2.position = l1.position AND l2.id < l1.id))
           )
     ) fl ON fl.phase_id = fp.phase_id
     LEFT JOIN user_lesson_progress ulp
       ON ulp.user_id = e.user_id
      AND ulp.lesson_id = fl.lesson_id
     WHERE e.status = 'active'
       AND (ulp.id IS NULL OR ulp.status = 'locked')"
)->fetchColumn();

echo ($checks['login_attempts'] ? '[OK]' : '[FALHA]')
    . " tabela login_attempts\n";
echo ($checks['xp_unique'] ? '[OK]' : '[FALHA]')
    . " UNIQUE uq_xp_event_once\n";
echo ($duplicateGroups === 0 ? '[OK]' : '[FALHA]')
    . " duplicidades XP: {$duplicateGroups}\n";
echo ($missingModule === 0 ? '[OK]' : '[FALHA]')
    . " matrículas sem primeiro módulo liberado: {$missingModule}\n";
echo ($missingPhase === 0 ? '[OK]' : '[FALHA]')
    . " matrículas sem primeira fase liberada: {$missingPhase}\n";
echo ($missingLesson === 0 ? '[OK]' : '[FALHA]')
    . " matrículas sem primeira aula liberada: {$missingLesson}\n";

$failed = !$checks['login_attempts']
    || !$checks['xp_unique']
    || $duplicateGroups > 0
    || $missingModule > 0
    || $missingPhase > 0
    || $missingLesson > 0;

echo "\n" . ($failed
    ? 'ATENÇÃO: há itens pendentes.'
    : 'OK: banco preparado para as correções V4.'
) . "\n";

exit($failed ? 1 : 0);
