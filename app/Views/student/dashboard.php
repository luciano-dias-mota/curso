<?php
use App\Core\Session;

$success = Session::pullFlash('success');
$error = Session::pullFlash('error');

$difficultyLabels = [
    'beginner' => 'Iniciante',
    'intermediate' => 'Intermediário',
    'advanced' => 'Avançado',
];

$catalogCount = (int) ($summary['catalog_courses'] ?? 0);
$accessibleCount = (int) ($summary['accessible_courses'] ?? 0);
$lockedCount = (int) ($summary['locked_courses'] ?? 0);
$previewCount = (int) ($summary['preview_courses'] ?? 0);
$portalCourses = array_merge($myCourses ?? [], $otherCourses ?? []);

$renderCourseMedia = static function (
    array $course,
    bool $locked = false,
    bool $preview = false
): void {
    $cover = trim((string) ($course['cover_image'] ?? ''));
    $experience = trim((string) ($course['experience_key'] ?? 'generic'));

    if ($cover !== '') {
        $coverSrc = preg_match('#^https?://#i', $cover) === 1
            ? $cover
            : url('/' . ltrim($cover, '/'));
        ?>
        <img src="<?= e($coverSrc) ?>" alt="" loading="lazy">
        <?php
    } elseif ($experience === 'pmmt') {
        ?>
        <img
            src="<?= e(url('/assets/img/hero-soldier-dark.jpg')) ?>"
            alt=""
            loading="lazy"
            class="portal-course-cover portal-course-cover-pmmt"
        >
        <?php
    } elseif ($experience === 'postgresql') {
        ?>
        <svg
            class="portal-postgres-visual"
            viewBox="0 0 400 170"
            preserveAspectRatio="xMidYMid slice"
            role="img"
            aria-label="Ilustração de banco de dados PostgreSQL"
        >
            <defs>
                <linearGradient id="portal-db-bg" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="#0a1f4d"/>
                    <stop offset=".55" stop-color="#0b4a7a"/>
                    <stop offset="1" stop-color="#0e8fa8"/>
                </linearGradient>
                <radialGradient id="portal-db-glow" cx=".72" cy=".45" r=".55">
                    <stop offset="0" stop-color="#5fe0f5" stop-opacity=".55"/>
                    <stop offset="1" stop-color="#5fe0f5" stop-opacity="0"/>
                </radialGradient>
                <linearGradient id="portal-db-body" x1="0" y1="0" x2="1" y2="0">
                    <stop offset="0" stop-color="#7fd6ee" stop-opacity=".75"/>
                    <stop offset=".5" stop-color="#d4f6ff" stop-opacity=".95"/>
                    <stop offset="1" stop-color="#7fd6ee" stop-opacity=".7"/>
                </linearGradient>
            </defs>

            <rect width="400" height="170" fill="url(#portal-db-bg)"/>
            <rect width="400" height="170" fill="url(#portal-db-glow)"/>

            <g stroke="#fff" stroke-opacity=".07">
                <path d="M40 0v170M80 0v170M120 0v170M160 0v170M200 0v170M240 0v170M280 0v170M320 0v170M360 0v170M0 34h400M0 68h400M0 102h400M0 136h400"/>
            </g>

            <text x="36" y="70" font-family="ui-monospace,Menlo,Consolas,monospace" font-size="34" font-weight="800" fill="#fff" fill-opacity=".94">SQL</text>
            <rect x="36" y="88" width="120" height="8" rx="4" fill="#fff" fill-opacity=".22"/>
            <rect x="36" y="104" width="86" height="8" rx="4" fill="#fff" fill-opacity=".14"/>
            <rect x="36" y="120" width="104" height="8" rx="4" fill="#fff" fill-opacity=".09"/>

            <g stroke="#fff" stroke-opacity=".58" stroke-width="1.2">
                <path d="M222 108v26a58 13 0 0 0 116 0v-26a58 13 0 0 1-116 0Z" fill="url(#portal-db-body)" fill-opacity=".55"/>
                <ellipse cx="280" cy="108" rx="58" ry="13" fill="#bff3ff" fill-opacity=".55"/>
                <path d="M222 76v26a58 13 0 0 0 116 0V76a58 13 0 0 1-116 0Z" fill="url(#portal-db-body)" fill-opacity=".75"/>
                <ellipse cx="280" cy="76" rx="58" ry="13" fill="#d4f6ff" fill-opacity=".7"/>
                <path d="M222 44v26a58 13 0 0 0 116 0V44a58 13 0 0 1-116 0Z" fill="url(#portal-db-body)"/>
                <ellipse cx="280" cy="44" rx="58" ry="13" fill="#ecfbff"/>
            </g>
        </svg>
        <?php
    } else {
        $initial = mb_strtoupper(mb_substr((string) ($course['title'] ?? 'C'), 0, 1));
        ?>
        <div class="portal-generic-visual" aria-hidden="true">
            <span><?= e($initial) ?></span>
            <strong><?= e($course['title'] ?? 'Curso MI') ?></strong>
        </div>
        <?php
    }

    echo '<span class="portal-card-media-overlay" aria-hidden="true"></span>';

    if ($preview) {
        echo '<span class="portal-card-access is-preview">Em construção</span>';
        echo '<span class="portal-lock-mark is-preview" aria-hidden="true">🛠️</span>';
    } else {
        echo '<span class="portal-card-access ' . ($locked ? 'is-locked' : 'is-open') . '">'
            . ($locked ? 'Acesso não liberado' : 'Acesso liberado')
            . '</span>';

        if ($locked) {
            echo '<span class="portal-lock-mark" aria-hidden="true">🔒</span>';
        }
    }
};

