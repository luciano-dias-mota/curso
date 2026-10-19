<?php

declare(strict_types=1);

/**
 * Auditoria estrutural do Anexo D - Mérito Intelectual.
 *
 * A auditoria confirma que existe conteúdo publicado para cada item do
 * conteúdo programático. Ela não substitui a conferência jurídica da
 * literalidade, que é feita pelos importadores/fontes oficiais.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida apenas via CLI.\n");
}

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/vendor/autoload.php';

use App\Core\App;
use App\Core\Database;

App::boot(BASE_PATH);
$pdo = Database::connection();

$courseStmt = $pdo->prepare(
    "SELECT id,title
     FROM courses
     WHERE slug='preparacao-pmmt-merito-intelectual-e-caoc'
       AND status='published'
     LIMIT 1"
);
$courseStmt->execute();
$course = $courseStmt->fetch(PDO::FETCH_ASSOC);

if (!$course) {
    fwrite(STDERR,"[FALHA] Curso publicado não encontrado.\n");
    exit(2);
}

$courseId = (int)$course['id'];

$phaseExists = $pdo->prepare(
    "SELECT COUNT(*)
     FROM phases p
     INNER JOIN modules m ON m.id=p.module_id
     WHERE m.course_id=:course_id
       AND m.status='published'
       AND p.status='published'
       AND p.slug=:slug"
);

$textExists = $pdo->prepare(
    "SELECT COUNT(DISTINCT l.id)
     FROM modules m
     INNER JOIN phases p
       ON p.module_id=m.id
      AND p.status='published'
     INNER JOIN lessons l
       ON l.phase_id=p.id
      AND l.status='published'
     LEFT JOIN lesson_blocks lb
       ON lb.lesson_id=l.id
     WHERE m.course_id=:course_id
       AND m.status='published'
       AND LOWER(CONCAT_WS(
            ' ',
            m.title,m.slug,m.description,
            p.title,p.slug,p.description,
            l.title,l.slug,l.summary,
            lb.title,lb.content
       )) LIKE :needle"
);

$moduleExists = $pdo->prepare(
    "SELECT COUNT(*)
     FROM modules
     WHERE course_id=:course_id
       AND status='published'
       AND LOWER(CONCAT_WS(' ',title,slug,description)) LIKE :needle"
);

$hasPhase = static function (string $slug) use ($phaseExists,$courseId): bool {
    $phaseExists->execute(['course_id'=>$courseId,'slug'=>$slug]);
    return (int)$phaseExists->fetchColumn() > 0;
};

$hasText = static function (string $needle) use ($textExists,$courseId): bool {
    $textExists->execute([
        'course_id'=>$courseId,
        'needle'=>'%' . mb_strtolower($needle,'UTF-8') . '%',
    ]);
    return (int)$textExists->fetchColumn() > 0;
};

$hasModule = static function (string $needle) use ($moduleExists,$courseId): bool {
    $moduleExists->execute([
        'course_id'=>$courseId,
        'needle'=>'%' . mb_strtolower($needle,'UTF-8') . '%',
    ]);
    return (int)$moduleExists->fetchColumn() > 0;
};

$items = [
    ['1.1.1','Compreensão e intelecção de textos',fn()=> $hasText('compreensão')],
    ['1.1.2','Coesão textual',fn()=> $hasText('coesão')],
    ['1.1.3','Variações linguísticas e funções da linguagem',fn()=>
        ($hasText('variação linguística') || $hasText('variações linguísticas'))
        && ($hasText('funções da linguagem') || $hasText('funções de linguagem'))
    ],
    ['1.1.4','Ortografia oficial',fn()=> $hasText('ortografia')],
    ['1.1.5','Morfossintaxe e verbos',fn()=> $hasText('morfossintaxe') || ($hasText('classe') && $hasText('modos verbais'))],
    ['1.1.6','Coordenação e subordinação',fn()=> $hasText('coordenação') && $hasText('subordinação')],
    ['1.1.7','Pontuação',fn()=> $hasText('pontuação')],
    ['1.1.8','Semântica',fn()=> $hasText('semântica')],
    ['1.1.9','Concordância nominal e verbal',fn()=> $hasText('concordância')],
    ['1.1.10','Regência e crase',fn()=> $hasText('regência') && $hasText('crase')],
    ['1.1.11','Reescrita',fn()=> $hasText('reescrita')],

    ['1.2.1-8','Administração: fundamentos, organização, agentes, poderes, atos, controle e modelos',fn()=> $hasModule('Administração Pública') && $hasText('atos administrativos') && $hasText('responsabilidade civil')],
    ['1.2.9','Ética no exercício da função pública',fn()=> $hasPhase('10a-etica-no-exercicio-da-funcao-publica')],
    ['1.2.10','Eficiência, eficácia e efetividade',fn()=> $hasText('eficiência') && $hasText('eficácia') && $hasText('efetividade')],
    ['1.2.11','Materiais e patrimônio',fn()=> $hasText('materiais') && $hasText('patrimônio')],
    ['1.2.12','Gestão de Pessoas',fn()=> $hasText('Gestão de Pessoas') && $hasText('competência')],
    ['1.2.13','BSC, SWOT, GUT e 5W2H',fn()=> $hasText('SWOT') && $hasText('BSC') && $hasText('GUT') && $hasText('5W2H')],
    ['1.2.14','Lei Estadual nº 7.692/2002',fn()=> $hasPhase('11-lei-estadual-7692-2002-processo-administrativo')],

    ['2.1','Código Penal Militar - recorte do edital',fn()=> $hasPhase('1-codigo-penal-militar-recorte-do-edital') && $hasPhase('2-crimes-militares-selecionados')],
    ['2.2','Código de Processo Penal Militar - recorte do edital',fn()=> $hasPhase('3-policia-judiciaria-militar-no-cppm')],
    ['2.3','RDPM/MT',fn()=> $hasPhase('4-rdpm-mt-estrutura') && $hasPhase('5-transgressao-julgamento-punicao-e-comportamento')],
    ['2.4','Lei nº 13.491/2017',fn()=> $hasPhase('6-lei-13491-2017-alteracao-do-art-9-do-cpm')],
    ['2.5','Lei de Improbidade Administrativa',fn()=> $hasText('Improbidade Administrativa')],
    ['2.6','Lei de Tortura',fn()=> $hasPhase('2-lei-de-tortura-lei-no-9-455-1997')],
    ['2.7','Lei de Abuso de Autoridade',fn()=> $hasPhase('3-abuso-de-autoridade-lei-no-13-869-2019')],
    ['2.8','Manual de IPM e Prisão em Flagrante',fn()=> $hasPhase('1-inquerito-policial-militar-ipm')],
    ['2.9','Manual de Sindicância PMMT',fn()=> $hasPhase('2-sindicancia-pmmt')],
    ['2.10','Manual de Deserção PMMT',fn()=> $hasPhase('3-desercao-e-instrucao-provisoria')],
    ['2.11','Portaria nº 160/GCG/PMMT/09',fn()=> $hasText('Portaria 160')],

    ['3.1','Constituição Federal - recorte do edital',fn()=> $hasText('Princípios Fundamentais') && $hasText('art. 37') && $hasText('Segurança Pública')],
    ['3.2','Constituição Estadual - artigos do edital',fn()=> $hasText('Constituição Estadual')],
    ['3.3','Lei nº 14.751/2023 - LOB Nacional',fn()=> $hasText('14.751')],
    ['3.4','LC nº 555/2014 - Estatuto dos Militares',fn()=> $hasText('LC nº 555') || $hasText('Estatuto dos Militares')],
    ['3.5','Lei nº 10.076/2014 - Promoção',fn()=> $hasText('10.076')],
    ['3.6','LC nº 529/2014 - Efetivo',fn()=> $hasText('529')],
    ['3.7','LC nº 386/2010 - Organização Básica',fn()=> $hasPhase('8-lei-complementar-386-2010-organizacao-basica-pmmt')],
    ['3.8','Decreto nº 591/1980 - Movimentação',fn()=> $hasPhase('9-decreto-591-1980-regulamento-de-movimentacao')],
    ['3.9/3.10','Decreto nº 1.172/2021 + Diretriz 001/2021',fn()=> $hasPhase('10-decreto-1172-2021-diretriz-conjunta-001-2021')],
    ['3.11','Portaria nº 025/QCG/2025',fn()=> $hasPhase('11-portaria-025-qcg-2025-violencia-domestica')],

    ['4.1','POP PMMT - Módulos I a IV',fn()=> $hasText('POP 1') && $hasText('POP 2') && $hasText('POP 3') && $hasText('POP 4')],
    ['4.2','ECA',fn()=> $hasPhase('1-eca-lei-no-8-069-1990')],
    ['4.4','Lei Maria da Penha',fn()=> $hasPhase('2-lei-maria-da-penha-lei-no-11-340-2006')],
    ['4.5','Lei nº 9.099/1995',fn()=> $hasPhase('3-lei-no-9-099-1995')],
    ['4.6','Código de Trânsito Brasileiro',fn()=> $hasPhase('4-ctb-mapa-mental-fornecido') && $hasText('Sistema Nacional de Trânsito')],
    ['4.7','Lei de Crimes Ambientais',fn()=> $hasPhase('5-crimes-ambientais-lei-no-9-605-1998')],
    ['4.8','Lei de Drogas',fn()=> $hasPhase('6-lei-de-drogas-lei-no-11-343-2006')],
    ['4.9','Lei nº 7.716/1989 - Crime Racial',fn()=> $hasPhase('7-lei-7716-1989-crimes-resultantes-de-preconceito')],
    ['4.10','Lei nº 10.826/2003 - Desarmamento',fn()=> $hasPhase('8-lei-10826-2003-estatuto-do-desarmamento')],
    ['4.11/4.12','Decreto-Lei nº 3.688/1941 - Contravenções',fn()=> $hasPhase('9-decreto-lei-3688-1941-contravencoes-penais')],
    ['4.13','Manual do TCO PMMT 2024',fn()=> $hasPhase('10-manual-tco-pmmt-2024')],
    ['4.14','Código Penal - faixas do edital',fn()=> $hasPhase('11-codigo-penal-recorte-do-edital')],
    ['4.15','Código de Processo Penal - faixas do edital',fn()=> $hasPhase('12-codigo-processo-penal-recorte-do-edital')],
];

echo "=============================================================\n";
echo " COBERTURA ESTRUTURAL DO ANEXO D - MI 2026\n";
echo "=============================================================\n";
printf("Curso: #%d - %s\n\n",$courseId,$course['title']);

$ok = 0;
$missing = [];

foreach ($items as [$code,$label,$check]) {
    $covered = (bool)$check();
    printf(
        "%-10s %-10s %s\n",
        $code,
        $covered ? '[OK]' : '[FALTA]',
        $label
    );

    if ($covered) {
        $ok++;
    } else {
        $missing[] = "{$code} - {$label}";
    }
}

echo "\n=============================================================\n";
printf("Itens cobertos estruturalmente: %d / %d\n",$ok,count($items));

if ($missing !== []) {
    echo "Pendências:\n";
    foreach ($missing as $item) {
        echo " - {$item}\n";
    }
    fwrite(STDERR,"\n[PENDENTE] A cobertura do Anexo D ainda não é 100%.\n");
    exit(4);
}

echo "\n[OK] Todos os itens do Anexo D possuem conteúdo publicado no curso.\n";
echo "[ATENÇÃO] Este resultado é estrutural; mantenha a auditoria jurídica/fontes oficiais para literalidade.\n";
