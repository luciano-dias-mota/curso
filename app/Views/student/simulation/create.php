<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$courses = $courses ?? [];
$modules = $modules ?? [];
$allowedLimits = $allowedLimits ?? [10, 20, 30, 50];
$firstCourseId = (int) ($courses[0]['id'] ?? 0);
?>
<section class="simulation-create-page" data-simulation-create>
    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <div class="simulation-back"><a href="<?= e(url('/simulados')) ?>">← Voltar para simulados</a></div>

    <header class="simulation-create-header card">
        <span class="simulation-eyebrow">NOVO TREINO</span>
        <h1>Monte seu próximo simulado</h1>
        <p>As questões são escolhidas no servidor com prioridade para itens que você ainda não viu. No simulado geral, o sistema distribui as questões entre os módulos para evitar concentração em uma única matéria.</p>
    </header>

    <form class="simulation-builder" action="<?= e(url('/simulados/gerar')) ?>" method="post" data-simulation-builder>
        <?= Csrf::input() ?>

        <section class="card simulation-builder-card">
            <div class="simulation-builder-step"><span>1</span><div><strong>Curso</strong><small>Escolha entre suas matrículas ativas.</small></div></div>
            <select name="course_id" data-simulation-course required>
                <?php foreach ($courses as $course): ?>
                    <option value="<?= (int) $course['id'] ?>" data-medium="<?= (int) $course['medium_count'] ?>" data-hard="<?= (int) $course['hard_count'] ?>" data-total="<?= (int) $course['total_count'] ?>"><?= e($course['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </section>

        <section class="card simulation-builder-card">
            <div class="simulation-builder-step"><span>2</span><div><strong>Conteúdo</strong><small>Geral mistura os módulos. Você também pode treinar apenas uma matéria.</small></div></div>
            <select name="module_id" data-simulation-module>
                <option value="0" data-course="<?= $firstCourseId ?>" data-general="1">Simulado geral — todos os módulos</option>
                <?php foreach ($modules as $module): ?>
                    <option value="<?= (int) $module['id'] ?>" data-course="<?= (int) $module['course_id'] ?>" data-medium="<?= (int) $module['medium_count'] ?>" data-hard="<?= (int) $module['hard_count'] ?>" data-total="<?= (int) $module['total_count'] ?>"><?= e($module['title']) ?> (<?= (int) $module['total_count'] ?>)</option>
                <?php endforeach; ?>
            </select>
        </section>

        <section class="card simulation-builder-card">
            <div class="simulation-builder-step"><span>3</span><div><strong>Quantidade</strong><small>O tempo é calculado em aproximadamente 2 minutos por questão.</small></div></div>
            <div class="simulation-limit-grid">
                <?php foreach ($allowedLimits as $limit): ?>
                    <label class="simulation-limit-option">
                        <input type="radio" name="question_limit" value="<?= (int) $limit ?>" <?= (int) $limit === 20 ? 'checked' : '' ?>>
                        <span><strong><?= (int) $limit ?></strong><small>questões</small><em><?= (int) $limit * 2 ?> min</em></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card simulation-pool-status" data-simulation-pool-status>
            <div><span>Banco disponível</span><strong data-pool-total>0</strong><small>questões válidas</small></div>
            <div><span>Intermediárias</span><strong data-pool-medium>0</strong><small>prioridade 60%</small></div>
            <div><span>Difíceis</span><strong data-pool-hard>0</strong><small>prioridade 40%</small></div>
        </section>

        <div class="simulation-builder-note card">
            <strong>Como funciona a seleção?</strong>
            <p>O sistema tenta usar 60% de questões intermediárias e 40% difíceis, prioriza questões nunca vistas e distribui o simulado geral entre os módulos. Se o banco ainda não tiver questões difíceis suficientes, ele completa com questões intermediárias sem alterar artificialmente a classificação original.</p>
        </div>

        <button class="primary simulation-generate-button" type="submit">🎲 Gerar meu simulado</button>
    </form>
</section>
