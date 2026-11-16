<?php
/**
 * The AI outlook panel, used by the quote page and the dashboard.
 *
 * The including template sets $outlook_scope ('share' or 'market'),
 * $outlook_symbol (null for market) and $outlook_title, then includes this.
 *
 * $outlook_collapse, if true, shows only the verdict badge and the summary
 * sentence and folds the rest - For, Against, Action, Watch and the meta
 * line - under a "Read the full outlook" link. The outlook is eight
 * paragraphs and it was the first thing on the home page; a reader looking
 * for what their holdings did had to scroll past all of it. It opens by
 * default when it was generated today, because an outlook somebody has just
 * asked for is one they want to read.
 *
 * Loading this costs nothing: it reads the last saved outlook out of
 * ai_outlook and draws it. Only the Generate button calls the API.
 *
 * Everything from the model is escaped here, and rebuilt with textContent by
 * the Generate handler in scripts.js. Model output is untrusted text: it is
 * shaped by the headlines that went into the prompt, and a headline is
 * written by whoever wrote the headline.
 */

$outlook_latest = outlook_latest($outlook_scope, $outlook_symbol ?? null);

$outlook_badge = function ($stance) {
    $map = ["positive" => "text-bg-success", "negative" => "text-bg-danger"];
    return $map[$stance] ?? "text-bg-secondary";
};

$outlook_collapse = !empty($outlook_collapse);
/* Generated today, so it is probably what the reader just asked for. */
$outlook_fresh = $outlook_latest !== null
               && substr((string) $outlook_latest["created_at"], 0, 10) === date('Y-m-d');
$outlook_open  = !$outlook_collapse || $outlook_fresh;
/* One id per panel, because the quote page shows a share outlook and the
   dashboard a market one and two panels with the same id collapse together. */
$outlook_id = 'outlook-rest-' . preg_replace('/[^a-z0-9]+/i', '-',
                 $outlook_scope . '-' . (string) ($outlook_symbol ?? 'market'));
?>
<div class="card card-default mb-3 sm-outlook"
     data-scope="<?= htmlspecialchars($outlook_scope) ?>"
     data-symbol="<?= htmlspecialchars((string) ($outlook_symbol ?? '')) ?>">

    <div class="card-header d-flex justify-content-between align-items-center">
        <span><?= htmlspecialchars($outlook_title) ?></span>
        <button type="button" class="btn btn-sm btn-outline-primary sm-outlook-generate">
            Generate
        </button>
    </div>

    <div class="card-body">

        <div class="sm-outlook-spinner d-none" role="status">
            <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
            <span class="ms-2">Thinking. This takes a few seconds.</span>
        </div>

        <div class="sm-outlook-error alert alert-warning d-none" role="alert"></div>

        <div class="sm-outlook-body">
        <?php if ($outlook_latest === null): ?>
            <p class="text-muted mb-0">No outlook yet.</p>
        <?php else: ?>
            <?php $o = $outlook_latest["outlook"]; ?>
            <p class="mb-2">
                <span class="badge <?= $outlook_badge($o["stance"] ?? '') ?>">
                    <?= htmlspecialchars(ucfirst((string) ($o["stance"] ?? ''))) ?>
                </span>
            </p>

            <p<?= $outlook_collapse ? ' class="mb-2"' : '' ?>><?= htmlspecialchars((string) ($o["summary"] ?? '')) ?></p>

            <?php if ($outlook_collapse): ?>
                <p class="mb-2">
                    <a class="small" data-bs-toggle="collapse" href="#<?= htmlspecialchars($outlook_id) ?>"
                       role="button" aria-expanded="<?= $outlook_open ? 'true' : 'false' ?>"
                       aria-controls="<?= htmlspecialchars($outlook_id) ?>">Read the full outlook</a>
                </p>
            <?php endif ?>

            <div<?= $outlook_collapse
                     ? ' class="collapse' . ($outlook_open ? ' show' : '') . '" id="'
                       . htmlspecialchars($outlook_id) . '"'
                     : '' ?>>

            <?php if (!empty($o["positives"])): ?>
                <p class="mb-1"><strong>For</strong></p>
                <ul>
                    <?php foreach ($o["positives"] as $point): ?>
                        <li><?= htmlspecialchars((string) $point) ?></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>

            <?php if (!empty($o["risks"])): ?>
                <p class="mb-1"><strong>Against</strong></p>
                <ul>
                    <?php foreach ($o["risks"] as $point): ?>
                        <li><?= htmlspecialchars((string) $point) ?></li>
                    <?php endforeach ?>
                </ul>
            <?php endif ?>

            <?php if (trim((string) ($o["action"] ?? '')) !== ''): ?>
                <p class="mb-1"><strong>Action</strong> <?= htmlspecialchars((string) $o["action"]) ?></p>
            <?php endif ?>

            <?php if (trim((string) ($o["watch"] ?? '')) !== ''): ?>
                <p class="mb-1"><strong>Watch</strong> <?= htmlspecialchars((string) $o["watch"]) ?></p>
            <?php endif ?>

            <p class="text-muted small mb-0 sm-outlook-meta">
                <?php if (!empty($outlook_latest["notes_used"])): ?>
                    Notes used: <?= htmlspecialchars(implode(', ', $outlook_latest["notes_used"])) ?>.
                <?php else: ?>
                    No vault notes were available.
                <?php endif ?>
                Data as of <?= htmlspecialchars(((string) $outlook_latest["as_of_date"]) ?: 'an unknown date') ?>.
                <?= htmlspecialchars((string) $outlook_latest["model"]) ?>,
                generated <?= htmlspecialchars((string) $outlook_latest["created_at"]) ?>,
                cost $<?= htmlspecialchars(number_format((float) $outlook_latest["cost"], 4)) ?>.
                Not financial advice.
            </p>
            </div>
        <?php endif ?>
        </div>
    </div>
</div>
