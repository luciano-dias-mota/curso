<?php
$formatBytes = static function (int $bytes): string {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2, ',', '.') . ' GB';
    }
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1, ',', '.') . ' KB';
    }
    return $bytes . ' B';
};
?>

<section class="admin-media-page">
    <div class="admin-page-head">
        <div>
            <a class="back" href="<?= e(url('/admin')) ?>">← Administração</a>
            <span class="eyebrow">BIBLIOTECA DE MÍDIA</span>
            <h1>Vídeos</h1>
            <p>
                Envie cada vídeo uma única vez e reutilize-o nas aulas que precisar.
                Os arquivos ficam protegidos em <code>storage/media/videos</code>.
            </p>
        </div>

        <a class="secondary" href="<?= e(url('/admin/aulas')) ?>">Gerenciar aulas</a>
    </div>

    <article class="card media-upload-card">
        <div>
            <span class="eyebrow">NOVO VÍDEO</span>
            <h2>Adicionar à biblioteca</h2>
            <p class="muted">Formatos aceitos: MP4 e WebM. Limite da aplicação: 512 MB.</p>
        </div>

        <form
            action="<?= e(url('/admin/videos')) ?>"
            method="post"
            enctype="multipart/form-data"
            class="media-upload-form"
        >
            <?= \App\Core\Csrf::input() ?>

            <label>
                <span>Título</span>
                <input
                    class="form-control"
                    type="text"
                    name="title"
                    maxlength="200"
                    placeholder="Ex.: Princípios fundamentais — explicação"
                >
            </label>

            <label class="file-picker">
                <span>Arquivo de vídeo</span>
                <input
                    type="file"
                    name="video"
                    accept="video/mp4,video/webm,.mp4,.webm"
                    required
                    data-video-file
                >
                <small data-file-name>Nenhum arquivo selecionado.</small>
            </label>

            <button class="primary" type="submit">Enviar vídeo</button>
        </form>
    </article>

    <div class="section-head media-library-head">
        <div>
            <span class="eyebrow">ARQUIVOS DISPONÍVEIS</span>
            <h2><?= count($media) ?> vídeo(s)</h2>
        </div>
    </div>

    <?php if (!$media): ?>
        <article class="card empty admin-empty">
            <h2>A biblioteca está vazia</h2>
            <p>Envie o primeiro vídeo usando o formulário acima.</p>
        </article>
    <?php else: ?>
        <div class="media-library-grid">
            <?php foreach ($media as $item): ?>
                <article class="card media-card">
                    <div class="media-preview">
                        <video
                            controls
                            preload="none"
                            playsinline
                            src="<?= e(url('/media/video/' . $item['id'])) ?>"
                        ></video>
                    </div>

                    <div class="media-card-body">
                        <span class="badge <?= (int) $item['usage_count'] > 0 ? 'active' : 'available' ?>">
                            <?= (int) $item['usage_count'] ?> uso(s)
                        </span>

                        <h3><?= e($item['title']) ?></h3>
                        <p class="muted media-filename"><?= e($item['original_name']) ?></p>

                        <div class="media-meta">
                            <span><?= e($formatBytes((int) $item['size_bytes'])) ?></span>
                            <span><?= e(str_replace('video/', '', (string) $item['mime_type'])) ?></span>
                            <span><?= e(date('d/m/Y H:i', strtotime((string) $item['created_at']))) ?></span>
                        </div>

                        <details class="media-edit-details">
                            <summary>Renomear</summary>
                            <form action="<?= e(url('/admin/videos/' . $item['id'])) ?>" method="post">
                                <?= \App\Core\Csrf::input() ?>
                                <input type="hidden" name="_method" value="PUT">
                                <input
                                    class="form-control"
                                    type="text"
                                    name="title"
                                    maxlength="200"
                                    value="<?= e($item['title']) ?>"
                                    required
                                >
                                <button class="secondary" type="submit">Salvar título</button>
                            </form>
                        </details>

                        <?php if ((int) $item['usage_count'] === 0): ?>
                            <form
                                action="<?= e(url('/admin/videos/' . $item['id'])) ?>"
                                method="post"
                                data-confirm="Excluir definitivamente este vídeo da biblioteca e do disco?"
                            >
                                <?= \App\Core\Csrf::input() ?>
                                <input type="hidden" name="_method" value="DELETE">
                                <button class="danger-button" type="submit">Excluir definitivamente</button>
                            </form>
                        <?php else: ?>
                            <button class="secondary" type="button" disabled>
                                Em uso — remova das aulas antes de excluir
                            </button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
