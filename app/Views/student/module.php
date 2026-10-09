<section class="trail-page"><header class="trail-hero card"><div><a class="back" href="<?=e(url('/curso/'.$module['course_id']))?>">← Voltar ao curso</a><span class="eyebrow">MÓDULO <?=(int)$module['position']?></span><h1><?=e($module['title'])?></h1><p><?=e($module['description']??'')?></p></div><div class="score"><strong><?=number_format((float)$module['progress_pct'],0)?>%</strong><span>dominado</span><div class="progress"><span style="width:<?=(float)$module['progress_pct']?>%"></span></div></div></header><div class="section-head"><div><span class="eyebrow">MISSÕES DO MÓDULO</span><h2>Fases</h2></div></div><div class="phase-grid"><?php foreach($phases as $p):$locked=$p['user_status']==='locked';$lib=(int)$p['is_required']===0;$done=$p['user_status']==='completed';?><article class="phase-card <?=$locked?'locked':''?> <?=$lib?'library':''?>"><div class="phase-top"><span class="phase-index"><?=$lib?'BIB':str_pad((string)$p['position'],2,'0',STR_PAD_LEFT)?></span><span class="badge <?=$lib?'library':($locked?'locked':($done?'done':'active'))?>"><?=$lib?'BIBLIOTECA':($locked?'BLOQUEADA':($done?'CONCLUÍDA':'MISSÃO'))?></span></div><h3><?=e($p['title'])?></h3><p><?=e($p['description']??'')?></p><div class="meta"><span><?=(int)$p['lesson_count']?> aula(s)</span><span><?=$lib?'consulta livre':number_format((float)$p['best_score'],0).'% melhor nota'?></span></div><?php if(!$lib):?><div class="progress"><span style="width:<?=(float)$p['progress_pct']?>%"></span></div><?php endif;?><?php if($locked):?><button class="secondary" disabled>🔒 Bloqueada</button><?php else:?><a class="<?=$lib?'secondary':'primary'?>" href="<?=e(url('/fase/'.$p['id']))?>"><?=$lib?'Consultar material →':'Entrar na fase →'?></a><?php endif;?></article><?php endforeach;?></div></section>


<?php
$moduleQuizExpected = (int) ($moduleQuiz['question_limit'] ?? 0);
$moduleQuizRequiredCorrect = (int) ($moduleQuiz['required_correct'] ?? 0);
$moduleQuizRequiredScore = (float) ($moduleQuiz['required_score'] ?? 0);
$moduleQuizReady = $moduleQuiz && $moduleQuizExpected > 0
    && (int) ($moduleQuiz['question_count'] ?? 0) === $moduleQuizExpected;
?>

<?php if ((int) ($module['position'] ?? 0) > 0): ?>
<article class="card module-boss-card">
    <div class="module-boss-head">
        <div>
            <span class="eyebrow">ETAPA FINAL DO MÓDULO</span>
            <h2>🏆 Avaliação do módulo</h2>
            <p class="muted">
                Depois de vencer todas as fases, faça esta avaliação.
                O próximo módulo só será liberado após aprovação.
            </p>
        </div>

        <?php if ($moduleQuiz && (float) ($moduleQuiz['best_passed'] ?? 0) >= $moduleQuizRequiredScore): ?>
            <span class="badge done">APROVADO</span>
        <?php elseif ($allPhasesComplete): ?>
            <span class="badge active">LIBERADA</span>
        <?php else: ?>
            <span class="badge locked">BLOQUEADA</span>
        <?php endif; ?>
    </div>

    <div class="boss-rules">
        <div class="boss-rule"><strong><?= $moduleQuizExpected ?: '—' ?></strong><span>questões</span></div>
        <div class="boss-rule"><strong><?= $moduleQuiz ? ($moduleQuizRequiredCorrect . '/' . $moduleQuizExpected) : '—' ?></strong><span>mínimo</span></div>
        <div class="boss-rule"><strong><?= $moduleQuiz ? number_format($moduleQuizRequiredScore, 0) . '%' : '—' ?></strong><span>aprovação</span></div>
    </div>

    <?php if (!$allPhasesComplete): ?>
        <button class="secondary" type="button" disabled>
            🔒 Conclua todas as fases
        </button>
    <?php elseif (!$moduleQuiz): ?>
        <button class="secondary" type="button" disabled>
            Avaliação ainda não configurada
        </button>
    <?php elseif (!$moduleQuizReady): ?>
        <button class="secondary" type="button" disabled>
            Banco incompleto:
            <?= (int) $moduleQuiz['question_count'] ?>/<?= $moduleQuizExpected ?>
        </button>
    <?php else: ?>
        <a class="primary" href="<?= e(url('/prova/' . $moduleQuiz['id'])) ?>">
            Fazer avaliação do módulo →
        </a>
    <?php endif; ?>
</article>
<?php endif; ?>

