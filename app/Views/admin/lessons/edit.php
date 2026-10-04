<?php
$blockLabels = [
    'theory' => 'Teoria',
    'example' => 'Exemplo',
    'warning' => 'Atenção',
    'summary' => 'Resumo',
    'comparison' => 'Comparação',
    'memory' => 'Memorize',
    'case_study' => 'Caso prático',
    'image' => 'Imagem',
    'video' => 'Vídeo',
    'question' => 'Questão',
];
?>

<section class="ld-admin-page-head ld-admin-page-head-compact">
    <div>
        <a class="ld-admin-back-link" href="<?= e(url('/admin/aulas')) ?>">← Todas as aulas</a>
        <span class="ld-admin-kicker">EDITOR DE AULA</span>
        <h1><?= e($lesson['title']) ?></h1>
        <p><?= e($lesson['course_title']) ?> · <?= e($lesson['module_title']) ?> · <?= e($lesson['phase_title']) ?></p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-primary" href="<?= e(url('/admin/videos')) ?>">Biblioteca de vídeos</a>
    </div>
</section>

<div class="ld-admin-lesson-editor-grid">
    <section class="ld-admin-panel ld-admin-panel-compact">
        <div class="ld-admin-panel-heading ld-admin-panel-heading-compact">
            <div>
                <span class="ld-admin-panel-kicker">ORDEM DA AULA</span>
                <h2>Blocos de conteúdo</h2>
                <p>Arraste os vídeos no computador ou use ↑/↓ no celular.</p>
            </div>
        </div>

        <div class="ld-admin-block-list" data-block-list data-reorder-url="<?= e(url('/admin/aulas/' . $lesson['id'] . '/reordenar')) ?>">
            <?php foreach ($blocks as $block): ?>
                <?php
                $isVideo = $block['block_type'] === 'video';
                $label = $blockLabels[$block['block_type']] ?? $block['block_type'];
                $preview = trim((string) ($block['content'] ?? ''));
                ?>
                <article class="ld-admin-content-block <?= $isVideo ? 'is-video' : '' ?>" data-block-id="<?= (int) $block['id'] ?>" draggable="<?= $isVideo ? 'true' : 'false' ?>">
                    <div class="ld-admin-block-drag block-drag <?= $isVideo ? '' : 'is-static' ?>" title="<?= $isVideo ? 'Arrastar vídeo para reposicionar' : 'Bloco de conteúdo fixo' ?>">
                        <?= $isVideo ? '☰' : '•' ?>
                    </div>

                    <div class="ld-admin-block-position block-position"><?= (int) $block['position'] ?></div>

                    <div class="ld-admin-block-content">
                        <div class="ld-admin-block-heading">
                            <span class="ld-admin-pill <?= $isVideo ? 'is-video' : '' ?>"><?= $isVideo ? '▶ VÍDEO' : e(mb_strtoupper($label)) ?></span>
                            <strong><?= e($block['title'] ?: 'Sem título') ?></strong>
                        </div>

                        <?php if ($isVideo): ?>
                            <p>
                                <?= e($block['media_title'] ?: 'Vídeo não vinculado') ?>
                                <?php if (!empty($block['media_original_name'])): ?><span>• <?= e($block['media_original_name']) ?></span><?php endif; ?>
                            </p>

                            <div class="ld-admin-video-block-actions">
                                <?php if (!empty($block['media_id'])): ?>
                                    <a class="ld-admin-btn is-small" target="_blank" rel="noopener" href="<?= e(url('/media/video/' . $block['media_id'])) ?>">▶ Visualizar</a>
                                <?php endif; ?>

                                <details class="ld-admin-details ld-admin-video-block-edit">
                                    <summary>Trocar / editar</summary>
                                    <form action="<?= e(url('/admin/aulas/' . $lesson['id'] . '/blocos/' . $block['id'] . '/video')) ?>" method="post">
                                        <?= \App\Core\Csrf::input() ?>
                                        <input type="hidden" name="_method" value="PUT">

                                        <label>
                                            <span>Vídeo</span>
                                            <select class="ld-admin-select" name="media_id" required>
                                                <?php foreach ($media as $item): ?>
                                                    <option value="<?= (int) $item['id'] ?>" <?= (int) $item['id'] === (int) $block['media_id'] ? 'selected' : '' ?>><?= e($item['title']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>

                                        <label>
                                            <span>Título dentro da aula</span>
                                            <input class="ld-admin-input" name="title" maxlength="200" value="<?= e($block['title']) ?>">
                                        </label>

                                        <label>
                                            <span>Observação abaixo do vídeo</span>
                                            <textarea class="ld-admin-textarea" name="content" rows="3"><?= e($block['content']) ?></textarea>
                                        </label>

                                        <button class="ld-admin-btn" type="submit">Salvar alterações</button>
                                    </form>
                                </details>

                                <form action="<?= e(url('/admin/aulas/' . $lesson['id'] . '/blocos/' . $block['id'] . '/video')) ?>" method="post" data-confirm="Remover este vídeo da aula? O arquivo continuará na biblioteca.">
                                    <?= \App\Core\Csrf::input() ?>
                                    <input type="hidden" name="_method" value="DELETE">
                                    <button class="ld-admin-btn is-danger is-small" type="submit">Remover da aula</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <p class="ld-admin-block-preview"><?= e(mb_substr($preview, 0, 190)) ?><?= mb_strlen($preview) > 190 ? '…' : '' ?></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($isVideo): ?>
                        <div class="ld-admin-block-mobile-move block-mobile-move">
                            <button class="ld-admin-icon-btn ld-admin-mini-icon" type="button" data-move="up" title="Mover vídeo para cima">↑</button>
                            <button class="ld-admin-icon-btn ld-admin-mini-icon" type="button" data-move="down" title="Mover vídeo para baixo">↓</button>
                        </div>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>

        <div class="ld-admin-reorder-status reorder-status" data-reorder-status aria-live="polite"></div>
    </section>

    <aside class="ld-admin-panel ld-admin-insert-video-panel">
        <span class="ld-admin-panel-kicker">INSERIR VÍDEO</span>
        <h2>Novo bloco de vídeo</h2>
        <p>Escolha um vídeo da biblioteca e indique em qual ponto da aula ele deve aparecer.</p>

        <?php if (!$media): ?>
            <div class="ld-admin-empty">
                <p>Você ainda não enviou nenhum vídeo.</p>
                <a class="ld-admin-btn is-primary" href="<?= e(url('/admin/videos')) ?>">Enviar primeiro vídeo</a>
            </div>
        <?php else: ?>
            <form action="<?= e(url('/admin/aulas/' . $lesson['id'] . '/videos')) ?>" method="post" class="ld-admin-form-grid">
                <?= \App\Core\Csrf::input() ?>

                <label>
                    <span>Vídeo da biblioteca</span>
                    <select class="ld-admin-select" name="media_id" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($media as $item): ?>
                            <option value="<?= (int) $item['id'] ?>"><?= e($item['title']) ?> (<?= (int) $item['usage_count'] ?> uso(s))</option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Inserir</span>
                    <select class="ld-admin-select" name="after_block_id">
                        <option value="">No final da aula</option>
                        <option value="0">No início da aula</option>
                        <?php foreach ($blocks as $block): ?>
                            <option value="<?= (int) $block['id'] ?>">Depois do bloco <?= (int) $block['position'] ?> — <?= e($block['title'] ?: ($blockLabels[$block['block_type']] ?? 'Conteúdo')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Título dentro da aula <small>(opcional)</small></span>
                    <input class="ld-admin-input" name="title" maxlength="200" placeholder="Se vazio, usa o título da biblioteca">
                </label>

                <label>
                    <span>Observação abaixo do vídeo <small>(opcional)</small></span>
                    <textarea class="ld-admin-textarea" name="content" rows="4" placeholder="Ex.: Assista antes de avançar para o resumo."></textarea>
                </label>

                <button class="ld-admin-btn is-primary" type="submit">Adicionar vídeo à aula</button>
            </form>
        <?php endif; ?>
    </aside>
</div>
