<?php

declare(strict_types=1);

namespace App\Lab\Engines;

use App\Lab\LabEngineInterface;
use App\Lab\LabEvaluationResult;
use RuntimeException;

final class PgliteEngine implements LabEngineInterface
{
    private const VALIDATORS = [
        'statement_success',
        'query_result',
        'schema_table',
    ];

    public function key(): string
    {
        return 'postgresql_pglite';
    }

    public function label(): string
    {
        return 'PostgreSQL no navegador (PGlite)';
    }

    public function executionMode(): string
    {
        return 'browser';
    }

    public function verificationMode(): string
    {
        return 'client_evidence';
    }

    public function clientAssets(): array
    {
        return ['/assets/js/labs/postgresql-pglite.js'];
    }

    public function validateActivityDefinition(array $activity): void
    {
        $validatorType = trim((string) ($activity['validator_type'] ?? ''));
        if (!in_array($validatorType, self::VALIDATORS, true)) {
            throw new RuntimeException(
                'Validador PGlite não suportado: ' . ($validatorType !== '' ? $validatorType : '(vazio)')
            );
        }

        $validator = is_array($activity['validator_config'] ?? null)
            ? $activity['validator_config']
            : [];

        if ($validatorType === 'query_result') {
            $hasRule = isset($validator['columns'])
                || isset($validator['rows'])
                || isset($validator['row_count']);

            if (!$hasRule) {
                throw new RuntimeException(
                    'Atividade query_result precisa definir columns, rows ou row_count.'
                );
            }
        }

        if ($validatorType === 'schema_table') {
            if (trim((string) ($validator['table'] ?? '')) === '') {
                throw new RuntimeException(
                    'Atividade schema_table precisa definir a tabela esperada.'
                );
            }

            if (!isset($validator['columns']) || !is_array($validator['columns'])) {
                throw new RuntimeException(
                    'Atividade schema_table precisa definir as colunas esperadas.'
                );
            }
        }
    }

    public function clientManifest(array $activity, array $context = []): array
    {
        $bootstrap = is_array($activity['bootstrap_config'] ?? null)
            ? $activity['bootstrap_config']
            : [];

        $userId = (int) ($context['user_id'] ?? 0);
        $courseId = (int) ($context['course_id'] ?? 0);
        $activityId = (int) ($activity['id'] ?? 0);

        $storageKey = 'curso-mi-pg-' . substr(
            hash('sha256', $userId . ':' . $courseId . ':' . $this->key()),
            0,
            24
        );

        return [
            'module_url' => (string) config(
                'lab.pglite.module_url',
                'https://cdn.jsdelivr.net/npm/@electric-sql/pglite@0.5.10/dist/index.js'
            ),
            'storage_key' => $storageKey,
            'state_version' => max(0, (int) ($activity['state_version'] ?? 0)),
            'bootstrap_sql' => (string) ($bootstrap['sql'] ?? ''),
            'statement_timeout_ms' => max(
                1000,
                min(30000, (int) ($bootstrap['statement_timeout_ms'] ?? 10000))
            ),
            'max_result_rows' => max(
                10,
                min(500, (int) ($bootstrap['max_result_rows'] ?? 200))
            ),
            'success_title' => trim((string) ($bootstrap['success_title'] ?? '')),
            'success_explanation' => trim((string) ($bootstrap['success_explanation'] ?? '')),
            'practical_context' => trim((string) ($bootstrap['practical_context'] ?? '')),
            'activity_id' => $activityId,
        ];
    }

