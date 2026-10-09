<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Session;
use PDO;
use RuntimeException;
use Throwable;

final class QuestionController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): void
    {
        $pdo = Database::connection();

        $search = trim((string) $request->input('q', ''));
        $difficulty = trim((string) $request->input('difficulty', ''));
        $moduleId = max(0, (int) $request->input('module_id', 0));
        $state = trim((string) $request->input('state', ''));
        $page = max(1, (int) $request->input('page', 1));

        [$whereSql, $params, $difficulty, $state] = $this->filters(
            $search,
            $difficulty,
            $moduleId,
            $state
        );

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM questions q WHERE {$whereSql}");
        $countStmt->execute($params);
        $filteredTotal = (int) $countStmt->fetchColumn();

        $totalPages = max(1, (int) ceil($filteredTotal / self::PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        $stmt = $pdo->prepare(
            "SELECT q.id, q.statement, q.explanation, q.difficulty, q.source_label,
                    q.active, q.updated_at, q.phase_id, q.lesson_id,
                    m.title AS module_title,
                    c.title AS course_title,
                    (SELECT COUNT(*) FROM alternatives a WHERE a.question_id = q.id) AS alternatives_count,
                    (SELECT COUNT(*) FROM quiz_questions qq WHERE qq.question_id = q.id) AS quiz_links,
                    (SELECT COUNT(*) FROM simulation_questions sq WHERE sq.question_id = q.id) AS simulation_links,
                    (SELECT COUNT(*) FROM simulation_attempt_questions saq WHERE saq.question_id = q.id) AS simulation_uses
             FROM questions q
             LEFT JOIN modules m ON m.id = q.module_id
             LEFT JOIN courses c ON c.id = q.course_id
             WHERE {$whereSql}
             ORDER BY q.id DESC
             LIMIT " . self::PER_PAGE . " OFFSET {$offset}"
        );
        $stmt->execute($params);

        $stats = $pdo->query(
            "SELECT COUNT(*) AS total,
                    SUM(active = 1) AS active_count,
                    SUM(difficulty = 'medium' AND active = 1) AS medium_count,
                    SUM(difficulty = 'hard' AND active = 1) AS hard_count,
                    SUM(difficulty = 'easy' AND active = 1) AS easy_count
             FROM questions"
        )->fetch(PDO::FETCH_ASSOC) ?: [];

        $modules = $this->modules($pdo);

        $this->view('admin/questions/index', [
            'title' => 'Banco de questões',
            'questions' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'stats' => $stats,
            'modules' => $modules,
            'filters' => compact('search', 'difficulty', 'moduleId', 'state'),
            'pagination' => [
                'page' => $page,
                'perPage' => self::PER_PAGE,
                'total' => $filteredTotal,
                'pages' => $totalPages,
            ],
        ], 'layouts/admin');
    }

    public function create(): void
    {
        $pdo = Database::connection();
        $old = Session::pullFlash('old_question', []);

        $this->view('admin/questions/form', [
            'title' => 'Nova questão',
            'mode' => 'create',
            'question' => is_array($old) ? $old : [],
            'alternatives' => $this->oldAlternatives($old),
            'modules' => $this->modules($pdo),
            'usage' => ['quiz_links' => 0, 'simulation_links' => 0, 'simulation_uses' => 0, 'simulation_answers' => 0, 'exercise_uses' => 0],
            'scopeLocked' => false,
        ], 'layouts/admin');
    }

    public function store(Request $request): void
    {
        $pdo = Database::connection();

        try {
            $data = $this->validatedQuestionPayload($pdo, $request, null);

            $duplicate = $pdo->prepare(
                'SELECT id FROM questions WHERE TRIM(statement) = :statement LIMIT 1'
            );
            $duplicate->execute(['statement' => trim($data['statement'])]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('Já existe uma questão com o mesmo enunciado.');
            }

            $pdo->beginTransaction();

            $insert = $pdo->prepare(
                "INSERT INTO questions
                    (course_id, module_id, phase_id, lesson_id, question_type,
                     statement, explanation, difficulty, source_label, active, created_by)
                 VALUES
                    (:course_id, :module_id, NULL, NULL, 'multiple_choice',
                     :statement, :explanation, :difficulty, :source_label, :active, :created_by)"
            );
            $insert->execute([
                'course_id' => $data['course_id'],
                'module_id' => $data['module_id'],
                'statement' => $data['statement'],
                'explanation' => $data['explanation'],
                'difficulty' => $data['difficulty'],
                'source_label' => $data['source_label'],
                'active' => $data['active'],
                'created_by' => Auth::id(),
            ]);

            $questionId = (int) $pdo->lastInsertId();
            $insertAlternative = $pdo->prepare(
                "INSERT INTO alternatives
                    (question_id, label, text, is_correct, explanation, position)
                 VALUES
                    (:question_id, :label, :text, :is_correct, NULL, :position)"
            );

            foreach ($data['alternatives'] as $index => $text) {
                $position = $index + 1;
                $insertAlternative->execute([
                    'question_id' => $questionId,
                    'label' => $this->label($position),
                    'text' => $text,
                    'is_correct' => $position === $data['correct_position'] ? 1 : 0,
                    'position' => $position,
                ]);
            }

            $this->audit(
                $pdo,
                'question_created',
                $questionId,
                'Questão criada manualmente pelo administrador.'
            );

            $pdo->commit();
            Session::flash('success', 'Questão criada e adicionada ao banco com sucesso.');
            $this->redirect('/admin/questoes/' . $questionId . '/editar');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::flash('error', $e->getMessage());
            Session::flash('old_question', $request->all());
            $this->redirect('/admin/questoes/nova');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erro ao criar questão: ' . $e->getMessage());
            Session::flash('error', 'Não foi possível criar a questão.');
            Session::flash('old_question', $request->all());
            $this->redirect('/admin/questoes/nova');
        }
    }

    public function edit(int $id): void
    {
        $pdo = Database::connection();
        $question = $this->question($pdo, $id);
        $alternatives = $this->alternatives($pdo, $id);
        $usage = $this->usage($pdo, $id);

        $immutable = $this->isImmutable($usage);
        $scopeLocked = $immutable
            || (int) ($question['phase_id'] ?? 0) > 0
            || (int) ($question['lesson_id'] ?? 0) > 0;

        $this->view('admin/questions/form', [
            'title' => 'Editar questão #' . $id,
            'mode' => 'edit',
            'question' => $question,
            'alternatives' => $alternatives,
            'modules' => $this->modules($pdo),
            'usage' => $usage,
            'scopeLocked' => $scopeLocked,
            'immutable' => $immutable,
        ], 'layouts/admin');
    }

    public function update(int $id, Request $request): void
    {
        $pdo = Database::connection();

        try {
            $question = $this->question($pdo, $id);
            $existingAlternatives = $this->alternatives($pdo, $id);
            $usage = $this->usage($pdo, $id);

            $immutable = $this->isImmutable($usage);
            if ($immutable) {
                throw new RuntimeException(
                    'Esta questão já está vinculada ou foi utilizada em avaliação. Para preservar o histórico dos alunos, ela não pode mais ser editada. Cadastre uma nova questão para substituir o conteúdo.'
                );
            }

            $scopeLocked = (int) ($question['phase_id'] ?? 0) > 0
                || (int) ($question['lesson_id'] ?? 0) > 0;

            $data = $this->validatedQuestionPayload(
                $pdo,
                $request,
                $scopeLocked ? (int) $question['module_id'] : null,
                true,
                $existingAlternatives
            );

            $submittedIds = $request->input('alternative_id', []);
            $submittedTexts = $request->input('alternative_text', []);
            $correctAlternativeId = (int) $request->input('correct_alternative_id', 0);

            if (!is_array($submittedIds) || !is_array($submittedTexts)) {
                throw new RuntimeException('Alternativas inválidas.');
            }

            $submittedIds = array_values(array_map('intval', $submittedIds));
            $submittedTexts = array_values(array_map(
                static fn ($value): string => trim((string) $value),
                $submittedTexts
            ));

            $existingIds = array_map(static fn (array $row): int => (int) $row['id'], $existingAlternatives);

            if ($submittedIds !== $existingIds || count($submittedTexts) !== count($existingIds)) {
                throw new RuntimeException(
                    'A quantidade de alternativas de uma questão existente não pode ser alterada por esta tela, para preservar tentativas anteriores.'
                );
            }

            if (count($existingIds) < 2 || in_array('', $submittedTexts, true)) {
                throw new RuntimeException('Todas as alternativas existentes precisam possuir texto.');
            }

            if (!in_array($correctAlternativeId, $existingIds, true)) {
                throw new RuntimeException('Selecione exatamente uma alternativa correta.');
            }

            $pdo->beginTransaction();

            $update = $pdo->prepare(
                "UPDATE questions
                 SET course_id = :course_id,
                     module_id = :module_id,
                     statement = :statement,
                     explanation = :explanation,
                     difficulty = :difficulty,
                     source_label = :source_label,
                     active = :active
                 WHERE id = :id"
            );
            $update->execute([
                'course_id' => $data['course_id'],
                'module_id' => $data['module_id'],
                'statement' => $data['statement'],
                'explanation' => $data['explanation'],
                'difficulty' => $data['difficulty'],
                'source_label' => $data['source_label'],
                'active' => $data['active'],
                'id' => $id,
            ]);

            $updateAlt = $pdo->prepare(
                "UPDATE alternatives
                 SET label = :label,
                     text = :text,
                     is_correct = :is_correct,
                     position = :position
                 WHERE id = :id AND question_id = :question_id"
            );

            foreach ($submittedIds as $index => $alternativeId) {
                $position = $index + 1;
                $updateAlt->execute([
                    'label' => $this->label($position),
                    'text' => $submittedTexts[$index],
                    'is_correct' => $alternativeId === $correctAlternativeId ? 1 : 0,
                    'position' => $position,
                    'id' => $alternativeId,
                    'question_id' => $id,
                ]);
            }

            $this->audit(
                $pdo,
                'question_updated',
                $id,
                'Enunciado/metadados/alternativas atualizados pelo administrador.'
            );

            $pdo->commit();
            Session::flash('success', 'Questão atualizada com sucesso.');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Session::flash('error', $e->getMessage());
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Erro ao atualizar questão #' . $id . ': ' . $e->getMessage());
            Session::flash('error', 'Não foi possível atualizar a questão.');
        }

        $this->redirect('/admin/questoes/' . $id . '/editar');
    }

    public function setStatus(int $id, Request $request): void
    {
        $pdo = Database::connection();
        $this->question($pdo, $id);
        $usage = $this->usage($pdo, $id);

        if ($this->isImmutable($usage)) {
            Session::flash(
                'error',
                'Questão vinculada/usada em avaliação não pode ser ativada ou desativada, para preservar o histórico. Cadastre uma nova versão.'
            );
            $this->redirect('/admin/questoes');
        }

        $active = (int) $request->input('active', -1);

        if (!in_array($active, [0, 1], true)) {
            Session::flash('error', 'Status de questão inválido.');
            $this->redirect('/admin/questoes');
        }

        $stmt = $pdo->prepare('UPDATE questions SET active = :active WHERE id = :id');
        $stmt->execute(['active' => $active, 'id' => $id]);

        $this->audit(
            $pdo,
            $active ? 'question_activated' : 'question_deactivated',
            $id,
            $active ? 'Questão ativada pelo administrador.' : 'Questão desativada pelo administrador.'
        );

        Session::flash('success', $active ? 'Questão ativada.' : 'Questão desativada.');
        $this->redirect('/admin/questoes');
    }

    private function validatedQuestionPayload(
        PDO $pdo,
        Request $request,
        ?int $forcedModuleId = null,
        bool $editing = false,
        array $existingAlternatives = []
    ): array {
        $moduleId = $forcedModuleId ?? (int) $request->input('module_id', 0);
        $statement = trim((string) $request->input('statement', ''));
        $explanation = trim((string) $request->input('explanation', ''));
        $difficulty = trim((string) $request->input('difficulty', 'medium'));
        $sourceLabel = trim((string) $request->input('source_label', ''));
        $active = (int) $request->input('active', 0) === 1 ? 1 : 0;

        if ($moduleId <= 0) {
            throw new RuntimeException('Selecione o módulo da questão.');
        }

        $moduleStmt = $pdo->prepare(
            'SELECT m.id, m.course_id, m.title FROM modules m WHERE m.id = :id LIMIT 1'
        );
        $moduleStmt->execute(['id' => $moduleId]);
        $module = $moduleStmt->fetch(PDO::FETCH_ASSOC);
        if (!$module) {
            throw new RuntimeException('Módulo selecionado não encontrado.');
        }

        if (mb_strlen($statement) < 12) {
            throw new RuntimeException('O enunciado precisa possuir pelo menos 12 caracteres.');
        }
        if (!in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            throw new RuntimeException('Dificuldade inválida.');
        }
        if (mb_strlen($sourceLabel) > 255) {
            throw new RuntimeException('A fonte deve possuir no máximo 255 caracteres.');
        }

        $result = [
            'course_id' => (int) $module['course_id'],
            'module_id' => $moduleId,
            'statement' => $statement,
            'explanation' => $explanation !== '' ? $explanation : null,
            'difficulty' => $difficulty,
            'source_label' => $sourceLabel !== '' ? $sourceLabel : null,
            'active' => $active,
        ];

        if ($editing) {
            return $result;
        }

        $rawAlternatives = $request->input('alternative_text', []);
        $correctPosition = (int) $request->input('correct_position', 0);
        if (!is_array($rawAlternatives)) {
            throw new RuntimeException('Alternativas inválidas.');
        }

        $slots = array_values(array_map(
            static fn ($value): string => trim((string) $value),
            $rawAlternatives
        ));

        // Na criação, A-D são obrigatórias; E é opcional.
        if (count($slots) < 4 || in_array('', array_slice($slots, 0, 4), true)) {
            throw new RuntimeException('Preencha pelo menos quatro alternativas (A, B, C e D).');
        }

        $alternatives = array_slice($slots, 0, 5);
        while ($alternatives !== [] && end($alternatives) === '') {
            array_pop($alternatives);
        }
        if (in_array('', $alternatives, true)) {
            throw new RuntimeException('Não deixe alternativas vazias entre outras preenchidas.');
        }
        if (count($alternatives) < 4 || count($alternatives) > 5) {
            throw new RuntimeException('Use quatro ou cinco alternativas.');
        }
        if ($correctPosition < 1 || $correctPosition > count($alternatives)) {
            throw new RuntimeException('Marque uma alternativa correta entre as alternativas preenchidas.');
        }

        $result['alternatives'] = $alternatives;
        $result['correct_position'] = $correctPosition;
        return $result;
    }

    private function filters(string $search, string $difficulty, int $moduleId, string $state): array
    {
        $where = ['1=1'];
        $params = [];

        if ($search !== '') {
            $where[] = '(q.statement LIKE :search_statement OR q.source_label LIKE :search_source)';
            $params['search_statement'] = '%' . $search . '%';
            $params['search_source'] = '%' . $search . '%';
        }

        if (in_array($difficulty, ['easy', 'medium', 'hard'], true)) {
            $where[] = 'q.difficulty = :difficulty';
            $params['difficulty'] = $difficulty;
        } else {
            $difficulty = '';
        }

        if ($moduleId > 0) {
            $where[] = 'q.module_id = :module_id';
            $params['module_id'] = $moduleId;
        }

        if ($state === 'active' || $state === 'inactive') {
            $where[] = 'q.active = :active';
            $params['active'] = $state === 'active' ? 1 : 0;
        } else {
            $state = '';
        }

        return [implode(' AND ', $where), $params, $difficulty, $state];
    }

    private function question(PDO $pdo, int $id): array
    {
        $stmt = $pdo->prepare(
            "SELECT q.*, m.title AS module_title, c.title AS course_title
             FROM questions q
             LEFT JOIN modules m ON m.id = q.module_id
             LEFT JOIN courses c ON c.id = q.course_id
             WHERE q.id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $id]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$question) {
            http_response_code(404);
            Session::flash('error', 'Questão não encontrada.');
            $this->redirect('/admin/questoes');
        }

        return $question;
    }

    private function alternatives(PDO $pdo, int $questionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, label, text, is_correct, explanation, position FROM alternatives WHERE question_id = :id ORDER BY position, id'
        );
        $stmt->execute(['id' => $questionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function usage(PDO $pdo, int $questionId): array
    {
        $stmt = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM quiz_questions WHERE question_id = :q1) AS quiz_links,
                (SELECT COUNT(*) FROM simulation_questions WHERE question_id = :q2) AS simulation_links,
                (SELECT COUNT(*) FROM simulation_attempt_questions WHERE question_id = :q3) AS simulation_uses,
                (SELECT COUNT(*) FROM simulation_answers WHERE question_id = :q4) AS simulation_answers"
        );
        $stmt->execute(['q1' => $questionId, 'q2' => $questionId, 'q3' => $questionId, 'q4' => $questionId]);
        $usage = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
            'quiz_links' => 0,
            'simulation_links' => 0,
            'simulation_uses' => 0,
            'simulation_answers' => 0,
        ];

        $usage['exercise_uses'] = 0;
        if ($this->tableExists($pdo, 'exercise_session_questions')) {
            $exerciseStmt = $pdo->prepare(
                'SELECT COUNT(*) FROM exercise_session_questions WHERE question_id = :question_id'
            );
            $exerciseStmt->execute(['question_id' => $questionId]);
            $usage['exercise_uses'] = (int) $exerciseStmt->fetchColumn();
        }

        return $usage;
    }

    private function isImmutable(array $usage): bool
    {
        return (int) ($usage['quiz_links'] ?? 0) > 0
            || (int) ($usage['simulation_links'] ?? 0) > 0
            || (int) ($usage['simulation_uses'] ?? 0) > 0
            || (int) ($usage['simulation_answers'] ?? 0) > 0
            || (int) ($usage['exercise_uses'] ?? 0) > 0;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
             LIMIT 1"
        );
        $stmt->execute(['table_name' => $table]);
        return (bool) $stmt->fetchColumn();
    }

    private function modules(PDO $pdo): array
    {
        return $pdo->query(
            "SELECT m.id, m.title, m.position, c.id AS course_id, c.title AS course_title
             FROM modules m
             INNER JOIN courses c ON c.id = m.course_id
             WHERE m.status <> 'archived' AND c.status <> 'archived'
             ORDER BY c.position, c.id, m.position, m.id"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function oldAlternatives(mixed $old): array
    {
        if (!is_array($old)) {
            return [];
        }
        $raw = $old['alternative_text'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $correct = (int) ($old['correct_position'] ?? 0);
        $rows = [];
        foreach (array_slice(array_values($raw), 0, 5) as $index => $text) {
            $position = $index + 1;
            $rows[] = [
                'id' => 0,
                'label' => $this->label($position),
                'text' => (string) $text,
                'is_correct' => $position === $correct ? 1 : 0,
                'position' => $position,
            ];
        }
        return $rows;
    }

    private function label(int $position): string
    {
        return chr(64 + max(1, min(26, $position)));
    }

    private function audit(PDO $pdo, string $action, int $questionId, string $description): void
    {
        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs
                (user_id, action, entity_type, entity_id, description, ip_address, user_agent)
             VALUES
                (:user_id, :action, 'question', :entity_id, :description, :ip_address, :user_agent)"
        );
        $stmt->execute([
            'user_id' => Auth::id(),
            'action' => $action,
            'entity_id' => $questionId,
            'description' => $description,
            'ip_address' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        ]);
    }
}
