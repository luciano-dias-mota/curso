<?php

use App\Core\Csrf;
use App\Core\Session;
use App\Services\PedagogyService;

$error = Session::pullFlash('error');
$success = Session::pullFlash('success');

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

$looksLikeQuestion = $pedagogy->looksLikeMultipleChoice(
    (string) ($currentBlock['title'] ?? ''),
    $displayContent
);

$currentType = $looksLikeQuestion
    ? 'question'
    : (string) ($currentBlock['block_type'] ?? 'theory');

$meta = $types[$currentType] ?? $types['theory'];

$visited = (int) ($progress['blocks_viewed'] ?? 0);
$pct = $totalBlocks > 0
    ? min(100, (int) round(($visited / $totalBlocks) * 100))
    : 0;

$analogy = $pedagogy->analogyFor(
    (string) ($currentBlock['title'] ?? ''),
    $displayContent
);

$studyPrompt = $pedagogy->studyPromptFor(
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

$prevUrl = $currentIndex > 1
    ? url('/aula/' . $lesson['id'] . '?bloco=' . ($currentIndex - 1))
    : null;

$nextUrl = $currentIndex < $totalBlocks
    ? url('/aula/' . $lesson['id'] . '?bloco=' . ($currentIndex + 1))
    : null;
?>

<section class="study-room">

    <aside class="study-map">
        <div class="study-map-head">
            <a class="back study-back" href="<?= e(url('/fase/' . $lesson['phase_id'])) ?>">
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

                $blockLooksQuestion = $pedagogy->looksLikeMultipleChoice(
                    (string) ($block['title'] ?? ''),
                    $blockContent
                );

                $blockType = $blockLooksQuestion
                    ? 'question'
                    : (string) ($block['block_type'] ?? 'theory');

                $blockMeta = $types[$blockType] ?? $types['theory'];
                ?>

                <a
                    class="map-item <?= $number === $currentIndex ? 'active' : '' ?>"
                    href="<?= e(url('/aula/' . $lesson['id'] . '?bloco=' . $number)) ?>"
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

        <header class="stage-top">
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
        </header>

        <!-- Navegação principal fica no topo: nunca fica escondida abaixo da dobra. -->
        <nav class="study-nav-top" aria-label="Navegação da aula">
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
                        action="<?= e(url('/aula/' . $lesson['id'] . '/concluir')) ?>"
                        method="post"
                    >
                        <?= Csrf::input() ?>

                        <button class="success" type="submit">
                            <?= $optional ? 'Concluir leitura' : 'Ir para exercícios' ?> →
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
                        href="<?= e(url('/aula/' . $lesson['id'] . '?bloco=' . $number)) ?>"
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

                <?php if ($looksLikeQuestion): ?>
                    <div class="question-preview-note">
                        <strong>Questão do material de estudo</strong>
                        <span>
                            Resolva mentalmente aqui. A correção que libera seu avanço
                            acontece no exercício obrigatório ao final da aula.
                        </span>
                    </div>

                    <?= $renderQuestionPreview($displayContent) ?>
                <?php else: ?>
                    <?= $renderNormalContent($displayContent) ?>
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

                <aside class="pedagogy-card study-tip-card">
                    <div class="pedagogy-icon">✓</div>

                    <div>
                        <span class="pedagogy-label">
                            <?= e(mb_strtoupper($studyPrompt['label'])) ?>
                        </span>

                        <p><?= e($studyPrompt['text']) ?></p>
                    </div>
                </aside>

                <?php if ($currentIndex >= $totalBlocks && !$optional): ?>
                    <aside class="lesson-exercise-gate">
                        <div class="gate-icon">✓</div>

                        <div>
                            <strong>Leitura concluída — agora vem a fixação</strong>

                            <p>
                                O exercício da aula é corrigido pelo sistema.
                                A próxima aula só é liberada após atingir a nota mínima.
                            </p>
                        </div>
                    </aside>
                <?php endif; ?>

            </div>
        </article>

    </section>

</section>