    public function evaluate(
        array $activity,
        array $payload,
        array $context = []
    ): LabEvaluationResult {
        $this->validateActivityDefinition($activity);

        $sql = trim((string) ($payload['sql'] ?? ''));
        if ($sql === '') {
            return new LabEvaluationResult(
                false,
                0,
                'Digite e execute uma instrução SQL antes de validar.',
                [],
                null,
                'client_evidence'
            );
        }

        if (strlen($sql) > 200000) {
            throw new RuntimeException('A instrução SQL enviada é grande demais.');
        }

        $evidence = $payload['evidence'] ?? null;
        if (!is_array($evidence) || ($evidence['success'] ?? false) !== true) {
            $message = is_array($evidence)
                ? trim((string) ($evidence['error'] ?? ''))
                : '';

            return new LabEvaluationResult(
                false,
                0,
                $message !== '' ? $message : 'A execução SQL não foi concluída com sucesso.',
                [],
                null,
                'client_evidence'
            );
        }

        $validatorType = (string) $activity['validator_type'];
        $validator = is_array($activity['validator_config'] ?? null)
            ? $activity['validator_config']
            : [];

        return match ($validatorType) {
            'statement_success' => $this->evaluateStatement(
                $activity,
                $evidence,
                $validator
            ),
            'query_result' => $this->evaluateQueryResult(
                $activity,
                $evidence,
                $validator
            ),
            'schema_table' => $this->evaluateSchemaTable(
                $activity,
                $evidence,
                $validator
            ),
            default => throw new RuntimeException(
                'Validador PGlite não implementado: ' . $validatorType
            ),
        };
    }

    private function evaluateStatement(
        array $activity,
        array $evidence,
        array $validator
    ): LabEvaluationResult {
        $expectedCommand = strtoupper(trim((string) ($validator['command'] ?? '')));
        $command = strtoupper(trim((string) (
            $evidence['last_result']['command'] ?? ''
        )));

        $passed = $expectedCommand === ''
            || $command === $expectedCommand;

        return new LabEvaluationResult(
            $passed,
            $passed ? 100 : 0,
            $passed
                ? 'Execução concluída corretamente.'
                : 'A instrução executada não corresponde ao tipo esperado.',
            [
                'command' => $command,
            ],
            $passed ? (int) ($activity['state_version'] ?? 0) : null,
            'client_evidence'
        );
    }

    private function evaluateQueryResult(
        array $activity,
        array $evidence,
        array $validator
    ): LabEvaluationResult {
        $result = is_array($evidence['last_result'] ?? null)
            ? $evidence['last_result']
            : [];

        $columns = array_values(array_map(
            'strval',
            is_array($result['columns'] ?? null) ? $result['columns'] : []
        ));

        $rows = is_array($result['rows'] ?? null)
            ? array_values($result['rows'])
            : [];

        $problems = [];

        if (isset($validator['columns']) && is_array($validator['columns'])) {
            $expectedColumns = array_values(array_map('strval', $validator['columns']));
            $orderedColumns = (bool) ($validator['columns_ordered'] ?? true);

            if ($orderedColumns) {
                if ($columns !== $expectedColumns) {
                    $problems[] = 'As colunas retornadas não correspondem ao esperado.';
                }
            } else {
                $a = $columns;
                $b = $expectedColumns;
                sort($a);
                sort($b);

                if ($a !== $b) {
                    $problems[] = 'As colunas retornadas não correspondem ao esperado.';
                }
            }
        }

        if (isset($validator['row_count'])) {
            $expectedCount = max(0, (int) $validator['row_count']);
            if (count($rows) !== $expectedCount) {
                $problems[] = sprintf(
                    'A consulta deveria retornar %d linha(s), mas retornou %d.',
                    $expectedCount,
                    count($rows)
                );
            }
        }

        if (isset($validator['rows']) && is_array($validator['rows'])) {
            $expectedRows = array_values($validator['rows']);
            $orderedRows = (bool) ($validator['ordered'] ?? true);

            $actualCanonical = array_map(
                fn (mixed $row): mixed => $this->canonicalize($row),
                $rows
            );
            $expectedCanonical = array_map(
                fn (mixed $row): mixed => $this->canonicalize($row),
                $expectedRows
            );

            if (!$orderedRows) {
                usort(
                    $actualCanonical,
                    fn (mixed $a, mixed $b): int => strcmp(
                        json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    )
                );
                usort(
                    $expectedCanonical,
                    fn (mixed $a, mixed $b): int => strcmp(
                        json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                    )
                );
            }

            if ($actualCanonical !== $expectedCanonical) {
                $problems[] = 'Os dados retornados ainda não correspondem ao resultado esperado.';
            }
        }

        $passed = $problems === [];

        return new LabEvaluationResult(
            $passed,
            $passed ? 100 : 0,
            $passed
                ? 'Consulta validada. Resultado correto.'
                : implode(' ', $problems),
            [
                'columns' => $columns,
                'row_count' => count($rows),
            ],
            $passed ? (int) ($activity['state_version'] ?? 0) : null,
            'client_evidence'
        );
    }

