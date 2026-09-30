<section>
    <p class="muted">Painel administrativo</p>
    <h1>Central de Comando</h1>

    <div class="grid grid-4">
        <article class="card"><h2><?= (int) $stats['students'] ?></h2><p>Estudantes</p></article>
        <article class="card"><h2><?= (int) $stats['courses'] ?></h2><p>Cursos</p></article>
        <article class="card"><h2><?= (int) $stats['modules'] ?></h2><p>Módulos</p></article>
        <article class="card"><h2><?= (int) $stats['questions'] ?></h2><p>Questões</p></article>
    </div>

    <div class="card" style="margin-top:16px">
        <h2>Próxima etapa do desenvolvimento</h2>
        <p>CRUD de cursos, módulos, fases, aulas, questões, usuários, simulados e relatórios.</p>
    </div>
</section>
