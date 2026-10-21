<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);

$apply = in_array('--apply', $argv ?? [], true);
$forceProduction = in_array('--force-production', $argv ?? [], true);
$isProduction = strtolower((string) config('app.env', 'production')) === 'production';

if ($apply && $isProduction && !$forceProduction) {
    fwrite(
        STDERR,
        "ABORTADO: APP_ENV=production. Revise o dry-run, faça backup e use --apply --force-production.\n"
    );
    exit(1);
}

$pdo = Database::connection();
$courseSlug = 'postgresql-zero-intermediario';

$grade = [
    [
        'slug' => 'primeiros-passos-postgresql',
        'title' => 'Módulo 0 — Primeiros Passos no PostgreSQL',
        'description' => 'Fundamentos de banco de dados, PostgreSQL, tabelas, SQL, ferramentas e leitura de erros para quem começa do zero.',
        'icon' => '🐘',
        'position' => 1,
        'phases' => [
            [
                'slug' => 'fundamentos-banco-postgresql',
                'title' => 'Fundamentos de Banco de Dados e PostgreSQL',
                'description' => 'Construa o vocabulário essencial e entenda como sistemas, PostgreSQL, tabelas e dados se conectam.',
                'lessons' => [
                    ['banco-de-dados-e-postgresql', 'Banco de dados e PostgreSQL: o que são', 'Diferencie dado, banco de dados, SGBD, PostgreSQL e SQL e entenda onde cada elemento aparece em um sistema real.', 14],
                    ['tabelas-linhas-e-colunas', 'Tabelas, linhas e colunas', 'Visualize registros e atributos como uma estrutura organizada e aprenda a ler uma tabela corretamente.', 13],
                    ['sql-e-ferramentas', 'SQL, pgAdmin, psql e o laboratório do curso', 'Entenda que SQL é a linguagem e que pgAdmin, psql e o laboratório são formas diferentes de conversar com o PostgreSQL.', 14],
                ],
            ],
            [
                'slug' => 'primeiro-contato-sql',
                'title' => 'Primeiro Contato com SQL',
                'description' => 'Execute as primeiras consultas e aprenda a interpretar resultados e mensagens de erro.',
                'lessons' => [
                    ['primeiro-select', 'Seu primeiro SELECT', 'Use SELECT para pedir informações ao PostgreSQL e interpretar o conjunto de resultados.', 14],
                    ['consultando-tabela-pronta', 'Consultando uma tabela pronta', 'Use SELECT ... FROM para consultar dados armazenados em uma tabela preparada.', 15],
                    ['lendo-erros-sql', 'Como ler erros e aprender com eles', 'Identifique tipo, local e provável causa de erros SQL e desenvolva uma rotina segura de correção.', 15],
                ],
            ],
        ],
    ],
    [
        'slug' => 'consultando-dados-select',
        'title' => 'Módulo 1 — Consultando Dados com SELECT',
        'description' => 'Seleção de colunas, aliases, DISTINCT, filtros, operadores, NULL, ordenação e paginação para localizar dados sem alterar o banco.',
        'icon' => '🔎',
        'position' => 2,
        'phases' => [
            [
                'slug' => 'select-projecao',
                'title' => 'SELECT e Projeção de Dados',
                'description' => 'Escolha exatamente o que deseja exibir e produza resultados mais claros para sistemas e relatórios.',
                'lessons' => [
                    ['select-colunas', 'Selecionando colunas com SELECT', 'Selecione apenas as colunas necessárias e entenda por que SELECT * nem sempre é uma boa escolha profissional.', 16],
                    ['aliases-as', 'Aliases com AS', 'Renomeie colunas e prepare aliases legíveis para consultas e relatórios.', 15],
                    ['distinct', 'Removendo duplicidades com DISTINCT', 'Elimine valores repetidos do resultado e entenda quando DISTINCT resolve o problema — e quando apenas esconde uma modelagem ruim.', 15],
                ],
            ],
            [
                'slug' => 'filtros-ordenacao',
                'title' => 'Filtros, Condições e Ordenação',
                'description' => 'Localize exatamente os registros necessários e controle como o resultado é apresentado.',
                'lessons' => [
                    ['where-comparacoes', 'WHERE e operadores de comparação', 'Filtre registros com igualdade, diferença e comparações numéricas ou temporais.', 17],
                    ['and-or-not', 'Combinando condições com AND, OR e NOT', 'Monte regras compostas com segurança, entendendo precedência e uso de parênteses.', 18],
                    ['like-in-between', 'LIKE, ILIKE, IN e BETWEEN', 'Pesquise textos, listas de valores e intervalos usando operadores adequados ao PostgreSQL.', 18],
                    ['is-null', 'NULL, IS NULL e IS NOT NULL', 'Entenda a ausência de valor e evite o erro clássico de comparar NULL com sinal de igualdade.', 16],
                    ['order-by-limit', 'ORDER BY, LIMIT e OFFSET', 'Ordene resultados, limite quantidade de linhas e implemente paginação básica.', 17],
                ],
            ],
        ],
    ],
    [
        'slug' => 'construindo-banco-ld-informatica',
        'title' => 'Módulo 2 — Construindo o Banco LD Informática',
        'description' => 'Criação de tabelas, escolha de tipos, datas, IDs e constraints para construir estruturas confiáveis.',
        'icon' => '🏗️',
        'position' => 3,
        'phases' => [
            [
                'slug' => 'criando-estrutura',
                'title' => 'Criando Estruturas',
                'description' => 'Transforme requisitos de um sistema em tabelas PostgreSQL com tipos adequados e identificadores consistentes.',
                'lessons' => [
                    ['create-table', 'Criando tabelas com CREATE TABLE', 'Crie tabelas e transforme um desenho de dados em uma estrutura executável no PostgreSQL.', 18],
                    ['tipos-de-dados', 'Tipos de dados no PostgreSQL', 'Escolha tipos de dados pensando em significado, validação, armazenamento e uso futuro.', 20],
                    ['datas-horarios-fuso', 'Datas, horários e fuso no PostgreSQL', 'Trabalhe com DATE, TIMESTAMP, TIMESTAMPTZ, CURRENT_DATE e NOW entendendo impactos de fuso horário em sistemas reais.', 20],
                    ['identity', 'Colunas IDENTITY e geração de IDs', 'Crie identificadores automáticos com GENERATED ... AS IDENTITY e entenda quando evitar IDs manuais.', 17],
                ],
            ],
            [
                'slug' => 'restricoes-estrutura',
                'title' => 'Restrições e Qualidade dos Dados',
                'description' => 'Imponha regras diretamente no banco para impedir dados inválidos e manter consistência.',
                'lessons' => [
                    ['primary-key', 'PRIMARY KEY', 'Defina a identidade única de cada registro e entenda o papel da chave primária na modelagem.', 16],
                    ['not-null-unique-default', 'NOT NULL, UNIQUE e DEFAULT', 'Use constraints para impedir ausência indevida, duplicidade e valores inconsistentes.', 19],
                    ['check', 'Validando regras com CHECK', 'Implemente regras de domínio e negócio simples diretamente no PostgreSQL.', 18],
                    ['alter-table', 'Evoluindo tabelas com ALTER TABLE', 'Altere uma estrutura existente com cuidado, avaliando impacto sobre dados e aplicações.', 19],
                ],
            ],
        ],
    ],
    [
        'slug' => 'crud-na-pratica',
        'title' => 'Módulo 3 — CRUD na Prática',
        'description' => 'Cadastro, consulta, alteração, exclusão, retorno de dados e importação em lote com foco em segurança operacional.',
        'icon' => '✍️',
        'position' => 4,
        'phases' => [
            [
                'slug' => 'inserindo-dados',
                'title' => 'Inserindo Dados',
                'description' => 'Cadastre registros individualmente e em lote e recupere imediatamente os dados gravados.',
                'lessons' => [
                    ['insert', 'INSERT: cadastrando dados', 'Cadastre registros informando explicitamente as colunas e valide o resultado.', 17],
                    ['insert-multiplas-linhas', 'Inserindo várias linhas', 'Insira vários registros em uma única instrução e entenda quando isso melhora produtividade.', 17],
                    ['returning', 'RETURNING: vendo o que acabou de mudar', 'Use RETURNING para obter IDs e valores gravados sem executar uma consulta extra.', 16],
                    ['copy-csv', 'Importação e exportação com COPY e CSV', 'Entenda COPY e \\copy, estrutura de CSV, delimitadores, cabeçalhos e cuidados em cargas de dados.', 21],
                ],
            ],
            [
                'slug' => 'alterando-excluindo-dados',
                'title' => 'Alterando e Excluindo com Segurança',
                'description' => 'Atualize e exclua registros com conferência prévia, filtros seguros e validação posterior.',
                'lessons' => [
                    ['update', 'UPDATE: alterando registros', 'Atualize dados existentes e compreenda como o WHERE determina o alcance da alteração.', 18],
                    ['delete', 'DELETE: removendo registros', 'Remova registros com consciência de relacionamentos, filtros e impacto operacional.', 18],
                    ['where-seguro', 'WHERE seguro em UPDATE e DELETE', 'Adote o padrão profissional SELECT de conferência → alteração → SELECT de validação.', 20],
                    ['mini-crud', 'Desafio CRUD completo', 'Integre INSERT, SELECT, UPDATE e DELETE em um fluxo semelhante a uma demanda real de sistema.', 25],
                ],
            ],
        ],
    ],
    [
        'slug' => 'relacionamentos-integridade',
        'title' => 'Módulo 4 — Relacionamentos e Integridade',
        'description' => 'Modelagem relacional, normalização básica, chaves estrangeiras e integridade referencial para evitar dados órfãos e repetição desnecessária.',
        'icon' => '🔗',
        'position' => 5,
        'phases' => [
            [
                'slug' => 'modelando-relacionamentos',
                'title' => 'Modelando Relacionamentos',
                'description' => 'Transforme relações do mundo real em tabelas conectadas de forma clara e sustentável.',
                'lessons' => [
                    ['conceito-relacionamentos', 'Por que relacionar tabelas', 'Separe responsabilidades e evite repetição desnecessária de dados.', 16],
                    ['normalizacao-basica', 'Normalização básica: 1FN, 2FN e 3FN na prática', 'Reconheça repetição e dependências simples e organize dados sem aprofundamento acadêmico excessivo.', 22],
                    ['um-para-muitos', 'Relacionamento 1:N', 'Modele situações em que um registro pai possui vários registros filhos.', 18],
                    ['muitos-para-muitos', 'Relacionamento N:N e tabela associativa', 'Resolva relações muitos-para-muitos usando uma tabela associativa.', 20],
                ],
            ],
            [
                'slug' => 'foreign-keys-integridade',
                'title' => 'Foreign Keys e Integridade Referencial',
                'description' => 'Use o PostgreSQL para impedir referências inválidas e controlar alterações relacionadas.',
                'lessons' => [
                    ['foreign-key', 'FOREIGN KEY na prática', 'Crie chaves estrangeiras e conecte registros preservando referências válidas.', 19],
                    ['integridade-referencial', 'Entendendo integridade referencial', 'Entenda registros pai/filho e por que o banco rejeita referências inexistentes.', 18],
                    ['on-delete-on-update', 'ON DELETE e ON UPDATE', 'Escolha conscientemente entre RESTRICT/NO ACTION, CASCADE e SET NULL conforme a regra de negócio.', 21],
                ],
            ],
        ],
    ],
    [
        'slug' => 'joins-consultas-multiplas-tabelas',
        'title' => 'Módulo 5 — JOINs e Consultas com Múltiplas Tabelas',
        'description' => 'Combine tabelas relacionadas e produza relatórios operacionais usando JOINs, filtros e operações de conjunto.',
        'icon' => '🧩',
        'position' => 6,
        'phases' => [
            [
                'slug' => 'joins-fundamentais',
                'title' => 'JOINs Fundamentais',
                'description' => 'Entenda visualmente como registros de tabelas diferentes são combinados por chaves relacionadas.',
                'lessons' => [
                    ['inner-join', 'INNER JOIN', 'Retorne apenas registros que possuem correspondência dos dois lados da relação.', 20],
                    ['left-join', 'LEFT JOIN', 'Preserve todos os registros da tabela esquerda e identifique também ausências de correspondência.', 21],
                    ['right-full-join', 'RIGHT JOIN e FULL JOIN', 'Entenda quando preservar o lado direito ou ambos os lados e quando LEFT JOIN pode ser mais legível.', 21],
                ],
            ],
            [
                'slug' => 'relatorios-multiplas-tabelas',
                'title' => 'Relatórios com Múltiplas Tabelas',
                'description' => 'Aproxime o SQL de demandas reais de suporte, vendas e operação.',
                'lessons' => [
                    ['multiplos-joins', 'Combinando vários JOINs', 'Combine cliente, pedido, item e produto em uma única consulta legível.', 23],
                    ['joins-com-filtros', 'JOINs com filtros e ordenação', 'Misture ON, WHERE e ORDER BY sem perder clareza nem alterar a semântica do JOIN.', 22],
                    ['union-intersect-except', 'UNION, INTERSECT e EXCEPT', 'Combine conjuntos de resultados e diferencie operações de conjunto de JOINs.', 22],
                    ['relatorio-os-vendas', 'Relatório integrado da LD Informática', 'Construa um relatório operacional combinando cliente, equipamento, ordem de serviço, técnico e valores.', 28],
                ],
            ],
        ],
    ],
    [
        'slug' => 'agregacoes-sql-intermediario',
        'title' => 'Módulo 6 — Agregações e SQL Intermediário',
        'description' => 'Indicadores, agrupamentos, regras, subconsultas, CTEs, funções úteis, window functions e JSONB para consultas profissionais.',
        'icon' => '📊',
        'position' => 7,
        'phases' => [
            [
                'slug' => 'agregacoes-grupos',
                'title' => 'Agregações e Grupos',
                'description' => 'Transforme registros individuais em indicadores e resumos úteis para decisão.',
                'lessons' => [
                    ['funcoes-agregacao', 'COUNT, SUM, AVG, MIN e MAX', 'Calcule quantidades, totais, médias e extremos para responder perguntas de negócio.', 20],
                    ['group-by', 'GROUP BY', 'Agrupe registros por categoria, técnico, cliente ou período para produzir indicadores.', 20],
                    ['having', 'HAVING: filtrando grupos', 'Filtre grupos depois da agregação e diferencie HAVING de WHERE.', 19],
                ],
            ],
            [
                'slug' => 'consultas-intermediarias',
                'title' => 'Consultas Intermediárias',
                'description' => 'Resolva consultas mais expressivas sem perder legibilidade e preserve contexto quando necessário.',
                'lessons' => [
                    ['case', 'CASE: regras dentro da consulta', 'Crie classificações e regras condicionais diretamente no resultado.', 19],
                    ['subconsultas', 'Subconsultas', 'Use uma consulta como parte de outra para responder problemas em etapas.', 22],
                    ['cte-with', 'CTEs com WITH', 'Quebre consultas complexas em partes nomeadas e mais fáceis de testar.', 22],
                    ['funcoes-texto', 'Funções de texto', 'Use LOWER, UPPER, TRIM, CONCAT, LENGTH e REPLACE para limpar e apresentar textos.', 21],
                    ['funcoes-data-hora', 'Funções de data e hora', 'Trabalhe com NOW, CURRENT_DATE, intervalos, diferenças e extração de partes de datas.', 22],
                    ['cast-coalesce', 'CAST e COALESCE', 'Converta tipos e trate valores NULL com clareza e previsibilidade.', 20],
                    ['on-conflict', 'UPSERT com ON CONFLICT', 'Insira ou atualize registros quando ocorrer conflito em chave ou restrição UNIQUE.', 22],
                    ['window-functions', 'Funções de janela: OVER, ROW_NUMBER e RANK', 'Calcule rankings, acumulados e indicadores por grupo mantendo as linhas individuais no resultado.', 27],
                    ['jsonb-pratica', 'JSONB na prática', 'Armazene e consulte dados semiestruturados e decida quando JSONB faz sentido em vez de colunas relacionais.', 25],
                ],
            ],
        ],
    ],
    [
        'slug' => 'recursos-profissionais-postgresql',
        'title' => 'Módulo 7 — PostgreSQL Profissional: Consistência, Performance, Segurança e Operação',
        'description' => 'Transações, views, índices, planos de execução, manutenção, monitoramento, schemas, roles, privilégios e backup para aproximar o aluno do ambiente profissional.',
        'icon' => '🛠️',
        'position' => 8,
        'phases' => [
            [
                'slug' => 'consistencia-performance',
                'title' => 'Consistência, Performance e Observabilidade',
                'description' => 'Proteja operações, entenda caminhos de execução e observe o comportamento básico de uma instância PostgreSQL.',
                'lessons' => [
                    ['transactions', 'Transações: BEGIN, COMMIT e ROLLBACK', 'Garanta que operações relacionadas sejam confirmadas juntas ou desfeitas em caso de problema.', 22],
                    ['views', 'Views', 'Encapsule consultas reutilizáveis e entenda benefícios e limites de uma view.', 20],
                    ['indexes', 'Índices: quando e por que usar', 'Entenda B-tree, custo de leitura e escrita e por que criar índices indiscriminadamente pode prejudicar o sistema.', 24],
                    ['explain', 'EXPLAIN e EXPLAIN ANALYZE', 'Leia Seq Scan, Index Scan, custos estimados e tempos reais entendendo que EXPLAIN ANALYZE executa a consulta.', 26],
                    ['vacuum-analyze', 'VACUUM, ANALYZE e autovacuum', 'Entenda MVCC em nível prático, estatísticas e por que manutenção automática é essencial ao PostgreSQL.', 24],
                    ['pg-stat-activity', 'Monitoramento básico com pg_stat_activity', 'Identifique sessões e consultas ativas e reconheça sinais iniciais de bloqueio ou atividade anormal.', 25],
                ],
            ],
            [
                'slug' => 'organizacao-seguranca',
                'title' => 'Organização, Acesso e Operação',
                'description' => 'Organize objetos, aplique menor privilégio e entenda rotinas fundamentais de backup e restauração.',
                'lessons' => [
                    ['schemas', 'Schemas', 'Organize objetos em namespaces e compreenda o papel introdutório do search_path.', 19],
                    ['roles', 'Roles e usuários', 'Entenda roles com e sem LOGIN e aplique o princípio do menor privilégio.', 22],
                    ['grant-revoke', 'GRANT e REVOKE', 'Conceda e remova privilégios de forma controlada em tabelas e schemas.', 23],
                    ['sequences-identity', 'Sequences e revisão de IDENTITY', 'Entenda sequences, geração de valores e a relação com colunas IDENTITY.', 20],
                    ['backup-restore-intro', 'Backup e restauração: pg_dump e pg_restore', 'Entenda backup lógico, formatos plain/custom e por que um backup só é confiável depois de um teste de restauração.', 28],
                ],
            ],
        ],
    ],
    [
        'slug' => 'projeto-final-ld-informatica',
        'title' => 'Módulo 8 — Projeto Final: Banco LD Informática',
        'description' => 'Integre modelagem, SQL, segurança e operação em um banco completo semelhante a uma demanda profissional.',
        'icon' => '🏁',
        'position' => 9,
        'phases' => [
            [
                'slug' => 'construindo-projeto-final',
                'title' => 'Construindo o Banco Final',
                'description' => 'Planeje e implemente a estrutura completa antes de começar a escrever relatórios.',
                'lessons' => [
                    ['desenho-schema-final', 'Planejando o schema final', 'Transforme requisitos em entidades, relacionamentos, chaves e regras antes de criar tabelas.', 25],
                    ['criando-tabelas-principais', 'Criando as tabelas principais', 'Implemente clientes, técnicos, equipamentos, ordens de serviço, produtos, serviços e itens.', 30],
                    ['relacionamentos-regras-final', 'Relacionamentos e regras de integridade', 'Aplique PK, FK, UNIQUE, CHECK e NOT NULL de forma coerente com as regras do projeto.', 30],
                    ['carga-dados-projeto', 'Carregando dados de teste', 'Crie uma massa de dados coerente para validar consultas, relatórios e regras.', 25],
                ],
            ],
            [
                'slug' => 'desafio-integrador',
                'title' => 'Desafio Integrador',
                'description' => 'Resolva demandas com autonomia, explique decisões e diagnostique problemas sem receber a solução pronta.',
                'lessons' => [
                    ['relatorios-operacionais', 'Relatórios operacionais', 'Produza relatórios de ordens abertas, técnicos, clientes, equipamentos e faturamento.', 30],
                    ['consultas-analiticas', 'Consultas analíticas', 'Crie indicadores, agrupamentos, rankings e análises usando recursos intermediários.', 30],
                    ['debug-sql', 'Encontrando e corrigindo SQL com erro', 'Diagnostique JOINs, filtros, NULLs, tipos e sintaxe incorreta como em um chamado real de suporte.', 28],
                    ['desafio-final', 'Desafio final do banco LD Informática', 'Receba demandas sem código pronto e escolha modelagem, consulta e alteração adequadas com segurança.', 40],
                    ['revisao-proximos-passos', 'Revisão e próximos passos no PostgreSQL', 'Revise as competências intermediárias e identifique próximos caminhos em DBA, backend, dados/BI e DevOps.', 20],
                ],
            ],
        ],
    ],
];