    private function evaluateSchemaTable(
        array $activity,
        array $evidence,
        array $validator
    ): LabEvaluationResult {
        $schemaRows = is_array($evidence['schema'] ?? null)
            ? $evidence['schema']
            : [];

        $tableName = trim((string) $validator['table']);
        $actualColumns = [];

        foreach ($schemaRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string) ($row['table_name'] ?? '') !== $tableName) {
                continue;
            }

            $columnName = (string) ($row['column_name'] ?? '');
            if ($columnName === '') {
                continue;
            }

            $actualColumns[$columnName] = [
                'name' => $columnName,
                'type' => strtolower((string) ($row['data_type'] ?? '')),
                'nullable' => strtoupper((string) ($row['is_nullable'] ?? 'YES')) === 'YES',
            ];
        }

        $problems = [];

        if ($actualColumns === []) {
            $problems[] = 'A tabela esperada ainda não foi encontrada.';
        }

        foreach ((array) $validator['columns'] as $expected) {
            if (is_string($expected)) {
                $expected = ['name' => $expected];
            }

            if (!is_array($expected)) {
                continue;
            }

            $name = trim((string) ($expected['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            if (!isset($actualColumns[$name])) {
                $problems[] = 'Coluna ausente: ' . $name . '.';
                continue;
            }

            if (isset($expected['type'])) {
                $expectedType = strtolower(trim((string) $expected['type']));
                if ($expectedType !== '' && $actualColumns[$name]['type'] !== $expectedType) {
                    $problems[] = sprintf(
                        'A coluna %s deveria ser %s.',
                        $name,
                        $expectedType
                    );
                }
            }

            if (array_key_exists('nullable', $expected)) {
                $expectedNullable = (bool) $expected['nullable'];
                if ($actualColumns[$name]['nullable'] !== $expectedNullable) {
                    $problems[] = sprintf(
                        'A configuração de NULL da coluna %s está incorreta.',
                        $name
                    );
                }
            }
        }

        if (($validator['allow_extra_columns'] ?? true) === false) {
            $expectedNames = [];

            foreach ((array) $validator['columns'] as $expected) {
                $name = is_string($expected)
                    ? $expected
                    : (string) ($expected['name'] ?? '');

                if ($name !== '') {
                    $expectedNames[] = $name;
                }
            }

            $extra = array_diff(array_keys($actualColumns), $expectedNames);
            if ($extra !== []) {
                $problems[] = 'A tabela possui colunas extras não previstas.';
            }
        }

        $passed = $problems === [];

        return new LabEvaluationResult(
            $passed,
            $passed ? 100 : 0,
            $passed
                ? 'Estrutura validada corretamente.'
                : implode(' ', $problems),
            [
                'table' => $tableName,
                'columns_found' => array_values(array_keys($actualColumns)),
            ],
            $passed ? (int) ($activity['state_version'] ?? 0) : null,
            'client_evidence'
        );
    }

    private function canonicalize(mixed $value): mixed
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            foreach ($value as $key => $item) {
                $value[$key] = $this->canonicalize($item);
            }

            return $value;
        }

        if (is_float($value)) {
            return round($value, 10);
        }

        return $value;
    }
}
