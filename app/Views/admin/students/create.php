<?php $old = \App\Core\Session::pullFlash('old_student', []); ?>
<section class="ld-admin-page-head"><div><span class="ld-admin-kicker">ALUNOS</span><h1>Novo aluno</h1><p>Crie a conta e, opcionalmente, já faça a matrícula nos cursos disponíveis.</p></div><a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/usuarios')) ?>">← Voltar</a></section>

<section class="ld-admin-panel ld-admin-form-panel">
    <form method="post" action="<?= e(url('/admin/usuarios')) ?>" autocomplete="off">
        <?= \App\Core\Csrf::input() ?>
        <div class="ld-admin-form-grid two">
            <label><span>Nome completo</span><input class="ld-admin-input" type="text" name="name" maxlength="150" required value="<?= e($old['name'] ?? '') ?>"></label>
            <label><span>E-mail</span><input class="ld-admin-input" type="email" name="email" maxlength="190" required value="<?= e($old['email'] ?? '') ?>"></label>
            <label><span>Senha inicial</span><input class="ld-admin-input" id="student-password" type="password" name="password" minlength="8" required autocomplete="new-password"></label>
            <label><span>Confirmar senha</span><input class="ld-admin-input" id="student-password-confirmation" type="password" name="password_confirmation" minlength="8" required autocomplete="new-password"></label>
        </div>
        <button class="ld-admin-btn is-ghost ld-admin-generate-password" type="button" data-generate-password data-password-target="student-password" data-confirm-target="student-password-confirmation">Gerar senha temporária forte</button>

        <div class="ld-admin-form-section">
            <h2>Matrículas iniciais</h2><p>Você pode alterar as matrículas depois no perfil do aluno.</p>
            <div class="ld-admin-checkbox-grid">
                <?php foreach ($courses as $course): ?>
                    <label class="ld-admin-check"><input type="checkbox" name="course_ids[]" value="<?= (int) $course['id'] ?>" <?= in_array((int)$course['id'], array_map('intval', $old['course_ids'] ?? []), true) ? 'checked' : '' ?>><span><strong><?= e($course['title']) ?></strong><small><?= e($course['status']) ?></small></span></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="ld-admin-form-actions"><button class="ld-admin-primary-action" type="submit">Cadastrar aluno</button><a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/usuarios')) ?>">Cancelar</a></div>
    </form>
</section>
