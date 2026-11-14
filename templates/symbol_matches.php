<?php
/**
 * Several shares matched the navbar search, so offer the choice.
 *
 * The links go to quote.php by GET with an exact symbol, which needs no
 * resolving - this page is the only place the ambiguity is settled.
 */
?>
<p class="lead">
    Shares matching &ldquo;<?= htmlspecialchars($term) ?>&rdquo;
</p>

<div class="list-group mb-3">
<?php foreach ($matches as $match): ?>
    <a class="list-group-item list-group-item-action"
       href="quote.php?symbol=<?= urlencode($match["symbol"]) ?>">
        <strong><?= htmlspecialchars($match["symbol"]) ?></strong>
        <span class="text-muted"><?= htmlspecialchars((string) $match["name"]) ?></span>
    </a>
<?php endforeach ?>
</div>

<a href="javascript:history.go(-1);">Back</a>
