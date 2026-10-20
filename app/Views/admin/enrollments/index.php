<?php
$total = (int) ($stats['total'] ?? 0);
$active = (int) ($stats['active_count'] ?? 0);
$completed = (int) ($stats['completed_count'] ?? 0);
$cancelled = (int) ($stats['cancelled_count'] ?? 0);
$statusLabels = ['active' => 'Ativa', 'completed' => 'Concluída', 'cancelled' => 'Cancelada'];
?>
<section class="ld-admin-page-head">
    <div>
        <span class="ld-admin-kicker">ACESSO AOS CURSOS</span>
        <h1>Matrículas</h1>
        <p>Libere, conclua ou cancele o acesso de um aluno a qualquer curso da plataforma.</p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/usuarios/novo')) ?>">+ Criar aluno</a>
        <a class="ld-admin-btn" href="<?= e(url('/admin/cursos')) ?>">Gerenciar cursos</a>
    </div>
</section>

<div class="ld-admin-stats-grid">
    <article class="ld-admin-stat-card"><div><span>Total</span><strong><?= $total ?></strong><small>matrículas registradas</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Ativas</span><strong><?= $active ?></strong><small>cursos disponíveis aos alunos</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Concluídas</span><strong><?= $completed ?></strong><small>histórico preservado</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Canceladas</span><strong><?= $cancelled ?></strong><small>sem acesso ao curso</small></div></article>
</div>

<section class="ld-admin-panel">
    <div class="ld-admin-panel-heading">
        <div>
            <span class="ld-admin-panel-kicker">LIBERAR CURSO</span>
            <h2>Adicionar ou alterar acesso</h2>
            <p>Uma combinação aluno + curso possui uma única matrícula; reativar preserva o histórico existente.</p>
        </div>
    </div>

    <form method="post" action="<?= e(url('/admin/matriculas')) ?>">
        <?= AppCoreCsrf::input() ?>
        <div class="ld-admin-form-grid two">
            <label>
                <span>Aluno</span>
                <select class="ld-admin-select" name="student_id" required>
                    <option value="">Selecione o aluno</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>">
                            <?= e($student['name']) ?> — <?= e($student['email']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Curso</span>
                <select class="ld-admin-select" name="course_id" required>
                    <option value="">Selecione o curso</option>
                    <?php foreach ($courses as $course): ?>
                        <option value="<?= (int) $course['id'] ?>">
                            <?= e($course['title']) ?><?= $course['status'] === 'draft' ? ' — rascunho' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <span>Situação</span>
                <select class="ld-admin-select" name="status">
                    <option value="active">Ativa — liberar acesso</option>
                    <option value="completed">Concluída</option>
                    <option value="cancelled">Cancelada — retirar acesso</option>
                </select>
            </label>
        </div>
        <div class="ld-admin-form-actions">
            <button class="ld-admin-primary-action" type="submit">Salvar matrícula</button>
        </div>
    </form>
</section>

<section class="ld-admin-panel ld-admin-panel-spaced">
    <div class="ld-admin-panel-heading">
        <div><span class="ld-admin-panel-kicker">CONSULTA</span><h2>Matrículas existentes</h2></div>
    </div>

    <form class="ld-admin-filterbar" method="get" action="<?= e(url('/admin/matriculas')) ?>">
        <select class="ld-admin-select" name="student_id">
            <option value="">Todos os alunos</option>
            <?php foreach ($students as $student): ?>
                <option value="<?= (int) $student['id'] ?>" <?= (int) ($filters['student_id'] ?? 0) === (int) $student['id'] ? 'selected' : '' ?>>
                    <?= e($student['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="ld-admin-select" name="course_id">
            <option value="">Todos os cursos</option>
            <?php foreach ($courses as $course): ?>
                <option value="<?= (int) $course['id'] ?>" <?= (int) ($filters['course_id'] ?? 0) === (int) $course['id'] ? 'selected' : '' ?>>
                    <?= e($course['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select class="ld-admin-select" name="status">
            <option value="">Todos os status</option>
            <?php foreach ($statusLabels as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="ld-admin-btn" type="submit">Filtrar</button>
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/matriculas')) ?>">Limpar</a>
    </form>

    <div class="ld-admin-table-wrap">
        <table class="ld-admin-table">
            <thead>
                <tr>
                    <th>Aluno</th>
                    <th>Curso</th>
                    <th>Status</th>
                    <th>Progresso</th>
                    <th>Último acesso</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($enrollments)): ?>
                <tr><td colspan="6"><div class="ld-admin-empty">Nenhuma matrícula encontrada.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($enrollments as $enrollment): ?>
                <tr>
                    <td>
                        <strong><?= e($enrollment['student_name']) ?></strong>
                        <small><?= e($enrollment['student_email']) ?></small>
                    </td>
                    <td>
                        <strong><?= e($enrollment['course_title']) ?></strong>
                        <small><?= e($enrollment['course_status']) ?></small>
                    </td>
                    <td><span class="ld-admin-status is-<?= e($enrollment['status']) ?>"><?= e($statusLabels[$enrollment['status']] ?? $enrollment['status']) ?></span></td>
                    <td><?= number_format((float) $enrollment['progress_pct'], 1, ',', '.') ?>%</td>
                    <td><?= $enrollment['last_accessed_at'] ? e(date('d/m/Y H:i', strtotime((string) $enrollment['last_accessed_at']))) : 'Nunca' ?></td>
                    <td><a class="ld-admin-btn is-small" href="<?= e(url('/admin/usuarios/' . (int) $enrollment['user_id'])) ?>">Aluno</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
