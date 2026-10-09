<?php
$students = (int) ($stats['students'] ?? 0);
$activeStudents = (int) ($stats['active_students'] ?? 0);
$inactiveStudents = (int) ($stats['inactive_students'] ?? 0);
$courses = (int) ($stats['courses'] ?? 0);
$modules = (int) ($stats['modules'] ?? 0);
$questions = (int) ($stats['questions'] ?? 0);
$simulations = (int) ($stats['simulations'] ?? 0);
?>
<section class="ld-admin-dashboard-v5">
    <div class="ld-admin-dashboard-v5-main">
        <section class="ld-admin-hero-v5">
            <div class="ld-admin-hero-v5-copy">
                <span class="ld-admin-kicker">CENTRAL DE ADMINISTRAÇÃO</span>
                <h1>Gestão da plataforma.</h1>
                <p>Controle alunos, conteúdo, questões, simulados e relatórios em uma visão única.</p>

                <div class="ld-admin-hero-v5-actions">
                    <a class="ld-admin-primary-action" href="<?= e(url('/admin/usuarios/novo')) ?>">+ Novo aluno</a>
                    <a class="ld-admin-secondary-action" href="<?= e(url('/admin/questoes/nova')) ?>">+ Nova questão</a>
                    <a class="ld-admin-secondary-action" href="<?= e(url('/admin/usuarios')) ?>">Gerenciar alunos</a>
                </div>
            </div>

            <div class="ld-admin-hero-v5-art" aria-hidden="true">
                <span class="ld-admin-hero-v5-orbit orbit-a"></span>
                <span class="ld-admin-hero-v5-orbit orbit-b"></span>
                <span class="ld-admin-hero-v5-spark spark-a">✦</span>
                <span class="ld-admin-hero-v5-spark spark-b">✦</span>
                <span class="ld-admin-hero-v5-disc disc-back">✓</span>
                <span class="ld-admin-hero-v5-disc disc-front">LD</span>
            </div>
        </section>

        <div class="ld-admin-stats-v5">
            <a class="ld-admin-stat-v5 is-students" href="<?= e(url('/admin/usuarios')) ?>">
                <span class="ld-admin-stat-v5-label">ALUNOS</span>
                <strong><?= $students ?></strong>
                <small><?= $activeStudents ?> ativos • <?= $inactiveStudents ?> inativos</small>
                <em>→</em>
            </a>

            <a class="ld-admin-stat-v5 is-courses" href="<?= e(url('/admin/conteudo')) ?>">
                <span class="ld-admin-stat-v5-label">CURSOS</span>
                <strong><?= $courses ?></strong>
                <small><?= $modules ?> módulos publicados</small>
                <em>→</em>
            </a>

            <a class="ld-admin-stat-v5 is-questions" href="<?= e(url('/admin/questoes')) ?>">
                <span class="ld-admin-stat-v5-label">QUESTÕES</span>
                <strong><?= $questions ?></strong>
                <small>itens disponíveis no banco</small>
                <em>→</em>
            </a>

            <a class="ld-admin-stat-v5 is-simulations" href="<?= e(url('/admin/simulados')) ?>">
                <span class="ld-admin-stat-v5-label">SIMULADOS</span>
                <strong><?= $simulations ?></strong>
                <small>tentativas finalizadas</small>
                <em>→</em>
            </a>
        </div>

        <section class="ld-admin-panel ld-admin-access-v5">
            <div class="ld-admin-panel-heading compact">
                <div>
                    <span class="ld-admin-panel-kicker">GESTÃO RÁPIDA</span>
                    <h2>Acessos administrativos</h2>
                </div>
                <span class="ld-admin-access-v5-hint">atalhos principais</span>
            </div>

            <div class="ld-admin-access-v5-grid">
                <a href="<?= e(url('/admin/conteudo')) ?>">
                    <span>01</span>
                    <strong>Cursos</strong>
                    <small>Conteúdo</small>
                </a>
                <a href="<?= e(url('/admin/aulas')) ?>">
                    <span>02</span>
                    <strong>Aulas</strong>
                    <small>Estrutura</small>
                </a>
                <a href="<?= e(url('/admin/videos')) ?>">
                    <span>03</span>
                    <strong>Vídeos</strong>
                    <small>Biblioteca</small>
                </a>
                <a href="<?= e(url('/admin/questoes')) ?>">
                    <span>04</span>
                    <strong>Questões</strong>
                    <small>Banco</small>
                </a>
                <a href="<?= e(url('/admin/usuarios')) ?>">
                    <span>05</span>
                    <strong>Alunos</strong>
                    <small>Gestão</small>
                </a>
                <a href="<?= e(url('/admin/relatorios')) ?>">
                    <span>06</span>
                    <strong>Relatórios</strong>
                    <small>Análises</small>
                </a>
            </div>
        </section>
    </div>

    <aside class="ld-admin-dashboard-v5-rail">
        <section class="ld-admin-panel ld-admin-rail-v5 ld-admin-recent-v5">
            <div class="ld-admin-panel-heading compact">
                <div>
                    <span class="ld-admin-panel-kicker">ALUNOS</span>
                    <h2>Cadastros recentes</h2>
                </div>
                <a class="ld-admin-link" href="<?= e(url('/admin/usuarios')) ?>">Ver todos</a>
            </div>

            <div class="ld-admin-rail-v5-list">
                <?php if (empty($recentStudents)): ?>
                    <div class="ld-admin-empty">Nenhum aluno cadastrado.</div>
                <?php endif; ?>

                <?php foreach (($recentStudents ?? []) as $student): ?>
                    <a class="ld-admin-recent-student-v5" href="<?= e(url('/admin/usuarios/' . (int) $student['id'])) ?>">
                        <span class="ld-admin-recent-student-v5-avatar"><?= e(mb_strtoupper(mb_substr((string) $student['name'], 0, 1))) ?></span>
                        <span class="ld-admin-recent-student-v5-copy">
                            <strong><?= e($student['name']) ?></strong>
                            <small><?= e($student['email']) ?></small>
                        </span>
                        <em class="ld-admin-status is-<?= e($student['status']) ?>"><?= e($student['status']) ?></em>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="ld-admin-recent-v5-meter" aria-hidden="true">
                <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
            </div>
        </section>

        <section class="ld-admin-panel ld-admin-rail-v5 ld-admin-activity-v5">
            <div class="ld-admin-panel-heading compact">
                <div>
                    <span class="ld-admin-panel-kicker">AUDITORIA</span>
                    <h2>Atividade recente</h2>
                </div>
                <span class="ld-admin-activity-v5-badge">tempo real</span>
            </div>

            <div class="ld-admin-activity-v5-graph" aria-hidden="true">
                <span class="line l1"></span>
                <span class="line l2"></span>
                <span class="line l3"></span>
                <span class="line l4"></span>
                <span class="curve"></span>
                <i class="point p1"></i>
                <i class="point p2"></i>
                <i class="point p3"></i>
            </div>

            <div class="ld-admin-rail-v5-list ld-admin-activity-v5-list">
                <?php if (empty($recentAudit)): ?>
                    <div class="ld-admin-empty">Nenhuma atividade administrativa recente.</div>
                <?php endif; ?>

                <?php foreach (($recentAudit ?? []) as $event): ?>
                    <div class="ld-admin-activity-v5-item">
                        <span></span>
                        <div>
                            <strong><?= e($event['description'] ?: $event['action']) ?></strong>
                            <small><?= e($event['actor_name'] ?? 'Sistema') ?> • <?= e(date('d/m/Y H:i', strtotime($event['created_at']))) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </aside>
