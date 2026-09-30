<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Controller;use App\Core\Database;
final class ModuleController extends Controller{
public function show(int $id):void{$pdo=Database::connection();$s=$pdo->prepare("SELECT m.*,c.title course_title,c.id course_id,COALESCE(ump.status,'locked') user_status,COALESCE(ump.progress_pct,0) progress_pct FROM modules m JOIN courses c ON c.id=m.course_id LEFT JOIN user_module_progress ump ON ump.module_id=m.id AND ump.user_id=:u WHERE m.id=:id AND m.status='published' LIMIT 1");$s->execute(['u'=>Auth::id(),'id'=>$id]);$module=$s->fetch();if(!$module){http_response_code(404);$this->view('errors/404');return;}$p=$pdo->prepare("SELECT p.*,COALESCE(upp.status,'locked') user_status,COALESCE(upp.progress_pct,0) progress_pct,COALESCE(upp.best_score,0) best_score,(SELECT COUNT(*) FROM lessons l WHERE l.phase_id=p.id AND l.status='published') lesson_count FROM phases p LEFT JOIN user_phase_progress upp ON upp.phase_id=p.id AND upp.user_id=:u WHERE p.module_id=:m AND p.status='published' ORDER BY p.position");$p->execute(['u'=>Auth::id(),'m'=>$id]);$this->view('student/module',['title'=>$module['title'],'module'=>$module,'phases'=>$p->fetchAll()],'layouts/student');}}
