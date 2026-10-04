<?php
$statusLabels = ['active'=>'Ativo','inactive'=>'Inativo','blocked'=>'Bloqueado'];
?>
<section class="ld-admin-page-head">
    <div><span class="ld-admin-kicker">ALUNO #<?= (int) $student['id'] ?></span><h1><?= e($student['name']) ?></h1><p><?= e($student['email']) ?> • cadastrado em <?= e(date('d/m/Y', strtotime($student['created_at']))) ?></p></div>
    <div class="ld-admin-head-actions"><span class="ld-admin-status is-<?= e($student['status']) ?>"><?= e($statusLabels[$student['status']] ?? $student['status']) ?></span><a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/usuarios')) ?>">← Alunos</a></div>
</section>

<div class="ld-admin-stats-grid">
    <article class="ld-admin-stat-card"><div><span>XP</span><strong><?= (int) $student['xp_total'] ?></strong><small>Nível <?= (int) $student['current_level'] ?></small></div></article>
    <article class="ld-admin-stat-card"><div><span>Aulas concluídas</span><strong><?= (int) ($summary['lessons_completed'] ?? 0) ?></strong><small><?= (int) ($summary['phases_completed'] ?? 0) ?> fases</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Quizzes</span><strong><?= (int) ($summary['quiz_attempts'] ?? 0) ?></strong><small>média <?= number_format((float)($summary['quiz_average'] ?? 0),1,',','.') ?>%</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Simulados</span><strong><?= (int) ($summary['simulation_attempts'] ?? 0) ?></strong><small>média <?= number_format((float)($summary['simulation_average'] ?? 0),1,',','.') ?>%</small></div></article>
</div>

<div class="ld-admin-content-grid">
<section class="ld-admin-panel ld-admin-panel-large">
    <div class="ld-admin-panel-heading"><div><span class="ld-admin-panel-kicker">CADASTRO</span><h2>Dados e acesso</h2></div></div>
    <form method="post" action="<?= e(url('/admin/usuarios/' . (int)$student['id'] . '/atualizar')) ?>">
        <?= \App\Core\Csrf::input() ?>
        <div class="ld-admin-form-grid two"><label><span>Nome</span><input class="ld-admin-input" name="name" value="<?= e($student['name']) ?>" required></label><label><span>E-mail</span><input class="ld-admin-input" name="email" type="email" value="<?= e($student['email']) ?>" required></label></div>
        <div class="ld-admin-form-actions"><button class="ld-admin-btn" type="submit">Salvar dados</button></div>
    </form>

    <div class="ld-admin-divider"></div>
    <h3>Status de acesso</h3>
    <div class="ld-admin-action-row">
        <?php foreach (['active'=>'Ativar','inactive'=>'Desativar','blocked'=>'Bloquear'] as $value=>$label): ?>
        <form method="post" action="<?= e(url('/admin/usuarios/' . (int)$student['id'] . '/status')) ?>"><?= \App\Core\Csrf::input() ?><input type="hidden" name="status" value="<?= e($value) ?>"><button class="ld-admin-btn <?= $value==='active'?'is-success':($value==='blocked'?'is-danger':'') ?>" type="submit" <?= $student['status']===$value?'disabled':'' ?>><?= e($label) ?></button></form>
        <?php endforeach; ?>
    </div>
    <p class="ld-admin-help">Ao desativar ou bloquear, novas requisições autenticadas do aluno deixam de ser aceitas. O histórico acadêmico permanece preservado.</p>
</section>

<aside class="ld-admin-panel">
    <div class="ld-admin-panel-heading compact"><div><span class="ld-admin-panel-kicker">SEGURANÇA</span><h2>Redefinir senha</h2></div></div>
    <form method="post" action="<?= e(url('/admin/usuarios/' . (int)$student['id'] . '/senha')) ?>" autocomplete="off">
        <?= \App\Core\Csrf::input() ?>
        <label><span>Nova senha</span><input class="ld-admin-input" id="reset-password" type="password" name="password" minlength="8" required autocomplete="new-password"></label>
        <label><span>Confirmar</span><input class="ld-admin-input" id="reset-password-confirmation" type="password" name="password_confirmation" minlength="8" required autocomplete="new-password"></label>
        <button class="ld-admin-btn is-ghost ld-admin-generate-password" type="button" data-generate-password data-password-target="reset-password" data-confirm-target="reset-password-confirmation">Gerar senha temporária</button>
        <button class="ld-admin-btn ld-admin-block-btn" type="submit">Redefinir senha</button>
    </form>
</aside>
</div>

<section class="ld-admin-panel ld-admin-panel-spaced">
    <div class="ld-admin-panel-heading"><div><span class="ld-admin-panel-kicker">MATRÍCULAS</span><h2>Cursos do aluno</h2><p>Ative, conclua ou cancele uma matrícula sem apagar o histórico.</p></div></div>
    <div class="ld-admin-enrollment-grid">
        <?php foreach ($enrollments as $enrollment): ?>
            <form class="ld-admin-enrollment-card" method="post" action="<?= e(url('/admin/usuarios/' . (int)$student['id'] . '/matricula')) ?>">
                <?= \App\Core\Csrf::input() ?><input type="hidden" name="course_id" value="<?= (int)$enrollment['course_id'] ?>">
                <div><strong><?= e($enrollment['course_title']) ?></strong><small>Progresso <?= number_format((float)$enrollment['progress_pct'],1,',','.') ?>% • média <?= number_format((float)$enrollment['average_score'],1,',','.') ?>%</small></div>
                <select class="ld-admin-select" name="status"><?php foreach(['active'=>'Ativa','completed'=>'Concluída','cancelled'=>'Cancelada'] as $v=>$l): ?><option value="<?= e($v) ?>" <?= $enrollment['status']===$v?'selected':'' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                <button class="ld-admin-btn is-small" type="submit">Atualizar</button>
            </form>
        <?php endforeach; ?>
        <form class="ld-admin-enrollment-card is-new" method="post" action="<?= e(url('/admin/usuarios/' . (int)$student['id'] . '/matricula')) ?>">
            <?= \App\Core\Csrf::input() ?>
            <div><strong>Adicionar/reativar matrícula</strong><small>Selecione um curso e o estado desejado.</small></div>
            <select class="ld-admin-select" name="course_id" required><option value="">Escolha um curso</option><?php foreach($courses as $course): ?><option value="<?= (int)$course['id'] ?>"><?= e($course['title']) ?></option><?php endforeach; ?></select>
            <select class="ld-admin-select" name="status"><option value="active">Ativa</option><option value="completed">Concluída</option><option value="cancelled">Cancelada</option></select>
            <button class="ld-admin-btn is-small" type="submit">Salvar matrícula</button>
        </form>
    </div>
