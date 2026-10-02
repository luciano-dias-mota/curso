<?php
use App\Core\Session;

$success = Session::pullFlash('success');
$error = Session::pullFlash('error');
$firstName = explode(' ', trim((string) ($user['name'] ?? 'Estudante')))[0];
$avg = $courses
    ? array_sum(array_map(static fn ($c) => (float) $c['progress_pct'], $courses)) / count($courses)
    : 0;

$levelTitle = (string) ($currentLevel['title'] ?? 'Estudante');
$nextTitle = (string) ($nextLevel['title'] ?? 'Nível máximo');
$xpTotal = (int) ($user['xp_total'] ?? 0);
$streak = (int) ($user['current_streak'] ?? 0);
$bestStreak = (int) ($user['best_streak'] ?? 0);
$primaryCourse = $courses[0] ?? null;
$heroTitle = $continueLesson['module_title'] ?? ($primaryCourse['title'] ?? 'Sua trilha de estudos');
$heroSubtitle = $continueLesson
    ? ($continueLesson['lesson_title'] . ' • ' . $continueLesson['phase_title'])
    : ($primaryCourse['short_description'] ?? 'Continue avançando na sua preparação.');
$heroProgress = $continueLesson
    ? (float) $continueLesson['progress_pct']
    : (float) ($primaryCourse['progress_pct'] ?? $avg);

$lessonTotal = (int) ($lessonOverview['total'] ?? 0);
$lessonDone = (int) ($lessonOverview['completed'] ?? 0);
$questionTotal = (int) ($questionOverview['total'] ?? 0);
$questionDone = (int) ($questionOverview['completed'] ?? 0);
$simulationTotal = (int) ($simulationOverview['total'] ?? 0);
$simulationDone = (int) ($simulationOverview['completed'] ?? 0);
$studySecondsTotal = (int) ($studySecondsTotal ?? 0);
$studyHours = intdiv($studySecondsTotal, 3600);
$studyMinutes = intdiv($studySecondsTotal % 3600, 60);

