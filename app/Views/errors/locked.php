<div class="card">
    <h1>🔒 Fase bloqueada</h1>
    <p><?= e($message ?? 'Conclua a etapa anterior para continuar.') ?></p>
    <a class="btn" href="<?= e(url('/dashboard')) ?>">Voltar à missão</a>
</div>
