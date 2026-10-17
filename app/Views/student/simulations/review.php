<?php
$selectedCount = (int) ($attempt['answered_count'] ?? 0);

$formatSimulationStatement = static function (string $statement): array {
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
            'definitions' => [],
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
            'definitions' => [],
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
            'definitions' => [],
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
            $last = array_key_last($items);
            $items[$last] = trim($items[$last] . ' ' . $line);
        }
    }

    return [
        'type' => 'sequence',
        'source' => $source,
        'intro' => trim(implode(' ', $introLines)),
        'items' => $items,
        'definitions' => [],
        'prompt' => trim(implode(' ', $promptLines)),
        'relation' => null,
    ];
};

$splitSimulationProcedure = static function (string $text): array {
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

?>
<section class="simulation-review-page">
    <div class="simulation-back"><a href="<?= e(url('/simulados/tentativa/' . $attempt['id'] . '/resultado')) ?>">← Voltar ao resultado</a></div>

    <header class="simulation-review-header card">
        <span class="simulation-eyebrow">REVISÃO COMENTADA</span>
        <h1><?= e($attempt['module_title'] ?: 'Simulado geral') ?></h1>
        <p><?= (int) $attempt['correct_count'] ?> acertos em <?= (int) $attempt['question_limit'] ?> questões. Revise principalmente os erros e as questões deixadas em branco.</p>
    </header>

    <div class="simulation-review-list">
        <?php foreach ($attempt['questions'] as $index => $question): ?>
            <?php
            $selectedId = (int) ($question['selected_alternative_id'] ?? 0);
            $isCorrect = (int) ($question['answer_is_correct'] ?? 0) === 1;
            $unanswered = $selectedId <= 0;
            $formattedStatement = $formatSimulationStatement((string) ($question['statement'] ?? ''));
            $introParts = $splitSimulationProcedure((string) ($formattedStatement['intro'] ?? ''));
            ?>
            <article class="card simulation-review-card <?= $isCorrect ? 'correct' : 'wrong' ?>">
                <div class="simulation-review-heading">
                    <span><?= $isCorrect ? '✓' : ($unanswered ? '○' : '✕') ?></span>
                    <div><strong>Questão <?= $index + 1 ?></strong><small><?= e($question['module_title']) ?> • <?= match ((string) $question['difficulty']) { 'hard' => 'Difícil', 'easy' => 'Fácil', default => 'Intermediária' } ?></small></div>
                    <em><?= $isCorrect ? 'ACERTOU' : ($unanswered ? 'EM BRANCO' : 'ERROU') ?></em>
                </div>

                <div class="simulation-review-reading" data-question-type="<?= e((string) $formattedStatement['type']) ?>">
                    <span class="simulation-reading-badge">Leia e responda</span>

                    <?php if ($formattedStatement['type'] === 'matching'): ?>
                        <div class="simulation-reading-title">
                            <?php if ((string) $introParts['procedure'] !== ''): ?>
                                <span><?= e((string) $introParts['before']) ?></span>
                                <strong class="simulation-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                                <?php if ((string) $introParts['after'] !== ''): ?><span><?= e((string) $introParts['after']) ?></span><?php endif; ?>
                            <?php else: ?>
                                <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                            <?php endif; ?>
                        </div>
                        <div class="simulation-reading-list">
                            <?php foreach (($formattedStatement['items'] ?? []) as $item): ?>
                                <div class="simulation-reading-item"><span class="simulation-reading-number"><?= (int) ($item['number'] ?? 0) ?></span><p><?= e((string) ($item['text'] ?? '')) ?></p></div>
                            <?php endforeach; ?>
                        </div>
                        <div class="simulation-definitions-heading">Definições, na ordem</div>
                        <div class="simulation-definition-list">
                            <?php foreach (($formattedStatement['definitions'] ?? []) as $definition): ?>
                                <div class="simulation-definition-item"><span class="simulation-definition-roman"><?= e((string) ($definition['roman'] ?? '')) ?></span><p><?= e((string) ($definition['text'] ?? '')) ?></p></div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($formattedStatement['type'] === 'relation'): ?>
                        <div class="simulation-reading-title">
                            <?php if (is_array($formattedStatement['relation'] ?? null)): ?>
                                <span><?= e((string) $formattedStatement['relation']['before']) ?></span>
                                <strong class="simulation-procedure-highlight"><?= e((string) $formattedStatement['relation']['procedure']) ?></strong>
                                <span>, relacione cada item a</span>
                                <span class="simulation-relation-token is-r">R</span>
                                <span>(<?= e((string) $formattedStatement['relation']['r_label']) ?>)</span>
                                <span>ou</span>
                                <span class="simulation-relation-token is-a">A</span>
                                <span>(<?= e((string) $formattedStatement['relation']['a_label']) ?>):</span>
                            <?php else: ?>
                                <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                            <?php endif; ?>
                        </div>
                        <div class="simulation-reading-list">
                            <?php foreach (($formattedStatement['items'] ?? []) as $item): ?>
                                <div class="simulation-reading-item"><span class="simulation-reading-number"><?= (int) ($item['number'] ?? 0) ?></span><p><?= e((string) ($item['text'] ?? '')) ?></p></div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($formattedStatement['type'] === 'roman_blocks'): ?>
                        <div class="simulation-reading-title"><strong><?= e((string) $formattedStatement['intro']) ?></strong></div>
                        <div class="simulation-definition-list">
                            <?php foreach (($formattedStatement['items'] ?? []) as $item): ?>
                                <div class="simulation-definition-item"><span class="simulation-definition-roman"><?= e((string) ($item['roman'] ?? '')) ?></span><p><?= e((string) ($item['text'] ?? '')) ?></p></div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($formattedStatement['type'] === 'sequence'): ?>
                        <div class="simulation-reading-title"><strong><?= e((string) $formattedStatement['intro']) ?></strong></div>
                        <div class="simulation-sequence-list">
                            <?php foreach (($formattedStatement['items'] ?? []) as $itemIndex => $item): ?>
                                <div class="simulation-sequence-item"><span class="simulation-sequence-number"><?= $itemIndex + 1 ?></span><p><?= e((string) $item) ?></p></div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="simulation-reading-title is-default">
                            <?php if ((string) $introParts['procedure'] !== ''): ?>
                                <span><?= e((string) $introParts['before']) ?></span>
                                <strong class="simulation-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                                <?php if ((string) $introParts['after'] !== ''): ?><span><?= nl2br(e((string) $introParts['after'])) ?></span><?php endif; ?>
                            <?php else: ?>
                                <strong><?= nl2br(e((string) $formattedStatement['intro'])) ?></strong>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ((string) ($formattedStatement['prompt'] ?? '') !== ''): ?>
                        <div class="simulation-reading-prompt"><strong><?= e((string) $formattedStatement['prompt']) ?></strong></div>
                    <?php endif; ?>
                </div>

                <div class="simulation-review-options">
                    <?php foreach ($question['alternatives'] as $alternative): ?>
                        <?php
                        $selected = (int) $alternative['id'] === $selectedId;
                        $correctAlt = (int) $alternative['is_correct'] === 1;
                        $class = $correctAlt ? 'correct-option' : ($selected ? 'selected-wrong' : '');
                        ?>
                        <div class="simulation-review-option <?= $class ?>">
                            <strong><?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?></strong>
                            <span><?= e($alternative['text']) ?></span>
                            <?php if ($correctAlt): ?><em>Resposta correta</em><?php endif; ?>
                            <?php if ($selected && !$correctAlt): ?><em>Sua resposta</em><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!empty($question['explanation'])): ?>
                    <div class="simulation-explanation"><strong>Comentário</strong><p><?= nl2br(e($question['explanation'])) ?></p></div>
                <?php endif; ?>

                <?php if (!empty($question['source_label'])): ?>
                    <div class="simulation-source"><strong>Fonte:</strong> <?= e($question['source_label']) ?></div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="simulation-result-actions">
        <a class="primary" href="<?= e(url('/simulados/novo')) ?>">Gerar outro simulado</a>
        <a class="secondary" href="<?= e(url('/simulados')) ?>">Histórico</a>
    </div>
</section>
