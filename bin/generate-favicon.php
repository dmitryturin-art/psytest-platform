<?php

/**
 * Генератор растровых фавиконок из public/favicon.svg.
 *
 * Векторная иконка — источник истины; PNG 32×32 и favicon.ico нужны только
 * тем браузерам, которые не берут SVG (и тому запросу `/favicon.ico`, который
 * браузер делает сам и который раньше давал 404 на каждой странице).
 *
 * Внешних зависимостей нет: рисуем те же примитивы через GD и упаковываем
 * PNG в контейнер ICO вручную. Запуск: `php bin/generate-favicon.php`.
 */

declare(strict_types=1);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "ext-gd is required to regenerate the favicon.\n");
    exit(1);
}

const SIZE = 32;

$public = dirname(__DIR__) . '/public';

$image = imagecreatetruecolor(SIZE, SIZE);
imagesavealpha($image, true);
imagealphablending($image, false);
imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
imagealphablending($image, true);

$brand = imagecolorallocate($image, 0x34, 0x98, 0xdb);
$white = imagecolorallocate($image, 0xff, 0xff, 0xff);

// Скруглённая плитка фирменного цвета.
$radius = 7;
imagefilledrectangle($image, $radius, 0, SIZE - 1 - $radius, SIZE - 1, $brand);
imagefilledrectangle($image, 0, $radius, SIZE - 1, SIZE - 1 - $radius, $brand);
foreach ([[$radius, $radius], [SIZE - 1 - $radius, $radius], [$radius, SIZE - 1 - $radius], [SIZE - 1 - $radius, SIZE - 1 - $radius]] as [$cx, $cy]) {
    imagefilledellipse($image, $cx, $cy, $radius * 2, $radius * 2, $brand);
}

// Буква P: стойка и полукруглая чаша.
imagefilledrectangle($image, 10, 8, 13, 25, $white);
imagefilledellipse($image, 15, 13, 14, 12, $white);
imagefilledellipse($image, 15, 13, 7, 5, $brand);
imagefilledrectangle($image, 10, 8, 13, 25, $white);

// Точка-акцент — тот же мотив, что в SVG.
imagefilledellipse($image, 24, 24, 7, 7, $white);

$pngPath = $public . '/favicon-32.png';
imagepng($image, $pngPath, 9);

$png = (string) file_get_contents($pngPath);

// ICO-контейнер с одним PNG-изображением (поддерживается всеми
// актуальными браузерами; формат описан в Microsoft ICO spec).
$ico = pack('vvv', 0, 1, 1)
    . pack('CCCCvvVV', SIZE, SIZE, 0, 0, 1, 32, strlen($png), 22)
    . $png;

file_put_contents($public . '/favicon.ico', $ico);

printf("favicon-32.png: %d bytes\nfavicon.ico: %d bytes\n", strlen($png), strlen($ico));
