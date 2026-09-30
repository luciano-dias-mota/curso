<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;

final class ProfileController extends Controller
{
    public function updateTheme(Request $request): void
    {
        $theme = (string) $request->input('theme', 'dark');

        if (!in_array($theme, ['light', 'dark', 'system'], true)) {
            $this->json(['ok' => false, 'message' => 'Tema inválido.'], 422);
        }

        Database::connection()
            ->prepare('UPDATE users SET theme = :theme WHERE id = :id')
            ->execute([
                'theme' => $theme,
                'id' => Auth::id(),
            ]);

        $this->json(['ok' => true, 'theme' => $theme]);
    }
}