$renderCourseBody = static function (
    array $course,
    bool $locked = false,
    bool $preview = false
) use ($difficultyLabels): void {
    $progress = max(0, min(100, (float) ($course['progress_pct'] ?? 0)));
    $continue = $course['continue_lesson'] ?? null;
    $continueStatus = (string) ($continue['lesson_status'] ?? '');
    $resumeLabel = $continueStatus === 'in_progress' ? 'RETOMAR' : 'COMEÇAR';
    $courseTitle = (string) ($course['title'] ?? 'Curso');
    ?>
    <div class="portal-card-body">
        <div class="portal-card-meta">
            <span class="portal-meta-pill">
                <span aria-hidden="true">▥</span>
                <?= e($difficultyLabels[$course['difficulty']] ?? (string) $course['difficulty']) ?>
            </span>

            <?php if ((int) ($course['module_count'] ?? 0) > 0): ?>
                <span class="portal-meta-pill">
                    <span aria-hidden="true">◇</span>
                    <?= (int) $course['module_count'] ?> módulos
                </span>
            <?php endif; ?>

            <?php if ((int) ($course['lesson_count'] ?? 0) > 0): ?>
                <span class="portal-meta-pill">
                    <span aria-hidden="true">▷</span>
                    <?= (int) $course['lesson_count'] ?> aulas
                </span>
            <?php endif; ?>
        </div>

        <div class="portal-card-copy">
            <h3><?= e($courseTitle) ?></h3>
            <p class="portal-card-description">
                <?= e($course['short_description'] ?: ($locked
                    ? 'Curso disponível na plataforma mediante liberação de acesso.'
                    : 'Curso disponível para sua conta.')) ?>
            </p>
        </div>

        <?php if ($preview): ?>
            <div class="portal-locked-note is-preview">
                <span aria-hidden="true">🛠️</span>
                <div>
                    <strong>Curso em construção</strong>
                    <small>
                        <?= e(
                            trim((string) ($course['catalog_preview_message'] ?? ''))
                            ?: 'Estamos preparando este curso. Em breve ele estará disponível para os estudantes.'
                        ) ?>
                    </small>
                </div>
            </div>
        <?php elseif (!$locked && $continue): ?>
            <div class="portal-resume">
                <span class="portal-resume-icon" aria-hidden="true">▶</span>
                <div class="portal-resume-copy">
                    <span><?= e($resumeLabel) ?></span>
                    <strong><?= e($continue['lesson_title']) ?></strong>
                    <small><?= e($continue['module_title']) ?> · <?= e($continue['phase_title']) ?></small>
                </div>
            </div>
        <?php elseif ($locked): ?>
            <div class="portal-locked-note">
                <span aria-hidden="true">🔒</span>
                <div>
                    <strong>Liberação necessária</strong>
                    <small>Solicite acesso ao gestor da plataforma para iniciar este curso.</small>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!$locked && !$preview): ?>
            <div class="portal-progress">
                <div class="portal-progress-row">
                    <span>Seu progresso</span>
                    <strong><?= number_format($progress, 0, ',', '.') ?>%</strong>
                </div>
                <div
                    class="portal-progress-track"
                    role="progressbar"
                    aria-label="Progresso no curso <?= e($courseTitle) ?>"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    aria-valuenow="<?= e((string) $progress) ?>"
                >
                    <span style="width:<?= e((string) $progress) ?>%"></span>
                </div>
            </div>
        <?php endif; ?>

        <div class="portal-card-footer">
            <div class="portal-card-state <?= $preview ? 'is-preview' : ($locked ? 'is-locked' : 'is-ready') ?>">
                <span aria-hidden="true"></span>
                <small>
                    <?php if ($preview): ?>
                        Em breve
                    <?php elseif ($locked): ?>
                        Aguardando liberação
                    <?php elseif (!empty($course['last_accessed_at'])): ?>
                        Último acesso <?= e(date('d/m/Y', strtotime((string) $course['last_accessed_at']))) ?>
                    <?php else: ?>
                        Pronto para começar
                    <?php endif; ?>
                </small>
            </div>

            <span class="portal-card-action <?= $preview ? 'is-preview' : '' ?>">
                <?= $preview
                    ? 'Em breve'
                    : (
                        $locked
                            ? 'Solicitar acesso'
                            : ($progress > 0 ? 'Continuar curso' : 'Entrar no curso')
                    ) ?>
                <?php if (!$preview): ?><span aria-hidden="true">→</span><?php endif; ?>
            </span>
        </div>
    </div>
    <?php
};
?>

