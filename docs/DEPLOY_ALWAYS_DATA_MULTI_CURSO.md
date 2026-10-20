# Deploy seguro da fundação multi-curso no Alwaysdata

Este procedimento pressupõe que o site atual está em `~/www/cursomi` e que a branch de trabalho é `feature/multi-course-platform`.

A ideia é **não trocar imediatamente o código do site em produção**. Primeiro clonamos a branch em uma pasta separada, usamos o mesmo `.env` apenas para executar lint e migrações e mantemos o curso PostgreSQL em rascunho.

## 1. Acessar o SSH

No computador local:

```bash
ssh ldcode@ssh-ldcode.alwaysdata.net
```

## 2. Conferir o site atual

```bash
cd ~/www/cursomi
git status
git branch --show-current
git log -1 --oneline
php -v
```

Não continue se houver alterações locais inesperadas no repositório.

## 3. Criar backup dos arquivos

```bash
mkdir -p ~/backups
cp -a ~/www/cursomi ~/backups/cursomi_code_$(date +%Y%m%d_%H%M%S)
```

## 4. Conferir dados de conexão sem mostrar a senha

```bash
cd ~/www/cursomi
grep -E '^DB_(HOST|PORT|DATABASE|USERNAME)=' .env
```

## 5. Backup do MySQL/MariaDB

Use os valores mostrados no passo anterior. O `-p` pedirá a senha sem exibi-la no comando:

```bash
mysqldump -h SEU_DB_HOST -P SEU_DB_PORT -u SEU_DB_USERNAME -p SEU_DB_DATABASE > ~/backups/curso_db_$(date +%Y%m%d_%H%M%S).sql
```

Confirme que o arquivo foi criado:

```bash
ls -lh ~/backups/curso_db_*.sql | tail
```

## 6. Clonar a branch em uma pasta separada

```bash
cd ~/www
git clone --branch feature/multi-course-platform --single-branch https://github.com/luciano-dias-mota/curso.git cursomi-multicurso
cp ~/www/cursomi/.env ~/www/cursomi-multicurso/.env
cd ~/www/cursomi-multicurso
composer install --no-dev --optimize-autoloader
```

Se a pasta `~/www/cursomi-multicurso` já existir de um teste anterior, não use `rm -rf` às cegas. Entre nela, confira `git status` e atualize conscientemente.

## 7. Configurar PGlite

A configuração já possui fallback pinado, mas é melhor deixar explícito no ambiente:

```bash
grep -q '^PGLITE_MODULE_URL=' .env || printf '\nPGLITE_MODULE_URL="https://cdn.jsdelivr.net/npm/@electric-sql/pglite@0.5.10/dist/index.js"\n' >> .env
```

## 8. Validar a sintaxe PHP

```bash
find app config routes database -name '*.php' -print0 | xargs -0 -n1 php -l
```

Não aplique migrações se aparecer qualquer erro de sintaxe.

## 9. Validar e aplicar em ordem de dependência

A criação do curso depende de `course_settings`, que nasce na fundação multi-curso. A grade depende do próprio curso. Por isso, valide e aplique etapa por etapa.

### 9.1 Fundação multi-curso

Dry-run:

```bash
php database/aplicar_plataforma_multicursos_v1.php
```

Se estiver correto:

```bash
php database/aplicar_plataforma_multicursos_v1.php --apply --force-production
```

Rode o dry-run novamente e confirme que tudo aparece como OK.

### 9.2 Motor de laboratórios

Dry-run:

```bash
php database/aplicar_motor_laboratorios_v1.php
```

Se estiver correto:

```bash
php database/aplicar_motor_laboratorios_v1.php --apply --force-production
```

Rode o dry-run novamente e confirme que tudo aparece como OK.

### 9.3 Curso PostgreSQL

Somente depois da fundação:

```bash
php database/criar_curso_postgresql_v1.php
```

Se a previsão estiver correta:

```bash
php database/criar_curso_postgresql_v1.php --apply --force-production
```

### 9.4 Grade PostgreSQL

Somente depois que o curso existir:

```bash
php database/criar_grade_postgresql_v1.php
```

Se a previsão estiver correta:

```bash
php database/criar_grade_postgresql_v1.php --apply --force-production
```

## 10. Rodar os dry-runs novamente

```bash
php database/aplicar_plataforma_multicursos_v1.php
php database/aplicar_motor_laboratorios_v1.php
php database/criar_curso_postgresql_v1.php
php database/criar_grade_postgresql_v1.php
```

Agora a saída deve indicar que a fundação/curso/grade já existem ou estão corretos.

## O que será inserido

As duas primeiras migrações evoluem a plataforma para multi-curso e adicionam o Motor Genérico de Laboratórios.

O terceiro script cria, em rascunho:

- PostgreSQL — Do Zero ao Intermediário;
- configuração `postgresql_pglite`;
- 9 módulos-base.

O quarto cria, também em rascunho:

- 18 fases;
- 68 aulas-shell;
- `completion_mode=lab`.

Nenhuma dessas estruturas do PostgreSQL aparece ao estudante porque curso, módulos, fases e aulas permanecem em `draft`.

## Ativação do novo código

Aplicar o banco **não substitui automaticamente o código do site atual**. O portal multi-curso só aparece quando a nova branch for aprovada e implantada.

A opção mais segura é criar um segundo site de teste no painel Alwaysdata apontando para:

```
/home/ldcode/www/cursomi-multicurso/public
```

Depois do smoke test e do merge da PR no `main`, o site de produção pode voltar ao fluxo normal de atualização:

```bash
cd ~/www/cursomi
git status
git switch main
git pull --ff-only origin main
composer install --no-dev --optimize-autoloader
```

Não troque o site de produção para a feature branch antes dos testes.

## 12. Aplicar isolamento de experiências V2

Depois que a fundação multi-curso V1 já estiver instalada, a evolução seguinte adiciona uma chave explícita de experiência por curso.

Primeiro atualize a branch e rode o dry-run:

```bash
cd ~/www/cursomi-multicurso
git pull origin feature/multi-course-platform

php database/aplicar_isolamento_cursos_v2.php
```

O dry-run deve prever:

```text
courses.experience_key                         CRIAR/AJUSTAR
courses.idx_courses_experience_status          CRIAR/AJUSTAR
Backfill previsto: cursos PMMT -> pmmt; curso PostgreSQL -> postgresql.
```

A migração não publica cursos nem altera matrículas.

Depois do backup já validado e da revisão do dry-run:

```bash
php database/aplicar_isolamento_cursos_v2.php --apply --force-production
```

Confirme:

```bash
php database/aplicar_isolamento_cursos_v2.php
```

O esperado é:

```text
courses.experience_key                         OK
courses.idx_courses_experience_status          OK
Cursos experience_key=pmmt: 1
Cursos experience_key=postgresql: 1
```

O site atual em `~/www/cursomi` pode continuar no `main`: a coluna nova é aditiva e o código antigo simplesmente a ignora.

A branch multi-curso mais recente só deve ser testada pelo navegador **depois** dessa V2, pois portal, admin e autorização passam a ler `courses.experience_key`.
