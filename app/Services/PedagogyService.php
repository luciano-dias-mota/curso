<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Camada pedagógica de apresentação.
 *
 * IMPORTANTE:
 * - não altera a regra normativa do material;
 * - não cria gabaritos;
 * - não substitui textos legais;
 * - analogias servem apenas para memorização.
 */
final class PedagogyService
{
    public function cleanDisplayText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Remove resíduos de paginação trazidos da conversão de PDF/DOCX:
        // Página2de6, Página 2 de 6, Pagina2de6 etc.
        $text = preg_replace(
            '/(?:P[aá]gina|Pagina)\s*\d+\s*de\s*\d+/iu',
            '',
            $text
        ) ?? $text;

        // Limpa espaços no fim de linha sem "corrigir" o conteúdo-fonte.
        $lines = array_map(
            static fn (string $line): string => rtrim($line),
            explode("\n", $text)
        );

        $text = implode("\n", $lines);

        // Evita quatro ou mais quebras consecutivas.
        $text = preg_replace("/\n{4,}/", "\n\n\n", $text) ?? $text;

        return trim($text);
    }

    public function looksLikeMultipleChoice(string $title, string $content): bool
    {
        $haystack = $title . "\n" . $content;

        $hits = 0;
        foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
            if (preg_match('/(?:^|\s)' . $letter . '\)\s+/u', $haystack)) {
                $hits++;
            }
        }

        return $hits >= 3;
    }

    /**
     * Retorna uma analogia somente para conceitos em que a analogia é
     * suficientemente neutra e não cria regra jurídica/normativa.
     */
    public function analogyFor(string $title, string $content): ?array
    {
        $text = mb_strtolower($title . ' ' . $content);

        if (
            str_contains($text, 'compreensão') &&
            (str_contains($text, 'interpretação') || str_contains($text, 'intelecção'))
        ) {
            return [
                'title' => 'Câmera x detetive',
                'text' =>
                    'Pense na compreensão como uma câmera: ela registra o que está explicitamente no texto. '
                    . 'A interpretação funciona como um detetive: liga pistas presentes no próprio texto para chegar a uma inferência, '
                    . 'sem inventar fatos externos.',
            ];
        }

        if (str_contains($text, 'anáfora') || str_contains($text, 'catáfora')) {
            return [
                'title' => 'Retrovisor x farol',
                'text' =>
                    'Use esta imagem mental: a anáfora olha pelo retrovisor para recuperar algo que já apareceu; '
                    . 'a catáfora aponta o farol para uma informação que ainda será apresentada.',
            ];
        }

        if (str_contains($text, 'coesão textual') || str_contains($text, 'elementos coesivos')) {
            return [
                'title' => 'Os elos de uma corrente',
                'text' =>
                    'Imagine cada frase como um elo. Pronomes, substituições e conectivos fazem os encaixes entre esses elos. '
                    . 'Quando as conexões são claras, o leitor consegue acompanhar a progressão do texto sem perder a referência.',
            ];
        }

        if (
            str_contains($text, 'operadores argumentativos') ||
            str_contains($text, 'conectivos')
        ) {
            return [
                'title' => 'Placas de trânsito do raciocínio',
                'text' =>
                    'Os conectivos podem ser lembrados como placas que avisam o rumo da argumentação: '
                    . 'alguns acrescentam, outros opõem, explicam ou encaminham uma conclusão. '
                    . 'Na prova, observe a relação de sentido produzida, não apenas a palavra isolada.',
            ];
        }

        if (
            str_contains($text, 'tipologia textual') ||
            str_contains($text, 'tipos textuais') ||
            str_contains($text, 'gêneros discursivos')
        ) {
            return [
                'title' => 'Estrutura x uso concreto',
                'text' =>
                    'Uma forma simples de separar as ideias é pensar em “estrutura” e “uso”. '
                    . 'O tipo textual descreve como o texto se organiza linguisticamente; o gênero é a forma concreta '
                    . 'em que o texto circula em uma situação comunicativa.',
            ];
        }

        if (
            str_contains($text, 'concordância verbal') ||
            str_contains($text, 'concordância nominal')
        ) {
            return [
                'title' => 'Peças que precisam combinar',
                'text' =>
                    'Pense na concordância como um encaixe: certas palavras precisam acompanhar características de outras. '
                    . 'Na resolução da questão, identifique primeiro quais termos estão ligados antes de decidir a forma correta.',
            ];
        }

        return null;
    }

    public function studyPromptFor(string $blockType, bool $looksLikeQuestion): array
    {
        if ($looksLikeQuestion || $blockType === 'question') {
            return [
                'label' => 'Raciocínio de prova',
                'text' =>
                    'Antes de olhar alternativas, diga em uma frase o que o comando está pedindo. '
                    . 'Depois elimine opções que contradizem ou extrapolam o conteúdo apresentado.',
            ];
        }

        return match ($blockType) {
            'comparison' => [
                'label' => 'Como memorizar',
                'text' => 'Transforme a comparação em duas colunas mentais: “o que diferencia” e “o que costuma confundir”.',
            ],
            'warning' => [
                'label' => 'Atenção de prova',
                'text' => 'Isole a palavra que muda a regra: “sempre”, “somente”, “pode”, “deve”, exceções, prazos ou condições.',
            ],
            'memory' => [
                'label' => 'Recuperação ativa',
                'text' => 'Feche o texto por alguns segundos e tente reconstruir a regra com suas próprias palavras.',
            ],
            'example', 'case_study' => [
                'label' => 'Faça a ponte',
                'text' => 'Depois do exemplo, volte à regra e identifique exatamente qual parte dela foi aplicada.',
            ],
            default => [
                'label' => 'Leitura ativa',
                'text' => 'Ao terminar esta tela, resuma o ponto central em uma frase e identifique uma possível confusão de prova.',
            ],
        };
    }
}
