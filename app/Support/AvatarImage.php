<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Turns an uploaded photo into a small, square profile picture.
 *
 * With GD available (it is in the Docker image) the image is centre-cropped to
 * a square, resized to SIZE px, honours the EXIF orientation of phone photos
 * and is re-encoded as JPEG. Re-encoding also drops all metadata (EXIF GPS
 * etc.) and anything that isn't pixels. Without GD the validated original is
 * kept as-is.
 */
class AvatarImage
{
    public const SIZE = 256;

    /** Refuse images that would need an unreasonable amount of memory to decode. */
    public const MAX_PIXELS = 4096 * 4096;

    private const TYPES = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * @return array{0: string, 1: string} [bytes, file extension]
     */
    public static function normalize(string $binary): array
    {
        $info = @getimagesizefromstring($binary);
        if (! $info || ! isset(self::TYPES[$info[2]])) {
            throw new InvalidArgumentException('Unsupported image.');
        }
        [$w, $h, $type] = $info;
        if ($w < 1 || $h < 1 || $w * $h > self::MAX_PIXELS) {
            throw new InvalidArgumentException('Image dimensions are out of range.');
        }

        if (! function_exists('imagecreatefromstring')) {
            return [$binary, self::TYPES[$type]];
        }

        $src = @imagecreatefromstring($binary);
        if (! $src) {
            throw new InvalidArgumentException('Unreadable image.');
        }
        if ($type === IMAGETYPE_JPEG) {
            $src = self::orient($src, $binary);
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);

        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        // JPEG has no alpha: flatten transparent PNG/GIF/WebP onto white.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled(
            $dst, $src,
            0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2),
            self::SIZE, self::SIZE, $side, $side,
        );

        ob_start();
        imagejpeg($dst, null, 86);
        $out = (string) ob_get_clean();

        return [$out, 'jpg'];
    }

    /** Apply the EXIF orientation so photos taken in portrait aren't sideways. */
    private static function orient(\GdImage $img, string $binary): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $img;
        }

        $stream = fopen('php://memory', 'r+b');
        fwrite($stream, $binary);
        rewind($stream);
        $exif = @exif_read_data($stream);
        fclose($stream);

        $rotate = fn (\GdImage $i, int $deg) => imagerotate($i, $deg, 0) ?: $i;
        $flip = function (\GdImage $i): \GdImage {
            imageflip($i, IMG_FLIP_HORIZONTAL);

            return $i;
        };

        return match ((int) ($exif['Orientation'] ?? 1)) {
            2 => $flip($img),
            3 => $rotate($img, 180),
            4 => (function () use ($img) {
                imageflip($img, IMG_FLIP_VERTICAL);

                return $img;
            })(),
            5 => $flip($rotate($img, -90)),
            6 => $rotate($img, -90),
            7 => $flip($rotate($img, 90)),
            8 => $rotate($img, 90),
            default => $img,
        };
    }
}
