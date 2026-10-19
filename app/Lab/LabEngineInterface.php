<?php

declare(strict_types=1);

namespace App\Lab;

interface LabEngineInterface
{
    /**
     * Chave técnica estável, por exemplo: javascript_browser.
     * Não deve depender do nome de um curso.
     */
    public function key(): string;

    public function label(): string;

    /**
     * browser | server | hybrid
     */
    public function executionMode(): string;

    /**
     * server | client_evidence | hybrid
     */
    public function verificationMode(): string;

    /**
     * Assets locais/confiáveis necessários pelo runtime do navegador.
     * Ex.: ['/assets/js/labs/meu-engine.js']
     *
     * @return string[]
     */
    public function clientAssets(): array;

    /**
     * Valida a definição administrativa antes de executar uma tentativa.
     * Deve lançar exceção quando a atividade estiver inconsistente.
     */
    public function validateActivityDefinition(array $activity): void;

    /**
     * Retorna somente configuração segura para exposição ao navegador.
     */
    public function clientManifest(array $activity, array $context = []): array;

    /**
     * Valida a submissão/evidência e devolve o resultado normalizado.
     * O núcleo nunca marca uma atividade como concluída apenas porque o
     * navegador enviou passed=true; a decisão passa pelo provider.
     */
    public function evaluate(
        array $activity,
        array $payload,
        array $context = []
    ): LabEvaluationResult;
}
