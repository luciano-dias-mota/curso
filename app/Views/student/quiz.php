<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');

$expected = (int) ($quiz['question_limit'] ?? 0);
$actual = (int) ($quiz['question_count'] ?? 0);
$requiredCorrect = (int) ($quiz['required_correct'] ?? 0);
$requiredScore = (float) ($quiz['required_score'] ?? 0);
$ready = $expected > 0 && $actual === $expected;
$passedBefore = (float) ($quiz['best_passed'] ?? 0) >= $requiredScore;
$maxAttempts = (int) ($quiz['max_attempts'] ?? 0);
$attempts = (int) ($quiz['attempts'] ?? 0);
$inProgressAttemptId = (int) ($quiz['in_progress_attempt_id'] ?? 0);
$remainingAttempts = $maxAttempts > 0 ? max(0, $maxAttempts - $attempts) : null;
$attemptLimitReached = $maxAttempts > 0 && $remainingAttempts === 0 && $inProgressAttemptId <= 0;
?>
<section class="quiz-page">
    <a class="back" href="<?= e(url($quiz['context_url'])) ?>">← Voltar ao conteúdo</a>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <header class="quiz-hero card">
        <div>
            <span class="quiz-kind"><?= e($quiz['type_label']) ?></span>
            <h1><?= e($quiz['title']) ?></h1>
            <p class="quiz-context">
                <?php if ($quiz['quiz_type'] === 'lesson_fixation'): ?>
                    A próxima aula só será liberada após aprovação neste exercício.
                <?php elseif ($quiz['quiz_type'] === 'phase_exam'): ?>
                    A próxima fase só será liberada após aprovação nesta prova.
                <?php else: ?>
                    O próximo módulo só será liberado após aprovação nesta avaliação.
                <?php endif; ?>
            </p>
        </div>

        <div class="quiz-rule-box">
            <div><strong><?= $expected ?></strong><span>questões</span></div>
            <div><strong><?= $requiredCorrect ?>/<?= $expected ?></strong><span>mínimo</span></div>
            <div><strong><?= number_format($requiredScore, 0) ?>%</strong><span>aprovação</span></div>
        </div>
    </header>

    <div class="quiz-info-grid">
        <article class="card quiz-info-card">
            <span class="eyebrow">TENTATIVAS</span>
            <strong><?= $attempts ?></strong>
            <p class="muted">
                <?php if ($maxAttempts > 0): ?>
                    <?= $inProgressAttemptId > 0 ? 'Há uma tentativa em andamento.' : ($remainingAttempts > 0 ? 'Restam ' . $remainingAttempts . ' de ' . $maxAttempts . ' tentativa(s).' : 'Limite de tentativas atingido.') ?>
                <?php else: ?>
                    Você pode refazer se necessário.
                <?php endif; ?>
            </p>
        </article>

        <article class="card quiz-info-card">
            <span class="eyebrow">MELHOR NOTA</span>
            <strong><?= number_format((float) $quiz['best_percentage'], 0) ?>%</strong>
            <p class="muted">Sua melhor pontuação fica registrada.</p>
        </article>

        <article class="card quiz-info-card <?= $passedBefore ? 'is-passed' : '' ?>">
            <span class="eyebrow">STATUS</span>
            <strong><?= $passedBefore ? 'APROVADO' : ($ready ? 'LIBERADA' : 'INCOMPLETA') ?></strong>
            <p class="muted">
                <?= $ready ? 'Banco de questões pronto.' : "{$actual}/{$expected} questões cadastradas." ?>
            </p>
        </article>
    </div>

    <?php if (!$ready): ?>
        <div class="card quiz-unavailable">
            <span class="quiz-lock">🔒</span>
            <div>
                <span class="eyebrow">BANCO INCOMPLETO</span>
                <h2>A avaliação ainda não pode ser iniciada.</h2>
                <p>
                    O sistema só libera a tentativa quando a quantidade de questões
                    revisadas estiver completa.
                </p>
            </div>
        </div>
    <?php elseif ($attemptLimitReached): ?>
        <div class="card quiz-unavailable">
            <span class="quiz-lock">🔒</span>
            <div>
                <span class="eyebrow">LIMITE DE TENTATIVAS</span>
                <h2>Você já utilizou todas as tentativas desta avaliação.</h2>
                <p>Revise o conteúdo e consulte seus resultados anteriores.</p>
            </div>
        </div>
    <?php else: ?>
        <form action="<?= e(url('/prova/' . $quiz['id'] . '/iniciar')) ?>" method="post">
            <?= Csrf::input() ?>
            <button class="primary quiz-start" type="submit">
                <?= $inProgressAttemptId > 0 ? 'Continuar tentativa →' : ($attempts > 0 ? 'Fazer nova tentativa →' : 'Iniciar →') ?>
            </button>
        </form>
    <?php endif; ?>
</section>
