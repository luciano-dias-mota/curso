<?php

declare(strict_types=1);

namespace App\Core;

final class Validator
{
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $fieldRules = is_array($ruleString) ? $ruleString : explode('|', $ruleString);

            foreach ($fieldRules as $rule) {
                [$name, $param] = array_pad(explode(':', (string) $rule, 2), 2, null);

                if ($name === 'required') {
                    $empty = $value === null
                        || (is_string($value) && trim($value) === '')
                        || (is_array($value) && $value === []);

                    if ($empty) {
                        $errors[$field][] = "O campo {$field} é obrigatório.";
                    }

                    continue;
                }

                if ($value === null || $value === '' || $value === []) {
                    continue;
                }

                if ($name === 'array') {
                    if (!is_array($value)) {
                        $errors[$field][] = "O campo {$field} deve ser uma lista.";
                    }
                    continue;
                }

                if (is_array($value)) {
                    $errors[$field][] = "O campo {$field} possui formato inválido.";
                    continue;
                }

                if ($name === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $errors[$field][] = "O campo {$field} deve conter um e-mail válido.";
                }

                if ($name === 'min' && mb_strlen((string) $value) < (int) $param) {
                    $errors[$field][] = "O campo {$field} deve ter no mínimo {$param} caracteres.";
                }

                if ($name === 'max' && mb_strlen((string) $value) > (int) $param) {
                    $errors[$field][] = "O campo {$field} deve ter no máximo {$param} caracteres.";
                }

                if ($name === 'same' && $value !== ($data[$param] ?? null)) {
                    $errors[$field][] = "O campo {$field} deve ser igual ao campo {$param}.";
                }

                if ($name === 'integer' && filter_var($value, FILTER_VALIDATE_INT) === false) {
                    $errors[$field][] = "O campo {$field} deve conter um número inteiro.";
                }

                if ($name === 'numeric' && !is_numeric($value)) {
                    $errors[$field][] = "O campo {$field} deve conter um valor numérico.";
                }

                if ($name === 'in' && $param !== null) {
                    $allowed = explode(',', $param);
                    if (!in_array((string) $value, $allowed, true)) {
                        $errors[$field][] = "O campo {$field} possui um valor inválido.";
                    }
                }
            }
        }

        return $errors;
    }
}
