<?php
$students = (int) ($stats['students'] ?? 0);
$activeStudents = (int) ($stats['active_students'] ?? 0);
$inactiveStudents = (int) ($stats['inactive_students'] ?? 0);
$courses = (int) ($stats['courses'] ?? 0);
$modules = (int) ($stats['modules'] ?? 0);
$questions = (int) ($stats['questions'] ?? 0);
$simulations = (int) ($stats['simulations'] ?? 0);
?>
<section class="ld-admin-dashboard-v4">
    <div class="ld-admin-dashboard-v4-top">
        <section class="ld-admin-hero-v4">
            <div class="ld-admin-hero-v4-copy">
                <span class="ld-admin-kicker">CENTRAL DE ADMINISTRAÇÃO</span>
                <h1>Gestão da plataforma.</h1>
                <p>Administre alunos, aulas, vídeos, questões, simulados e relatórios sem sair do painel.</p>

                <div class="ld-admin-hero-v4-actions">
                    <a class="ld-admin-primary-action" href="<?= e(url('/admin/usuarios/novo')) ?>">+ Novo aluno</a>
                    <a class="ld-admin-secondary-action" href="<?= e(url('/admin/questoes/nova')) ?>">+ Nova questão</a>
                    <a class="ld-admin-secondary-action" href="<?= e(url('/admin/usuarios')) ?>">Gerenciar alunos</a>
                </div>
            </div>

            <div class="ld-admin-hero-v4-brand" aria-hidden="true">
                <span>LD</span>
                <strong>ADMIN</strong>
                <small>GESTÃO • CONTROLE</small>
            </div>
        </section>

        <div class="ld-admin-stats-v4">
            <article class="ld-admin-stat-v4">
                <span class="ld-admin-stat-v4-icon">♙</span>
                <div><small>ALUNOS</small><strong><?= $students ?></strong><em><?= $activeStudents ?> ativos • <?= $inactiveStudents ?> inativos</em></div>
            </article>

            <article class="ld-admin-stat-v4">
                <span class="ld-admin-stat-v4-icon">▦</span>
                <div><small>CURSOS</small><strong><?= $courses ?></strong><em><?= $modules ?> módulos</em></div>
            </article>

            <article class="ld-admin-stat-v4">
                <span class="ld-admin-stat-v4-icon">?</span>
                <div><small>QUESTÕES</small><strong><?= $questions ?></strong><em>ativas no banco</em></div>
            </article>

            <article class="ld-admin-stat-v4">
                <span class="ld-admin-stat-v4-icon">◎</span>
                <div><small>SIMULADOS</small><strong><?= $simulations ?></strong><em>finalizados</em></div>
            </article>
        </div>
    </div>

    <div class="ld-admin-dashboard-v4-bottom">
        <section class="ld-admin-panel ld-admin-v4-actions-panel">
            <div class="ld-admin-panel-heading compact">
                <div><span class="ld-admin-panel-kicker">GESTÃO RÁPIDA</span><h2>Acessos administrativos</h2></div>
            </div>

            <div class="ld-admin-v4-actions-grid">
                <a class="ld-admin-v4-action" href="<?= e(url('/admin/conteudo')) ?>">
                    <span>01</span><div><strong>Cursos e conteúdo</strong><small>Estrutura geral do curso</small></div><em>→</em>
                </a>
                <a class="ld-admin-v4-action" href="<?= e(url('/admin/aulas')) ?>">
                    <span>02</span><div><strong>Gerenciar aulas</strong><small>Blocos, conteúdo e organização</small></div><em>→</em>
                </a>
                <a class="ld-admin-v4-action" href="<?= e(url('/admin/videos')) ?>">
                    <span>03</span><div><strong>Biblioteca de vídeos</strong><small>Enviar e reutilizar mídias</small></div><em>→</em>
                </a>
                <a class="ld-admin-v4-action" href="<?= e(url('/admin/questoes')) ?>">
                    <span>04</span><div><strong>Banco de questões</strong><small>Criar, revisar e editar</small></div><em>→</em>
                </a>
                <a class="ld-admin-v4-action" href="<?= e(url('/admin/usuarios')) ?>">
                    <span>05</span><div><strong>Gestão de alunos</strong><small>Senha, acesso e histórico</small></div><em>→</em>
                </a>
                <a class="ld-admin-v4-action" href="<?= e(url('/admin/relatorios')) ?>">
                    <span>06</span><div><strong>Relatórios</strong><small>Desempenho e acompanhamento</small></div><em>→</em>
                </a>
            </div>
        </section>

        <div class="ld-admin-v4-side-stack">
            <section class="ld-admin-panel ld-admin-v4-mini-panel">
                <div class="ld-admin-panel-heading compact">
                    <div><span class="ld-admin-panel-kicker">ALUNOS</span><h2>Cadastros recentes</h2></div>
                    <a class="ld-admin-link" href="<?= e(url('/admin/usuarios')) ?>">Ver todos →</a>
                </div>

                <div class="ld-admin-v4-scroll-list">
                    <?php if (empty($recentStudents)): ?>
                        <div class="ld-admin-empty">Nenhum aluno cadastrado.</div>
                    <?php endif; ?>

                    <?php foreach (($recentStudents ?? []) as $student): ?>
                        <a class="ld-admin-v4-student" href="<?= e(url('/admin/usuarios/' . (int) $student['id'])) ?>">
                            <span class="ld-admin-mini-avatar"><?= e(mb_strtoupper(mb_substr((string) $student['name'], 0, 1))) ?></span>
                            <span><strong><?= e($student['name']) ?></strong><small><?= e($student['email']) ?></small></span>
                            <em class="ld-admin-status is-<?= e($student['status']) ?>"><?= e($student['status']) ?></em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="ld-admin-panel ld-admin-v4-mini-panel">
                <div class="ld-admin-panel-heading compact">
                    <div><span class="ld-admin-panel-kicker">AUDITORIA</span><h2>Atividade recente</h2></div>
                </div>

                <div class="ld-admin-v4-scroll-list ld-admin-v4-audit-list">
                    <?php if (empty($recentAudit)): ?>
                        <div class="ld-admin-empty">Nenhuma atividade administrativa recente.</div>
                    <?php endif; ?>

                    <?php foreach (($recentAudit ?? []) as $event): ?>
                        <div class="ld-admin-v4-audit-item">
                            <span></span>
                            <div>
                                <strong><?= e($event['description'] ?: $event['action']) ?></strong>
                                <small><?= e($event['actor_name'] ?? 'Sistema') ?> • <?= e(date('d/m/Y H:i', strtotime($event['created_at']))) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>
    </div>
</section>
