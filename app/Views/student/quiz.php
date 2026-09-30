<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$ready = (int) $quiz['question_count'] === 5;
$passedBefore = (float) $quiz['best_passed'] >= 80;
?>
<section class="quiz-page">
    <a class="back" href="<?= e(url('/fase/' . $quiz['phase_id'])) ?>">← Voltar para a fase</a>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <header class="quiz-hero card">
        <div>
            <span class="eyebrow">CHECKPOINT DA FASE</span>
            <h1>Prova • <?= e($quiz['phase_title']) ?></h1>
            <p>
                Esta prova possui <strong>5 questões</strong>. Para ser aprovado e desbloquear
                a próxima fase, você precisa acertar <strong>pelo menos 4</strong>.
            </p>
        </div>

        <div class="quiz-rule-box">
            <div><strong>5</strong><span>questões</span></div>
            <div><strong>4/5</strong><span>mínimo</span></div>
            <div><strong>80%</strong><span>aprovação</span></div>
        </div>
    </header>

    <div class="quiz-info-grid">
        <article class="card quiz-info-card">
            <span class="eyebrow">TENTATIVAS</span>
            <strong><?= (int) $quiz['attempts'] ?></strong>
            <p class="muted">Você pode refazer a prova se não alcançar 4 acertos.</p>
        </article>

        <article class="card quiz-info-card">
            <span class="eyebrow">MELHOR NOTA</span>
            <strong><?= number_format((float) $quiz['best_percentage'], 0) ?>%</strong>
            <p class="muted">O sistema mantém sua melhor pontuação.</p>
        </article>

        <article class="card quiz-info-card <?= $passedBefore ? 'is-passed' : '' ?>">
            <span class="eyebrow">STATUS</span>
            <strong><?= $passedBefore ? 'APROVADO' : ($ready ? 'LIBERADA' : 'EM PREPARAÇÃO') ?></strong>
            <p class="muted">
                <?= $passedBefore
                    ? 'Você já venceu este checkpoint.'
                    : ($ready ? 'A prova está pronta para começar.' : 'Faltam questões revisadas nesta fase.') ?>
            </p>
        </article>
    </div>

    <?php if (!$ready): ?>
        <div class="card quiz-unavailable">
            <span class="quiz-lock">🔒</span>
            <div>
                <span class="eyebrow">PROVA AINDA NÃO PUBLICÁVEL</span>
                <h2>Esta fase ainda não possui exatamente 5 questões revisadas.</h2>
                <p>
                    A progressão continuará bloqueada até que o banco de questões desta fase
                    esteja completo. Isso evita liberar uma prova incompleta ou pedagogicamente fraca.
                </p>
            </div>
        </div>
    <?php else: ?>
        <form action="<?= e(url('/prova/' . $quiz['id'] . '/iniciar')) ?>" method="post">
            <?= Csrf::input() ?>
            <button class="primary quiz-start" type="submit">
                <?= $quiz['attempts'] > 0 ? 'Fazer nova tentativa →' : 'Iniciar prova →' ?>
            </button>
        </form>
    <?php endif; ?>
</section>