</section>
<?php
$students = (int) ($stats['students'] ?? 0);
$activeStudents = (int) ($stats['active_students'] ?? 0);
$inactiveStudents = (int) ($stats['inactive_students'] ?? 0);
$courses = (int) ($stats['courses'] ?? 0);
$modules = (int) ($stats['modules'] ?? 0);
$questions = (int) ($stats['questions'] ?? 0);
$simulations = (int) ($stats['simulations'] ?? 0);

// Gráfico de atividade: consolida apenas os eventos recentes já fornecidos pelo controller.
$activityCounts = [];
$activityLabels = [];
$today = new DateTimeImmutable('today');
for ($i = 6; $i >= 0; $i--) {
    $day = $today->modify('-' . $i . ' days');
    $key = $day->format('Y-m-d');
    $activityCounts[$key] = 0;
    $activityLabels[$key] = $day->format('d/m');
}

foreach (($recentAudit ?? []) as $event) {
    $timestamp = strtotime((string) ($event['created_at'] ?? ''));
    if ($timestamp === false) {
        continue;
    }

    $key = date('Y-m-d', $timestamp);
    if (array_key_exists($key, $activityCounts)) {
        $activityCounts[$key]++;
    }
}

$activityValues = array_values($activityCounts);
$activityMax = max(1, ...$activityValues);
$activityPoints = [];
$activityAreaPoints = ['28,148'];
foreach ($activityValues as $index => $count) {
    $x = 28 + ($index * 104);
    $y = 138 - (($count / $activityMax) * 92);
    $activityPoints[] = $x . ',' . round($y, 2);
    $activityAreaPoints[] = $x . ',' . round($y, 2);
}
$activityAreaPoints[] = '652,148';
$activityTotal = array_sum($activityValues);
?>
<section class="ld-admin-dashboard-v5">
    <div class="ld-admin-dashboard-v5-main">
        <section class="ld-admin-hero-v5">
            <div class="ld-admin-hero-v5-copy">
                <span class="ld-admin-kicker">CENTRAL DE ADMINISTRAÇÃO</span>
                <h1>Gestão da plataforma.</h1>
                <p>Controle alunos, conteúdo, questões, simulados e relatórios em uma visão única.</p>

                <div class="ld-admin-hero-v5-actions">
                    <a class="ld-admin-primary-action" href="<?= e(url('/admin/usuarios/novo')) ?>">+ Novo aluno</a>
                    <a class="ld-admin-secondary-action" href="<?= e(url('/admin/questoes/nova')) ?>">+ Nova questão</a>
                    <a class="ld-admin-secondary-action" href="<?= e(url('/admin/usuarios')) ?>">Gerenciar alunos</a>
                </div>
            </div>

            <div class="ld-admin-hero-v5-art" aria-hidden="true">
                <span class="ld-admin-hero-v5-orbit orbit-a"></span>
                <span class="ld-admin-hero-v5-orbit orbit-b"></span>
                <span class="ld-admin-hero-v5-spark spark-a">✦</span>
                <span class="ld-admin-hero-v5-spark spark-b">✦</span>
                <span class="ld-admin-hero-v5-disc disc-back">✓</span>
                <span class="ld-admin-hero-v5-disc disc-front">LD</span>
            </div>
        </section>

        <div class="ld-admin-stats-v5">
            <a class="ld-admin-stat-v5 is-students" href="<?= e(url('/admin/usuarios')) ?>">
                <span class="ld-admin-stat-v5-label">ALUNOS</span>
                <strong><?= $students ?></strong>
                <small><?= $activeStudents ?> ativos • <?= $inactiveStudents ?> inativos</small>
                <em>→</em>
            </a>

            <a class="ld-admin-stat-v5 is-courses" href="<?= e(url('/admin/conteudo')) ?>">
                <span class="ld-admin-stat-v5-label">CURSOS</span>
                <strong><?= $courses ?></strong>
                <small><?= $modules ?> módulos publicados</small>
                <em>→</em>
            </a>

            <a class="ld-admin-stat-v5 is-questions" href="<?= e(url('/admin/questoes')) ?>">
                <span class="ld-admin-stat-v5-label">QUESTÕES</span>
                <strong><?= $questions ?></strong>
                <small>itens disponíveis no banco</small>
                <em>→</em>
            </a>

            <a class="ld-admin-stat-v5 is-simulations" href="<?= e(url('/admin/simulados')) ?>">
                <span class="ld-admin-stat-v5-label">SIMULADOS</span>
                <strong><?= $simulations ?></strong>
                <small>tentativas finalizadas</small>
                <em>→</em>
            </a>
        </div>

        <section class="ld-admin-panel ld-admin-access-v5">
            <div class="ld-admin-panel-heading compact">
                <div>
                    <span class="ld-admin-panel-kicker">GESTÃO RÁPIDA</span>
                    <h2>Acessos administrativos</h2>
                </div>
                <span class="ld-admin-access-v5-hint">atalhos principais</span>
            </div>

            <div class="ld-admin-access-v5-grid">
                <a class="is-courses" href="<?= e(url('/admin/conteudo')) ?>">
                    <span class="ld-admin-access-v6-icon" aria-hidden="true">📚</span>
                    <strong>Cursos e conteúdo</strong>
                    <small>Estrutura da plataforma</small>
                </a>
                <a class="is-lessons" href="<?= e(url('/admin/aulas')) ?>">
                    <span class="ld-admin-access-v6-icon" aria-hidden="true">🎓</span>
                    <strong>Gerenciar aulas</strong>
                    <small>Blocos e organização</small>
                </a>
                <a class="is-videos" href="<?= e(url('/admin/videos')) ?>">
                    <span class="ld-admin-access-v6-icon" aria-hidden="true">🎬</span>
                    <strong>Biblioteca de vídeos</strong>
                    <small>Mídias e reutilização</small>
                </a>
                <a class="is-questions" href="<?= e(url('/admin/questoes')) ?>">
                    <span class="ld-admin-access-v6-icon" aria-hidden="true">🧠</span>
                    <strong>Banco de questões</strong>
                    <small>Criação e revisão</small>
                </a>
                <a class="is-students" href="<?= e(url('/admin/usuarios')) ?>">
                    <span class="ld-admin-access-v6-icon" aria-hidden="true">👥</span>
                    <strong>Gestão de alunos</strong>
                    <small>Acesso e histórico</small>
                </a>
                <a class="is-reports" href="<?= e(url('/admin/relatorios')) ?>">
                    <span class="ld-admin-access-v6-icon" aria-hidden="true">📊</span>
                    <strong>Relatórios</strong>
                    <small>Desempenho e análise</small>
                </a>
            </div>
        </section>
    </div>

    <aside class="ld-admin-dashboard-v5-rail">
        <section class="ld-admin-panel ld-admin-rail-v5 ld-admin-recent-v5">
            <div class="ld-admin-panel-heading compact">
                <div>
                    <span class="ld-admin-panel-kicker">ALUNOS</span>
                    <h2>Cadastros recentes</h2>
                </div>
                <a class="ld-admin-link" href="<?= e(url('/admin/usuarios')) ?>">Ver todos</a>
            </div>

            <div class="ld-admin-rail-v5-list">
                <?php if (empty($recentStudents)): ?>
                    <div class="ld-admin-empty">Nenhum aluno cadastrado.</div>
                <?php endif; ?>

                <?php foreach (($recentStudents ?? []) as $student): ?>
                    <a class="ld-admin-recent-student-v5" href="<?= e(url('/admin/usuarios/' . (int) $student['id'])) ?>">
                        <span class="ld-admin-recent-student-v5-avatar"><?= e(mb_strtoupper(mb_substr((string) $student['name'], 0, 1))) ?></span>
                        <span class="ld-admin-recent-student-v5-copy">
                            <strong><?= e($student['name']) ?></strong>
                            <small><?= e($student['email']) ?></small>
                        </span>
                        <em class="ld-admin-status is-<?= e($student['status']) ?>"><?= e($student['status']) ?></em>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="ld-admin-recent-v5-meter" aria-hidden="true">
                <span></span><span></span><span></span><span></span><span></span><span></span><span></span><span></span>
            </div>
        </section>

        <section class="ld-admin-panel ld-admin-rail-v5 ld-admin-activity-v5">
            <div class="ld-admin-panel-heading compact">
                <div>
                    <span class="ld-admin-panel-kicker">AUDITORIA</span>
                    <h2>Atividade recente</h2>
                </div>
                <span class="ld-admin-activity-v5-badge">tempo real</span>
            </div>

            <div class="ld-admin-activity-v6-chart">
                <div class="ld-admin-activity-v6-summary">
                    <div><strong><?= $activityTotal ?></strong><span>eventos nos últimos 7 dias</span></div>
                    <small>atividade administrativa registrada</small>
                </div>

                <div class="ld-admin-activity-v6-canvas" role="img" aria-label="Gráfico de eventos administrativos dos últimos sete dias">
                    <svg viewBox="0 0 680 170" preserveAspectRatio="none" aria-hidden="true">
                        <defs>
                            <linearGradient id="ldActivityArea" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="#5eead4" stop-opacity=".28" />
                                <stop offset="100%" stop-color="#6366f1" stop-opacity="0" />
                            </linearGradient>
                            <linearGradient id="ldActivityLine" x1="0" y1="0" x2="1" y2="0">
                                <stop offset="0%" stop-color="#38bdf8" />
                                <stop offset="55%" stop-color="#5eead4" />
                                <stop offset="100%" stop-color="#818cf8" />
                            </linearGradient>
                        </defs>
                        <g class="ld-admin-chart-grid">
                            <line x1="28" y1="46" x2="652" y2="46" />
                            <line x1="28" y1="92" x2="652" y2="92" />
                            <line x1="28" y1="138" x2="652" y2="138" />
                        </g>
                        <polygon class="ld-admin-chart-area" points="<?= e(implode(' ', $activityAreaPoints)) ?>" />
                        <polyline class="ld-admin-chart-line" points="<?= e(implode(' ', $activityPoints)) ?>" />
                        <?php foreach ($activityPoints as $index => $point): ?>
                            <?php [$cx, $cy] = explode(',', $point); ?>
                            <circle class="ld-admin-chart-point" cx="<?= e($cx) ?>" cy="<?= e($cy) ?>" r="4.5" />
                        <?php endforeach; ?>
                    </svg>
                </div>

                <div class="ld-admin-activity-v6-labels" aria-hidden="true">
                    <?php foreach ($activityLabels as $label): ?><span><?= e($label) ?></span><?php endforeach; ?>
                </div>
            </div>

            <div class="ld-admin-rail-v5-list ld-admin-activity-v5-list">
                <?php if (empty($recentAudit)): ?>
                    <div class="ld-admin-empty">Nenhuma atividade administrativa recente.</div>
                <?php endif; ?>

                <?php foreach (($recentAudit ?? []) as $event): ?>
                    <div class="ld-admin-activity-v5-item">
                        <span></span>
                        <div>
                            <strong><?= e($event['description'] ?: $event['action']) ?></strong>
                            <small><?= e($event['actor_name'] ?? 'Sistema') ?> • <?= e(date('d/m/Y H:i', strtotime($event['created_at']))) ?></small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </aside>
</section>
