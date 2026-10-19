<?php

declare(strict_types=1);

namespace App\Lab;

use InvalidArgumentException;

final readonly class LabEvaluationResult
{
    public function __construct(
        public bool $passed,
        public float $score,
        public string $message = '',
        public array $result = [],
        public ?int $stateVersion = null,
        public string $verificationSource = 'server'
    ) {
        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException('A pontuação do laboratório deve ficar entre 0 e 100.');
        }

        if (!in_array($verificationSource, ['server', 'client_evidence', 'hybrid'], true)) {
            throw new InvalidArgumentException('Fonte de verificação do laboratório inválida.');
        }

        if ($stateVersion !== null && $stateVersion < 0) {
            throw new InvalidArgumentException('Versão de estado do laboratório inválida.');
        }
    }

    public function toArray(): array
    {
        return [
            'passed' => $this->passed,
            'score' => round($this->score, 2),
            'message' => $this->message,
            'result' => $this->result,
            'state_version' => $this->stateVersion,
            'verification_source' => $this->verificationSource,
        ];
    }
}
