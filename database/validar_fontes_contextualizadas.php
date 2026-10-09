<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}

define('BASE_PATH', dirname(__DIR__));

$files = [
    'Português' => BASE_PATH . '/database/sources/portugues_ufmt_reestruturado.json',
    'POP PMMT' => BASE_PATH . '/database/sources/pop_pmmt_2023_course_data.json',
    'Complementar' => BASE_PATH . '/database/questoes_simulados_complementares.json',
];

function walkQuestions(mixed $value, array &$out): void
{
    if (!is_array($value)) {
        return;
    }

    if (isset($value['statement'], $value['alternatives']) && is_array($value['alternatives'])) {
        $out[] = $value;
    }

    foreach ($value as $child) {
        if (is_array($child)) {
            walkQuestions($child, $out);
        }
    }
}

function correctCount(array $question): int
{
    $alternatives = $question['alternatives'] ?? [];
    if (!is_array($alternatives)) {
        return 0;
    }

    // Estrutura do Português: alternativas como strings + índice "correct" na questão.
    if (array_key_exists('correct', $question) && is_numeric($question['correct'])) {
        $index = (int) $question['correct'];
        return $index >= 0 && $index < count($alternatives) ? 1 : 0;
    }

    // Estrutura POP/complementar: cada alternativa possui flag "correct".
    $correct = 0;
    foreach ($alternatives as $alternative) {
        if (is_array($alternative) && !empty($alternative['correct'])) {
            $correct++;
        }
    }

    return $correct;
}

$total = 0;
$invalid = 0;
$easy = 0;
$medium = 0;
$hard = 0;
$statements = [];

echo "=============================================================\n";
echo " VALIDAÇÃO DAS FONTES CONTEXTUALIZADAS - V4\n";
echo "=============================================================\n";

foreach ($files as $name => $path) {
    if (!is_file($path)) {
        fwrite(STDERR, "Arquivo ausente: {$path}\n");
        exit(2);
    }

    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $questions = [];
    walkQuestions($data, $questions);

    $m = 0;
    $h = 0;
    $e = 0;
    $bad = 0;

    foreach ($questions as $question) {
        $difficulty = strtolower((string) ($question['difficulty'] ?? 'medium'));
        if ($difficulty === 'medium') {
            $medium++;
            $m++;
        } elseif ($difficulty === 'hard') {
            $hard++;
            $h++;
        } elseif ($difficulty === 'easy') {
            $easy++;
            $e++;
        } else {
            $invalid++;
            $bad++;
        }

        $statement = trim((string) ($question['statement'] ?? ''));
        $alternatives = $question['alternatives'] ?? [];
        $isValid = $statement !== ''
            && is_array($alternatives)
            && count($alternatives) >= 4
            && correctCount($question) === 1;

        if (!$isValid) {
            $invalid++;
            $bad++;
        }

        if ($statement !== '') {
            $normalized = (string) preg_replace('/\s+/u', ' ', $statement);
            $key = function_exists('mb_strtolower')
                ? mb_strtolower($normalized, 'UTF-8')
                : strtolower($normalized);
            $statements[$key] = ($statements[$key] ?? 0) + 1;
        }
    }

    $total += count($questions);
    printf(
        "%-14s Total:%4d | M:%3d | H:%3d | E:%2d | inválidas:%d\n",
        $name,
        count($questions),
        $m,
        $h,
        $e,
        $bad
    );
}

$duplicates = count(array_filter($statements, static fn (int $count): bool => $count > 1));
printf(
    "\nTotal:%d | Medium:%d | Hard:%d | Easy:%d | Inválidas:%d | Duplicidades:%d grupos\n",
    $total,
    $medium,
    $hard,
    $easy,
    $invalid,
    $duplicates
);

if ($total === 1048 && $invalid === 0 && $duplicates === 0) {
    echo "OK: fontes V4 íntegras.\n";
    exit(0);
}

fwrite(STDERR, "ATENÇÃO: revisar validação das fontes.\n");
exit(3);
