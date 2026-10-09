<?php
$students = (int) ($stats['students'] ?? 0);
$activeStudents = (int) ($stats['active_students'] ?? 0);
$inactiveStudents = (int) ($stats['inactive_students'] ?? 0);
$blockedStudents = (int) ($stats['blocked_students'] ?? 0);
$courses = (int) ($stats['courses'] ?? 0);
$modules = (int) ($stats['modules'] ?? 0);
$lessons = (int) ($stats['lessons'] ?? 0);
$questions = (int) ($stats['questions'] ?? 0);
$assessments = (int) ($stats['assessments'] ?? 0);
$quizzes = (int) ($stats['quizzes'] ?? 0);
$simulations = (int) ($stats['simulations'] ?? 0);
$exercises = (int) ($stats['exercises'] ?? 0);

$totalStatus = max(1, $activeStudents + $inactiveStudents + $blockedStudents);
$activePct = round(($activeStudents / $totalStatus) * 100, 2);
$inactivePct = round(($inactiveStudents / $totalStatus) * 100, 2);
$blockedPct = max(0, 100 - $activePct - $inactivePct);
$inactiveEnd = min(100, $activePct + $inactivePct);

$activityLabels = $activity['labels'] ?? [];
$activitySeries = [
    'lessons' => $activity['lessons'] ?? [],
    'exercises' => $activity['exercises'] ?? [],
    'quizzes' => $activity['quizzes'] ?? [],
    'simulations' => $activity['simulations'] ?? [],
];
$activityAll = [];
foreach ($activitySeries as $values) {
    foreach ($values as $value) {
        $activityAll[] = (int) $value;
    }
}
$activityMax = max(1, ...($activityAll ?: [1]));
$activityTotal = array_sum($activitySeries['lessons'])
    + array_sum($activitySeries['exercises'])
    + array_sum($activitySeries['quizzes'])
    + array_sum($activitySeries['simulations']);

$performanceLabels = $performance['labels'] ?? [];
$performanceSeries = [
    'quizzes' => $performance['quizzes'] ?? [],
    'exercises' => $performance['exercises'] ?? [],
    'simulations' => $performance['simulations'] ?? [],
];

$chartPoints = static function (array $values, int $width = 720, int $left = 52, int $right = 18): string {
    $count = count($values);
    if ($count === 0) {
        return '';
    }

    $usable = $width - $left - $right;
    $step = $count > 1 ? $usable / ($count - 1) : 0;
    $points = [];

    foreach ($values as $index => $value) {
        if ($value === null) {
            continue;
        }
        $x = $left + ($step * $index);
        $score = max(0, min(100, (float) $value));
        $y = 182 - (($score / 100) * 140);
        $points[] = round($x, 2) . ',' . round($y, 2);
    }

    return implode(' ', $points);
};