$courseStmt = $pdo->prepare(
    "SELECT id, title, status, experience_key
       FROM courses
      WHERE slug = :slug
      LIMIT 1"
);
$courseStmt->execute(['slug' => $courseSlug]);
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR, "ABORTADO: curso PostgreSQL não encontrado.\n");
    exit(2);
}

if ((string) $course['experience_key'] !== 'postgresql') {
    fwrite(STDERR, "ABORTADO: o curso encontrado não usa experience_key=postgresql.\n");
    exit(3);
}

$courseId = (int) $course['id'];
$desiredLessons = 0;
$desiredPhases = 0;
$newLessons = [];
$unexpectedLessons = [];

$moduleFind = $pdo->prepare(
    "SELECT id, title, status
       FROM modules
      WHERE course_id = :course_id
        AND slug = :slug
      LIMIT 1"
);
$phaseFind = $pdo->prepare(
    "SELECT id, title, status
       FROM phases
      WHERE module_id = :module_id
        AND slug = :slug
      LIMIT 1"
);
$lessonSlugsStmt = $pdo->prepare(
    "SELECT id, slug, title, status
       FROM lessons
      WHERE phase_id = :phase_id
      ORDER BY position, id"
);

echo "=================================================================\n";
echo " LD ACADEMY - GRADE MESTRA POSTGRESQL V2\n";
echo "=================================================================\n";
printf("Banco: %s\n", (string) $pdo->query('SELECT DATABASE()')->fetchColumn());
printf("Curso: #%d - %s [%s]\n", $courseId, (string) $course['title'], (string) $course['status']);
printf("Modo:  %s\n\n", $apply ? 'APLICAÇÃO' : 'DRY-RUN');

