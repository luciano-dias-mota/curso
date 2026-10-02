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

<section class="admin-lesson-editor">
    <div class="admin-page-head">
        <div>
            <a class="back" href="<?= e(url('/admin/aulas')) ?>">← Todas as aulas</a>
            <span class="eyebrow">EDITOR DE AULA</span>
            <h1><?= e($lesson['title']) ?></h1>
            <p>
                <?= e($lesson['course_title']) ?> ·
                <?= e($lesson['module_title']) ?> ·
                <?= e($lesson['phase_title']) ?>
            </p>
        </div>

        <div class="admin-page-actions">
            <a class="primary" href="<?= e(url('/admin/videos')) ?>">Biblioteca de vídeos</a>
        </div>
    </div>

    <div class="lesson-editor-grid">
        <section>
            <div class="section-head">
                <div>
                    <span class="eyebrow">ORDEM DA AULA</span>
                    <h2>Blocos de conteúdo</h2>
                </div>
                <p>Arraste no computador ou use ↑/↓ no celular.</p>
            </div>

            <div
                class="admin-block-list"
                data-block-list
                data-reorder-url="<?= e(url('/admin/aulas/' . $lesson['id'] . '/reordenar')) ?>"
            >
                <?php foreach ($blocks as $block): ?>
                    <?php
                    $isVideo = $block['block_type'] === 'video';
                    $label = $blockLabels[$block['block_type']] ?? $block['block_type'];
                    ?>
                    <article
                        class="card admin-block <?= $isVideo ? 'is-video' : '' ?>"
                        data-block-id="<?= (int) $block['id'] ?>"
                        draggable="<?= $isVideo ? 'true' : 'false' ?>"
                    >
                        <div class="block-drag <?= $isVideo ? '' : 'is-static' ?>" title="<?= $isVideo ? 'Arrastar vídeo para reposicionar' : 'Bloco de conteúdo fixo' ?>">
                            <?= $isVideo ? '☰' : '•' ?>
                        </div>

                        <div class="block-position"><?= (int) $block['position'] ?></div>

                        <div class="block-content">
                            <div class="block-heading">
                                <span class="badge <?= $isVideo ? 'active' : 'available' ?>">
                                    <?= $isVideo ? '▶ VÍDEO' : e(mb_strtoupper($label)) ?>
                                </span>
                                <strong><?= e($block['title'] ?: 'Sem título') ?></strong>
                            </div>

                            <?php if ($isVideo): ?>
                                <p>
                                    <?= e($block['media_title'] ?: 'Vídeo não vinculado') ?>
                                    <?php if (!empty($block['media_original_name'])): ?>
                                        <span>• <?= e($block['media_original_name']) ?></span>
                                    <?php endif; ?>
                                </p>

                                <div class="video-block-actions">
                                    <?php if (!empty($block['media_id'])): ?>
                                        <a
                                            class="secondary"
                                            target="_blank"
                                            rel="noopener"
                                            href="<?= e(url('/media/video/' . $block['media_id'])) ?>"
                                        >
                                            ▶ Visualizar
                                        </a>
                                    <?php endif; ?>

                                    <details class="video-block-edit">
                                        <summary>Trocar / editar</summary>

                                        <form
                                            action="<?= e(url('/admin/aulas/' . $lesson['id'] . '/blocos/' . $block['id'] . '/video')) ?>"
                                            method="post"
                                        >
                                            <?= \App\Core\Csrf::input() ?>
                                            <input type="hidden" name="_method" value="PUT">

                                            <label>
                                                <span>Vídeo</span>
                                                <select class="form-control" name="media_id" required>
                                                    <?php foreach ($media as $item): ?>
                                                        <option
                                                            value="<?= (int) $item['id'] ?>"
                                                            <?= (int) $item['id'] === (int) $block['media_id'] ? 'selected' : '' ?>
                                                        >
                                                            <?= e($item['title']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>

                                            <label>
                                                <span>Título dentro da aula</span>
                                                <input
                                                    class="form-control"
                                                    name="title"
                                                    maxlength="200"
                                                    value="<?= e($block['title']) ?>"
                                                >
                                            </label>

                                            <label>
                                                <span>Observação abaixo do vídeo</span>
                                                <textarea
                                                    class="form-control"
                                                    name="content"
                                                    rows="3"
                                                ><?= e($block['content']) ?></textarea>
                                            </label>

                                            <button class="secondary" type="submit">Salvar alterações</button>
                                        </form>
                                    </details>

                                    <form
                                        action="<?= e(url('/admin/aulas/' . $lesson['id'] . '/blocos/' . $block['id'] . '/video')) ?>"
                                        method="post"
                                        data-confirm="Remover este vídeo da aula? O arquivo continuará na biblioteca."
                                    >
                                        <?= \App\Core\Csrf::input() ?>
                                        <input type="hidden" name="_method" value="DELETE">
                                        <button class="danger-button subtle" type="submit">Remover da aula</button>
                                    </form>
                                </div>
                            <?php else: ?>
                                <p class="muted block-preview">
                                    <?= e(mb_substr(trim((string) $block['content']), 0, 180)) ?>
                                    <?= mb_strlen(trim((string) $block['content'])) > 180 ? '…' : '' ?>
                                </p>
                            <?php endif; ?>
                        </div>

                        <?php if ($isVideo): ?>
                            <div class="block-mobile-move">
                                <button class="icon-btn" type="button" data-move="up" title="Mover vídeo para cima">↑</button>
                                <button class="icon-btn" type="button" data-move="down" title="Mover vídeo para baixo">↓</button>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="reorder-status" data-reorder-status aria-live="polite"></div>
        </section>

        <aside class="card insert-video-panel">
            <div>
                <span class="eyebrow">INSERIR VÍDEO</span>
                <h2>Novo bloco de vídeo</h2>
                <p>
                    Escolha um vídeo da biblioteca e indique exatamente em qual ponto da aula ele deve entrar.
                </p>
            </div>

            <?php if (!$media): ?>
                <div class="admin-empty compact">
                    <p>Você ainda não enviou nenhum vídeo.</p>
                    <a class="primary" href="<?= e(url('/admin/videos')) ?>">Enviar primeiro vídeo</a>
                </div>
            <?php else: ?>
                <form
                    action="<?= e(url('/admin/aulas/' . $lesson['id'] . '/videos')) ?>"
                    method="post"
                    class="insert-video-form"
                >
                    <?= \App\Core\Csrf::input() ?>

                    <label>
                        <span>Vídeo da biblioteca</span>
                        <select class="form-control" name="media_id" required>
                            <option value="">Selecione...</option>
                            <?php foreach ($media as $item): ?>
                                <option value="<?= (int) $item['id'] ?>">
                                    <?= e($item['title']) ?> (<?= (int) $item['usage_count'] ?> uso(s))
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Inserir</span>
                        <select class="form-control" name="after_block_id">
                            <option value="">No final da aula</option>
                            <option value="0">No início da aula</option>
                            <?php foreach ($blocks as $block): ?>
                                <option value="<?= (int) $block['id'] ?>">
                                    Depois do bloco <?= (int) $block['position'] ?> —
                                    <?= e($block['title'] ?: ($blockLabels[$block['block_type']] ?? 'Conteúdo')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        <span>Título dentro da aula <small>(opcional)</small></span>
                        <input
                            class="form-control"
                            name="title"
                            maxlength="200"
                            placeholder="Se vazio, usa o título da biblioteca"
                        >
                    </label>

                    <label>
                        <span>Observação abaixo do vídeo <small>(opcional)</small></span>
                        <textarea
                            class="form-control"
                            name="content"
                            rows="4"
                            placeholder="Ex.: Assista antes de avançar para o resumo."
                        ></textarea>
                    </label>

                    <button class="primary" type="submit">Adicionar vídeo à aula</button>
                </form>
            <?php endif; ?>
        </aside>
    </div>
</section>
