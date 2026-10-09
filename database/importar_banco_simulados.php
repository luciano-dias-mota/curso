<?php

declare(strict_types=1);


if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Execução permitida apenas via CLI.');
}


/**
 * Importador exclusivo do BANCO DE QUESTÕES PARA SIMULADOS.
 *
 * NÃO cria/edita módulos, fases, aulas, quizzes, matrículas ou progressão.
 * Apenas insere/relaciona registros em questions + alternatives.
 *
 * Uso:
 *   php database/importar_banco_simulados.php          (dry-run)
 *   php database/importar_banco_simulados.php --apply  (gravação)
 *   php database/importar_banco_simulados.php --source=pop
 *   php database/importar_banco_simulados.php --source=portugues
 *   php database/importar_banco_simulados.php --source=complementar
 */

define('BASE_PATH', dirname(__DIR__));

$autoload = BASE_PATH . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Erro: vendor/autoload.php não encontrado. Execute composer install.\n");
    exit(1);
}

require $autoload;
\Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();

function envv(string $key, mixed $default = null): mixed
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return is_string($value) ? trim($value, "\"'") : $value;
}

function argValue(string $name): ?string
{
    global $argv;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with((string) $arg, $name . '=')) {
            return substr((string) $arg, strlen($name) + 1);
        }
    }
    return null;
}

function hasFlag(string $flag): bool
{
    global $argv;
    return in_array($flag, $argv ?? [], true);
}

function loadJson(string $path): array
{
    if (!is_file($path)) {
        throw new RuntimeException("Arquivo-fonte não encontrado: {$path}");
    }

    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException("JSON inválido: {$path}");
    }
    return $data;
}

function normalizeDifficulty(mixed $difficulty): string
{
    $difficulty = strtolower(trim((string) $difficulty));
    return in_array($difficulty, ['easy', 'medium', 'hard'], true) ? $difficulty : 'medium';
}

function resolveCourse(PDO $pdo): array
{
    $slug = (string) envv('SIMULATION_COURSE_SLUG', 'preparacao-pmmt-merito-intelectual-e-caoc');

    $stmt = $pdo->prepare(
        "SELECT id, title, slug
         FROM courses
         WHERE slug = :slug
           AND status = 'published'
         LIMIT 1"
    );
    $stmt->execute(['slug' => $slug]);
    $course = $stmt->fetch();

    if (!$course) {
        throw new RuntimeException(
            "Curso de simulados não encontrado para o slug configurado: {$slug}. "
            . 'Defina SIMULATION_COURSE_SLUG corretamente; o importador não escolherá outro curso automaticamente.'
        );
    }

    return $course;
}

function resolveModule(PDO $pdo, int $courseId, string $slugContains, string $titleContains): array
{
    $stmt = $pdo->prepare(
        "SELECT id, title, slug, position
         FROM modules
         WHERE course_id = :course_id
           AND status = 'published'
           AND (
                slug LIKE :slug_pattern
                OR title LIKE :title_pattern
           )
         ORDER BY position, id
         LIMIT 1"
    );
    $stmt->execute([
        'course_id' => $courseId,
        'slug_pattern' => '%' . $slugContains . '%',
        'title_pattern' => '%' . $titleContains . '%',
    ]);
    $module = $stmt->fetch();

    if (!$module) {
        throw new RuntimeException(
            "Módulo não encontrado no curso #{$courseId}: {$titleContains} ({$slugContains})."
        );
    }

    return $module;
}

function firstAdminId(PDO $pdo): ?int
{
    $id = $pdo->query(
        "SELECT u.id
         FROM users u
         INNER JOIN roles r ON r.id = u.role_id
         WHERE r.slug = 'admin'
           AND u.status = 'active'
         ORDER BY u.id
         LIMIT 1"
    )->fetchColumn();

    return $id ? (int) $id : null;
}

/**
 * @return array{inserted:int,updated:int,skipped:int,invalid:int}
 */