foreach ($grade as $moduleDef) {
    $moduleFind->execute([
        'course_id' => $courseId,
        'slug' => $moduleDef['slug'],
    ]);
    $module = $moduleFind->fetch(PDO::FETCH_ASSOC);

    if (!$module) {
        fwrite(
            STDERR,
            "ABORTADO: módulo-base ausente: {$moduleDef['slug']}. Execute primeiro criar_curso_postgresql_v1.php.\n"
        );
        exit(4);
    }

    printf("%s\n", $moduleDef['title']);

    foreach ($moduleDef['phases'] as $phaseDef) {
        $desiredPhases++;
        $desiredLessons += count($phaseDef['lessons']);

        $phaseFind->execute([
            'module_id' => (int) $module['id'],
            'slug' => $phaseDef['slug'],
        ]);
        $phase = $phaseFind->fetch(PDO::FETCH_ASSOC);

        if (!$phase) {
            fwrite(
                STDERR,
                "ABORTADO: fase-base ausente: {$moduleDef['slug']} / {$phaseDef['slug']}.\n"
            );
            exit(5);
        }

        $desiredSlugs = array_column($phaseDef['lessons'], 0);
        $lessonSlugsStmt->execute(['phase_id' => (int) $phase['id']]);
        $currentRows = $lessonSlugsStmt->fetchAll(PDO::FETCH_ASSOC);
        $currentBySlug = [];

        foreach ($currentRows as $row) {
            $currentBySlug[(string) $row['slug']] = $row;

            if (!in_array((string) $row['slug'], $desiredSlugs, true)
                && (string) $row['status'] !== 'archived') {
                $unexpectedLessons[] = [
                    'module' => $moduleDef['title'],
                    'phase' => $phaseDef['title'],
                    'lesson' => (string) $row['title'],
                    'slug' => (string) $row['slug'],
                ];
            }
        }

        $missingInPhase = 0;

        foreach ($phaseDef['lessons'] as [$slug]) {
            if (!isset($currentBySlug[$slug])) {
                $newLessons[] = $moduleDef['slug'] . ' / ' . $slug;
                $missingInPhase++;
            }
        }

        printf(
            "  %-44s %2d aulas | novas: %d\n",
            $phaseDef['title'],
            count($phaseDef['lessons']),
            $missingInPhase
        );
    }
}

