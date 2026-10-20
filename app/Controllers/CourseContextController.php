<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Services\CourseContextService;

final class CourseContextController extends Controller
{
    public function leave(): void
    {
        (new CourseContextService())->clear();

        $this->redirect('/dashboard');
    }
}
