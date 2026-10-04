<?php
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    return $bytes . ' B';
};
?>

<section class="ld-admin-page-head ld-admin-page-head-compact">
    <div>
        <a class="ld-admin-back-link" href="<?= e(url('/admin/conteudo')) ?>">← Cursos e conteúdo</a>
        <span class="ld-admin-kicker">BIBLIOTECA DE MÍDIA</span>
        <h1>Vídeos</h1>
        <p>Envie o arquivo uma única vez e reutilize-o nas aulas. Os vídeos permanecem armazenados fora da pasta pública.</p>
    </div>
    <div class="ld-admin-head-actions">
        <a class="ld-admin-btn is-ghost" href="<?= e(url('/admin/aulas')) ?>">Gerenciar aulas</a>
    </div>
</section>

<section class="ld-admin-panel ld-admin-media-upload">
    <div class="ld-admin-media-upload-copy">
        <span class="ld-admin-panel-kicker">NOVO VÍDEO</span>
        <h2>Adicionar à biblioteca</h2>
        <p>Formatos aceitos: MP4 e WebM. Limite da aplicação: 512 MB.</p>
    </div>

    <form action="<?= e(url('/admin/videos')) ?>" method="post" enctype="multipart/form-data" class="ld-admin-media-upload-form">
        <?= \App\Core\Csrf::input() ?>

        <label>
            <span>Título</span>
            <input class="ld-admin-input" type="text" name="title" maxlength="200" placeholder="Ex.: Princípios fundamentais — explicação">
        </label>

        <label class="ld-admin-file-picker">
            <span>Arquivo de vídeo</span>
            <input type="file" name="video" accept="video/mp4,video/webm,.mp4,.webm" required data-video-file>
            <small data-file-name>Nenhum arquivo selecionado.</small>
        </label>

        <button class="ld-admin-btn is-primary" type="submit">Enviar vídeo</button>
    </form>
</section>

<div class="ld-admin-section-title">
    <div><span class="ld-admin-panel-kicker">ARQUIVOS DISPONÍVEIS</span><h2><?= count($media) ?> vídeo(s)</h2></div>
</div>

<?php if (!$media): ?>
    <section class="ld-admin-panel ld-admin-empty">A biblioteca está vazia. Envie o primeiro vídeo no formulário acima.</section>
<?php else: ?>
    <div class="ld-admin-media-grid">
        <?php foreach ($media as $item): ?>
            <article class="ld-admin-media-card">
                <div class="ld-admin-media-preview">
                    <video controls preload="none" playsinline src="<?= e(url('/media/video/' . $item['id'])) ?>"></video>
                </div>

                <div class="ld-admin-media-body">
                    <div class="ld-admin-media-topline">
                        <span class="ld-admin-pill <?= (int) $item['usage_count'] > 0 ? 'is-success' : '' ?>"><?= (int) $item['usage_count'] ?> uso(s)</span>
                    </div>

                    <h3><?= e($item['title']) ?></h3>
                    <p class="ld-admin-media-filename"><?= e($item['original_name']) ?></p>

                    <div class="ld-admin-media-meta">
                        <span><?= e($formatBytes((int) $item['size_bytes'])) ?></span>
                        <span><?= e(str_replace('video/', '', (string) $item['mime_type'])) ?></span>
                        <span><?= e(date('d/m/Y H:i', strtotime((string) $item['created_at']))) ?></span>
                    </div>

                    <details class="ld-admin-details">
                        <summary>Renomear vídeo</summary>
                        <form action="<?= e(url('/admin/videos/' . $item['id'])) ?>" method="post">
                            <?= \App\Core\Csrf::input() ?>
                            <input type="hidden" name="_method" value="PUT">
                            <input class="ld-admin-input" type="text" name="title" maxlength="200" value="<?= e($item['title']) ?>" required>
                            <button class="ld-admin-btn" type="submit">Salvar título</button>
                        </form>
                    </details>

                    <?php if ((int) $item['usage_count'] === 0): ?>
                        <form action="<?= e(url('/admin/videos/' . $item['id'])) ?>" method="post" data-confirm="Excluir definitivamente este vídeo da biblioteca e do disco?">
                            <?= \App\Core\Csrf::input() ?>
                            <input type="hidden" name="_method" value="DELETE">
                            <button class="ld-admin-btn is-danger ld-admin-block-btn" type="submit">Excluir definitivamente</button>
                        </form>
                    <?php else: ?>
                        <button class="ld-admin-btn is-ghost ld-admin-block-btn" type="button" disabled>Em uso — remova das aulas antes de excluir</button>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