printf("\nGrade desejada: %d módulos, %d fases, %d aulas.\n", count($grade), $desiredPhases, $desiredLessons);
printf("Novas aulas a criar: %d.\n", count($newLessons));

foreach ($newLessons as $lessonRef) {
    echo "  + {$lessonRef}\n";
}

if ($unexpectedLessons !== []) {
    echo "\nATENÇÃO: foram encontradas aulas ativas que não pertencem à Grade V2:\n";
    foreach ($unexpectedLessons as $item) {
        printf(
            "  - %s / %s / %s [%s]\n",
            $item['module'],
            $item['phase'],
            $item['lesson'],
            $item['slug']
        );
    }

    fwrite(
        STDERR,
        "\nABORTADO por segurança. Arquive ou incorpore essas aulas à grade antes de aplicar.\n"
    );
    exit(6);
}

if (!$apply) {
    echo "\nDRY-RUN concluído. Nenhuma alteração foi realizada.\n";
    echo "O script preserva status, blocos e laboratórios das aulas existentes.\n";
    echo "As aulas novas serão criadas como draft e completion_mode=lab.\n";
    echo "Após backup, execute:\n";
    echo "php database/aplicar_grade_mestra_postgresql_v2.php --apply";
    if ($isProduction) {
        echo " --force-production";
    }
    echo "\n";
    exit(0);
}

