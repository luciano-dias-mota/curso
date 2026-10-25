<?php

use App\Core\Csrf;
use App\Core\Session;
use App\Services\PedagogyService;

$error = Session::pullFlash('error');
$success = Session::pullFlash('success');
$completionMode = (string) ($completionMode ?? 'quiz');
$isPostgresLesson = (string) Session::get('active_course_experience', 'generic') === 'postgresql';
$courseId = (int) ($lesson['course_id'] ?? 0);

$lessonBasePath = $isPostgresLesson && $courseId > 0
    ? '/curso/' . $courseId . '/aula/' . (int) $lesson['id']
    : '/aula/' . (int) $lesson['id'];

$phasePath = $isPostgresLesson && $courseId > 0
    ? '/curso/' . $courseId . '/fase/' . (int) $lesson['phase_id']
    : '/fase/' . (int) $lesson['phase_id'];

$optional = (int) ($lesson['is_required'] ?? 1) === 0
    || (int) ($lesson['phase_required'] ?? 1) === 0;

$types = [
    'theory' => ['TEORIA', 'T', 'theory'],
    'example' => ['EXEMPLO', 'E', 'example'],
    'warning' => ['ATENÇÃO', '!', 'warning'],
    'summary' => ['RESUMO', 'R', 'summary'],
    'comparison' => ['COMPARAÇÃO', '↔', 'comparison'],
    'memory' => ['MEMORIZE', 'M', 'memory'],
    'case_study' => ['CASO PRÁTICO', 'C', 'case'],
    'image' => ['IMAGEM', 'I', 'media'],
    'video' => ['VÍDEO', 'V', 'media'],
    'question' => ['QUESTÃO', '?', 'question'],
];

$pedagogy = new PedagogyService();

$rawContent = (string) ($currentBlock['content'] ?? '');
$displayContent = $pedagogy->cleanDisplayText($rawContent);

$declaredType = (string) ($currentBlock['block_type'] ?? 'theory');

$looksLikeQuestion = $declaredType !== 'video'
    && $pedagogy->looksLikeMultipleChoice(
        (string) ($currentBlock['title'] ?? ''),
        $displayContent
    );

$currentType = $looksLikeQuestion
    ? 'question'
    : $declaredType;

$meta = $types[$currentType] ?? $types['theory'];

$visited = (int) ($progress['blocks_viewed'] ?? 0);
$pct = $totalBlocks > 0
    ? min(100, (int) round(($currentIndex / $totalBlocks) * 100))
    : 0;

$analogy = $currentType === 'video'
    ? null
    : $pedagogy->analogyFor(
        (string) ($currentBlock['title'] ?? ''),
        $displayContent
    );

$studyPrompt = $currentType === 'video'
    ? [
        'label' => 'Fixação ativa',
        'text' => 'Assista com atenção e, ao final, explique com suas próprias palavras os pontos principais antes de avançar.',
    ]
    : $pedagogy->studyPromptFor(
        $currentType,
        $looksLikeQuestion
    );

