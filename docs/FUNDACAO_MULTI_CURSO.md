# Fundação Multi-Curso — Curso MI

Esta evolução transforma o Curso MI em uma plataforma preparada para hospedar vários cursos independentes, mantendo uma única conta de usuário e múltiplas matrículas por estudante.

## Modelo principal

- `users`: contas de administradores e estudantes.
- `courses`: catálogo de cursos.
- `enrollments`: relação muitos-para-muitos entre estudante e curso.
- `course_settings`: comportamento/metodologia configurável por curso.
- `modules -> phases -> lessons -> lesson_blocks`: árvore acadêmica reutilizável.
- `questions`, `quizzes`, `simulations`: avaliações vinculáveis ao curso.
- tabelas `user_*_progress`: progresso individual por conteúdo.

Um estudante pode estar matriculado em zero, um ou vários cursos simultaneamente.

## Configurações por curso

As configurações iniciais suportadas são:

- `module_access_mode`: `free` ou `sequential`.
- `lesson_access_mode`: `free` ou `sequential`.
- `exercises_enabled`: habilita exercícios do curso.
- `simulations_enabled`: habilita simulados do curso.
- `notes_enabled`: habilita anotações vinculadas ao curso.
- `certificate_enabled`: reserva a ativação de certificados por curso.
- `lab_enabled`: reserva recursos práticos/laboratórios específicos.

Os valores padrão preservam a experiência atual do curso existente:
módulos livres, aulas sequenciais, exercícios/simulados/anotações habilitados.

## Administração

Novas rotas:

- `/admin/cursos`: catálogo administrativo.
- `/admin/cursos/novo`: criação de curso.
- `/admin/cursos/{id}`: central de gestão do curso.
- `/admin/cursos/{id}/editar`: dados e metodologia.
- `/admin/cursos/{id}/estrutura`: construtor Curso -> Módulo -> Fase -> Aula.

O conteúdo detalhado das aulas continua sendo editado em `/admin/aulas`.

## Banco de dados

A migration incremental é:

```bash
php database/aplicar_plataforma_multicursos_v1.php
```

Esse comando executa somente o DRY-RUN.

Depois de backup e revisão:

```bash
php database/aplicar_plataforma_multicursos_v1.php --apply
```

Em produção, com `APP_ENV=production`:

```bash
php database/aplicar_plataforma_multicursos_v1.php --apply --force-production
```

A migration:

1. sincroniza `phases.is_required`;
2. sincroniza `lessons.is_required`;
3. cria `course_settings`;
4. adiciona índice para consultas de matrículas do aluno;
5. permite conquistas globais ou específicas por curso;
6. cria configurações padrão para os cursos existentes;
7. troca o nome legado `PMMT Academy` por `Curso MI` quando ainda estiver com o valor antigo.

## Checklist antes da produção

1. Fazer backup do banco.
2. Atualizar o código da branch/PR.
3. Executar o DRY-RUN da migration.
4. Conferir os itens marcados como `CRIAR/AJUSTAR`.
5. Aplicar a migration.
6. Acessar `/admin/cursos`.
7. Conferir o curso PMMT existente e suas matrículas.
8. Criar um curso de teste em rascunho.
9. Criar módulo, fase e aula nesse curso.
10. Matricular um usuário de teste em dois cursos.
11. Conferir os dois cards no dashboard do estudante.
12. Validar exercícios, simulados e anotações em cada curso habilitado.

## PostgreSQL

Nenhuma estrutura pedagógica específica de PostgreSQL foi criada nesta etapa.

O próximo curso poderá ser montado sobre esta fundação depois de definir metodologia, módulos, aulas, exercícios, avaliações e eventual laboratório SQL, sem acoplar essas decisões ao núcleo da plataforma.
