<?php
$active = (int) ($stats['active_count'] ?? 0);
$inactive = (int) ($stats['inactive_count'] ?? 0);
$blocked = (int) ($stats['blocked_count'] ?? 0);
$total = (int) ($stats['total_count'] ?? 0);
?>
<section class="ld-admin-page-head">
    <div><span class="ld-admin-kicker">GESTÃO DE ALUNOS</span><h1>Alunos</h1><p>Cadastre, consulte, bloqueie, redefina senhas e acompanhe o histórico acadêmico.</p></div>
    <a class="ld-admin-primary-action" href="<?= e(url('/admin/usuarios/novo')) ?>">+ Novo aluno</a>
</section>

<div class="ld-admin-stats-grid">
    <article class="ld-admin-stat-card"><div><span>Total</span><strong><?= $total ?></strong><small>alunos cadastrados</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Ativos</span><strong><?= $active ?></strong><small>podem acessar a plataforma</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Inativos</span><strong><?= $inactive ?></strong><small>acesso desativado</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Bloqueados</span><strong><?= $blocked ?></strong><small>acesso bloqueado</small></div></article>
</div>

<section class="ld-admin-panel">
    <form class="ld-admin-filterbar" method="get" action="<?= e(url('/admin/usuarios')) ?>">
        <input class="ld-admin-input" type="search" name="q" value="<?= e($filters['q'] ?? '') ?>" placeholder="Buscar por nome ou e-mail">
        <select class="ld-admin-select" name="status">
            <option value="">Todos os status</option>
            <?php foreach (['active'=>'Ativo','inactive'=>'Inativo','blocked'=>'Bloqueado'] as $value=>$label): ?>
                <option value="<?= e($value) ?>" <?= ($filters['status'] ?? '') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="ld-admin-btn" type="submit">Filtrar</button>
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/usuarios')) ?>">Limpar</a>
    </form>

    <div class="ld-admin-table-wrap">
        <table class="ld-admin-table">
            <thead><tr><th>Aluno</th><th>Status</th><th>Curso(s)</th><th>Progresso</th><th>XP / nível</th><th>Último login</th><th></th></tr></thead>
            <tbody>
            <?php if (empty($students)): ?><tr><td colspan="7"><div class="ld-admin-empty">Nenhum aluno encontrado.</div></td></tr><?php endif; ?>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td><strong><?= e($student['name']) ?></strong><small><?= e($student['email']) ?></small></td>
                    <td><span class="ld-admin-status is-<?= e($student['status']) ?>"><?= e($student['status']) ?></span></td>
                    <td><?= (int) $student['active_courses'] ?></td>
                    <td><?= number_format((float) $student['max_progress'], 1, ',', '.') ?>%</td>
                    <td><?= (int) $student['xp_total'] ?> XP <small>Nível <?= (int) $student['current_level'] ?></small></td>
                    <td><?= $student['last_login_at'] ? e(date('d/m/Y H:i', strtotime($student['last_login_at']))) : 'Nunca' ?></td>
                    <td><a class="ld-admin-btn is-small" href="<?= e(url('/admin/usuarios/' . (int) $student['id'])) ?>">Gerenciar</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