$renderNormalContent = static function (string $text): string {
    if ($text === '') {
        return '<p class="content-p muted">Conteúdo não preenchido.</p>';
    }

    $parts = preg_split('/\R\R+/', $text) ?: [];
    $html = '';

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }

        $lines = array_values(
            array_filter(
                array_map('trim', preg_split('/\R/', $part) ?: []),
                static fn ($x) => $x !== ''
            )
        );

        // Tabela importada com separador |
        $isTable = count($lines) >= 2
            && count(
                array_filter(
                    $lines,
                    static fn ($x) => substr_count($x, ' | ') >= 1
                )
            ) === count($lines);

        if ($isTable) {
            $rows = array_map(
                static fn ($x) => array_map('trim', explode(' | ', $x)),
                $lines
            );

            $counts = array_unique(array_map('count', $rows));

            if (count($counts) === 1) {
                $html .= '<div class="table-wrap"><table class="content-table">';

                foreach ($rows as $ri => $row) {
                    $html .= '<tr>';

                    foreach ($row as $cell) {
                        $tag = $ri === 0 ? 'th' : 'td';
                        $html .= '<' . $tag . '>' . e($cell) . '</' . $tag . '>';
                    }

                    $html .= '</tr>';
                }

                $html .= '</table></div>';
                continue;
            }
        }

        $bullets = count($lines) > 1;
        $ordered = count($lines) > 1;

        foreach ($lines as $line) {
            if (!preg_match('/^(?:•|\-|\*)\s+/u', $line)) {
                $bullets = false;
            }

            if (!preg_match('/^\d+[\.\)]\s+/u', $line)) {
                $ordered = false;
            }
        }

        if ($bullets || $ordered) {
            $tag = $ordered ? 'ol' : 'ul';
            $html .= '<' . $tag . ' class="content-list">';

            foreach ($lines as $line) {
                $line = preg_replace(
                    $ordered
                        ? '/^\d+[\.\)]\s+/u'
                        : '/^(?:•|\-|\*)\s+/u',
                    '',
                    $line
                );

                $html .= '<li>' . e((string) $line) . '</li>';
            }

            $html .= '</' . $tag . '>';
            continue;
        }

        if (
            preg_match('/^([^:\n]{2,55}):\s*(.+)$/us', $part, $m)
            && !str_contains($m[1], '.')
        ) {
            $html .=
                '<p class="content-p"><strong>'
                . e(trim($m[1]))
                . ':</strong> '
                . nl2br(e(trim($m[2])))
                . '</p>';
        } else {
            $html .= '<p class="content-p">' . nl2br(e($part)) . '</p>';
        }
    }

    return $html;
};

$renderQuestionPreview = static function (string $text): string {
    $text = trim($text);

    if ($text === '') {
        return '<p class="content-p muted">Questão sem conteúdo.</p>';
    }

    $firstAltPos = null;

    if (preg_match('/(?:^|\s)A\)\s+/u', $text, $m, PREG_OFFSET_CAPTURE)) {
        $firstAltPos = (int) $m[0][1];
    }

    if ($firstAltPos === null) {
        return '<p class="content-p">' . nl2br(e($text)) . '</p>';
    }

    $statement = trim(substr($text, 0, $firstAltPos));
    $alternativesText = substr($text, $firstAltPos);

    preg_match_all(
        '/(?:^|\s)([A-E])\)\s*(.*?)(?=(?:\s+[A-E]\)\s)|$)/su',
        $alternativesText,
        $matches,
        PREG_SET_ORDER
    );

    $html = '';

    if ($statement !== '') {
        $html .= '<p class="question-preview-statement">' . nl2br(e($statement)) . '</p>';
    }

    if ($matches) {
        $html .= '<div class="question-preview-options">';

        foreach ($matches as $match) {
            $html .=
                '<div class="question-preview-option">'
                . '<span>' . e($match[1]) . '</span>'
                . '<p>' . nl2br(e(trim($match[2]))) . '</p>'
                . '</div>';
        }

        $html .= '</div>';
    }

    return $html;
};


$renderPostgresLessonContent = static function (string $text) use ($renderNormalContent): string {
    // Renderização pedagógica de comandos SQL; demais conteúdos usam o renderer original escapado.
    $lines = preg_split('/\\R/', $text) ?: [];
    $html = '';
    $buffer = [];
    $flush = static function () use (&$buffer, &$html, $renderNormalContent): void {
        if ($buffer) {
            $html .= $renderNormalContent(implode("\\n", $buffer));
            $buffer = [];
        }
    };
    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim !== '' && preg_match('/^(?:SELECT|WHERE|FROM|ORDER\\s+BY|GROUP\\s+BY|HAVING|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|WITH|JOIN|AND|OR|NOT)\\b/i', $trim)) {
            $flush();
            $html .= '<div class="pg-sql-demo"><div class="pg-sql-demo-label">SQL · exemplo de consulta</div><pre><code>'
                . e($trim) . '</code></pre></div>';
        } else {
            $buffer[] = $line;
        }
    }
    $flush();
    return $html;
};

$prevUrl = $currentIndex > 1
    ? url($lessonBasePath . '?bloco=' . ($currentIndex - 1))
    : null;

$nextUrl = $currentIndex < $totalBlocks
    ? url($lessonBasePath . '?bloco=' . ($currentIndex + 1))
    : null;
?>