$chartCircles = static function (array $values, int $width = 720, int $left = 52, int $right = 18): array {
    $count = count($values);
    if ($count === 0) {
        return [];
    }

    $usable = $width - $left - $right;
    $step = $count > 1 ? $usable / ($count - 1) : 0;
    $circles = [];

    foreach ($values as $index => $value) {
        if ($value === null) {
            continue;
        }
        $score = max(0, min(100, (float) $value));
        $circles[] = [
            'x' => round($left + ($step * $index), 2),
            'y' => round(182 - (($score / 100) * 140), 2),
            'value' => $score,
        ];
    }

    return $circles;
};
?>
<section class="ld-admin-management-v6">
    <header class="ld-admin-management-v6-head">
        <div>
            <span class="ld-admin-kicker">CENTRAL DE GESTÃO</span>
            <h1>Gestão da plataforma</h1>
            <p>Acompanhe uso, desempenho e pontos de atenção em uma visão única.</p>
        </div>

        <div class="ld-admin-management-v6-actions">
            <a class="ld-admin-management-action is-primary" href="<?= e(url('/admin/usuarios/novo')) ?>"><span>＋</span>Novo aluno</a>
            <a class="ld-admin-management-action" href="<?= e(url('/admin/questoes/nova')) ?>"><span>＋</span>Nova questão</a>
            <a class="ld-admin-management-action" href="<?= e(url('/admin/usuarios')) ?>"><span>◇</span>Gerenciar alunos</a>
            <a class="ld-admin-management-action is-report" href="<?= e(url('/admin/relatorios')) ?>"><span>↗</span>Relatórios</a>
        </div>
    </header>

    <div class="ld-admin-management-v6-layout">
        <main class="ld-admin-management-v6-center">
            <section class="ld-admin-management-v6-kpis">
                <a class="ld-admin-management-kpi is-students" href="<?= e(url('/admin/usuarios')) ?>">
                    <div class="ld-admin-management-kpi-head"><span>Alunos</span><i>＋</i></div>
                    <strong><?= $students ?></strong>
                    <small><?= $activeStudents ?> ativos • <?= $inactiveStudents + $blockedStudents ?> sem acesso normal</small>
                    <span class="ld-admin-management-kpi-spark"><b></b><b></b><b></b><b></b><b></b><b></b></span>
                </a>

                <a class="ld-admin-management-kpi is-courses" href="<?= e(url('/admin/conteudo')) ?>">
                    <div class="ld-admin-management-kpi-head"><span>Cursos</span><i>→</i></div>
                    <strong><?= $courses ?></strong>
                    <small><?= $modules ?> módulos • <?= $lessons ?> aulas</small>
                    <span class="ld-admin-management-kpi-spark"><b></b><b></b><b></b><b></b><b></b><b></b></span>
                </a>

                <a class="ld-admin-management-kpi is-questions" href="<?= e(url('/admin/questoes')) ?>">
                    <div class="ld-admin-management-kpi-head"><span>Questões</span><i>→</i></div>
                    <strong><?= $questions ?></strong>
                    <small><?= (int) ($stats['questions_medium'] ?? 0) ?> médias • <?= (int) ($stats['questions_hard'] ?? 0) ?> difíceis</small>
                    <span class="ld-admin-management-kpi-spark"><b></b><b></b><b></b><b></b><b></b><b></b></span>
                </a>

                <a class="ld-admin-management-kpi is-assessments" href="<?= e(url('/admin/simulados')) ?>">
                    <div class="ld-admin-management-kpi-head"><span>Avaliações</span><i>✓</i></div>
                    <strong><?= $assessments ?></strong>
                    <small><?= $exercises ?> exercícios • <?= $quizzes ?> quizzes • <?= $simulations ?> simulados</small>
                    <span class="ld-admin-management-kpi-spark"><b></b><b></b><b></b><b></b><b></b><b></b></span>
                </a>
            </section>

            <section class="ld-admin-management-v6-activity ld-admin-panel">
                <div class="ld-admin-management-panel-head">
                    <div>
                        <span class="ld-admin-panel-kicker">VISÃO OPERACIONAL</span>
                        <h2>Atividade da plataforma</h2>
                    </div>
                    <nav class="ld-admin-management-period" aria-label="Período do dashboard">
                        <?php foreach ([7, 30, 90] as $days): ?>
                            <a class="<?= $period === $days ? 'is-active' : '' ?>" href="<?= e(url('/admin?period=' . $days)) ?>"><?= $days ?> dias</a>
                        <?php endforeach; ?>
                    </nav>
                </div>

                <div class="ld-admin-management-activity-grid">
                    <div class="ld-admin-management-donut-column">
                        <div class="ld-admin-management-donut" style="--ld-active:<?= $activePct ?>%;--ld-inactive:<?= $inactiveEnd ?>%;">
                            <div><strong><?= $students ?></strong><small>alunos</small></div>
                        </div>
                        <div class="ld-admin-management-donut-legend">
                            <span><i class="is-active"></i><b>Ativos</b><em><?= $activeStudents ?></em></span>
                            <span><i class="is-inactive"></i><b>Inativos</b><em><?= $inactiveStudents ?></em></span>
                            <span><i class="is-blocked"></i><b>Bloqueados</b><em><?= $blockedStudents ?></em></span>
                        </div>
                    </div>

                    <div class="ld-admin-management-bars-column">
                        <div class="ld-admin-management-bars-summary">
                            <strong><?= $activityTotal ?></strong>
                            <span>ações de aprendizagem no período</span>
                        </div>
                        <div class="ld-admin-management-bars-legend">
                            <span class="is-lessons">Aulas</span>
                            <span class="is-exercises">Exercícios</span>
                            <span class="is-quizzes">Quizzes</span>
                            <span class="is-simulations">Simulados</span>
                        </div>
                        <div class="ld-admin-management-bars" role="img" aria-label="Atividade da plataforma por período">
                            <?php foreach ($activityLabels as $index => $label): ?>
                                <div class="ld-admin-management-bar-group">
                                    <div class="ld-admin-management-bar-stack">
                                        <?php
                                        $barData = [
                                            ['key' => 'lessons', 'class' => 'is-lessons'],
                                            ['key' => 'exercises', 'class' => 'is-exercises'],
                                            ['key' => 'quizzes', 'class' => 'is-quizzes'],
                                            ['key' => 'simulations', 'class' => 'is-simulations'],
                                        ];
                                        foreach ($barData as $bar):
                                            $value = (int) ($activitySeries[$bar['key']][$index] ?? 0);
                                            $height = $value > 0 ? max(7, round(($value / $activityMax) * 100, 1)) : 2;
                                        ?>
                                            <span class="<?= e($bar['class']) ?>" style="height:<?= e((string) $height) ?>%" title="<?= e($bar['key']) ?>: <?= $value ?>"></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <small><?= e($label) ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="ld-admin-management-v6-performance ld-admin-panel">
                <div class="ld-admin-management-performance-main">
                    <div class="ld-admin-management-panel-head compact">
                        <div>
                            <span class="ld-admin-panel-kicker">DESEMPENHO</span>
                            <h2>Evolução das médias</h2>
                        </div>
                        <div class="ld-admin-management-line-legend">
                            <span class="is-quiz">Quiz</span>
                            <span class="is-exercise">Exercícios</span>
                            <span class="is-simulation">Simulados</span>
                        </div>
                    </div>

                    <div class="ld-admin-management-line-chart" role="img" aria-label="Médias de quizzes, exercícios e simulados no período">
                        <svg viewBox="0 0 720 220" preserveAspectRatio="none" aria-hidden="true">
                            <g class="ld-admin-management-line-grid">
                                <line x1="52" y1="42" x2="702" y2="42"></line>
                                <line x1="52" y1="77" x2="702" y2="77"></line>
                                <line x1="52" y1="112" x2="702" y2="112"></line>
                                <line x1="52" y1="147" x2="702" y2="147"></line>
                                <line x1="52" y1="182" x2="702" y2="182"></line>
                            </g>
                            <line class="ld-admin-management-target-line" x1="52" y1="70" x2="702" y2="70"></line>
                            <text class="ld-admin-management-target-label" x="58" y="65">80% meta</text>

                            <?php foreach ([
                                ['key' => 'quizzes', 'class' => 'is-quiz'],
                                ['key' => 'exercises', 'class' => 'is-exercise'],
                                ['key' => 'simulations', 'class' => 'is-simulation'],
                            ] as $line): ?>
                                <?php $points = $chartPoints($performanceSeries[$line['key']]); ?>
                                <?php if ($points !== ''): ?>
                                    <polyline class="ld-admin-management-line <?= e($line['class']) ?>" points="<?= e($points) ?>"></polyline>
                                    <?php foreach ($chartCircles($performanceSeries[$line['key']]) as $point): ?>
                                        <circle class="ld-admin-management-line-point <?= e($line['class']) ?>" cx="<?= e((string) $point['x']) ?>" cy="<?= e((string) $point['y']) ?>" r="3.8"></circle>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </svg>
                        <div class="ld-admin-management-line-y" aria-hidden="true"><span>100%</span><span>75%</span><span>50%</span><span>25%</span><span>0%</span></div>
                        <div class="ld-admin-management-line-x" aria-hidden="true">
                            <?php foreach ($performanceLabels as $label): ?><span><?= e($label) ?></span><?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <aside class="ld-admin-management-difficulty">
                    <div class="ld-admin-management-panel-head compact">
                        <div><span class="ld-admin-panel-kicker">ATENÇÃO PEDAGÓGICA</span><h2>Maior dificuldade</h2></div>
                    </div>
                    <div class="ld-admin-management-difficulty-list">
                        <?php if (empty($contentDifficulty)): ?>
                            <div class="ld-admin-empty">Ainda não há respostas suficientes no período.</div>
                        <?php endif; ?>
                        <?php foreach (($contentDifficulty ?? []) as $content): ?>
                            <?php $accuracy = max(0, min(100, (float) ($content['accuracy'] ?? 0))); ?>
                            <div class="ld-admin-management-difficulty-item">
                                <div><strong><?= e($content['title']) ?></strong><small><?= (int) $content['answer_count'] ?> respostas analisadas</small></div>
                                <b><?= number_format($accuracy, 0, ',', '.') ?>%</b>
                                <span><i style="width:<?= e((string) $accuracy) ?>%"></i></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </section>
        </main>

        <aside class="ld-admin-management-v6-rail">
            <section class="ld-admin-management-access ld-admin-panel">
                <div class="ld-admin-management-rail-title"><span>01</span><strong>Acessos administrativos</strong><i>⌄</i></div>
                <nav>
                    <a href="<?= e(url('/admin/conteudo')) ?>"><span>01</span><strong>📚 Cursos e conteúdo</strong><i>›</i></a>
                    <a href="<?= e(url('/admin/aulas')) ?>"><span>02</span><strong>🎓 Gerenciar aulas</strong><i>›</i></a>
                    <a href="<?= e(url('/admin/questoes')) ?>"><span>03</span><strong>🧠 Banco de questões</strong><i>›</i></a>
                    <a href="<?= e(url('/admin/simulados')) ?>"><span>04</span><strong>🎯 Simulados</strong><i>›</i></a>
                    <a href="<?= e(url('/admin/usuarios')) ?>"><span>05</span><strong>👥 Gestão de alunos</strong><i>›</i></a>
                    <a href="<?= e(url('/admin/relatorios')) ?>"><span>06</span><strong>📊 Relatórios</strong><i>›</i></a>
                    <a href="<?= e(url('/admin/videos')) ?>"><span>07</span><strong>🎬 Biblioteca de vídeos</strong><i>›</i></a>
                </nav>
            </section>

            <section class="ld-admin-management-recent ld-admin-panel">
                <div class="ld-admin-management-panel-head compact">
                    <div><span class="ld-admin-panel-kicker">ALUNOS</span><h2>Cadastros recentes</h2></div>
                    <a class="ld-admin-link" href="<?= e(url('/admin/usuarios')) ?>">Ver todos</a>
                </div>
                <div class="ld-admin-management-recent-list">
                    <?php if (empty($recentStudents)): ?><div class="ld-admin-empty">Nenhum cadastro recente.</div><?php endif; ?>
                    <?php foreach (($recentStudents ?? []) as $student): ?>
                        <a href="<?= e(url('/admin/usuarios/' . (int) $student['id'])) ?>">
                            <span class="ld-admin-management-avatar"><?= e(mb_strtoupper(mb_substr((string) $student['name'], 0, 1))) ?></span>
                            <span><strong><?= e($student['name']) ?></strong><small><?= e($student['email']) ?></small></span>
                            <em class="ld-admin-status is-<?= e($student['status']) ?>"><?= e($student['status']) ?></em>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="ld-admin-management-attention ld-admin-panel">
                <div class="ld-admin-management-panel-head compact">
                    <div><span class="ld-admin-panel-kicker">ACOMPANHAMENTO</span><h2>Precisam de atenção</h2></div>
                </div>
                <div class="ld-admin-management-attention-list">
                    <?php if (empty($attentionStudents)): ?><div class="ld-admin-management-all-good">✓ Nenhum alerta relevante agora.</div><?php endif; ?>
                    <?php foreach (($attentionStudents ?? []) as $student): ?>
                        <a href="<?= e(url('/admin/usuarios/' . (int) $student['id'])) ?>">
                            <span>!</span>
                            <div><strong><?= e($student['name']) ?></strong><small><?= e($student['reason']) ?></small></div>
                            <i>›</i>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        </aside>
    </div>
</section>