function importQuestionSet(
    PDO $pdo,
    int $courseId,
    int $moduleId,
    ?int $adminId,
    array $questions,
    bool $dryRun,
    string $origin
): array {
    $stats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0];

    $find = $pdo->prepare(
        "SELECT id, module_id, course_id, active
         FROM questions
         WHERE statement = :statement
           AND ((source_label = :source_label) OR (source_label IS NULL AND :source_label_null IS NULL))
         LIMIT 1"
    );

    $insertQuestion = $pdo->prepare(
        "INSERT INTO questions
            (course_id, module_id, phase_id, lesson_id, question_type,
             statement, explanation, difficulty, source_label, active, created_by)
         VALUES
            (:course_id, :module_id, NULL, NULL, 'multiple_choice',
             :statement, :explanation, :difficulty, :source_label, 1, :created_by)"
    );

    $updateScope = $pdo->prepare(
        "UPDATE questions
         SET course_id = :course_id,
             module_id = :module_id,
             active = 1
         WHERE id = :id"
    );

    $altCount = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) AS corrects
         FROM alternatives
         WHERE question_id = :question_id"
    );

    $deleteAlts = $pdo->prepare("DELETE FROM alternatives WHERE question_id = :question_id");

    $insertAlt = $pdo->prepare(
        "INSERT INTO alternatives
            (question_id, label, text, is_correct, explanation, position)
         VALUES
            (:question_id, :label, :text, :is_correct, :explanation, :position)"
    );

    foreach ($questions as $item) {
        $statement = trim((string) ($item['statement'] ?? ''));
        $explanation = trim((string) ($item['explanation'] ?? ''));
        $sourceLabel = trim((string) ($item['source_label'] ?? $origin));
        $difficulty = normalizeDifficulty($item['difficulty'] ?? 'medium');
        $alternatives = $item['alternatives'] ?? [];

        if ($statement === '' || !is_array($alternatives) || count($alternatives) < 4) {
            $stats['invalid']++;
            continue;
        }

        $normalizedAlternatives = [];
        $correctCount = 0;

        // Formato A: [{text:..., correct:true}, ...]
        if (isset($alternatives[0]) && is_array($alternatives[0])) {
            foreach (array_values($alternatives) as $index => $alt) {
                $text = trim((string) ($alt['text'] ?? ''));
                $correct = !empty($alt['correct']);
                if ($text === '') {
                    continue;
                }
                if ($correct) {
                    $correctCount++;
                }
                $normalizedAlternatives[] = [
                    'text' => $text,
                    'correct' => $correct,
                    'explanation' => isset($alt['explanation']) ? trim((string) $alt['explanation']) : null,
                ];
            }
        } else {
            // Formato B: ["A", "B", ...] + correct = índice 0-based.
            $correctIndex = isset($item['correct']) ? (int) $item['correct'] : -1;
            foreach (array_values($alternatives) as $index => $text) {
                $text = trim((string) $text);
                if ($text === '') {
                    continue;
                }
                $correct = $index === $correctIndex;
                if ($correct) {
                    $correctCount++;
                }
                $normalizedAlternatives[] = [
                    'text' => $text,
                    'correct' => $correct,
                    'explanation' => null,
                ];
            }
        }

        if (count($normalizedAlternatives) < 4 || $correctCount !== 1) {
            $stats['invalid']++;
            continue;
        }

        $find->execute([
            'statement' => $statement,
            'source_label' => $sourceLabel !== '' ? $sourceLabel : null,
            'source_label_null' => $sourceLabel !== '' ? $sourceLabel : null,
        ]);
        $existing = $find->fetch();

        if ($existing) {
            $questionId = (int) $existing['id'];

            // Se a questão já existia, apenas garante que ela pertença ao curso/módulo
            // correto para o simulador. Não altera enunciado, gabarito ou dificuldade.
            if ((int) $existing['course_id'] !== $courseId
                || (int) $existing['module_id'] !== $moduleId
                || (int) $existing['active'] !== 1) {
                if (!$dryRun) {
                    $updateScope->execute([
                        'course_id' => $courseId,
                        'module_id' => $moduleId,
                        'id' => $questionId,
                    ]);
                }
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }

            // Corrige somente uma estrutura quebrada de alternativas.
            $altCount->execute(['question_id' => $questionId]);
            $quality = $altCount->fetch();
            $totalAlts = (int) ($quality['total'] ?? 0);
            $corrects = (int) ($quality['corrects'] ?? 0);
            if (($totalAlts < 4 || $corrects !== 1) && !$dryRun) {
                $deleteAlts->execute(['question_id' => $questionId]);
                foreach ($normalizedAlternatives as $index => $alt) {
                    $insertAlt->execute([
                        'question_id' => $questionId,
                        'label' => chr(65 + $index),
                        'text' => $alt['text'],
                        'is_correct' => $alt['correct'] ? 1 : 0,
                        'explanation' => $alt['explanation'],
                        'position' => $index + 1,
                    ]);
                }
            }
            continue;
        }

        if ($dryRun) {
            $stats['inserted']++;
            continue;
        }

        $insertQuestion->execute([
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'statement' => $statement,
            'explanation' => $explanation !== '' ? $explanation : null,
            'difficulty' => $difficulty,
            'source_label' => $sourceLabel !== '' ? $sourceLabel : null,
            'created_by' => $adminId,
        ]);
        $questionId = (int) $pdo->lastInsertId();

        foreach ($normalizedAlternatives as $index => $alt) {
            $insertAlt->execute([
                'question_id' => $questionId,
                'label' => chr(65 + $index),
                'text' => $alt['text'],
                'is_correct' => $alt['correct'] ? 1 : 0,
                'explanation' => $alt['explanation'],
                'position' => $index + 1,
            ]);
        }

        $stats['inserted']++;
    }

    return $stats;
}

