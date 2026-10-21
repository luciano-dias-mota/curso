<?php
$activity = $manifest['activity'] ?? [];
$engine = $manifest['engine'] ?? [];
$progress = $manifest['progress'] ?? [];
$assets = is_array($engine['assets'] ?? null) ? $engine['assets'] : [];
$courseId = (int) ($activeCourseId ?? 0);
$activityId = (int) ($activity['id'] ?? 0);
$lessonId = (int) ($activity['lesson_id'] ?? 0);
$activityType = (string) ($activity['activity_type'] ?? 'sql');
$isChallenge = $activityType === 'sql_challenge';
$isGuided = !$isChallenge && trim((string) ($activity['starter_code'] ?? '')) !== '';
$referenceCode = $isGuided ? (string) ($activity['starter_code'] ?? '') : '';
$activityUrlBase = $courseId > 0
    ? '/curso/' . $courseId . '/laboratorio/atividade/'
    : '/laboratorio/atividade/';
$lessonUrlBase = $courseId > 0
    ? '/curso/' . $courseId . '/aula/'
    : '/aula/';
$courseHomePath = $courseId > 0
    ? '/curso/' . $courseId
    : '/dashboard';
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
    data-activity-url-base="<?= e(url($activityUrlBase)) ?>"
    data-lesson-url-base="<?= e(url($lessonUrlBase)) ?>"
    data-course-home-url="<?= e(url($courseHomePath)) ?>"
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
                    <span class="eyebrow"><?= $isChallenge ? 'DESAFIO SEM MODELO' : 'PRÁTICA GUIADA' ?></span>
                    <h2><?= e($activity['title'] ?? '') ?></h2>
                </div>
            </header>

            <div class="study-content">
                <p class="content-p"><?= nl2br(e($activity['instructions'] ?? '')) ?></p>

                <?php if ($isGuided): ?>
                    <section class="lab-guided-example" aria-label="Comando para praticar">
                        <div class="lab-guided-example-head">
                            <span>1</span>
                            <div>
                                <strong>Digite exatamente este comando</strong>
                                <small>Leia o exemplo e escreva manualmente no campo abaixo.</small>
                            </div>
                        </div>
                        <pre><code><?= e($referenceCode) ?></code></pre>
                    </section>
                <?php else: ?>
                    <section class="lab-independent-banner">
                        <span><?= max(2, (int) ($activity['position'] ?? 2)) ?></span>
                        <div>
                            <strong>Agora é com você</strong>
                            <small>Resolva um exercício semelhante sem o comando pronto na tela.</small>
                        </div>
                    </section>
                <?php endif; ?>

                <div data-lab-status class="alert" hidden></div>

                <label class="lab-editor-label" for="lab-sql-editor">
                    <?= $isChallenge ? 'Escreva sua solução SQL' : 'Digite o comando mostrado acima' ?>
                </label>

                <div data-lab-editor-host>
                    <textarea
                        id="lab-sql-editor"
                        class="ld-admin-input lab-sql-editor"
                        data-lab-fallback-editor
                        rows="12"
                        spellcheck="false"
                        autocapitalize="off"
                        autocomplete="off"
                        autocorrect="off"
                        placeholder="<?= $isChallenge ? 'Digite aqui sua solução SQL...' : 'Digite aqui o comando do exemplo...' ?>"
                    ></textarea>
                </div>

                <div class="ld-admin-form-actions">
                    <button class="ld-admin-btn" type="button" data-lab-run>Executar / validar</button>
                    <?php if ($isChallenge && (int) ($manifest['hints_count'] ?? 0) > 0): ?>
                        <button class="ld-admin-btn is-ghost" type="button" data-lab-hint>Solicitar dica</button>
                    <?php endif; ?>
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
        <script type="module" src="<?= e(url($asset) . '?v=20261021-4') ?>"></script>
    <?php endif; ?>
<?php endforeach; ?>
<script type="module" src="<?= e(url('/assets/js/lab-runtime.js') . '?v=20261021-4') ?>"></script>
