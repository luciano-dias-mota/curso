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

$publishedCount = (int) ($summary['published_courses'] ?? 0);
$accessibleCount = (int) ($summary['accessible_courses'] ?? 0);
$lockedCount = (int) ($summary['locked_courses'] ?? 0);

$renderCourseMedia = static function (array $course, bool $locked = false): void {
    $cover = trim((string) ($course['cover_image'] ?? ''));
    $slug = mb_strtolower((string) ($course['slug'] ?? ''));
    $title = mb_strtolower((string) ($course['title'] ?? ''));
    $isPmmt = str_contains($slug, 'pmmt')
        || str_contains($title, 'pmmt')
        || str_contains($title, 'mérito intelectual')
        || str_contains($title, 'merito intelectual');
    $isPostgres = str_contains($slug, 'postgres')
        || str_contains($title, 'postgres');

    if ($cover !== '') {
        $coverSrc = preg_match('#^https?://#i', $cover) === 1
            ? $cover
            : url('/' . ltrim($cover, '/'));
        ?>
        <img src="<?= e($coverSrc) ?>" alt="" loading="lazy">
        <?php
    } elseif ($isPmmt) {
        ?>
        <img src="<?= e(url('/assets/img/hero-soldier-dark.jpg')) ?>" alt="" loading="lazy">
        <?php
    } elseif ($isPostgres) {
        ?>
        <div class="portal-postgres-art">
            <span class="portal-elephant" aria-hidden="true">🐘</span>
            <strong>PostgreSQL</strong>
            <small>Do zero ao intermediário</small>
        </div>
        <?php
    } else {
        $initial = mb_strtoupper(mb_substr((string) ($course['title'] ?? 'C'), 0, 1));
        ?>
        <div class="portal-postgres-art">
            <span class="portal-elephant" aria-hidden="true"><?= e($initial) ?></span>
            <strong><?= e($course['title'] ?? 'Curso') ?></strong>
            <small>Curso MI</small>
        </div>
        <?php
    }

    echo '<span class="portal-card-media-overlay" aria-hidden="true"></span>';
    echo '<span class="portal-card-access ' . ($locked ? 'is-locked' : 'is-open') . '">'
        . ($locked ? 'Acesso não liberado' : 'Acesso liberado')
        . '</span>';

    if ($locked) {
        echo '<span class="portal-lock-mark" aria-hidden="true">🔒</span>';
    }
};

$renderCourseBody = static function (array $course, bool $locked = false) use ($difficultyLabels): void {
    $progress = max(0, min(100, (float) ($course['progress_pct'] ?? 0)));
    $continue = $course['continue_lesson'] ?? null;
    ?>
    <div class="portal-card-body">
        <div class="portal-card-meta">
            <span><?= e($difficultyLabels[$course['difficulty']] ?? (string) $course['difficulty']) ?></span>
            <?php if ((int) ($course['module_count'] ?? 0) > 0): ?>
                <span><?= (int) $course['module_count'] ?> módulos</span>
            <?php endif; ?>
            <?php if ((int) ($course['lesson_count'] ?? 0) > 0): ?>
                <span><?= (int) $course['lesson_count'] ?> aulas</span>
            <?php endif; ?>
        </div>

        <h3><?= e($course['title']) ?></h3>
        <p class="portal-card-description">
            <?= e($course['short_description'] ?: ($locked
                ? 'Curso disponível na plataforma mediante liberação de acesso.'
                : 'Curso disponível para sua conta.')) ?>
        </p>

        <?php if (!$locked && $continue): ?>
            <div class="portal-resume">
                <span>RETOMAR</span>
                <strong><?= e($continue['lesson_title']) ?></strong>
                <small><?= e($continue['module_title']) ?> • <?= e($continue['phase_title']) ?></small>
            </div>
        <?php endif; ?>

        <?php if (!$locked): ?>
            <div class="portal-progress">
                <div class="portal-progress-row">
                    <span>Seu progresso</span>
                    <strong><?= number_format($progress, 0, ',', '.') ?>%</strong>
                </div>
                <div class="portal-progress-track" aria-label="Progresso <?= e((string) $progress) ?>%">
                    <span style="width:<?= e((string) $progress) ?>%"></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="portal-card-footer">
            <?php if ($locked): ?>
                <small>Liberação feita pelo gestor da plataforma</small>
                <span class="portal-card-action">Como liberar acesso</span>
            <?php else: ?>
                <small>
                    <?= !empty($course['last_accessed_at'])
                        ? 'Último acesso ' . e(date('d/m/Y', strtotime((string) $course['last_accessed_at'])))
                        : 'Pronto para começar' ?>
                </small>
                <span class="portal-card-action"><?= $progress > 0 ? 'Continuar curso' : 'Entrar no curso' ?> →</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
};
?>

