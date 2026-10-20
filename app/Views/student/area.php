<?php
$pageType = (string) ($pageType ?? 'help');
$courseId = (int) ($course['id'] ?? 0);
?>
<section class="pg-area-page">
    <nav class="pg-breadcrumb" aria-label="Navegação estrutural">
        <a href="<?= e(url('/curso/' . $courseId)) ?>">Início</a>
        <span aria-hidden="true">›</span>
        <strong><?= e((string) ($title ?? 'Área do aluno')) ?></strong>
    </nav>

    <?php if ($pageType === 'profile'): ?>
        <header class="pg-area-hero">
            <span class="pg-area-icon" aria-hidden="true">♙</span>
            <div>
                <span class="pg-area-kicker">CONTA DO ALUNO</span>
                <h1>Meu Perfil</h1>
                <p>Confira seus dados de acesso à LD Academy.</p>
            </div>
        </header>

        <div class="pg-area-grid">
            <article class="pg-area-card">
                <small>Nome</small>
                <strong><?= e((string) ($user['name'] ?? 'Estudante')) ?></strong>
            </article>

            <article class="pg-area-card">
                <small>E-mail</small>
                <strong><?= e((string) ($user['email'] ?? '—')) ?></strong>
            </article>

            <article class="pg-area-card">
                <small>Perfil de acesso</small>
                <strong><?= e((string) ($user['role_name'] ?? 'Aluno')) ?></strong>
            </article>

            <article class="pg-area-card">
                <small>Status</small>
                <strong><?= ($user['status'] ?? '') === 'active' ? 'Ativo' : e((string) ($user['status'] ?? '—')) ?></strong>
            </article>
        </div>

        <article class="pg-area-panel">
            <h2>Curso atual</h2>
            <p><?= e((string) ($course['title'] ?? 'PostgreSQL')) ?></p>
            <a class="pg-area-button" href="<?= e(url('/curso/' . $courseId)) ?>">Voltar ao curso</a>
        </article>

    <?php elseif ($pageType === 'certificates'): ?>
        <?php
        $progressPct = max(0, min(100, (float) ($progress['progress_pct'] ?? 0)));
        $eligible = $progressPct >= 100;
        ?>
        <header class="pg-area-hero">
            <span class="pg-area-icon" aria-hidden="true">▣</span>
            <div>
                <span class="pg-area-kicker">CONCLUSÃO DO CURSO</span>
                <h1>Certificados</h1>
                <p>Acompanhe os requisitos para liberação do certificado deste curso.</p>
            </div>
        </header>

        <article class="pg-certificate-card <?= $eligible ? 'is-ready' : '' ?>">
            <div class="pg-certificate-copy">
                <span class="pg-area-kicker"><?= $eligible ? 'CERTIFICADO LIBERADO' : 'EM ANDAMENTO' ?></span>
                <h2><?= e((string) ($course['title'] ?? 'PostgreSQL')) ?></h2>
                <p>
                    <?= $eligible
                        ? 'Você concluiu o curso. O certificado está apto para emissão.'
                        : 'Conclua 100% do curso para liberar a emissão do certificado.' ?>
                </p>
            </div>

            <div class="pg-certificate-progress">
                <strong><?= number_format($progressPct, 0, ',', '.') ?>%</strong>
                <span>concluído</span>
            </div>

            <div class="pg-progress-track">
                <span style="width:<?= e((string) $progressPct) ?>%"></span>
            </div>

            <?php if ($eligible): ?>
                <span class="pg-area-button is-disabled">Emissão disponível após integração do gerador</span>
            <?php else: ?>
                <a class="pg-area-button" href="<?= e(url('/curso/' . $courseId)) ?>">Continuar estudando</a>
            <?php endif; ?>
        </article>

    <?php else: ?>
        <header class="pg-area-hero">
            <span class="pg-area-icon" aria-hidden="true">?</span>
            <div>
                <span class="pg-area-kicker">CENTRAL DE SUPORTE</span>
                <h1>Ajuda</h1>
                <p>Orientações rápidas para usar o curso PostgreSQL na LD Academy.</p>
            </div>
        </header>

        <div class="pg-help-grid">
            <article class="pg-area-card">
                <small>01</small>
                <h2>Como estudar</h2>
                <p>Abra os módulos pela trilha de aprendizagem e avance pelas aulas na ordem indicada.</p>
            </article>

            <article class="pg-area-card">
                <small>02</small>
                <h2>Progresso</h2>
                <p>O percentual é atualizado conforme as atividades obrigatórias do curso são concluídas.</p>
            </article>

            <article class="pg-area-card">
                <small>03</small>
                <h2>Laboratórios</h2>
                <p>Use os laboratórios práticos quando forem liberados para exercitar os comandos SQL.</p>
            </article>

            <article class="pg-area-card">
                <small>04</small>
                <h2>Problemas de acesso</h2>
                <p>Se uma aula que deveria estar liberada continuar bloqueada, retorne ao início e atualize a página.</p>
            </article>
        </div>

        <article class="pg-area-panel">
            <h2>Ainda precisa de ajuda?</h2>
            <p>Volte ao curso e confira se o módulo anterior foi concluído. Se o problema persistir, informe ao administrador qual módulo ou aula apresentou erro.</p>
            <a class="pg-area-button" href="<?= e(url('/curso/' . $courseId)) ?>">Voltar ao curso</a>
        </article>
    <?php endif; ?>
</section>
