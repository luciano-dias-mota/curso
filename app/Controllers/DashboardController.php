<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;

final class DashboardController extends Controller
{
    public function index(): void
    {
        if(Auth::isAdmin()){$this->redirect('/admin');}
        $s=Database::connection()->prepare("SELECT c.id,c.title,c.slug,c.short_description,c.difficulty,COALESCE(p.progress_pct,0) progress_pct,COALESCE(p.average_score,0) average_score,(SELECT COUNT(*) FROM modules m WHERE m.course_id=c.id AND m.status='published') module_count FROM enrollments e JOIN courses c ON c.id=e.course_id LEFT JOIN user_course_progress p ON p.course_id=c.id AND p.user_id=e.user_id WHERE e.user_id=:u AND e.status='active' AND c.status='published' ORDER BY c.position,c.id");
        $s->execute(['u'=>Auth::id()]);
        $this->view('student/dashboard',['title'=>'Painel do Estudante','user'=>Auth::user(),'courses'=>$s->fetchAll()],'layouts/student');
    }
}
