<?php

declare(strict_types=1);

namespace App\Lab;

use RuntimeException;

final class LabEngineRegistry
{
    /** @var array<string, LabEngineInterface> */
    private array $engines = [];

    public function __construct(?array $providers = null)
    {
        $providers ??= (array) config('lab.engines', []);

        foreach ($providers as $provider) {
            $engine = $provider;

            if (is_string($provider)) {
                if (!class_exists($provider)) {
                    throw new RuntimeException('Provider de laboratório não encontrado: ' . $provider);
                }
                $engine = new $provider();
            }

            if (!$engine instanceof LabEngineInterface) {
                throw new RuntimeException(
                    'Todo provider de laboratório deve implementar ' . LabEngineInterface::class . '.'
                );
            }

            $key = trim($engine->key());
            if ($key === '' || preg_match('/^[a-z0-9][a-z0-9_-]{1,79}$/', $key) !== 1) {
                throw new RuntimeException('engine_key inválida: ' . $key);
            }

            if (isset($this->engines[$key])) {
                throw new RuntimeException('engine_key duplicada: ' . $key);
            }

            if (!in_array($engine->executionMode(), ['browser', 'server', 'hybrid'], true)) {
                throw new RuntimeException('Modo de execução inválido para ' . $key . '.');
            }

            if (!in_array($engine->verificationMode(), ['server', 'client_evidence', 'hybrid'], true)) {
                throw new RuntimeException('Modo de verificação inválido para ' . $key . '.');
            }

            $this->engines[$key] = $engine;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->engines[$key]);
    }

    public function get(string $key): LabEngineInterface
    {
        if (!$this->has($key)) {
            throw new RuntimeException(
                'O motor de laboratório "' . $key . '" ainda não está registrado na plataforma.'
            );
        }

        return $this->engines[$key];
    }

    public function descriptors(): array
    {
        $items = [];

        foreach ($this->engines as $key => $engine) {
            $items[] = [
                'key' => $key,
                'label' => $engine->label(),
                'execution_mode' => $engine->executionMode(),
                'verification_mode' => $engine->verificationMode(),
                'assets' => array_values(array_filter(
                    $engine->clientAssets(),
                    static fn (mixed $asset): bool => is_string($asset) && trim($asset) !== ''
                )),
            ];
        }

        return $items;
    }
}
