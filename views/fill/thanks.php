<?php
use App\Core\View;

// PRG (P6): este template se sirve SOLO vía GET /f/{slug}/thanks, nunca como
// respuesta directa al POST (evita reenvíos al refrescar).
$settings = $settings ?? [];
$thanks = $settings['thanks'] ?? [];
$customMessage = trim((string) ($thanks['message'] ?? ''));
$quiz = $quiz ?? null;

// P8 — Mapa id => campo para etiquetas del quiz.
$fieldsByIdQuiz = [];
foreach (($form['fields'] ?? []) as $ff) {
    $fieldsByIdQuiz[(int) ($ff['id'] ?? 0)] = $ff;
}

// P9 — i18n reducido (idioma del formulario + catálogo es/en).
$locale = \App\Core\I18n::resolve($settings);
$t = static fn (string $key, array $params = []): string => \App\Core\I18n::t($key, $locale, $params);

// P8 — Quiz: explicación de la puntuación para el bloque de resultados.
$quizTypeLabel = static function (string $type) use ($locale): string {
    $key = 'type.' . $type;
    $label = \App\Core\I18n::t($key, $locale);
    return $label === $key ? ucfirst($type) : $label;
};
?>
<!DOCTYPE html>
<html lang="<?= $locale ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $t('thanks.title') ?> — <?= View::escape($form['title']) ?></title>
    <link rel="icon" type="image/svg+xml" href="/assets/favicon.svg">
    <link rel="stylesheet" href="<?= View::asset('css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/all.min.css') ?>">
    <link rel="stylesheet" href="<?= View::asset('css/app.css') ?>">
    <?php
    // P7 — Tema inline (gana a app.css por orden de carga).
    $themeCss = \App\Services\FormSettings::themeCss($settings);
    if ($themeCss !== ''): ?><style><?= $themeCss ?></style><?php endif; ?>
</head>
<body class="fill-body">
<div class="fill-container">
    <div class="card border-0 shadow-sm app-fill-card text-center">
        <div class="card-body p-5">
            <div class="app-success-icon mx-auto mb-3"><i class="fa-solid fa-check"></i></div>
            <h1 class="h3 fw-bold mb-2"><?= $customMessage !== '' ? $t('thanks.custom_title') : $t('thanks.title') ?></h1>
            <?php if ($customMessage !== ''): ?>
            <p class="mb-4"><?= nl2br(View::escape($customMessage)) ?></p>
            <?php else: ?>
            <p class="text-muted mb-4"><?= str_replace('{title}', View::escape($form['title']), $t('thanks.body')) ?></p>
            <?php endif; ?>

            <?php if ($quiz !== null && ($settings['quiz']['enabled'] ?? false)): ?>
            <?php
            $okPct = $quiz['max'] > 0 ? (int) round(($quiz['points'] / $quiz['max']) * 100) : 0;
            $quizOk = $quiz['points'] >= $quiz['max'];
            ?>
            <div class="app-quiz-results text-start mx-auto mt-4" style="max-width:520px">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="app-quiz-badge rounded-circle d-flex align-items-center justify-content-center text-white flex-shrink-0 <?= $quizOk ? 'bg-success' : 'bg-primary' ?>">
                        <i class="fa-solid <?= $quizOk ? 'fa-trophy' : 'fa-star-half-stroke' ?>"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5"><?= $t('quiz.result', ['points' => (int) $quiz['points'], 'max' => (int) $quiz['max']]) ?></div>
                        <div class="text-muted small"><?= $quizOk ? $t('quiz.perfect') : $t('quiz.review') ?></div>
                    </div>
                </div>
                <div class="progress app-quiz-bar mb-4" style="height:8px">
                    <div class="progress-bar <?= $quizOk ? 'bg-success' : 'bg-primary' ?>" style="width:<?= $okPct ?>%"></div>
                </div>
                <ul class="list-group list-group-flush">
                    <?php foreach ($quiz['per'] as $fid => $item): ?>
                    <?php
                    $expected = is_array($item['expected']) ? implode(', ', array_map('strval', $item['expected'])) : (string) $item['expected'];
                    $answerShown = is_array($item['answer']) ? implode(', ', array_map('strval', $item['answer'])) : (string) ($item['answer'] ?? '—');
                    ?>
                    <li class="list-group-item px-0 d-flex align-items-start gap-2 <?= $item['correct'] ? 'bg-success-subtle' : 'bg-danger-subtle' ?>">
                        <i class="fa-solid fa-circle-check mt-1 <?= $item['correct'] ? 'text-success' : 'text-danger' ?>"></i>
                        <div class="small flex-grow-1">
                            <div class="fw-semibold"><?= View::escape((string) ($fieldsByIdQuiz[$fid]['label'] ?? $t('quiz.question', ['n' => (int) $fid]))) ?> <span class="text-muted fw-normal">· <?= View::escape($quizTypeLabel((string) ($fieldsByIdQuiz[$fid]['type'] ?? ''))) ?></span></div>
                            <?php if ($item['correct']): ?>
                            <div class="text-success"><?= $t('quiz.correct') ?></div>
                            <?php else: ?>
                            <div class="text-danger"><?= $t('quiz.your_answer') ?> <span class="text-decoration-line-through"><?= View::escape($answerShown) ?></span></div>
                            <div class="text-muted"><?= $t('quiz.correct_answer') ?> <strong><?= View::escape($expected) ?></strong></div>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <a href="/f/<?= View::escape($form['slug']) ?>" class="btn btn-outline-primary"><i class="fa-solid fa-rotate-left me-1"></i><?= $t('thanks.again') ?></a>
        </div>
    </div>
</div>
</body>
</html>