function collectPopQuestions(array $data): array
{
    $questions = [];
    foreach ($data['modules'] ?? [] as $sourceModule) {
        foreach ($sourceModule['phases'] ?? [] as $phase) {
            foreach ($phase['lessons'] ?? [] as $lesson) {
                foreach ($lesson['questions'] ?? [] as $question) {
                    $questions[] = $question;
                }
            }
        }
    }
    return $questions;
}

function collectPortugueseQuestions(array $data): array
{
    $questions = [];
    $module = $data['module'] ?? [];
    foreach ($module['phases'] ?? [] as $phase) {
        foreach ($phase['lessons'] ?? [] as $lesson) {
            foreach ($lesson['questions'] ?? [] as $question) {
                $questions[] = $question;
            }
        }
    }
    return $questions;
}

function mergeStats(array &$total, array $stats): void
{
    foreach ($total as $key => $_) {
        $total[$key] += (int) ($stats[$key] ?? 0);
    }
}

$source = strtolower((string) (argValue('--source') ?? 'all'));
if (!in_array($source, ['all', 'pop', 'portugues', 'complementar'], true)) {
    fwrite(STDERR, "Fonte inválida. Use --source=all|pop|portugues|complementar\n");
    exit(1);
}

$apply = hasFlag('--apply');
$dryRun = !$apply;

$isProduction = strtolower((string) envv('APP_ENV', 'production')) === 'production';
$forceProduction = in_array('--force-production', $argv ?? [], true);
if ($apply && $isProduction && !$forceProduction) {
    fwrite(STDERR, "ABORTADO: APP_ENV=production. Use --apply --force-production somente após backup e revisão.\n");
    exit(1);
}

$pdo = new PDO(
    sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        envv('DB_HOST', '127.0.0.1'),
        (int) envv('DB_PORT', 3306),
        envv('DB_DATABASE', 'curso')
    ),
    (string) envv('DB_USERNAME', 'root'),
    (string) envv('DB_PASSWORD', ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$course = resolveCourse($pdo);
$courseId = (int) $course['id'];
$adminId = firstAdminId($pdo);

$baseSources = BASE_PATH . '/database/sources';
$popPath = $baseSources . '/pop_pmmt_2023_course_data.json';
$portuguesePath = $baseSources . '/portugues_ufmt_reestruturado.json';
$complementaryPath = BASE_PATH . '/database/questoes_simulados_complementares.json';

$totalStats = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'invalid' => 0];

