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

## Motor genérico de atividades práticas

A evolução multi-curso inclui uma fundação separada para laboratórios, documentada em `docs/MOTOR_LABORATORIOS.md`.

O núcleo não conhece tecnologias específicas. Cada atividade usa uma `engine_key` e um provider que implementa `App\\Lab\\LabEngineInterface`. Isso permite adicionar no futuro motores diferentes sem criar tabelas ou fluxos exclusivos para PostgreSQL, JavaScript, HTML/CSS, PHP, Linux etc.

As políticas de progressão também são configuráveis por curso: aulas podem fechar por leitura, quiz ou laboratório; fases podem fechar por avaliação ou pelas aulas concluídas; módulos podem fechar por avaliação final ou pelas fases concluídas. Os padrões continuam compatíveis com o curso PMMT.



## Portal por perfil e seleção de curso

O fluxo de entrada da plataforma passa a ser orientado pelo perfil autenticado.

### Estudante

Após o login, o estudante entra em `/dashboard`, que funciona como portal multi-curso. A tela exibe somente cursos que atendem simultaneamente a estas regras:

- o estudante possui matrícula com `enrollments.status = active`;
- o curso está com `courses.status = published`.

Cada curso aparece como um card com progresso, quantidade de módulos/aulas e informação de retomada. Ao clicar no card, o estudante entra em `/curso/{id}`, onde a navegação passa a respeitar as configurações daquele curso.

A ausência do card não é a única proteção: `CourseController` também valida matrícula ativa e curso publicado no backend.

### Administrador

Após o login, o administrador entra diretamente em `/admin`, a Central de Gestão, com acessos prioritários para:

- criar e gerenciar alunos;
- criar e gerenciar cursos;
- liberar ou retirar cursos através da Central de Matrículas;
- gerenciar conteúdo, questões, simulados, relatórios e vídeos.

A rota `/admin/matriculas` centraliza a relação aluno ↔ curso. O perfil individual do aluno continua permitindo alterar matrícula, mas ambos os fluxos usam o mesmo `EnrollmentService`, evitando regras duplicadas.

### Princípio de arquitetura

O portal escolhe **qual curso** o usuário deseja acessar. O dashboard interno do curso cuida de **como aquele curso funciona**.

Assim, novos cursos não exigem novos dashboards específicos como `dashboard_postgresql.php`, `dashboard_php.php` etc. O comportamento é orientado pelos dados e configurações de cada curso.

## Isolamento visual entre plataforma e cursos

A interface deve ser tratada em três camadas independentes:

1. **Portal da plataforma**
   - rota: `/dashboard`;
   - layout: `app/Views/layouts/portal.php`;
   - CSS exclusivo: `public/assets/css/portal.css`;
   - JS exclusivo: `public/assets/js/portal.js`;
   - não carrega `app.css` nem `layouts/student.php`.

2. **Preparatório PMMT existente**
   - mantém `layouts/student.php` e o conjunto de estilos atuais;
   - clicar no card liberado apenas leva para `/curso/{id}`;
   - o portal não redefine cores, navegação ou estrutura interna do PMMT.

3. **Novos cursos**
   - não devem reutilizar CSS específico do PMMT quando houver identidade própria;
   - devem receber layout/stylesheet próprios antes de serem publicados;
   - PostgreSQL continuará em `draft` enquanto sua experiência prática específica estiver sendo construída.

Regra de arquitetura: alterações visuais no portal devem permanecer em `portal.*`; alterações visuais específicas de um curso devem permanecer no shell/assets desse curso. Nenhum novo tema deve ser implementado alterando seletores globais compartilhados com outros cursos.

### Visibilidade no portal

O portal mostra todos os cursos com `courses.status = 'published'`.

- matrícula `active`: card liberado, com link real para `/curso/{id}`;
- sem matrícula ativa: card visível e bloqueado, sem link para o curso;
- `draft` ou `archived`: não aparece para estudantes.

A autorização real continua no backend de `CourseController`; portanto digitar manualmente `/curso/{id}` sem matrícula ativa não libera o conteúdo.

