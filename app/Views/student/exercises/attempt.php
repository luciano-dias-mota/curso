<?php
use App\Core\Csrf;

$session = $session ?? [];
$current = $current ?? [];
$total = max(1, (int) ($session['question_limit'] ?? 1));
$answered = (int) ($answered ?? 0);
$position = (int) ($current['position'] ?? ($answered + 1));
$progress = min(100, max(0, (($position - 1) / $total) * 100));
$scope = $session['topic_title_snapshot']
    ?: ($session['phase_title_snapshot'] ?: ($session['module_title_snapshot'] ?: $session['course_title_snapshot']));

$difficultyLabel = match ((string) ($current['difficulty_snapshot'] ?? 'medium')) {
    'hard' => 'Difícil',
    default => 'Média',
};

$formatStatement = static function (string $statement): array {
    $statement = str_replace("\xC2\xA0", ' ', trim($statement));
    $statement = preg_replace("/[ \t]+/u", ' ', $statement) ?? $statement;

    $source = '';
    if (preg_match('/^\s*\((POP\s+\d+\s*[-–—]\s*[^)]+)\)\s*/ui', $statement, $match) === 1) {
        $source = trim((string) $match[1]);
        $statement = trim((string) preg_replace('/^\s*\((POP\s+\d+\s*[-–—]\s*[^)]+)\)\s*/ui', '', $statement, 1));
    }

    $lines = preg_split('/\R/u', $statement) ?: [$statement];
    $lines = array_values(array_filter(array_map(
        static fn (string $line): string => trim($line),
        $lines
    ), static fn (string $line): bool => $line !== ''));

    $joinedLines = implode("\n", $lines);

    $isSequence = stripos($statement, 'ordene os trechos abaixo') !== false
        && preg_match('/^\(\s*\)/mu', $joinedLines) === 1;

    $isMatching = stripos($statement, 'relacione:') !== false
        && preg_match('/^\(\d+\)\s+/mu', $joinedLines) === 1
        && preg_match('/^Definições/i', $joinedLines) === 1;

    $hasRomanBlocks = preg_match('/^(?:I|II|III|IV|V|VI|VII|VIII|IX|X)\.\s+/mu', $joinedLines) === 1;

    $isRelation = stripos($statement, 'relacione cada item') !== false
        && preg_match('/^\d+\.\s+/mu', $joinedLines) === 1;

    if ($isMatching) {
        $introLines = [];
        $items = [];
        $definitions = [];
        $promptLines = [];
        $mode = 'intro';

        foreach ($lines as $line) {
            if (preg_match('/^\((\d+)\)\s*(.+)$/u', $line, $itemMatch) === 1) {
                $mode = 'items';
                $items[] = [
                    'number' => (int) $itemMatch[1],
                    'text' => trim((string) $itemMatch[2]),
                ];
                continue;
            }

            if (preg_match('/^Definições(?:,\s*na\s*ordem)?\s*:\s*$/ui', $line) === 1) {
                $mode = 'definitions';
                continue;
            }

            if (preg_match('/^((?:I|II|III|IV|V|VI|VII|VIII|IX|X))\.\s*(.+)$/u', $line, $definitionMatch) === 1) {
                $mode = 'definitions';
                $definitions[] = [
                    'roman' => trim((string) $definitionMatch[1]),
                    'text' => trim((string) $definitionMatch[2]),
                ];
                continue;
            }

            if (preg_match('/^(Assinale|Indique|Marque|Escolha)/ui', $line) === 1) {
                $mode = 'prompt';
            }

            if ($mode === 'prompt') {
                $promptLines[] = $line;
            } elseif ($mode === 'intro') {
                $introLines[] = $line;
            } elseif ($mode === 'items' && $items !== []) {
                $last = array_key_last($items);
                $items[$last]['text'] = trim($items[$last]['text'] . ' ' . $line);
            } elseif ($mode === 'definitions' && $definitions !== []) {
                $last = array_key_last($definitions);
                $definitions[$last]['text'] = trim($definitions[$last]['text'] . ' ' . $line);
            }
        }

        return [
            'type' => 'matching',
            'source' => $source,
            'intro' => trim(implode(' ', $introLines)),
            'items' => $items,
            'definitions' => $definitions,
            'prompt' => trim(implode(' ', $promptLines)),
            'relation' => null,
        ];
    }

    if ($isRelation) {
        $introLines = [];
        $items = [];
        $promptLines = [];
        $readingItems = false;
        $readingPrompt = false;

        foreach ($lines as $line) {
            if (preg_match('/^(\d+)\.\s*(.+)$/u', $line, $itemMatch) === 1) {
                $readingItems = true;
                $readingPrompt = false;
                $items[] = [
                    'number' => (int) $itemMatch[1],
                    'text' => trim((string) $itemMatch[2]),
                ];
                continue;
            }

            if ($readingItems && preg_match('/^(Assinale|Indique|Marque|Escolha)/ui', $line) === 1) {
                $readingPrompt = true;
            }

            if ($readingPrompt) {
                $promptLines[] = $line;
            } elseif (!$readingItems) {
                $introLines[] = $line;
            } elseif ($items !== []) {
                $last = array_key_last($items);
                $items[$last]['text'] = trim($items[$last]['text'] . ' ' . $line);
            }
        }

        $intro = trim(implode(' ', $introLines));
        $relation = null;

        if (preg_match(
            '/^(.*?)(PROCEDIMENTO\s+\d+(?:\.\d+)*\s*-\s*[^,]+),\s*relacione cada item a\s*R\s*\(([^)]+)\)\s*ou\s*A\s*\(([^)]+)\)\s*:?$/ui',
            $intro,
            $relationMatch
        ) === 1) {
            $relation = [
                'before' => trim((string) $relationMatch[1]),
                'procedure' => trim((string) $relationMatch[2]),
                'r_label' => trim((string) $relationMatch[3]),
                'a_label' => trim((string) $relationMatch[4]),
            ];
        }

        return [
            'type' => 'relation',
            'source' => $source,
            'intro' => $intro,
            'items' => $items,
            'prompt' => trim(implode(' ', $promptLines)),
            'relation' => $relation,
        ];
    }

    if (!$isSequence && $hasRomanBlocks) {
        $introLines = [];
        $blocks = [];
        $promptLines = [];
        $readingBlocks = false;
        $readingPrompt = false;

        foreach ($lines as $line) {
            if (preg_match('/^((?:I|II|III|IV|V|VI|VII|VIII|IX|X))\.\s*(.+)$/u', $line, $blockMatch) === 1) {
                $readingBlocks = true;
                $readingPrompt = false;
                $blocks[] = [
                    'roman' => trim((string) $blockMatch[1]),
                    'text' => trim((string) $blockMatch[2]),
                ];
                continue;
            }

            if ($readingBlocks && preg_match('/^(Assinale|Indique|Marque|Escolha)/ui', $line) === 1) {
                $readingPrompt = true;
            }

            if ($readingPrompt) {
                $promptLines[] = $line;
            } elseif (!$readingBlocks) {
                $introLines[] = $line;
            } elseif ($blocks !== []) {
                $last = array_key_last($blocks);
                $blocks[$last]['text'] = trim($blocks[$last]['text'] . ' ' . $line);
            }
        }

        return [
            'type' => 'roman_blocks',
            'source' => $source,
            'intro' => trim(implode(' ', $introLines)),
            'items' => $blocks,
            'prompt' => trim(implode(' ', $promptLines)),
            'relation' => null,
        ];
    }

    if (!$isSequence) {
        return [
            'type' => 'default',
            'source' => $source,
            'intro' => $statement,
            'items' => [],
            'prompt' => '',
            'relation' => null,
        ];
    }

    $introLines = [];
    $items = [];
    $promptLines = [];
    $readingItems = false;
    $readingPrompt = false;

    foreach ($lines as $line) {
        if (preg_match('/^\(\s*\)\s*(.+)$/u', $line, $itemMatch) === 1) {
            $readingItems = true;
            $readingPrompt = false;
            $items[] = trim((string) $itemMatch[1]);
            continue;
        }

        if ($readingItems && preg_match('/^(A sequência correta|Assinale|Indique|Marque)/ui', $line) === 1) {
            $readingPrompt = true;
        }

        if ($readingPrompt) {
            $promptLines[] = $line;
        } elseif (!$readingItems) {
            $introLines[] = $line;
        } elseif ($items !== []) {
            // Continuação do último trecho, caso o banco contenha quebra de linha interna.
            $last = array_key_last($items);
            $items[$last] = trim($items[$last] . ' ' . $line);
        }
    }

    return [
        'type' => 'sequence',
        'source' => $source,
        'intro' => trim(implode(' ', $introLines)),
        'items' => $items,
        'prompt' => trim(implode(' ', $promptLines)),
        'relation' => null,
    ];
};

