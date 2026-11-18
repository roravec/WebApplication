<?php if ($flash): ?><p class="flash"><?= $e($flash) ?></p><?php endif ?>
<h1>Demo app</h1>
<p>Try <a href="<?= $e($app->url('/hello/World')) ?>">/hello/World</a> or <a href="<?= $e($app->url('/api/status')) ?>">/api/status</a>.</p>
