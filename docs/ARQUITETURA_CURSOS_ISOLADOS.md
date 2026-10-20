# Arquitetura de cursos isolados

## Objetivo

O Curso MI é uma plataforma que hospeda experiências educacionais independentes.

O portal centraliza:

- autenticação;
- usuários;
- matrículas;
- administração;
- catálogo;
- entrada e saída de cursos.

Cada curso pode ter navegação, layout, assets e metodologia próprios sem alterar os demais.

## Regra principal

Nunca use nome, título, posição ou ID fixo para decidir qual experiência um curso utiliza.

A decisão é explícita:

```text
courses.experience_key
```

Chaves registradas atualmente:

```text
generic
pmmt
postgresql
```

As definições ficam em:

```text
config/course_experiences.php
```

O código de resolução fica em:

```text
app/Services/CourseExperienceRegistry.php
app/Services/CourseLayoutResolver.php
```

## Fronteiras visuais

### Portal

```text
app/Views/layouts/portal.php
public/assets/css/portal.css
public/assets/js/portal.js
```

O portal não deve carregar o stylesheet interno do PMMT.

### PMMT

O PMMT preserva o shell legado:

```text
app/Views/layouts/student.php
app/Views/student/pmmt/dashboard.php
```

Os assets legados estão declarados em `config/course_experiences.php`.

Eles continuam fisicamente nos caminhos históricos para reduzir risco de regressão. Novos cursos não devem adicionar regras próprias nesses arquivos.

### PostgreSQL

O namespace já existe:

```text
app/Views/layouts/courses/postgresql.php
public/assets/courses/postgresql/course.css
```

Todo visual específico do PostgreSQL deve permanecer nesse namespace.

## Autorização

Sessão não é autorização.

`active_course_id` e `active_course_experience` existem somente para navegação e escolha de layout.

Toda operação sensível deve validar o banco novamente através de:

```text
app/Services/CourseAccessService.php
```

A cadeia mínima de autorização é:

```text
usuário autenticado
→ curso publicado
→ matrícula ativa
→ recurso publicado
→ recurso pertence ao curso da matrícula
→ regra de desbloqueio/progresso
```

Não confie em `course_id` enviado por formulário, query string, JavaScript ou URL sem confrontá-lo com o relacionamento real do recurso no banco.

## Rotas

As rotas contextualizadas são preferidas:

```text
/curso/{courseId}/modulo/{id}
/curso/{courseId}/fase/{id}
/curso/{courseId}/aula/{id}
/curso/{courseId}/exercicios
/curso/{courseId}/simulados
/curso/{courseId}/anotacoes
/curso/{courseId}/laboratorio/atividade/{id}
```

As rotas históricas continuam temporariamente para compatibilidade do PMMT.

Os middlewares de módulo, fase e aula verificam se `courseId` da URL corresponde ao curso real do recurso.

## Como adicionar um novo curso

1. Registre a experiência em `config/course_experiences.php`.
2. Crie um namespace de assets em `public/assets/courses/<chave>/`.
3. Crie um layout em `app/Views/layouts/courses/`.
4. Cadastre o curso com o `experience_key` correto.
5. Mantenha o curso em `draft` durante construção e testes.
6. Use `CourseAccessService` para toda nova rota de estudante.
7. Prefira URLs contextualizadas por curso.
8. Nunca execute código do estudante no banco MySQL principal.
9. Antes de publicar, teste com:
   - aluno sem matrícula;
   - matrícula ativa;
   - matrícula cancelada;
   - URL adulterada com courseId diferente;
   - recurso de outro curso;
   - usuário administrador e estudante.

## Migração

A coluna `courses.experience_key` é adicionada por:

```bash
php database/aplicar_isolamento_cursos_v2.php
```

O script é dry-run por padrão.

Em produção, somente após backup e revisão:

```bash
php database/aplicar_isolamento_cursos_v2.php --apply --force-production
```

O backfill marca automaticamente o Preparatório PMMT como `pmmt` e o curso PostgreSQL como `postgresql`.

## Compatibilidade

Não remova as rotas antigas nem mova os assets legados do PMMT de uma só vez.

A estratégia é:

```text
adicionar estrutura nova
→ testar
→ migrar links
→ observar
→ remover compatibilidade antiga em versão futura
```

Isso reduz regressões em um curso que já está funcional.