printf("=============================================================\n");
printf(" IMPORTAÇÃO DO BANCO DE QUESTÕES PARA SIMULADOS\n");
printf("=============================================================\n");
printf("Banco:  %s\n", (string) envv('DB_DATABASE', 'curso'));
printf("Curso:  #%d - %s\n", $courseId, $course['title']);
printf("Modo:   %s\n", $dryRun ? 'DRY-RUN (nenhuma alteração será gravada)' : 'GRAVAÇÃO');
printf("Fonte:  %s\n\n", $source);

if (!$dryRun) {
    $pdo->beginTransaction();
}

try {
    if (in_array($source, ['all', 'portugues'], true)) {
        $module = resolveModule($pdo, $courseId, 'lingua-portuguesa', 'Língua Portuguesa');
        $questions = collectPortugueseQuestions(loadJson($portuguesePath));
        $stats = importQuestionSet(
            $pdo,
            $courseId,
            (int) $module['id'],
            $adminId,
            $questions,
            $dryRun,
            'Língua Portuguesa - fonte estruturada do projeto'
        );
        mergeStats($totalStats, $stats);
        printf(
            "Português -> %s | fonte=%d | inserir=%d | atualizar=%d | já existentes=%d | inválidas=%d\n",
            $module['title'],
            count($questions),
            $stats['inserted'],
            $stats['updated'],
            $stats['skipped'],
            $stats['invalid']
        );
    }

    if (in_array($source, ['all', 'pop'], true)) {
        $module = resolveModule(
            $pdo,
            $courseId,
            'procedimentos-operacionais-padrao',
            'Procedimentos Operacionais Padrão'
        );
        $questions = collectPopQuestions(loadJson($popPath));
        $stats = importQuestionSet(
            $pdo,
            $courseId,
            (int) $module['id'],
            $adminId,
            $questions,
            $dryRun,
            'Manual POP PMMT 2023'
        );
        mergeStats($totalStats, $stats);
        printf(
            "POP PMMT  -> %s | fonte=%d | inserir=%d | atualizar=%d | já existentes=%d | inválidas=%d\n",
            $module['title'],
            count($questions),
            $stats['inserted'],
            $stats['updated'],
            $stats['skipped'],
            $stats['invalid']
        );
    }

    if (in_array($source, ['all', 'complementar'], true)) {
        $data = loadJson($complementaryPath);
        foreach ($data['modules'] ?? [] as $bankModule) {
            $questions = $bankModule['questions'] ?? [];
            if (!is_array($questions) || $questions === []) {
                continue;
            }

            $module = resolveModule(
                $pdo,
                $courseId,
                (string) ($bankModule['module_slug_contains'] ?? ''),
                (string) ($bankModule['module_title_contains'] ?? '')
            );
            $stats = importQuestionSet(
                $pdo,
                $courseId,
                (int) $module['id'],
                $adminId,
                $questions,
                $dryRun,
                'Banco complementar de simulados'
            );
            mergeStats($totalStats, $stats);
            printf(
                "Complementar -> %s | fonte=%d | inserir=%d | atualizar=%d | já existentes=%d | inválidas=%d\n",
                $module['title'],
                count($questions),
                $stats['inserted'],
                $stats['updated'],
                $stats['skipped'],
                $stats['invalid']
            );
        }
    }

    if (!$dryRun) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(2);
}

printf("\n-------------------------------------------------------------\n");
printf("Novas questões:      %d\n", $totalStats['inserted']);
printf("Escopo atualizado:   %d\n", $totalStats['updated']);
printf("Já existentes:       %d\n", $totalStats['skipped']);
printf("Inválidas ignoradas: %d\n", $totalStats['invalid']);
printf("-------------------------------------------------------------\n");

if ($dryRun) {
    echo "DRY-RUN concluído. Nenhuma alteração foi gravada.\n";
    echo "Se os números estiverem corretos, execute novamente com --apply.\n";
} else {
    echo "Importação concluída. Agora execute:\n";
    echo "  php database/auditar_banco_simulados.php\n";
}
