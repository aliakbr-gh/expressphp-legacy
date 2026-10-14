<?php

declare(strict_types=1);

?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($title ?? 'ExpressPHP') ?></title>
</head>

<body>
    <h1><?= $escape($title ?? 'ExpressPHP') ?></h1>
    <p><?= $escape($message ?? 'Your view is ready.') ?></p>
    <ul>
        <?php foreach ($cars ?? [] as $car): ?>
            <li><?= $escape($car) ?></li>
        <?php endforeach; ?>
    </ul>
</body>

</html>