<?php if ($success): ?><div class="portal-empty"><?= e((string) $success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="portal-empty"><?= e((string) $error) ?></div><?php endif; ?>

<section class="portal-intro">
    <span class="portal-kicker">Portal de cursos</span>
    <h1>Bem-vindo de volta, <?= e($firstName) ?>.</h1>
    <p>Escolha onde deseja continuar seus estudos. Cada curso possui ambiente, identidade visual e estrutura próprios.</p>

    <div class="portal-overview" aria-label="Resumo dos cursos">
        <span><?= $publishedCount ?> curso<?= $publishedCount === 1 ? '' : 's' ?> disponível<?= $publishedCount === 1 ? '' : 'is' ?></span>
        <span><?= $accessibleCount ?> liberado<?= $accessibleCount === 1 ? '' : 's' ?></span>
        <?php if ($lockedCount > 0): ?>
            <span><?= $lockedCount ?> aguardando liberação</span>
        <?php endif; ?>
    </div>
</section>

<section class="portal-section" aria-labelledby="meus-cursos-title">
    <div class="portal-section-head">
        <div>
            <span>Minha área de estudo</span>
            <h2 id="meus-cursos-title">Meus cursos</h2>
        </div>
        <p>Ao entrar, você será levado para o ambiente próprio do curso exatamente como ele foi configurado.</p>
    </div>

    <?php if (empty($myCourses)): ?>
        <div class="portal-empty">
            Sua conta está ativa, mas nenhum curso publicado foi liberado para você ainda.
        </div>
    <?php else: ?>
        <div class="portal-course-grid">
            <?php foreach ($myCourses as $course): ?>
                <?php
                $slug = mb_strtolower((string) ($course['slug'] ?? ''));
                $titleLower = mb_strtolower((string) ($course['title'] ?? ''));
                $mediaClass = (str_contains($slug, 'postgres') || str_contains($titleLower, 'postgres'))
                    ? ' is-postgresql'
                    : '';
                ?>
                <a class="portal-course-card" href="<?= e(url((string) ($course['entry_path'] ?? ('/curso/' . (int) $course['id'])))) ?>">
                    <div class="portal-card-media<?= e($mediaClass) ?>">
                        <?php $renderCourseMedia($course, false); ?>
                    </div>
                    <?php $renderCourseBody($course, false); ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if (!empty($otherCourses)): ?>
    <section class="portal-section" aria-labelledby="outros-cursos-title">
        <div class="portal-section-head">
            <div>
                <span>Catálogo Curso MI</span>
                <h2 id="outros-cursos-title">Outros cursos</h2>
            </div>
            <p>Você pode conhecer os cursos publicados da plataforma mesmo antes de receber acesso.</p>
        </div>

        <div class="portal-course-grid">
            <?php foreach ($otherCourses as $course): ?>
                <?php
                $slug = mb_strtolower((string) ($course['slug'] ?? ''));
                $titleLower = mb_strtolower((string) ($course['title'] ?? ''));
                $mediaClass = (str_contains($slug, 'postgres') || str_contains($titleLower, 'postgres'))
                    ? ' is-postgresql'
                    : '';
                ?>
                <button
                    class="portal-course-card is-locked"
                    type="button"
                    data-course-locked
                    data-course-title="<?= e($course['title']) ?>"
                    aria-haspopup="dialog"
                >
                    <div class="portal-card-media<?= e($mediaClass) ?>">
                        <?php $renderCourseMedia($course, true); ?>
                    </div>
                    <?php $renderCourseBody($course, true); ?>
                </button>
            <?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>

<div
    class="portal-modal"
    data-access-modal
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="portal-access-title"
    hidden
>
    <div class="portal-modal-backdrop" data-access-dismiss></div>
    <section class="portal-modal-card">
        <div class="portal-modal-icon" aria-hidden="true">🔒</div>
        <h2 id="portal-access-title">Curso não liberado</h2>
        <p>Você ainda não possui acesso a este curso.</p>
        <div class="portal-modal-course" data-access-course></div>
        <p>Solicite a liberação ao gestor da plataforma para iniciar seus estudos.</p>
        <div class="portal-modal-actions">
            <button class="portal-modal-close" type="button" data-access-close data-access-dismiss>Entendi</button>
        </div>
    </section>
</div>
