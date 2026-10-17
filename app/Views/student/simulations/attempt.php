<?php
use App\Core\Csrf;
use App\Core\Session;

$error = Session::pullFlash('error');
$total = (int) $attempt['question_limit'];
$remaining = (int) ($attempt['remaining_seconds'] ?? 0);

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
<section class="simulation-attempt-page"
    data-simulation-attempt
    data-attempt-id="<?= (int) $attempt['id'] ?>"
    data-save-url="<?= e(url('/simulados/tentativa/' . $attempt['id'] . '/resposta')) ?>"
    data-remaining-seconds="<?= $remaining ?>">

    <?php if ($error): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

    <header class="simulation-attempt-header card">
        <div>
            <span class="simulation-eyebrow">SIMULADO LIVRE</span>
            <h1><?= e($attempt['module_title'] ?: 'Simulado geral') ?></h1>
            <p><?= e($attempt['course_title'] ?? '') ?> • Tentativa <?= (int) $attempt['attempt_number'] ?></p>
        </div>
        <div class="simulation-timer" data-simulation-timer><small>Tempo restante</small><strong>--:--</strong></div>
    </header>

    <div class="simulation-attempt-progress card">
        <div><span data-simulation-counter>Questão 1 de <?= $total ?></span><strong data-simulation-answered><?= (int) ($attempt['answered_count'] ?? 0) ?>/<?= $total ?> respondidas</strong></div>
        <div class="progress"><span data-simulation-progress style="width:<?= $total > 0 ? (100 / $total) : 0 ?>%"></span></div>
        <small data-simulation-save-status>Suas respostas são salvas automaticamente.</small>
    </div>

    <form action="<?= e(url('/simulados/tentativa/' . $attempt['id'] . '/finalizar')) ?>" method="post" data-simulation-finish-form>
        <?= Csrf::input() ?>

        <div class="simulation-question-layout">
            <div>
                <div class="simulation-question-list">
                    <?php foreach ($attempt['questions'] as $index => $question): ?>
                        <?php
                        $selected = (int) ($question['selected_alternative_id'] ?? 0);
                        $formattedStatement = $formatSimulationStatement((string) ($question['statement'] ?? ''));
                        $introParts = $splitSimulationProcedure((string) ($formattedStatement['intro'] ?? ''));
                        ?>
                        <article class="card simulation-question-card <?= $index === 0 ? 'is-active' : '' ?>" data-simulation-question data-index="<?= $index ?>" data-question-id="<?= (int) $question['id'] ?>">
                            <div class="simulation-question-top">
                                <div><span>QUESTÃO <?= $index + 1 ?> DE <?= $total ?></span><small><?= e($question['module_title']) ?></small></div>
                                <span class="difficulty-chip <?= e((string) $question['difficulty']) ?>"><?= $question['difficulty'] === 'hard' ? 'DIFÍCIL' : 'INTERMEDIÁRIA' ?></span>
                            </div>

                            <div class="simulation-reading-wrap" data-question-type="<?= e((string) $formattedStatement['type']) ?>">
                                <?php if ((string) $formattedStatement['source'] !== ''): ?>
                                    <span class="simulation-source-badge"><?= e((string) $formattedStatement['source']) ?></span>
                                <?php endif; ?>

                                <div class="simulation-reading-panel">
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
                                                <div class="simulation-reading-item">
                                                    <span class="simulation-reading-number"><?= (int) ($item['number'] ?? 0) ?></span>
                                                    <p><?= e((string) ($item['text'] ?? '')) ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                        <div class="simulation-definitions-heading">Definições, na ordem</div>
                                        <div class="simulation-definition-list">
                                            <?php foreach (($formattedStatement['definitions'] ?? []) as $definition): ?>
                                                <div class="simulation-definition-item">
                                                    <span class="simulation-definition-roman"><?= e((string) ($definition['roman'] ?? '')) ?></span>
                                                    <p><?= e((string) ($definition['text'] ?? '')) ?></p>
                                                </div>
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
                                                <div class="simulation-reading-item">
                                                    <span class="simulation-reading-number"><?= (int) ($item['number'] ?? 0) ?></span>
                                                    <p><?= e((string) ($item['text'] ?? '')) ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                    <?php elseif ($formattedStatement['type'] === 'roman_blocks'): ?>
                                        <div class="simulation-reading-title">
                                            <?php if ((string) $introParts['procedure'] !== ''): ?>
                                                <span><?= e((string) $introParts['before']) ?></span>
                                                <strong class="simulation-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                                                <?php if ((string) $introParts['after'] !== ''): ?><span><?= e((string) $introParts['after']) ?></span><?php endif; ?>
                                            <?php else: ?>
                                                <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                                            <?php endif; ?>
                                        </div>

                                        <div class="simulation-definition-list">
                                            <?php foreach (($formattedStatement['items'] ?? []) as $item): ?>
                                                <div class="simulation-definition-item">
                                                    <span class="simulation-definition-roman"><?= e((string) ($item['roman'] ?? '')) ?></span>
                                                    <p><?= e((string) ($item['text'] ?? '')) ?></p>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>

                                    <?php elseif ($formattedStatement['type'] === 'sequence'): ?>
                                        <div class="simulation-reading-title">
                                            <?php if ((string) $introParts['procedure'] !== ''): ?>
                                                <span><?= e((string) $introParts['before']) ?></span>
                                                <strong class="simulation-procedure-highlight"><?= e((string) $introParts['procedure']) ?></strong>
                                                <?php if ((string) $introParts['after'] !== ''): ?><span><?= e((string) $introParts['after']) ?></span><?php endif; ?>
                                            <?php else: ?>
                                                <strong><?= e((string) $formattedStatement['intro']) ?></strong>
                                            <?php endif; ?>
                                        </div>

                                        <div class="simulation-sequence-list">
                                            <?php foreach (($formattedStatement['items'] ?? []) as $itemIndex => $item): ?>
                                                <div class="simulation-sequence-item">
                                                    <span class="simulation-sequence-number"><?= $itemIndex + 1 ?></span>
                                                    <p><?= e((string) $item) ?></p>
                                                </div>
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
                                        <div class="simulation-reading-prompt">
                                            <strong><?= e((string) $formattedStatement['prompt']) ?></strong>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="simulation-options-heading">
                                <span>Alternativas</span>
                                <small>Selecione uma opção</small>
                            </div>
                            <div class="simulation-options">
                                <?php foreach ($question['alternatives'] as $alternative): ?>
                                    <label class="simulation-option">
                                        <input type="radio" name="answers[<?= (int) $question['id'] ?>]" value="<?= (int) $alternative['id'] ?>" <?= $selected === (int) $alternative['id'] ? 'checked' : '' ?>>
                                        <span class="simulation-option-letter"><?= e($alternative['label'] ?: chr(64 + (int) $alternative['position'])) ?></span>
                                        <span><?= e($alternative['text']) ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>

                <div class="simulation-question-controls card">
                    <button class="secondary" type="button" data-simulation-prev>← Anterior</button>
                    <button class="primary" type="button" data-simulation-next>Próxima →</button>
                </div>

                <div class="simulation-submit card">
                    <div><strong>Terminou?</strong><span>Questões não respondidas serão contabilizadas como erro.</span></div>
                    <button class="primary" type="submit">Finalizar e corrigir</button>
                </div>
            </div>

            <aside class="simulation-navigator card">
                <div class="simulation-timer simulation-timer-side" data-simulation-timer-mirror><small>Tempo restante</small><strong>--:--</strong></div>
                <h3>Questões</h3>
                <div class="simulation-jump-grid">
                    <?php foreach ($attempt['questions'] as $index => $question): ?>
                        <?php $answered = !empty($question['selected_alternative_id']); ?>
                        <button class="simulation-jump <?= $index === 0 ? 'active' : ($answered ? 'answered' : '') ?>" type="button" data-simulation-jump="<?= $index ?>"><?= $index + 1 ?></button>
                    <?php endforeach; ?>
                </div>
                <div class="simulation-nav-legend"><span><i class="current"></i>Atual</span><span><i class="answered"></i>Respondida</span><span><i></i>Não respondida</span></div>
            </aside>
        </div>
    </form>
</section>