<?php if ($isPostgresLesson): ?>
<div class="pg-lesson-hero">
    <div class="pg-lesson-hero-row">
        <a href="<?= e(url($phasePath)) ?>">← Voltar à fase</a>
        <button type="button" data-focus-toggle aria-pressed="false">◉ Modo foco</button>
    </div>
    <span class="pg-lesson-kicker"><?= e($lesson['module_title']) ?></span>
    <h1><?= e($lesson['title']) ?></h1>
    <p><?= e($lesson['phase_title']) ?></p>
    <div class="pg-lesson-chips"><span>Tela <?= $currentIndex ?> de <?= $totalBlocks ?></span><span>~<?= (int) $lesson['estimated_minutes'] ?> min</span><span>+<?= (int) $lesson['xp_reward'] ?> XP</span></div>
    <div class="pg-lesson-track"><span style="width:<?= $pct ?>%"></span></div>
    <small>Tela <?= $currentIndex ?> de <?= $totalBlocks ?> · <?= $pct ?>% concluído</small>
</div>
<?php endif; ?>
<section class="study-room <?= $isPostgresLesson ? 'study-room--postgres-didactic' : '' ?>">

    <aside class="study-map">
        <?php if ($isPostgresLesson): ?><div class="pg-map-heading">Mapa da aula</div><?php endif; ?>
        <div class="study-map-head" <?= $isPostgresLesson ? 'hidden' : '' ?>>
            <a class="back study-back" href="<?= e(url($phasePath)) ?>">
                ← Voltar à fase
            </a>

            <span class="eyebrow">
                <?= $optional ? 'BIBLIOTECA' : 'MISSÃO ATUAL' ?>
            </span>

            <h2><?= e($lesson['title']) ?></h2>
            <p><?= e($lesson['phase_title']) ?></p>
        </div>

        <div class="ring" style="--p: <?= $pct ?>">
            <div>
                <strong><?= $pct ?>%</strong>
                <small><?= $optional ? 'lido' : 'progresso' ?></small>
            </div>
        </div>

        <nav class="map-list">
            <?php foreach ($blocks as $idx => $block): ?>
                <?php
                $number = $idx + 1;

                $blockContent = $pedagogy->cleanDisplayText(
                    (string) ($block['content'] ?? '')
                );

                $blockDeclaredType = (string) ($block['block_type'] ?? 'theory');

                $blockLooksQuestion = $blockDeclaredType !== 'video'
                    && $pedagogy->looksLikeMultipleChoice(
                        (string) ($block['title'] ?? ''),
                        $blockContent
                    );

                $blockType = $blockLooksQuestion
                    ? 'question'
                    : $blockDeclaredType;

                $blockMeta = $types[$blockType] ?? $types['theory'];
                ?>

                <a
                    class="map-item <?= $number === $currentIndex ? 'active' : '' ?>"
                    href="<?= e(url($lessonBasePath . '?bloco=' . $number)) ?>"
                >
                    <span><?= $number ?></span>

                    <div>
                        <strong><?= e($block['title'] ?: 'Tela ' . $number) ?></strong>
                        <small><?= e($blockMeta[0]) ?></small>
                    </div>
                </a>
            <?php endforeach; ?>
        </nav>
    </aside>

    <section class="study-stage">

        <header class="stage-top" <?= $isPostgresLesson ? 'hidden' : '' ?>>
            <div>
                <span class="eyebrow"><?= e($lesson['module_title']) ?></span>
                <h1><?= e($lesson['phase_title']) ?></h1>
            </div>

            <div class="stage-meta">
                <span><?= $currentIndex ?>/<?= $totalBlocks ?></span>
                <span>~<?= (int) $lesson['estimated_minutes'] ?> min</span>

                <?php if (!$optional): ?>
                    <span>+<?= (int) $lesson['xp_reward'] ?> XP</span>
                <?php endif; ?>
            </div>

            <button class="focus-toggle secondary" type="button" data-focus-toggle aria-pressed="false" title="Ocultar elementos secundários">
                <span class="focus-toggle-icon">◎</span>
                <span data-focus-label>Modo foco</span>
            </button>
        </header>

        <!-- Navegação principal fica no topo: nunca fica escondida abaixo da dobra. -->
        <nav class="study-nav-top" <?= $isPostgresLesson ? 'hidden' : '' ?> aria-label="Navegação da aula">
            <div class="study-nav-side">
                <?php if ($prevUrl): ?>
                    <a class="secondary" data-prev href="<?= e($prevUrl) ?>">
                        ← Anterior
                    </a>
                <?php else: ?>
                    <span class="nav-placeholder"></span>
                <?php endif; ?>
            </div>

            <div class="study-nav-progress">
                <div class="progress">
                    <span style="width: <?= $pct ?>%"></span>
                </div>

                <small>
                    Tela <?= $currentIndex ?> de <?= $totalBlocks ?>
                    • <?= $pct ?>%
                </small>
            </div>

            <div class="study-nav-side study-nav-side--right">
                <?php if ($nextUrl): ?>
                    <a class="primary" data-next href="<?= e($nextUrl) ?>">
                        Próximo →
                    </a>
                <?php else: ?>
                    <form
                        class="inline nav-finish-form"
                        action="<?= e(url($lessonBasePath . '/concluir')) ?>"
                        method="post"
                    >
                        <?= Csrf::input() ?>

                        <button class="success" type="submit">
                            <?php if ($optional): ?>
                                Concluir leitura →
                            <?php elseif ($completionMode === 'lab'): ?>
                                Abrir laboratório SQL →
                            <?php elseif ($completionMode === 'reading'): ?>
                                Concluir aula →
                            <?php else: ?>
                                Ir para exercícios →
                            <?php endif; ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </nav>

        <details class="mobile-outline">
            <summary>
                Mapa da aula • <?= $currentIndex ?>/<?= $totalBlocks ?>
            </summary>

            <div class="mobile-outline-list">
                <?php foreach ($blocks as $idx => $block): ?>
                    <?php $number = $idx + 1; ?>

                    <a
                        class="<?= $number === $currentIndex ? 'active' : '' ?>"
                        href="<?= e(url($lessonBasePath . '?bloco=' . $number)) ?>"
                    >
                        <span><?= $number ?></span>
                        <?= e($block['title'] ?: 'Tela ' . $number) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </details>

        <?php if ($success): ?>
            <div class="alert success"><?= e($success) ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error"><?= e($error) ?></div>
        <?php endif; ?>

        <article class="study-card type-<?= e($meta[2]) ?>">
            <header>
                <span class="symbol"><?= e($meta[1]) ?></span>

                <div>
                    <span class="eyebrow"><?= e($meta[0]) ?></span>
                    <h2><?= e($currentBlock['title'] ?? 'Conteúdo') ?></h2>
                </div>
            </header>

            <div class="study-content">

                <?php if ($currentType === 'video'): ?>
                    <?php
                    $videoSource = !empty($currentBlock['media_id'])
                        ? url('/media/video/' . (int) $currentBlock['media_id'])
                        : trim((string) ($currentBlock['media_url'] ?? ''));
                    ?>

                    <?php if ($videoSource !== ''): ?>
                        <div class="lesson-video-shell">
                            <video
                                class="lesson-video-player"
                                controls
                                preload="metadata"
                                playsinline
                                src="<?= e($videoSource) ?>"
                            >
                                Seu navegador não suporta reprodução de vídeo.
                            </video>
                        </div>

                        <?php if ($displayContent !== ''): ?>
                            <div class="lesson-video-note">
                                <?= $renderNormalContent($displayContent) ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="alert error">
                            O vídeo deste bloco não está mais disponível.
                        </div>
                    <?php endif; ?>

                <?php elseif ($looksLikeQuestion): ?>
                    <div class="question-preview-note">
                        <strong>Questão do material de estudo</strong>
                        <span>
                            Resolva mentalmente aqui. A correção que libera seu avanço
                            acontece no exercício obrigatório ao final da aula.
                        </span>
                    </div>

                    <?= $renderQuestionPreview($displayContent) ?>
                <?php else: ?>
                    <?php if ($isPostgresLesson): ?>
                        <?= $renderPostgresLessonContent($displayContent) ?>
                    <?php else: ?>
                        <?= $renderNormalContent($displayContent) ?>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($analogy): ?>
                    <aside class="pedagogy-card analogy-card">
                        <div class="pedagogy-icon">↔</div>

                        <div>
                            <span class="pedagogy-label">ANALOGIA DIDÁTICA</span>
                            <h3><?= e($analogy['title']) ?></h3>
                            <p><?= e($analogy['text']) ?></p>

                            <small>
                                Recurso de memorização. Use a analogia para compreender o conceito;
                                na prova, aplique a regra explicada na aula.
                            </small>
                        </div>
                    </aside>
                <?php endif; ?>

                <?php if (!$isPostgresLesson): ?>
                <aside class="pedagogy-card study-tip-card">
                    <div class="pedagogy-icon">✓</div>

                    <div>
                        <span class="pedagogy-label">
                            <?= e(mb_strtoupper($studyPrompt['label'])) ?>
                        </span>

                        <p><?= e($studyPrompt['text']) ?></p>
                    </div>
                </aside>

                <?php endif; ?>

                <?php if ($currentIndex >= $totalBlocks && !$optional): ?>
                    <aside class="lesson-exercise-gate">
                        <div class="gate-icon">✓</div>

                        <div>
                            <?php if ($completionMode === 'lab'): ?>
                                <strong>Leitura concluída — agora pratique SQL</strong>
                                <p>
                                    Abra o laboratório, execute sua solução no PostgreSQL do navegador
                                    e conclua a atividade prática para liberar a próxima aula.
                                </p>
                            <?php elseif ($completionMode === 'reading'): ?>
                                <strong>Leitura concluída</strong>
                                <p>Finalize a aula para registrar seu progresso e seguir na trilha.</p>
                            <?php else: ?>
                                <strong>Leitura concluída — agora vem a fixação</strong>
                                <p>
                                    O exercício da aula é corrigido pelo sistema.
                                    A próxima aula só é liberada após atingir a nota mínima.
                                </p>
                            <?php endif; ?>
                        </div>
                    </aside>
                <?php endif; ?>

            </div>
        </article>

        <?php if ($isPostgresLesson): ?>
            <section class="pg-reading-reflection" aria-labelledby="pg-reflection-title">
                <div class="pg-reading-reflection-head">
                    <span aria-hidden="true">✎</span>
                    <div>
                        <h3 id="pg-reflection-title">Leitura ativa</h3>
                        <p>Explique com suas palavras o conceito principal desta tela e onde você usaria isso em um banco de dados.</p>
                    </div>
                </div>
                <label for="pg-reading-notes">Seu resumo pessoal</label>
                <textarea id="pg-reading-notes" rows="3" placeholder="Ex.: Usaria SELECT para consultar os dados de uma tabela..." data-lesson-note="<?= (int) $lesson['id'] ?>:<?= (int) $currentIndex ?>"></textarea>
                <small>Rascunho local neste navegador. Não substitui o laboratório obrigatório.</small>
            </section>

            <nav class="pg-lesson-footer-nav" aria-label="Navegação ao final do conteúdo">
                <?php if ($prevUrl): ?>
                    <a class="secondary" href="<?= e($prevUrl) ?>">← Anterior</a>
                <?php else: ?>
                    <span></span>
                <?php endif; ?>
                <small>Tela <?= $currentIndex ?> de <?= $totalBlocks ?></small>
                <?php if ($nextUrl): ?>
                    <a class="primary" href="<?= e($nextUrl) ?>">Próximo →</a>
                <?php else: ?>
                    <form action="<?= e(url($lessonBasePath . '/concluir')) ?>" method="post">
                        <?= Csrf::input() ?>
                        <button type="submit" class="success"><?= $completionMode === 'lab' ? 'Abrir laboratório SQL →' : 'Concluir etapa →' ?></button>
                    </form>
                <?php endif; ?>
            </nav>

            <script>
            (() => {
                const notes = document.querySelectorAll('[data-lesson-note]');
                for (const area of notes) {
                    const key = 'ldacademy:pgsql:reading:' + area.dataset.lessonNote;
                    try { area.value = localStorage.getItem(key) || ''; } catch (_) {}
                    area.addEventListener('input', () => {
                        try { localStorage.setItem(key, area.value); } catch (_) {}
                    });
                }
            })();
            </script>
        <?php endif; ?>

    </section>

</section>
