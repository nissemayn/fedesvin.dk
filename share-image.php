<?php
declare(strict_types=1);

if (!function_exists('db')) {
    http_response_code(404);
    exit;
}

$code = is_string($_GET['code'] ?? null) ? $_GET['code'] : '';
$name = normalizeName(is_string($_GET['name'] ?? null) ? $_GET['name'] : '');
$personalMessage = '';
if ($code !== '') {
    $link = shortlink($code);
    if ($link === null) {
        http_response_code(404);
        exit;
    }
    $name = $link['name'];
    $personalMessage = $link['message'];
}
$name = displayName($name);
$variants = pigVariants();
$variant = is_string($_GET['v'] ?? null) && in_array($_GET['v'], $variants, true) ? $_GET['v'] : $variants[random_int(0, count($variants) - 1)];
$font = getenv('SHARE_FONT_REGULAR') ?: '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
$bold = getenv('SHARE_FONT_BOLD') ?: '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
if (!extension_loaded('gd') || !is_readable($font) || !is_readable($bold)) {
    error_log('Share image requires GD and readable TrueType fonts.');
    http_response_code(503);
    header('Cache-Control: no-store');
    exit;
}

function imageTextLines(string $text, string $font, int $size, int $width): array
{
    $lines = [];
    $line = '';
    foreach (explode(' ', $text) as $word) {
        $candidate = $line === '' ? $word : $line . ' ' . $word;
        $box = imagettfbbox($size, 0, $font, $candidate);
        if ($box[2] - $box[0] <= $width) {
            $line = $candidate;
            continue;
        }
        if ($line !== '') {
            $lines[] = $line;
            $line = '';
        }
        // Split long unbroken names/messages too, without splitting UTF-8 bytes.
        foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $box = imagettfbbox($size, 0, $font, $line . $character);
            if ($line !== '' && $box[2] - $box[0] > $width) {
                $lines[] = $line;
                $line = '';
            }
            $line .= $character;
        }
    }
    if ($line !== '') {
        $lines[] = $line;
    }
    return $lines;
}

$image = imagecreatetruecolor(1200, 630);
imageantialias($image, true);
$color = static fn (string $hex): int => imagecolorallocate($image, hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
$ink = $color('242322');
$yellow = $color('f5c84c');
$paper = $color('fffdf7');
$muted = $color('77736d');
$pink = $color('ee795e');
imagefill($image, 0, 0, $yellow);
for ($x = 8; $x < 1200; $x += 17) {
    for ($y = 8; $y < 630; $y += 17) {
        imagefilledellipse($image, $x, $y, 2, 2, $color('d6ae41'));
    }
}
imagefilledrectangle($image, 49, 49, 1169, 599, $ink);
imagefilledrectangle($image, 38, 38, 1158, 588, $paper);
imagesetthickness($image, 3);
imagerectangle($image, 38, 38, 1158, 588, $ink);
imagettftext($image, 18, 0, 82, 104, $ink, $bold, 'FEDESVIN.DK');
imagettftext($image, 13, 0, 82, 137, $muted, $font, 'Gammeldags internet. Nye svin.');

$greeting = $name !== '' ? 'Hej ' . $name . '!' : 'Hvem er dagens fedesvin?';
$size = 44;
while ($size > 26 && count(imageTextLines($greeting, $bold, $size, 650)) > 2) {
    $size -= 2;
}
$y = 227;
foreach (imageTextLines($greeting, $bold, $size, 650) as $line) {
    imagettftext($image, $size, 0, 82, $y, $ink, $bold, $line);
    $y += $size + 13;
}
$message = $personalMessage !== '' ? $personalMessage : ($name !== '' ? 'En eller anden synes åbenbart, at du er et fedt svin.' : 'Skriv et navn. Send linket. Spred lidt kærlig svinestreg.');
$messageSize = 22;
while ($messageSize > 16 && count(imageTextLines($message, $font, $messageSize, 640)) * ($messageSize + 12) > 490 - $y) {
    $messageSize -= 1;
}
$y += 10;
foreach (imageTextLines($message, $font, $messageSize, 640) as $line) {
    imagettftext($image, $messageSize, 0, 82, $y, $muted, $font, $line);
    $y += $messageSize + 12;
}

$pigColor = $color(['classic' => 'a6cee9', 'rose' => 'f3a5b7', 'mint' => 'a8d5b5', 'business' => 'c0b6df', 'party' => 'f6cb73', 'royal' => 'e4b4ed'][$variant]);
imagefilledellipse($image, 868, 196, 67, 94, $ink);
imagefilledellipse($image, 868, 196, 59, 86, $pigColor);
imagefilledellipse($image, 1022, 196, 67, 94, $ink);
imagefilledellipse($image, 1022, 196, 59, 86, $pigColor);
imagefilledellipse($image, 945, 285, 268, 232, $ink);
imagefilledellipse($image, 945, 282, 260, 224, $pigColor);
imagefilledellipse($image, 903, 252, 15, 19, $ink);
imagefilledellipse($image, 984, 252, 15, 19, $ink);
imagefilledellipse($image, 945, 300, 104, 64, $ink);
imagefilledellipse($image, 945, 300, 96, 56, $paper);
imagefilledellipse($image, 929, 300, 11, 12, $ink);
imagefilledellipse($image, 961, 300, 11, 12, $ink);
imagefilledellipse($image, 858, 314, 26, 16, $pink);
imagefilledellipse($image, 1032, 314, 26, 16, $pink);
imagearc($image, 945, 329, 30, 25, 0, 180, $ink);
if ($variant === 'business') {
    imagerectangle($image, 880, 235, 925, 267, $ink);
    imagerectangle($image, 963, 235, 1008, 267, $ink);
    imageline($image, 925, 248, 963, 248, $ink);
    imagefilledpolygon($image, [918, 365, 945, 378, 918, 391], $ink);
    imagefilledpolygon($image, [972, 365, 945, 378, 972, 391], $ink);
} elseif ($variant === 'party') {
    imagefilledpolygon($image, [908, 184, 950, 93, 987, 184], $pink);
    imagepolygon($image, [908, 184, 950, 93, 987, 184], $ink);
    imagefilledellipse($image, 950, 93, 22, 22, $ink);
    imagefilledellipse($image, 950, 93, 18, 18, $yellow);
    imagefilledellipse($image, 951, 150, 12, 12, $paper);
} elseif ($variant === 'royal') {
    imagefilledpolygon($image, [891, 178, 882, 125, 919, 148, 945, 114, 973, 148, 1009, 125, 1000, 178], $yellow);
    imagepolygon($image, [891, 178, 882, 125, 919, 148, 945, 114, 973, 148, 1009, 125, 1000, 178], $ink);
}
imagettftext($image, 12, 0, 856, 445, $muted, $bold, 'ET KOMPLIMENT.');
imagettftext($image, 12, 0, 867, 468, $muted, $bold, 'PÅ EN MÅDE.');
imageline($image, 82, 510, 1114, 510, $color('ded5bd'));
$linkLabel = $code !== '' ? DOMAIN . '/s/' . $code : ($name !== '' ? slugify($name) . '.' . DOMAIN : DOMAIN);
imagettftext($image, 15, 0, 82, 551, $ink, $bold, $linkLabel);

header('Content-Type: image/png');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
if (($_GET['download'] ?? '') === '1') {
    header('Content-Disposition: attachment; filename="fedesvin-' . ($name !== '' ? slugify($name) : 'kort') . '.png"');
}
imagepng($image);
imagedestroy($image);
exit;
