<?php
$activity = $manifest['activity'] ?? [];
$engine = $manifest['engine'] ?? [];
$progress = $manifest['progress'] ?? [];
$assets = is_array($engine['assets'] ?? null) ? $engine['assets'] : [];
$courseId = (int) ($activeCourseId ?? 0);
$activityId = (int) ($activity['id'] ?? 0);
$lessonId = (int) ($activity['lesson_id'] ?? 0);
$submitPath = $courseId > 0
    ? '/api/curso/' . $courseId . '/laboratorio/atividade/' . $activityId . '/executar'
    : '/api/laboratorio/atividade/' . $activityId . '/executar';
$hintPath = $courseId > 0
    ? '/api/curso/' . $courseId . '/laboratorio/atividade/' . $activityId . '/dica'
    : '/api/laboratorio/atividade/' . $activityId . '/dica';
$lessonPath = $courseId > 0
    ? '/curso/' . $courseId . '/aula/' . $lessonId
    : '/aula/' . $lessonId;

$manifestJson = json_encode(
    $manifest,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_HEX_TAG
    | JSON_HEX_AMP
    | JSON_HEX_APOS
    | JSON_HEX_QUOT
);
?>
<section class="study-room lab-room" data-lab-root
    data-engine-key="<?= e($engine['key'] ?? '') ?>"
    data-submit-url="<?= e(url($submitPath)) ?>"
    data-hint-url="<?= e(url($hintPath)) ?>"
    data-csrf-token="<?= e($csrfToken ?? '') ?>"
>
    <aside class="study-map">
        <div class="study-map-head">
            <a class="back study-back" href="<?= e(url($lessonPath)) ?>">← Voltar à aula</a>
            <span class="eyebrow">ATIVIDADE PRÁTICA</span>
            <h2><?= e($activity['title'] ?? 'Laboratório') ?></h2>
            <p><?= e($engine['label'] ?? $activity['engine_key'] ?? '') ?></p>
        </div>

        <div class="ld-admin-panel">
            <p><strong>Tentativas:</strong> <?= (int) ($progress['attempts_count'] ?? 0) ?></p>
            <p><strong>Dicas usadas:</strong> <?= (int) ($progress['hints_used'] ?? 0) ?></p>
            <p><strong>Melhor resultado:</strong> <?= number_format((float) ($progress['best_score'] ?? 0), 1, ',', '.') ?>%</p>
            <p><strong>XP base:</strong> +<?= (int) ($activity['xp_reward'] ?? 0) ?></p>
        </div>
    </aside>

    <section class="study-stage">
        <header class="stage-top">
            <div>
                <span class="eyebrow"><?= e($activity['module_title'] ?? '') ?> • <?= e($activity['phase_title'] ?? '') ?></span>
                <h1><?= e($activity['lesson_title'] ?? '') ?></h1>
            </div>
            <div class="stage-meta">
                <span><?= !empty($activity['is_required']) ? 'Obrigatório' : 'Opcional' ?></span>
                <span>Estado v<?= (int) ($activity['state_version'] ?? 0) ?></span>
            </div>
        </header>

        <article class="study-card type-case">
            <header>
                <span class="symbol">⌨</span>
                <div>
                    <span class="eyebrow">DESAFIO PRÁTICO</span>
                    <h2><?= e($activity['title'] ?? '') ?></h2>
                </div>
            </header>

            <div class="study-content">
                <p class="content-p"><?= nl2br(e($activity['instructions'] ?? '')) ?></p>

                <div data-lab-status class="alert" hidden></div>

                <div data-lab-editor-host>
                    <textarea
                        class="ld-admin-input"
                        data-lab-fallback-editor
                        rows="14"
                        spellcheck="false"
                    ><?= e($activity['starter_code'] ?? '') ?></textarea>
                </div>

                <div class="ld-admin-form-actions">
                    <button class="ld-admin-btn" type="button" data-lab-run>Executar / validar</button>
                    <button class="ld-admin-btn is-ghost" type="button" data-lab-hint>Solicitar dica</button>
                    <button class="ld-admin-btn is-ghost" type="button" data-lab-reset>Resetar atividade</button>
                </div>

                <section data-lab-hint-box hidden></section>
                <section data-lab-result></section>
            </div>
        </article>
    </section>
</section>

<script type="application/json" id="lab-manifest"><?= $manifestJson ?: '{}' ?></script>
<?php foreach ($assets as $asset): ?>
    <?php if (is_string($asset) && str_starts_with($asset, '/assets/')): ?>
        <script type="module" src="<?= e(url($asset)) ?>"></script>
    <?php endif; ?>
<?php endforeach; ?>
<script type="module" src="<?= e(url('/assets/js/lab-runtime.js')) ?>"></script>
