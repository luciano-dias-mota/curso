<?php
$filters = $filters ?? [];
$pagination = $pagination ?? ['page' => 1, 'pages' => 1, 'total' => count($questions ?? [])];
$baseQuery = [
    'q' => $filters['search'] ?? '',
    'module_id' => (int) ($filters['moduleId'] ?? 0),
    'difficulty' => $filters['difficulty'] ?? '',
    'state' => $filters['state'] ?? '',
];
$baseQuery = array_filter($baseQuery, static fn ($value) => $value !== '' && $value !== 0);
?>

<section class="ld-admin-page-head ld-admin-page-head-compact">
    <div>
        <span class="ld-admin-kicker">BANCO DE QUESTÕES</span>
        <h1>Questões</h1>
        <p>Consulte, filtre, edite o enunciado e as alternativas ou cadastre novas questões para os simulados.</p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-primary" href="<?= e(url('/admin/questoes/nova')) ?>">+ Nova questão</a>
    </div>
</section>

<div class="ld-admin-stats-grid ld-admin-stats-grid-compact">
    <article class="ld-admin-stat-card"><div><span>Total</span><strong><?= (int) ($stats['total'] ?? 0) ?></strong><small>cadastradas</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Ativas</span><strong><?= (int) ($stats['active_count'] ?? 0) ?></strong><small>disponíveis</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Intermediárias</span><strong><?= (int) ($stats['medium_count'] ?? 0) ?></strong><small>ativas</small></div></article>
    <article class="ld-admin-stat-card"><div><span>Difíceis</span><strong><?= (int) ($stats['hard_count'] ?? 0) ?></strong><small>ativas</small></div></article>
</div>

<section class="ld-admin-panel ld-admin-panel-compact">
    <form class="ld-admin-filterbar ld-admin-question-filterbar" method="get" action="<?= e(url('/admin/questoes')) ?>">
        <input class="ld-admin-input" type="search" name="q" value="<?= e($filters['search'] ?? '') ?>" placeholder="Buscar no enunciado ou fonte...">

        <select class="ld-admin-select" name="module_id" aria-label="Módulo">
            <option value="0">Todos os módulos</option>
            <?php foreach ($modules as $module): ?>
                <option value="<?= (int) $module['id'] ?>" <?= (int) ($filters['moduleId'] ?? 0) === (int) $module['id'] ? 'selected' : '' ?>>
                    <?= e($module['title']) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="ld-admin-select" name="difficulty" aria-label="Dificuldade">
            <option value="">Todas as dificuldades</option>
            <option value="medium" <?= ($filters['difficulty'] ?? '') === 'medium' ? 'selected' : '' ?>>Intermediária</option>
            <option value="hard" <?= ($filters['difficulty'] ?? '') === 'hard' ? 'selected' : '' ?>>Difícil</option>
            <option value="easy" <?= ($filters['difficulty'] ?? '') === 'easy' ? 'selected' : '' ?>>Fácil</option>
        </select>

        <select class="ld-admin-select" name="state" aria-label="Status">
            <option value="">Todos os status</option>
            <option value="active" <?= ($filters['state'] ?? '') === 'active' ? 'selected' : '' ?>>Ativas</option>
            <option value="inactive" <?= ($filters['state'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inativas</option>
        </select>

        <button class="ld-admin-btn" type="submit">Filtrar</button>
        <?php if ($baseQuery !== []): ?>
            <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/questoes')) ?>">Limpar</a>
        <?php endif; ?>
    </form>

    <div class="ld-admin-list-meta">
        <span><?= (int) ($pagination['total'] ?? 0) ?> resultado(s)</span>
        <span>Página <?= (int) ($pagination['page'] ?? 1) ?> de <?= (int) ($pagination['pages'] ?? 1) ?></span>
    </div>

    <?php if (!$questions): ?>
        <div class="ld-admin-empty">Nenhuma questão encontrada com os filtros informados.</div>
    <?php else: ?>
        <div class="ld-admin-question-list">
            <?php foreach ($questions as $q): ?>
                <?php
                $difficultyLabel = match ($q['difficulty']) {
                    'hard' => 'Difícil',
                    'easy' => 'Fácil',
                    default => 'Intermediária',
                };
                $linked = (int) ($q['quiz_links'] ?? 0) + (int) ($q['simulation_links'] ?? 0) + (int) ($q['simulation_uses'] ?? 0);
                ?>
                <article class="ld-admin-question-card">
                    <div class="ld-admin-question-card-main">
                        <div class="ld-admin-question-meta">
                            <span class="ld-admin-code">#<?= (int) $q['id'] ?></span>
                            <span class="ld-admin-pill <?= $q['difficulty'] === 'hard' ? 'is-hard' : '' ?>"><?= e($difficultyLabel) ?></span>
                            <span class="ld-admin-status <?= (int) $q['active'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                <?= (int) $q['active'] === 1 ? 'Ativa' : 'Inativa' ?>
                            </span>
                        </div>

                        <h2><?= e($q['statement']) ?></h2>

                        <div class="ld-admin-question-details">
                            <span><strong>Módulo:</strong> <?= e($q['module_title'] ?? 'Sem módulo') ?></span>
                            <span><strong>Fonte:</strong> <?= e($q['source_label'] ?: 'Não informada') ?></span>
                            <span><strong>Alternativas:</strong> <?= (int) ($q['alternatives_count'] ?? 0) ?></span>
                            <?php if ($linked > 0): ?>
                                <span><strong>Uso registrado:</strong> <?= $linked ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ld-admin-question-actions">
                        <a class="ld-admin-btn is-small" href="<?= e(url('/admin/questoes/' . (int) $q['id'] . '/editar')) ?>">Editar</a>
                        <form method="post" action="<?= e(url('/admin/questoes/' . (int) $q['id'] . '/status')) ?>">
                            <?= \App\Core\Csrf::input() ?>
                            <input type="hidden" name="active" value="<?= (int) $q['active'] === 1 ? 0 : 1 ?>">
                            <button class="ld-admin-btn is-small <?= (int) $q['active'] === 1 ? 'is-ghost' : 'is-success' ?>" type="submit">
                                <?= (int) $q['active'] === 1 ? 'Desativar' : 'Ativar' ?>
                            </button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>

        <?php if ((int) ($pagination['pages'] ?? 1) > 1): ?>
            <nav class="ld-admin-pagination" aria-label="Paginação das questões">
                <?php $current = (int) $pagination['page']; $pages = (int) $pagination['pages']; ?>
                <?php if ($current > 1): ?>
                    <a href="<?= e(url('/admin/questoes') . '?' . http_build_query($baseQuery + ['page' => $current - 1])) ?>">← Anterior</a>
                <?php endif; ?>
                <span><?= $current ?> / <?= $pages ?></span>
                <?php if ($current < $pages): ?>
                    <a href="<?= e(url('/admin/questoes') . '?' . http_build_query($baseQuery + ['page' => $current + 1])) ?>">Próxima →</a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</section>
