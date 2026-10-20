<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Session;

final class CourseContextController extends Controller
{
    public function leave(): void
    {
        Session::forget('active_course_id');
        Session::forget('active_course_experience');

        $this->redirect('/dashboard');
    }
}