<?php if ($success): ?>
    <div class="portal-flash is-success" role="status"><?= e((string) $success) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="portal-flash is-error" role="alert"><?= e((string) $error) ?></div>
<?php endif; ?>

<section class="portal-intro">
    <div class="portal-overview" aria-label="Resumo dos cursos">
        <span><?= $catalogCount ?> curso<?= $catalogCount === 1 ? '' : 's' ?> no portal</span>

        <span class="is-accessible">
            <i aria-hidden="true"></i>
            <?= $accessibleCount ?> liberado<?= $accessibleCount === 1 ? '' : 's' ?>
        </span>

        <?php if ($previewCount > 0): ?>
            <span class="is-preview">
                <i aria-hidden="true"></i>
                <?= $previewCount ?> em construção
            </span>
        <?php endif; ?>

        <?php if ($lockedCount > 0): ?>
            <span class="is-locked">
                <i aria-hidden="true"></i>
                <?= $lockedCount ?> aguardando liberação
            </span>
        <?php endif; ?>
    </div>

    <p>Escolha seu curso e continue seus estudos no ambiente próprio de cada formação.</p>
</section>

<section class="portal-section portal-section-unified" aria-labelledby="portal-courses-title">
    <div class="portal-section-head">
        <div>
            <span>Minha área de estudo</span>
            <h1 id="portal-courses-title">Cursos</h1>
        </div>
        <p>Os cursos liberados podem ser acessados agora. Os demais aparecem como catálogo ou próximos lançamentos.</p>
    </div>

    <?php if (empty($portalCourses)): ?>
        <div class="portal-empty">
            <strong>Nenhum curso disponível no momento.</strong>
            <span>Novas formações aparecerão aqui quando forem adicionadas à LD Academy.</span>
        </div>
    <?php else: ?>
        <div class="portal-course-grid portal-course-grid-unified">
            <?php foreach ($portalCourses as $course): ?>
                <?php
                $experience = (string) ($course['experience_key'] ?? 'generic');
                $isPreview = !empty($course['is_preview']);
                $hasAccess = !empty($course['has_access']);
                ?>

                <?php if ($hasAccess): ?>
                    <a
                        class="portal-course-card experience-<?= e($experience) ?>"
                        href="<?= e(url((string) ($course['entry_path'] ?? ('/curso/' . (int) $course['id'])))) ?>"
                    >
                        <div class="portal-card-media">
                            <?php $renderCourseMedia($course, false, false); ?>
                        </div>
                        <?php $renderCourseBody($course, false, false); ?>
                    </a>
                <?php elseif ($isPreview): ?>
                    <article
                        class="portal-course-card is-preview experience-<?= e($experience) ?>"
                        aria-label="<?= e($course['title']) ?> — curso em construção"
                    >
                        <div class="portal-card-media">
                            <?php $renderCourseMedia($course, true, true); ?>
                        </div>
                        <?php $renderCourseBody($course, true, true); ?>
                    </article>
                <?php else: ?>
                    <button
                        class="portal-course-card is-locked experience-<?= e($experience) ?>"
                        type="button"
                        data-course-locked
                        data-course-title="<?= e($course['title']) ?>"
                        aria-haspopup="dialog"
                    >
                        <div class="portal-card-media">
                            <?php $renderCourseMedia($course, true, false); ?>
                        </div>
                        <?php $renderCourseBody($course, true, false); ?>
                    </button>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

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
        <span class="portal-modal-kicker">ACESSO PROTEGIDO</span>
        <h2 id="portal-access-title">Curso não liberado</h2>
        <p>Você ainda não possui matrícula ativa para este curso.</p>
        <div class="portal-modal-course" data-access-course></div>
        <p>Solicite a liberação ao gestor da plataforma para iniciar seus estudos.</p>
        <div class="portal-modal-actions">
            <button class="portal-modal-close" type="button" data-access-close data-access-dismiss>Entendi</button>
        </div>
    </section>
</div>