$pdo->beginTransaction();

try {
    $updateCourse = $pdo->prepare(
        "UPDATE courses
            SET short_description = :short_description,
                description = :description,
                difficulty = 'intermediate',
                updated_at = NOW()
          WHERE id = :course_id"
    );
    $updateCourse->execute([
        'short_description' => 'Formação prática em PostgreSQL do zero ao intermediário, com SQL, modelagem, performance, segurança, operação e projeto final.',
        'description' => 'Trilha profissionalizante com explicações claras, prática guiada, desafios sem modelo, reforço e projeto final LD Informática. O objetivo é preparar o aluno para tarefas reais de PostgreSQL em desenvolvimento, suporte, análise operacional e administração inicial de banco.',
        'course_id' => $courseId,
    ]);

    $updateModule = $pdo->prepare(
        "UPDATE modules
            SET title = :title,
                description = :description,
                icon = :icon,
                position = :position,
                updated_at = NOW()
          WHERE id = :module_id"
    );

    $updatePhase = $pdo->prepare(
        "UPDATE phases
            SET title = :title,
                description = :description,
                position = :position,
                required_score = 70.00,
                required_lessons_pct = 100.00,
                updated_at = NOW()
          WHERE id = :phase_id"
    );

    $lessonFind = $pdo->prepare(
        "SELECT id, status
           FROM lessons
          WHERE phase_id = :phase_id
            AND slug = :slug
          LIMIT 1"
    );

    $updateLesson = $pdo->prepare(
        "UPDATE lessons
            SET title = :title,
                summary = :summary,
                position = :position,
                estimated_minutes = :estimated_minutes,
                xp_reward = :xp_reward,
                is_required = 1,
                completion_mode = 'lab',
                updated_at = NOW()
          WHERE id = :lesson_id"
    );

    $insertLesson = $pdo->prepare(
        "INSERT INTO lessons
            (phase_id, title, slug, summary, position, estimated_minutes,
             xp_reward, is_required, completion_mode, status)
         VALUES
            (:phase_id, :title, :slug, :summary, :position, :estimated_minutes,
             :xp_reward, 1, 'lab', 'draft')"
    );

    foreach ($grade as $moduleDef) {
        $moduleFind->execute([
            'course_id' => $courseId,
            'slug' => $moduleDef['slug'],
        ]);
        $module = $moduleFind->fetch(PDO::FETCH_ASSOC);
        $moduleId = (int) $module['id'];

        $updateModule->execute([
            'title' => $moduleDef['title'],
            'description' => $moduleDef['description'],
            'icon' => $moduleDef['icon'],
            'position' => (int) $moduleDef['position'],
            'module_id' => $moduleId,
        ]);

        foreach ($moduleDef['phases'] as $phaseIndex => $phaseDef) {
            $phaseFind->execute([
                'module_id' => $moduleId,
                'slug' => $phaseDef['slug'],
            ]);
            $phase = $phaseFind->fetch(PDO::FETCH_ASSOC);
            $phaseId = (int) $phase['id'];

            $updatePhase->execute([
                'title' => $phaseDef['title'],
                'description' => $phaseDef['description'],
                'position' => $phaseIndex + 1,
                'phase_id' => $phaseId,
            ]);

            // Libera as posições desejadas sem apagar IDs, progresso, blocos ou labs.
            $pdo->prepare(
                "UPDATE lessons
                    SET position = position + 1000
                  WHERE phase_id = :phase_id"
            )->execute(['phase_id' => $phaseId]);

            foreach ($phaseDef['lessons'] as $lessonIndex => [$slug, $title, $summary, $minutes]) {
                $position = $lessonIndex + 1;
                $lessonFind->execute([
                    'phase_id' => $phaseId,
                    'slug' => $slug,
                ]);
                $lesson = $lessonFind->fetch(PDO::FETCH_ASSOC);

                if ($lesson) {
                    $updateLesson->execute([
                        'title' => $title,
                        'summary' => $summary,
                        'position' => $position,
                        'estimated_minutes' => $minutes,
                        'xp_reward' => 25,
                        'lesson_id' => (int) $lesson['id'],
                    ]);
                    continue;
                }

                $insertLesson->execute([
                    'phase_id' => $phaseId,
                    'title' => $title,
                    'slug' => $slug,
                    'summary' => $summary,
                    'position' => $position,
                    'estimated_minutes' => $minutes,
                    'xp_reward' => 25,
                ]);
            }
        }
    }

    $verify = $pdo->prepare(
        "SELECT COUNT(*)
           FROM lessons l
           INNER JOIN phases p ON p.id = l.phase_id
           INNER JOIN modules m ON m.id = p.module_id
          WHERE m.course_id = :course_id
            AND l.status <> 'archived'"
    );
    $verify->execute(['course_id' => $courseId]);
    $actualLessons = (int) $verify->fetchColumn();

    if ($actualLessons !== $desiredLessons) {
        throw new RuntimeException(
            "Verificação final falhou: esperadas {$desiredLessons} aulas ativas, encontradas {$actualLessons}."
        );
    }

    $pdo->commit();

    echo "\n[OK] Grade Mestra V2 aplicada.\n";
    echo "[OK] 9 módulos preservados e atualizados.\n";
    echo "[OK] {$desiredPhases} fases sincronizadas.\n";
    echo "[OK] {$desiredLessons} aulas ativas na estrutura.\n";
    echo "[OK] " . count($newLessons) . " aulas novas criadas como draft.\n";
    echo "[OK] IDs, progresso, blocos, laboratórios e status das aulas existentes foram preservados.\n";
    echo "[OK] Nenhum conteúdo do curso PMMT foi alterado.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, "\nERRO: {$e->getMessage()}\n");
    exit(7);
}