$formattedStatement = $formatStatement((string) ($current['statement_snapshot'] ?? ''));

$splitProcedure = static function (string $text): array {
    if (preg_match('/^(.*?)(PROCEDIMENTO\s+\d+(?:\.\d+)*\s*-\s*[^,.:;]+)(.*)$/ui', $text, $m) === 1) {
        return [
            'before' => trim((string) $m[1]),
            'procedure' => trim((string) $m[2]),
            'after' => trim((string) $m[3]),
        ];
    }

    return [
        'before' => $text,
        'procedure' => '',
        'after' => '',
    ];
};

$introParts = $splitProcedure((string) ($formattedStatement['intro'] ?? ''));
?>
<section class="study-tools-page exercise-attempt-page">
    <header class="exercise-attempt-header card">
        <div>
            <a class="study-back-link" href="<?= e(url('/exercicios')) ?>">← Central de exercícios</a>
            <span class="study-tools-eyebrow">FIXAÇÃO · <?= e(mb_strtoupper($difficultyLabel)) ?></span>
            <h1><?= e((string) $scope) ?></h1>
            <p>Questão <?= $position ?> de <?= $total ?></p>
        </div>
        <div class="exercise-progress-box">
            <strong><?= $position ?>/<?= $total ?></strong>
            <div class="exercise-progress"><span style="width: <?= number_format($progress, 2, '.', '') ?>%"></span></div>
        </div>
    </header>

    <article class="exercise-question-card card">
        <div class="exercise-question-topline">
            <span>Questão <?= $position ?></span>
            <span class="study-chip"><?= e($difficultyLabel) ?></span>
        </div>

        <div class="exercise-statement" data-question-type="<?= e((string) $formattedStatement['type']) ?>">
            <?php if ((string) $formattedStatement['source'] !== ''): ?>
                <span class="exercise-source-badge"><?= e((string) $formattedStatement['source']) ?></span>
            <?php endif; ?>

            <?php if ($formattedStatement['type'] === 'matching'): ?>
                <div class="exercise-reading-panel">
                    <span class="exercise-reading-badge">Leia e responda</span>

                    <div class="exercise-reading-title">
                        <?php if ((string) $introParts['procedure'] !== ''): ?>
                            <span><?= e((string) $introParts['before']) ?></span>
                            <strong class="exercise-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                            <?php if ((string) $introParts['after'] !== ''): ?><span><?= e((string) $introParts['after']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                        <?php endif; ?>
                    </div>

                    <div class="exercise-reading-list">
                        <?php foreach (($formattedStatement['items'] ?? []) as $item): ?>
                            <div class="exercise-reading-item">
                                <span class="exercise-reading-number"><?= (int) ($item['number'] ?? 0) ?></span>
                                <p><?= e((string) ($item['text'] ?? '')) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="exercise-definitions-heading">Definições, na ordem</div>
                    <div class="exercise-definition-list">
                        <?php foreach (($formattedStatement['definitions'] ?? []) as $definition): ?>
                            <div class="exercise-definition-item">
                                <span class="exercise-definition-roman"><?= e((string) ($definition['roman'] ?? '')) ?></span>
                                <p><?= e((string) ($definition['text'] ?? '')) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ((string) ($formattedStatement['prompt'] ?? '') !== ''): ?>
                        <div class="exercise-reading-prompt">
                            <strong><?= e((string) $formattedStatement['prompt']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($formattedStatement['type'] === 'roman_blocks'): ?>
                <div class="exercise-reading-panel">
                    <span class="exercise-reading-badge">Leia e responda</span>
                    <div class="exercise-reading-title">
                        <?php if ((string) $introParts['procedure'] !== ''): ?>
                            <span><?= e((string) $introParts['before']) ?></span>
                            <strong class="exercise-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                            <?php if ((string) $introParts['after'] !== ''): ?><span><?= e((string) $introParts['after']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                        <?php endif; ?>
                    </div>

                    <div class="exercise-definition-list">
                        <?php foreach (($formattedStatement['items'] ?? []) as $item): ?>
                            <div class="exercise-definition-item">
                                <span class="exercise-definition-roman"><?= e((string) ($item['roman'] ?? '')) ?></span>
                                <p><?= e((string) ($item['text'] ?? '')) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ((string) ($formattedStatement['prompt'] ?? '') !== ''): ?>
                        <div class="exercise-reading-prompt">
                            <strong><?= e((string) $formattedStatement['prompt']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($formattedStatement['type'] === 'relation'): ?>
                <div class="exercise-reading-panel">
                    <span class="exercise-reading-badge">Leia e responda</span>

                    <div class="exercise-reading-title">
                        <?php if (is_array($formattedStatement['relation'] ?? null)): ?>
                            <span><?= e((string) $formattedStatement['relation']['before']) ?></span>
                            <strong class="exercise-procedure-highlight"><?= e((string) $formattedStatement['relation']['procedure']) ?></strong>
                            <span>, relacione cada item a</span>
                            <span class="exercise-relation-token is-r">R</span>
                            <span>(<?= e((string) $formattedStatement['relation']['r_label']) ?>)</span>
                            <span>ou</span>
                            <span class="exercise-relation-token is-a">A</span>
                            <span>(<?= e((string) $formattedStatement['relation']['a_label']) ?>):</span>
                        <?php else: ?>
                            <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                        <?php endif; ?>
                    </div>

                    <div class="exercise-reading-list">
                        <?php foreach (($formattedStatement['items'] ?? []) as $item): ?>
                            <div class="exercise-reading-item">
                                <span class="exercise-reading-number"><?= (int) ($item['number'] ?? 0) ?></span>
                                <p><?= e((string) ($item['text'] ?? '')) ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <?php if ((string) ($formattedStatement['prompt'] ?? '') !== ''): ?>
                        <div class="exercise-reading-prompt">
                            <strong><?= e((string) $formattedStatement['prompt']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

            <?php elseif ($formattedStatement['type'] === 'sequence'): ?>
                <div class="exercise-reading-panel">
                    <span class="exercise-reading-badge">Leia e responda</span>
                    <div class="exercise-reading-title">
                        <?php if ((string) $introParts['procedure'] !== ''): ?>
                            <span><?= e((string) $introParts['before']) ?></span>
                            <strong class="exercise-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                            <?php if ((string) $introParts['after'] !== ''): ?><span><?= e((string) $introParts['after']) ?></span><?php endif; ?>
                        <?php else: ?>
                            <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                        <?php endif; ?>
                    </div>

                    <div class="exercise-sequence-list" aria-label="Trechos para ordenar">
                        <?php foreach (($formattedStatement['items'] ?? []) as $index => $item): ?>
                            <article class="exercise-sequence-item">
                                <span class="exercise-sequence-number"><?= $index + 1 ?></span>
                                <p><?= e((string) $item) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>

                    <?php if ((string) ($formattedStatement['prompt'] ?? '') !== ''): ?>
                        <div class="exercise-reading-prompt">
                            <strong><?= e((string) $formattedStatement['prompt']) ?></strong>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="exercise-reading-panel">
                    <span class="exercise-reading-badge">Leia e responda</span>
                    <div class="exercise-reading-title is-default">
                        <?php if ((string) $introParts['procedure'] !== ''): ?>
                            <span><?= e((string) $introParts['before']) ?></span>
                            <strong class="exercise-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                            <?php if ((string) $introParts['after'] !== ''): ?><span><?= nl2br(e((string) $introParts['after'])) ?></span><?php endif; ?>
                        <?php else: ?>
                            <strong><?= nl2br(e((string) $formattedStatement['intro'])) ?></strong>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <form data-exercise-answer-form
              action="<?= e(url('/exercicios/sessao/' . (int) $session['id'] . '/resposta')) ?>"
              data-next-url="<?= e(url('/exercicios/sessao/' . (int) $session['id'])) ?>"
              data-result-url="<?= e(url('/exercicios/sessao/' . (int) $session['id'] . '/resultado')) ?>">
            <input type="hidden" name="session_question_id" value="<?= (int) ($current['id'] ?? 0) ?>">
            <input type="hidden" name="_token" value="<?= e(Csrf::token()) ?>">

            <div class="exercise-alternatives-heading">
                <span>Alternativas</span>
                <small>Selecione uma opção</small>
            </div>
            <div class="exercise-alternatives">
                <?php foreach (($current['alternatives'] ?? []) as $alternative): ?>
                    <label class="exercise-alternative">
                        <input type="radio" name="alternative_id" value="<?= (int) $alternative['id'] ?>">
                        <span class="exercise-alt-label"><?= e((string) $alternative['label']) ?></span>
                        <span><?= e((string) $alternative['text']) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>

            <div class="exercise-answer-actions">
                <button class="primary" type="submit" data-exercise-confirm>Confirmar resposta</button>
            </div>
        </form>

        <div class="exercise-feedback" data-exercise-feedback hidden>
            <div class="exercise-feedback-head">
                <span data-exercise-feedback-icon></span>
                <div><strong data-exercise-feedback-title></strong><small data-exercise-feedback-correct></small></div>
            </div>
            <p data-exercise-feedback-explanation></p>
            <small class="exercise-feedback-source" data-exercise-feedback-source></small>
            <a class="primary" href="#" data-exercise-next>Próxima questão →</a>
        </div>
    </article>
</section>
