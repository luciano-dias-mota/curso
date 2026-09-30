<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth;use App\Core\Controller;use App\Core\Database;
final class CourseController extends Controller{
public function show(int $id):void{$pdo=Database::connection();$s=$pdo->prepare("SELECT c.*,COALESCE(p.progress_pct,0) progress_pct,COALESCE(p.average_score,0) average_score FROM courses c JOIN enrollments e ON e.course_id=c.id AND e.user_id=:u AND e.status='active' LEFT JOIN user_course_progress p ON p.course_id=c.id AND p.user_id=:u2 WHERE c.id=:id AND c.status='published' LIMIT 1");$s->execute(['u'=>Auth::id(),'u2'=>Auth::id(),'id'=>$id]);$course=$s->fetch();if(!$course){http_response_code(404);$this->view('errors/404');return;}$m=$pdo->prepare("SELECT m.*,COALESCE(ump.status,'locked') user_status,COALESCE(ump.progress_pct,0) progress_pct,(SELECT COUNT(*) FROM phases p WHERE p.module_id=m.id AND p.status='published' AND p.is_required=1) required_phase_count,(SELECT COUNT(*) FROM phases p WHERE p.module_id=m.id AND p.status='published' AND p.is_required=0) library_phase_count FROM modules m LEFT JOIN user_module_progress ump ON ump.module_id=m.id AND ump.user_id=:u WHERE m.course_id=:c AND m.status='published' ORDER BY m.position");$m->execute(['u'=>Auth::id(),'c'=>$id]);$this->view('student/course',['title'=>$course['title'],'course'=>$course,'modules'=>$m->fetchAll()],'layouts/student');}}
