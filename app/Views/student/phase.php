<?php

use App\Core\Session;

$success = Session::pullFlash('success');
$error = Session::pullFlash('error');
?>
<section>
    <p class="muted"><?= e($phase['module_title']) ?></p>
    <h1><?= e($phase['title']) ?></h1>
    <p><?= e($phase['description'] ?? '') ?></p>

    <?php if ($success): ?>
        <div class="alert"><?= e($success) ?></div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= e($error) ?></div>
    <?php endif; ?>

    <div class="card" style="margin-bottom:16px">
        <strong>Progresso da fase</strong>
        <div class="progress" style="margin-top:10px">
            <span style="width: <?= (float) $phase['user_progress'] ?>%"></span>
        </div>
        <p class="muted">
            <?= number_format((float) $phase['user_progress'], 0) ?>% concluído
        </p>
    </div>

    <div class="grid grid-3">
        <?php foreach ($lessons as $lesson): ?>
            <?php $locked = $lesson['user_status'] === 'locked'; ?>

            <article class="card <?= $locked ? 'phase-locked' : '' ?>">
                <small>Aula <?= (int) $lesson['position'] ?></small>
                <h2><?= e($lesson['title']) ?></h2>
                <p class="muted"><?= e($lesson['summary'] ?? '') ?></p>

                <div class="progress">
                    <span style="width: <?= (float) $lesson['progress_pct'] ?>%"></span>
                </div>

                <p>
                    <?php if ($lesson['user_status'] === 'completed'): ?>
                        ✅ Concluída
                    <?php elseif ($lesson['user_status'] === 'in_progress'): ?>
                        ▶ Em andamento
                    <?php elseif ($locked): ?>
                        🔒 Bloqueada
                    <?php else: ?>
                        Disponível
                    <?php endif; ?>
                </p>

                <?php if (!$locked): ?>
                    <a class="btn" href="<?= e(url('/aula/' . $lesson['id'])) ?>">
                        <?= $lesson['user_status'] === 'completed' ? 'Revisar aula' : 'Continuar aula' ?>
                    </a>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($allLessonsCompleted): ?>
        <div class="card" style="margin-top:16px">
            <?php if ($quiz): ?>
                <p class="muted">CHECKPOINT DA FASE</p>
                <h2>Prova da fase</h2>
                <p>
                    A leitura foi concluída. Para continuar, será necessário atingir
                    pelo menos <strong><?= number_format((float) $quiz['required_score'], 0) ?>%</strong>.
                </p>
                <button class="btn green" type="button" disabled>
                    Prova interativa em implantação
                </button>

            <?php elseif (!empty($nextContent['lesson_id'])): ?>
                <p class="muted">FASE CONCLUÍDA</p>
                <h2>Próxima missão desbloqueada 🚀</h2>
                <p>
                    <?= e($nextContent['phase_title'] ?? '') ?>
                    <?php if (!empty($nextContent['lesson_title'])): ?>
                        — <?= e($nextContent['lesson_title']) ?>
                    <?php endif; ?>
                </p>

                <a class="btn green" href="<?= e(url('/aula/' . $nextContent['lesson_id'])) ?>">
                    Continuar para a próxima aula →
                </a>

            <?php else: ?>
                <p class="muted">FASE CONCLUÍDA</p>
                <h2>Leitura finalizada ✅</h2>
                <p>
                    Não há outra aula liberada neste momento.
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>
