<?php
use App\Core\Session;

$success = Session::pullFlash('success');
$error = Session::pullFlash('error');
$firstName = explode(' ', trim((string) ($user['name'] ?? 'Estudante')))[0];

$difficultyLabels = [
    'beginner' => 'Iniciante',
    'intermediate' => 'Intermediário',
    'advanced' => 'Avançado',
];

$statusLabels = [
    'not_started' => 'Novo',
    'in_progress' => 'Em andamento',
    'completed' => 'Concluído',
];

$courseCount = (int) ($summary['courses'] ?? 0);
$completedCourses = (int) ($summary['completed_courses'] ?? 0);
$averageProgress = (float) ($summary['average_progress'] ?? 0);
$totalModules = (int) ($summary['modules'] ?? 0);
$xpTotal = (int) ($summary['xp_total'] ?? 0);
$levelTitle = (string) ($summary['level_title'] ?? 'Estudante');
?>
<section class="course-portal">
    <?php if ($success): ?><div class="alert success"><?= e((string) $success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= e((string) $error) ?></div><?php endif; ?>

    <header class="course-portal-hero">
        <div>
            <span class="course-portal-kicker">CURSO MI • ÁREA DO ESTUDANTE</span>
            <h1>Olá, <?= e($firstName) ?>. Qual curso vamos estudar?</h1>
            <p>Você vê aqui somente os cursos que foram liberados para sua conta pelo administrador.</p>
        </div>
        <div class="course-portal-profile">
            <span>Nível</span>
            <strong><?= e($levelTitle) ?></strong>
            <small><?= number_format($xpTotal, 0, ',', '.') ?> XP acumulados</small>
        </div>
    </header>

    <section class="course-portal-summary" id="resumo" aria-label="Resumo geral">
        <article><span>Cursos liberados</span><strong><?= $courseCount ?></strong><small>matrículas ativas</small></article>
        <article><span>Concluídos</span><strong><?= $completedCourses ?></strong><small>cursos finalizados</small></article>
        <article><span>Progresso médio</span><strong><?= number_format($averageProgress, 0, ',', '.') ?>%</strong><small>entre seus cursos</small></article>
        <article><span>Módulos disponíveis</span><strong><?= $totalModules ?></strong><small>conteúdos publicados</small></article>
    </section>

    <section class="course-portal-section" id="meus-cursos">
        <div class="course-portal-section-head">
            <div>
                <span>MINHA ÁREA DE ESTUDO</span>
                <h2>Meus cursos</h2>
                <p>Clique em um card para entrar no ambiente daquele curso.</p>
            </div>
        </div>

        <?php if (empty($courses)): ?>
            <div class="course-portal-empty">
                <span>🎓</span>
                <h3>Nenhum curso liberado ainda</h3>
                <p>Sua conta está ativa, mas ainda não existe uma matrícula ativa em curso publicado. Entre em contato com o administrador da plataforma.</p>
            </div>
        <?php else: ?>
            <div class="course-portal-grid">
                <?php foreach ($courses as $index => $course): ?>
                    <?php
                    $progress = max(0, min(100, (float) ($course['progress_pct'] ?? 0)));
                    $status = (string) ($course['progress_status'] ?? 'not_started');
                    $continue = $course['continue_lesson'] ?? null;
                    $cover = trim((string) ($course['cover_image'] ?? ''));
                    $coverSrc = '';
                    if ($cover !== '') {
                        $coverSrc = preg_match('#^https?://#i', $cover) === 1
                            ? $cover
                            : url('/' . ltrim($cover, '/'));
                    }
                    $initial = mb_strtoupper(mb_substr((string) $course['title'], 0, 1));
                    ?>
                    <a class="course-portal-card" href="<?= e(url('/curso/' . (int) $course['id'])) ?>">
                        <div class="course-portal-cover">
                            <?php if ($coverSrc !== ''): ?>
                                <img src="<?= e($coverSrc) ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <span class="course-portal-cover-fallback"><?= e($initial) ?></span>
                            <?php endif; ?>
                            <div class="course-portal-cover-overlay"></div>
                            <span class="course-portal-index"><?= str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) ?></span>
                            <span class="course-portal-status is-<?= e($status) ?>"><?= e($statusLabels[$status] ?? 'Disponível') ?></span>
                        </div>

                        <div class="course-portal-card-body">
                            <div class="course-portal-card-meta">
                                <span><?= e($difficultyLabels[$course['difficulty']] ?? $course['difficulty']) ?></span>
                                <span><?= (int) $course['module_count'] ?> módulos</span>
                                <span><?= (int) $course['lesson_count'] ?> aulas</span>
                            </div>

                            <h3><?= e($course['title']) ?></h3>
                            <p><?= e($course['short_description'] ?: 'Curso disponível para sua matrícula.') ?></p>

                            <?php if ($continue): ?>
                                <div class="course-portal-resume">
                                    <span>RETOMAR</span>
                                    <strong><?= e($continue['lesson_title']) ?></strong>
                                    <small><?= e($continue['module_title']) ?> • <?= e($continue['phase_title']) ?></small>
                                </div>
                            <?php endif; ?>

                            <div class="course-portal-progress">
                                <div>
                                    <span>Progresso</span>
                                    <strong><?= number_format($progress, 0, ',', '.') ?>%</strong>
                                </div>
                                <div class="progress"><span style="width:<?= e((string) $progress) ?>%"></span></div>
                            </div>

                            <div class="course-portal-card-footer">
                                <span><?= $course['last_accessed_at'] ? 'Último acesso ' . e(date('d/m/Y', strtotime((string) $course['last_accessed_at']))) : 'Pronto para começar' ?></span>
                                <strong><?= $progress > 0 ? 'Continuar curso' : 'Entrar no curso' ?> →</strong>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</section>
