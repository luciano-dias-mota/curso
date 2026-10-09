<?php
use App\Core\Session;

$success = Session::pullFlash('success');
$error = Session::pullFlash('error');
$library = (int) $phase['is_required'] === 0;
$quizExpected = (int) ($quiz['question_limit'] ?? 0);
$quizRequiredCorrect = (int) ($quiz['required_correct'] ?? 0);
$quizRequiredScore = (float) ($quiz['required_score'] ?? 0);
$quizReady = $quiz && $quizExpected > 0 && (int) $quiz['question_count'] === $quizExpected;
$phasePassed = $phase['user_status'] === 'completed';
?>
<section class="phase-page">
    <?php if ($success): ?>
        <div class="alert success"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <header class="trail-hero card <?= $library ? 'library-hero' : '' ?>">
        <div>
            <a class="back" href="<?= e(url('/modulo/' . $phase['module_id'])) ?>">← Voltar ao módulo</a>
            <span class="eyebrow"><?= $library ? 'BIBLIOTECA DE REFERÊNCIA' : 'FASE ' . (int) $phase['position'] ?></span>
            <h1><?= e($phase['title']) ?></h1>
            <p><?= e($phase['description'] ?? '') ?></p>
        </div>

        <div class="score">
            <?php if ($library): ?>
                <strong>▤</strong>
                <span>consulta livre</span>
            <?php else: ?>
                <strong><?= number_format((float) $phase['user_progress'], 0) ?>%</strong>
                <span><?= $phasePassed ? 'fase aprovada' : 'leitura concluída' ?></span>
                <div class="progress"><span style="width:<?= (float) $phase['user_progress'] ?>%"></span></div>
            <?php endif; ?>
        </div>
    </header>

    <div class="section-head">
        <div>
            <span class="eyebrow"><?= $library ? 'MATERIAL DE APOIO' : 'ROTA DE ESTUDO' ?></span>
            <h2>Aulas</h2>
        </div>
        <p>
            <?= $library
                ? 'Literalidade e recortes completos para consulta.'
                : 'Conclua as aulas e depois vença o checkpoint de 5 questões.' ?>
        </p>
    </div>

    <div class="lesson-list">
        <?php foreach ($lessons as $lesson): ?>
            <?php
            $locked = $lesson['user_status'] === 'locked';
            $done = $lesson['user_status'] === 'completed';
            $progressing = $lesson['user_status'] === 'in_progress';
            ?>
            <article class="lesson-row <?= $locked ? 'locked' : '' ?>">
                <div class="lesson-num"><?= str_pad((string) $lesson['position'], 2, '0', STR_PAD_LEFT) ?></div>
                <div class="lesson-info">
                    <div>
                        <span class="badge <?= $done ? 'done' : ($progressing ? 'active' : ($locked ? 'locked' : 'available')) ?>">
                            <?= $done ? 'CONCLUÍDA' : ($progressing ? 'EM ANDAMENTO' : ($locked ? 'BLOQUEADA' : 'DISPONÍVEL')) ?>
                        </span>
                        <span class="muted">~<?= (int) $lesson['estimated_minutes'] ?> min</span>
                    </div>
                    <h3><?= e($lesson['title']) ?></h3>
                    <p><?= e($lesson['summary'] ?? '') ?></p>
                    <?php if (!$library): ?>
                        <div class="progress"><span style="width:<?= (float) $lesson['progress_pct'] ?>%"></span></div>
                    <?php endif; ?>
                </div>
                <div class="lesson-action">
                    <?php if ($locked): ?>
                        <button class="secondary" disabled>🔒 Bloqueada</button>
                    <?php else: ?>
                        <a class="<?= $library ? 'secondary' : 'primary' ?>" href="<?= e(url('/aula/' . $lesson['id'])) ?>">
                            <?= $done ? 'Revisar' : ($progressing ? 'Continuar' : 'Iniciar') ?> →
                        </a>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if (!$library && $allRequiredCompleted): ?>
        <section class="card phase-exam-card <?= $phasePassed ? 'passed' : '' ?>">
            <div class="phase-exam-icon"><?= $phasePassed ? '🏆' : '🎯' ?></div>
            <div class="phase-exam-content">
                <span class="eyebrow">CHECKPOINT OBRIGATÓRIO</span>

                <?php if ($phasePassed): ?>
                    <h2>Fase aprovada</h2>
                    <p>
                        Você já alcançou a nota mínima nesta fase.
                        Melhor desempenho: <strong><?= number_format((float) $phase['best_score'], 0) ?>%</strong>.
                    </p>
                    <?php if ($quiz): ?>
                        <a class="secondary" href="<?= e(url('/prova/' . $quiz['id'])) ?>">Revisar prova</a>
                    <?php endif; ?>

                <?php elseif (!$quiz): ?>
                    <h2>Prova ainda não configurada</h2>
                    <p>
                        A leitura foi concluída, mas a próxima fase continuará bloqueada até existir
                        uma prova configurada e completa para este conteúdo.
                    </p>

                <?php elseif (!$quizReady): ?>
                    <h2>Prova em preparação</h2>
                    <p>
                        Esta fase possui <strong><?= (int) $quiz['question_count'] ?>/<?= $quizExpected ?></strong> questões cadastradas.
                        A prova só será liberada quando as <?= $quizExpected ?> questões estiverem revisadas.
                    </p>

                <?php else: ?>
                    <h2>Leitura concluída. Hora de provar o domínio.</h2>
                    <p>
                        São <strong><?= $quizExpected ?> questões</strong>. Você precisa acertar <strong><?= $quizRequiredCorrect ?></strong>
                        para atingir <strong><?= number_format($quizRequiredScore, 0) ?>%</strong> e desbloquear a próxima fase.
                    </p>
                    <div class="phase-exam-stats">
                        <span><?= $quizExpected ?> questões</span>
                        <span><?= $quizRequiredCorrect ?> acertos mínimos</span>
                        <span><?= (int) ($quiz['finished_attempts'] ?? 0) ?> tentativa(s)</span>
                        <span>Melhor: <?= number_format((float) ($quiz['best_percentage'] ?? 0), 0) ?>%</span>
                    </div>
                    <a class="primary" href="<?= e(url('/prova/' . $quiz['id'])) ?>">
                        <?= (int) ($quiz['finished_attempts'] ?? 0) > 0 ? 'Refazer checkpoint →' : 'Iniciar checkpoint →' ?>
                    </a>
                <?php endif; ?>
            </div>
        </section>
    <?php endif; ?>
</section>
