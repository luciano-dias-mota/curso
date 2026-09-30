<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(
        string $view,
        array $data = [],
        string $layout = 'layouts/app'
    ): void {
        $viewFile = base_path('app/Views/' . str_replace('.', '/', $view) . '.php');
        $layoutFile = base_path('app/Views/' . str_replace('.', '/', $layout) . '.php');

        if (!is_file($viewFile)) {
            throw new RuntimeException("View não encontrada: {$view}");
        }

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = ob_get_clean();

        if (!is_file($layoutFile)) {
            echo $content;
            return;
        }

        require $layoutFile;
    }
}