</section>

<div class="ld-admin-content-grid ld-admin-panel-spaced">
<section class="ld-admin-panel ld-admin-panel-large">
    <div class="ld-admin-panel-heading"><div><span class="ld-admin-panel-kicker">HISTÓRICO</span><h2>Atividade e XP</h2></div></div>
    <div class="ld-admin-timeline ld-admin-timeline-scroll">
        <?php if(empty($xpEvents)): ?><div class="ld-admin-empty">Ainda não há eventos de XP.</div><?php endif; ?>
        <?php foreach($xpEvents as $event): ?><div class="ld-admin-timeline-item"><span></span><div><strong><?= e($event['description'] ?: $event['event_type']) ?></strong><small><?= e($event['event_type']) ?> • <?= (int)$event['xp_amount'] ?> XP • <?= e(date('d/m/Y H:i',strtotime($event['created_at']))) ?></small></div></div><?php endforeach; ?>
    </div>
</section>
<aside class="ld-admin-panel">
    <div class="ld-admin-panel-heading compact"><div><span class="ld-admin-panel-kicker">ADMINISTRAÇÃO</span><h2>Alterações registradas</h2></div></div>
    <div class="ld-admin-timeline ld-admin-timeline-scroll">
        <?php if(empty($auditEvents)): ?><div class="ld-admin-empty">Sem ações administrativas registradas.</div><?php endif; ?>
        <?php foreach($auditEvents as $event): ?><div class="ld-admin-timeline-item"><span></span><div><strong><?= e($event['description'] ?: $event['action']) ?></strong><small><?= e($event['actor_name'] ?? 'Sistema') ?> • <?= e(date('d/m/Y H:i',strtotime($event['created_at']))) ?></small></div></div><?php endforeach; ?>
    </div>
</aside>
</div>

<section class="ld-admin-panel ld-admin-panel-spaced">
    <div class="ld-admin-panel-heading"><div><span class="ld-admin-panel-kicker">AVALIAÇÕES</span><h2>Quizzes e simulados</h2></div></div>
    <div class="ld-admin-two-tables">
        <div><h3>Quizzes</h3><div class="ld-admin-table-wrap"><table class="ld-admin-table"><thead><tr><th>Prova</th><th>Nota</th><th>Status</th><th>Data</th></tr></thead><tbody><?php if(empty($quizAttempts)): ?><tr><td colspan="4">Sem tentativas.</td></tr><?php endif; ?><?php foreach($quizAttempts as $a): ?><tr><td><strong><?= e($a['quiz_title']) ?></strong><small><?= e($a['quiz_type']) ?></small></td><td><?= number_format((float)$a['percentage'],1,',','.') ?>%</td><td><?= $a['status']==='finished'?($a['passed']?'Aprovado':'Reprovado'):e($a['status']) ?></td><td><?= e(date('d/m/Y H:i',strtotime($a['started_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
        <div><h3>Simulados</h3><div class="ld-admin-table-wrap"><table class="ld-admin-table"><thead><tr><th>Escopo</th><th>Nota</th><th>Questões</th><th>Data</th></tr></thead><tbody><?php if(empty($simulationAttempts)): ?><tr><td colspan="4">Sem tentativas.</td></tr><?php endif; ?><?php foreach($simulationAttempts as $a): ?><tr><td><strong><?= e($a['selection_mode']==='module' ? ($a['module_title'] ?? 'Módulo') : 'Geral') ?></strong><small><?= e($a['status']) ?></small></td><td><?= number_format((float)$a['percentage'],1,',','.') ?>%</td><td><?= (int)$a['question_limit'] ?></td><td><?= e(date('d/m/Y H:i',strtotime($a['started_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    </div>
</section>

<section class="ld-admin-panel ld-admin-danger-zone ld-admin-panel-spaced">
    <div><span class="ld-admin-panel-kicker">ZONA DE PERIGO</span><h2>Excluir aluno definitivamente</h2><p>Esta ação remove a conta e os dados acadêmicos vinculados por cascata. Para preservar o histórico, prefira <strong>Desativar</strong>.</p></div>
    <form method="post" action="<?= e(url('/admin/usuarios/' . (int)$student['id'])) ?>" data-confirm="Esta exclusão é permanente. Deseja continuar?">
        <?= \App\Core\Csrf::input() ?><input type="hidden" name="_method" value="DELETE">
        <label><span>Digite o e-mail do aluno para confirmar</span><input class="ld-admin-input" type="email" name="confirm_email" placeholder="<?= e($student['email']) ?>" required></label>
        <button class="ld-admin-btn is-danger" type="submit">Excluir definitivamente</button>
    </form>
</section>
