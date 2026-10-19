# Motor Genérico de Laboratórios — Curso MI

## Objetivo

Esta camada permite que o Curso MI tenha atividades práticas executáveis sem acoplar o núcleo da plataforma a PostgreSQL, JavaScript, PHP, Linux ou qualquer tecnologia específica.

A hierarquia acadêmica continua sendo:

```
Curso -> Módulo -> Fase -> Aula -> Atividade prática
```

Cada atividade declara uma `engine_key`. O núcleo conhece somente o contrato da engine. O provider concreto será adicionado quando o respectivo curso precisar dele.

## Componentes

- `App\\Lab\\LabEngineInterface`: contrato obrigatório de qualquer motor.
- `App\\Lab\\LabEngineRegistry`: carrega providers configurados em `config/lab.php`.
- `App\\Services\\LabService`: controla acesso, tentativas, dicas, progresso, XP e estado versionado.
- `App\\Services\\LessonCompletionPolicyService`: decide se uma aula termina por leitura, quiz ou laboratório.
- `App\\Services\\LessonCompletionService`: conclui uma aula prática e preserva a progressão acadêmica.
- `lab-runtime.js`: ponte genérica entre a tela de laboratório e o runtime específico do navegador.

## Tabelas

### lab_activities
Define a atividade, a engine, instruções, código inicial, regras de validação, estado esperado, XP e obrigatoriedade.

### lab_activity_hints
Dicas progressivas independentes da tecnologia. Cada dica pode exigir um número mínimo de tentativas e reduzir o XP disponível.

### lab_attempts
Histórico imutável das submissões e resultados validados pela engine.

### lab_activity_progress
Estado resumido por aluno/atividade: tentativas, dicas, melhor nota e conclusão.

### lab_course_state
Registra a versão do estado prático por aluno, curso e engine. Serve para reconstrução de laboratórios acumulativos sem armazenar obrigatoriamente o banco/ambiente inteiro no MySQL.

## Regras de segurança

1. O cliente nunca conclui uma atividade enviando simplesmente `passed=true`.
2. Toda aprovação passa pelo provider registrado em `LabEngineInterface::evaluate()`.
3. O núcleo limita o tamanho de submissões e resultados.
4. O SQL/código do aluno não é executado no banco principal do Curso MI.
5. Assets de uma engine devem ser arquivos estáticos controlados pela aplicação.
6. XP e conclusão são gravados pelo backend após a avaliação da engine.

## Políticas genéricas de progressão

Para impedir que a plataforma fique acoplada ao modelo de concursos, cada curso define como sua progressão fecha cada nível.

- `lesson_completion_mode`: `reading`, `quiz` ou `lab`. O padrão é `quiz`.
- `phase_completion_mode`: `quiz` ou `lessons`. O padrão é `quiz`.
- `module_completion_mode`: `quiz` ou `phases`. O padrão é `quiz`.

Uma aula também pode sobrescrever `lesson_completion_mode` em `lessons.completion_mode`.

Assim, o PMMT preserva aula → exercício, fase → checkpoint e módulo → avaliação final. Um futuro curso de programação pode usar aula → laboratório e avançar fase/módulo automaticamente pelas atividades concluídas.

## Como adicionar uma nova tecnologia

1. Criar uma classe que implemente `LabEngineInterface`.
2. Registrar a classe em `config/lab.php`.
3. Adicionar os assets locais do runtime, quando houver execução no navegador.
4. Criar atividades com a `engine_key` do provider.
5. Definir a política de conclusão do curso/aula.

Exemplo futuro (não implementado nesta fundação):

```php
'engines' => [
    App\\Lab\\Engines\\AlgumEngine::class,
],
```

## PostgreSQL

Nenhum provider PostgreSQL, PGlite, aula, seed, desafio ou gabarito foi criado nesta etapa. Isso é intencional: a fundação deve permanecer genérica.

Quando a metodologia do curso PostgreSQL for materializada, o PGlite poderá ser implementado como um provider do motor, sem alterar o núcleo multi-curso.

## Migração

Dry-run:

```bash
php database/aplicar_motor_laboratorios_v1.php
```

Produção, somente depois de backup e revisão:

```bash
php database/aplicar_motor_laboratorios_v1.php --apply --force-production
```
