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

$achievementIcons = [
    'lesson' => '◆',
    'phase' => '◇',
    'module' => '⬢',
    'score' => '★',
    'streak' => '🔥',
    'xp' => '✦',
    'simulation' => '🎯',
    'special' => '🏆',
];
?>
<section class="dashboard-page">
    <?php if ($success): ?><div class="alert success reward-pop"><?= e($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <header class="mission-hero">
        <div class="mission-hero-copy">
            <span class="eyebrow">CENTRAL DE TREINAMENTO</span>
            <h1>Continue sua missão, <?= e($firstName) ?>.</h1>
            <p>Progresso claro, metas curtas e foco no que realmente precisa ser estudado agora.</p>

            <?php if ($continueLesson): ?>
                <div class="continue-card">
                    <div class="continue-card-main">
                        <span class="continue-label">CONTINUE DE ONDE PAROU</span>
                        <h2><?= e($continueLesson['lesson_title']) ?></h2>
                        <p><?= e($continueLesson['module_title']) ?> • <?= e($continueLesson['phase_title']) ?></p>
                    </div>
                    <div class="continue-progress">
                        <strong><?= number_format((float) $continueLesson['progress_pct'], 0) ?>%</strong>
                        <div class="progress"><span style="width:<?= (float) $continueLesson['progress_pct'] ?>%"></span></div>
                    </div>
                    <a class="primary mission-cta" href="<?= e(url('/aula/' . $continueLesson['lesson_id'])) ?>">Continuar aula →</a>
                </div>
            <?php elseif ($courses): ?>
                <a class="primary mission-cta" href="<?= e(url('/curso/' . $courses[0]['id'])) ?>">Abrir trilha principal →</a>
            <?php endif; ?>
        </div>

        <aside class="level-console" aria-label="Progresso de experiência">
            <div class="level-console-top">
                <span class="level-orb">✦</span>
                <div>
                    <small>NÍVEL <?= (int) ($currentLevel['level_number'] ?? 1) ?></small>
                    <strong><?= e($levelTitle) ?></strong>
                </div>
                <span class="xp-total"><?= number_format($xpTotal, 0, ',', '.') ?> XP</span>
            </div>

            <div class="xp-track-wrap">
                <div class="xp-track"><span style="width:<?= (float) $xpProgressPct ?>%"></span></div>
                <div class="xp-caption">
                    <span><?= number_format((int) $xpIntoLevel, 0, ',', '.') ?> / <?= number_format((int) $xpRange, 0, ',', '.') ?> XP</span>
                    <span>Próximo: <?= e($nextTitle) ?></span>
                </div>
            </div>

            <div class="hero-stat-grid">
                <div><span>🔥</span><strong><?= $streak ?></strong><small>dias seguidos</small></div>
                <div><span>🏆</span><strong><?= $achievementCount ?></strong><small>conquistas</small></div>
                <div><span>⚡</span><strong><?= number_format((int) $xpToday, 0, ',', '.') ?></strong><small>XP hoje</small></div>
                <div><span>◎</span><strong><?= number_format($avg, 0) ?>%</strong><small>progresso</small></div>
            </div>
        </aside>
    </header>

    <section class="dashboard-section" id="missoes">
        <div class="section-head section-head-modern">
            <div>
                <span class="eyebrow">DAILY QUESTS</span>
                <h2>Missões de hoje</h2>
            </div>
            <p>Micro-metas para manter constância sem transformar o estudo em maratona.</p>
        </div>

        <div class="quest-grid">
            <?php foreach ($dailyQuests as $quest): ?>
                <?php
                $current = (int) $quest['current'];
                $target = max(1, (int) $quest['target']);
                $done = $current >= $target;
                $pct = min(100, (int) round(($current / $target) * 100));
                ?>
                <article class="quest-card <?= $done ? 'completed' : '' ?>">
                    <div class="quest-icon"><?= e($quest['icon']) ?></div>
                    <div class="quest-copy">
                        <div class="quest-title-row">
                            <strong><?= e($quest['title']) ?></strong>
                            <span><?= $done ? 'CONCLUÍDA' : $current . '/' . $target ?></span>
                        </div>
                        <div class="progress"><span style="width:<?= $pct ?>%"></span></div>
                        <small><?= $done ? 'Meta diária cumprida.' : 'Progresso registrado automaticamente.' ?></small>
                    </div>
                    <div class="quest-check"><?= $done ? '✓' : $pct . '%' ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="dashboard-section" id="trilhas">
        <div class="section-head section-head-modern">
            <div>
                <span class="eyebrow">TRILHAS ATIVAS</span>
                <h2>Seus cursos</h2>
            </div>
            <p>Avance por módulos e fases; conteúdo complementar continua disponível para consulta.</p>
        </div>

        <?php if (!$courses): ?>
            <div class="card empty"><h2>Nenhum curso ativo</h2><p>Sua matrícula ainda não foi liberada.</p></div>
        <?php else: ?>
            <div class="course-grid gamer-course-grid">
                <?php foreach ($courses as $c): ?>
                    <article class="course-card gamer-course-card">
                        <div class="course-top">
                            <span class="badge active">ATIVO</span>
                            <span class="difficulty-chip"><?= e(mb_strtoupper($c['difficulty'] ?? 'advanced')) ?></span>
                        </div>
                        <div class="course-icon">◆</div>
                        <h3><?= e($c['title']) ?></h3>
                        <p><?= e($c['short_description'] ?? '') ?></p>
                        <div class="meta">
                            <span><?= (int) $c['module_count'] ?> módulos</span>
                            <span><?= number_format((float) $c['average_score'], 0) ?>% média</span>
                        </div>
                        <div class="progress-row"><span>Progresso geral</span><strong><?= number_format((float) $c['progress_pct'], 0) ?>%</strong></div>
                        <div class="progress"><span style="width:<?= (float) $c['progress_pct'] ?>%"></span></div>
                        <a class="primary" href="<?= e(url('/curso/' . $c['id'])) ?>">Continuar missão →</a>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="dashboard-grid-two" id="conquistas">
        <div class="dashboard-section">
            <div class="section-head section-head-modern compact-head">
                <div><span class="eyebrow">COLEÇÃO</span><h2>Conquistas</h2></div>
            </div>

            <div class="achievement-grid">
                <?php foreach ($achievements as $achievement): ?>
                    <?php
                    $unlocked = (int) ($achievement['unlocked'] ?? 0) === 1;
                    $type = (string) ($achievement['achievement_type'] ?? 'special');
                    ?>
                    <article class="achievement-card <?= $unlocked ? 'unlocked' : 'locked' ?>">
                        <div class="achievement-medal"><?= e($achievementIcons[$type] ?? '◆') ?></div>
                        <div>
                            <span><?= $unlocked ? 'DESBLOQUEADA' : 'EM PROGRESSO' ?></span>
                            <strong><?= e($achievement['name']) ?></strong>
                            <p><?= e($achievement['description']) ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </div>

        <aside class="profile-panel card" id="perfil">
            <span class="eyebrow">SEU PERFIL</span>
            <div class="profile-identity">
                <div class="profile-avatar"><?= e(mb_strtoupper(mb_substr($user['name'] ?? 'E', 0, 1))) ?></div>
                <div><h2><?= e($user['name'] ?? 'Estudante') ?></h2><p><?= e($levelTitle) ?> • Nível <?= (int) ($currentLevel['level_number'] ?? 1) ?></p></div>
            </div>
            <div class="profile-stats">
                <div><small>XP total</small><strong><?= number_format($xpTotal, 0, ',', '.') ?></strong></div>
                <div><small>Sequência</small><strong><?= $streak ?> dias</strong></div>
                <div><small>Melhor sequência</small><strong><?= $bestStreak ?> dias</strong></div>
                <div><small>Conquistas</small><strong><?= $achievementCount ?></strong></div>
            </div>
            <p class="profile-note">Use o modo foco dentro das aulas para reduzir distrações e deixar apenas conteúdo e navegação essenciais.</p>
        </aside>
    </section>
</section>