$achievementIcons = [
    'lesson' => '★',
    'phase' => '◆',
    'module' => '⬢',
    'score' => '✦',
    'streak' => '🔥',
    'xp' => '⚡',
    'simulation' => '🎯',
    'special' => '🏆',
];
?>
<section class="dashboard-page dashboard-command-center">
    <?php if ($success): ?><div class="alert success reward-pop"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <header class="reference-hero">
        <div class="reference-hero-content">
            <span class="continue-label">Continue de onde parou</span>
            <h1><?= e($heroTitle) ?></h1>
            <p><?= e($heroSubtitle) ?></p>

            <div class="hero-inline-progress">
                <div class="progress"><span style="width:<?= min(100, max(0, $heroProgress)) ?>%"></span></div>
                <strong><?= number_format($heroProgress, 0) ?>% concluída</strong>
            </div>

            <div class="hero-actions">
                <?php if ($continueLesson): ?>
                    <a class="primary" href="<?= e(url('/aula/' . $continueLesson['lesson_id'])) ?>"><span>▶</span>Continuar Aula</a>
                <?php elseif ($primaryCourse): ?>
                    <a class="primary" href="<?= e(url('/curso/' . $primaryCourse['id'])) ?>"><span>▶</span>Continuar Curso</a>
                <?php endif; ?>
                <?php if ($primaryCourse): ?>
                    <a class="secondary" href="<?= e(url('/curso/' . $primaryCourse['id'])) ?>"><span>↻</span>Ver Conteúdo</a>
                <?php endif; ?>
            </div>
        </div>
        <div class="reference-hero-art" aria-hidden="true"></div>
    </header>

    <section class="reference-stat-grid" aria-label="Resumo do estudante">
        <article class="reference-stat-card level-card">
            <span class="reference-stat-icon">★</span>
            <div><small>Nível Atual</small><strong><?= (int) ($currentLevel['level_number'] ?? 1) ?></strong><em><?= e($levelTitle) ?></em></div>
            <div class="mini-xp"><span style="width:<?= (float) $xpProgressPct ?>%"></span></div>
            <p><?= number_format($xpIntoLevel, 0, ',', '.') ?> / <?= number_format($xpRange, 0, ',', '.') ?> XP</p>
        </article>

        <article class="reference-stat-card streak-card">
            <span class="reference-stat-icon">🔥</span>
            <div><small>Sequência</small><strong><?= $streak ?></strong><em>dias seguidos</em></div>
        </article>

        <article class="reference-stat-card quest-stat-card">
            <span class="reference-stat-icon">✥</span>
            <div><small>Missões do Dia</small><strong><?= count(array_filter($dailyQuests, static fn ($q) => (int) $q['current'] >= (int) $q['target'])) ?>/<?= count($dailyQuests) ?></strong><em>concluídas</em></div>
        </article>

        <article class="reference-stat-card achievement-stat-card">
            <span class="reference-stat-icon">🏆</span>
            <div><small>Conquistas</small><strong><?= $achievementCount ?></strong><em>insígnias</em></div>
        </article>
    </section>

    <section class="reference-dashboard-grid" id="desempenho">
        <article class="reference-panel" id="missoes">
            <header class="reference-panel-head">
                <h2>Minhas Missões de Hoje</h2>
                <a href="#missoes">Ver todas</a>
            </header>

            <div class="reference-quest-list">
                <?php foreach ($dailyQuests as $quest): ?>
                    <?php
                    $current = (int) $quest['current'];
                    $target = max(1, (int) $quest['target']);
                    $done = $current >= $target;
                    $pct = min(100, (int) round(($current / $target) * 100));
                    ?>
                    <div class="reference-quest-row <?= $done ? 'done' : '' ?>">
                        <span class="reference-quest-icon"><?= $done ? '✓' : e($quest['icon']) ?></span>
                        <div>
                            <strong><?= e($quest['title']) ?></strong>
                            <small><?= min($current, $target) ?>/<?= $target ?> <?= e($quest['unit']) ?></small>
                        </div>
                        <span class="quest-percent"><?= $done ? 'OK' : $pct . '%' ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="reference-panel course-progress-panel">
            <header class="reference-panel-head">
                <h2>Meu Progresso no Curso</h2>
                <?php if ($primaryCourse): ?><a href="<?= e(url('/curso/' . $primaryCourse['id'])) ?>">Ver detalhes</a><?php endif; ?>
            </header>

            <div class="course-progress-body">
                <div class="progress-donut" style="--progress:<?= min(100, max(0, $avg)) ?>"><strong><?= number_format($avg, 0) ?>%</strong></div>
                <div class="course-progress-legend">
                    <div><i class="legend-purple"></i><span>Aulas concluídas</span><strong><?= $lessonDone ?>/<?= $lessonTotal ?></strong></div>
                    <div><i class="legend-blue"></i><span>Exercícios</span><strong><?= $questionDone ?>/<?= $questionTotal ?></strong></div>
                    <div><i class="legend-orange"></i><span>Simulados</span><strong><?= $simulationDone ?>/<?= $simulationTotal ?></strong></div>
                    <div><i class="legend-slate"></i><span>Tempo de estudo</span><strong><?= $studyHours ?>h <?= $studyMinutes ?>min</strong></div>
                </div>
            </div>
        </article>
    </section>

    <section class="dashboard-section reference-course-section" id="trilhas">
        <div class="section-head section-head-modern">
            <div><span class="eyebrow">MINHAS AULAS</span><h2>Trilhas ativas</h2></div>
            <p>Continue a partir do ponto em que parou ou revise um módulo já liberado.</p>
        </div>

        <?php if (!$courses): ?>
            <div class="card empty"><h2>Nenhum curso ativo</h2><p>Sua matrícula ainda não foi liberada.</p></div>
        <?php else: ?>
            <div class="course-grid gamer-course-grid">
                <?php foreach ($courses as $c): ?>
                    <article class="course-card gamer-course-card" data-searchable>
                        <div class="course-top"><span class="badge active">ATIVO</span><span class="difficulty-chip"><?= e(mb_strtoupper($c['difficulty'] ?? 'advanced')) ?></span></div>
                        <div class="course-icon">◆</div>
                        <h3><?= e($c['title']) ?></h3>
                        <p><?= e($c['short_description'] ?? '') ?></p>
                        <div class="meta"><span><?= (int) $c['module_count'] ?> módulos</span><span><?= number_format((float) $c['average_score'], 0) ?>% média</span></div>
                        <div class="progress-row"><span>Progresso geral</span><strong><?= number_format((float) $c['progress_pct'], 0) ?>%</strong></div>
                        <div class="progress"><span style="width:<?= (float) $c['progress_pct'] ?>%"></span></div>
                        <a class="primary" href="<?= e(url('/curso/' . $c['id'])) ?>">Continuar missão →</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-section" id="conquistas">
        <div class="section-head section-head-modern compact-head">
            <div><span class="eyebrow">CONQUISTAS</span><h2>Insígnias e marcos</h2></div>
        </div>
        <div class="achievement-grid reference-achievement-grid">
            <?php foreach ($achievements as $achievement): ?>
                <?php $unlocked = (int) ($achievement['unlocked'] ?? 0) === 1; $type = (string) ($achievement['achievement_type'] ?? 'special'); ?>
                <article class="achievement-card <?= $unlocked ? 'unlocked' : 'locked' ?>" data-searchable>
                    <div class="achievement-medal"><?= e($achievementIcons[$type] ?? '◆') ?></div>
                    <div><span><?= $unlocked ? 'DESBLOQUEADA' : 'EM PROGRESSO' ?></span><strong><?= e($achievement['name']) ?></strong><p><?= e($achievement['description']) ?></p></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <aside class="mobile-profile-summary" id="perfil">
        <div class="profile-avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['name'] ?? 'E'), 0, 1))) ?></div>
        <div><strong><?= e($user['name'] ?? 'Estudante') ?></strong><small>Nível <?= (int) ($currentLevel['level_number'] ?? 1) ?> • <?= number_format($xpTotal, 0, ',', '.') ?> XP • melhor sequência <?= $bestStreak ?> dias</small></div>
    </aside>
</section>
