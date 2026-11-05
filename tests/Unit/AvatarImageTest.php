<?php

namespace Tests\Unit;

use App\Support\AvatarImage;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AvatarImageTest extends TestCase
{
    protected function setUp(): void
    {
        if (! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD is not available.');
        }
    }

    /** A 200×100 JPEG: left half red, right half blue. */
    private function landscapeJpeg(): string
    {
        $img = imagecreatetruecolor(200, 100);
        imagefilledrectangle($img, 0, 0, 99, 99, imagecolorallocate($img, 255, 0, 0));
        imagefilledrectangle($img, 100, 0, 199, 99, imagecolorallocate($img, 0, 0, 255));
        ob_start();
        imagejpeg($img, null, 95);

        return (string) ob_get_clean();
    }

    /** Insert a minimal EXIF APP1 segment carrying an Orientation tag. */
    private function withOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "II*\x00\x08\x00\x00\x00"           // little-endian TIFF header, IFD0 at 8
            ."\x01\x00"                              // 1 entry
            ."\x12\x01\x03\x00\x01\x00\x00\x00"      // tag 0x0112 Orientation, SHORT, count 1
            .pack('v', $orientation)."\x00\x00"
            ."\x00\x00\x00\x00";                     // no next IFD
        $data = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($data) + 2).$data.substr($jpeg, 2);
    }

    private function rgbAt(string $jpeg, int $x, int $y): array
    {
        $img = imagecreatefromstring($jpeg);
        $c = imagecolorat($img, $x, $y);

        return [($c >> 16) & 0xFF, ($c >> 8) & 0xFF, $c & 0xFF];
    }

    public function test_crops_to_a_centred_square_jpeg(): void
    {
        [$bytes, $ext] = AvatarImage::normalize($this->landscapeJpeg());

        $this->assertSame('jpg', $ext);
        [$w, $h, $type] = getimagesizefromstring($bytes);
        $this->assertSame([AvatarImage::SIZE, AvatarImage::SIZE, IMAGETYPE_JPEG], [$w, $h, $type]);

        // Centre crop keeps red on the left and blue on the right.
        [$r, , $b] = $this->rgbAt($bytes, 20, 128);
        $this->assertGreaterThan(200, $r);
        $this->assertLessThan(60, $b);
    }

    public function test_applies_exif_orientation_and_strips_metadata(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('exif is not available.');
        }

        // Orientation 6 = rotate 90° clockwise to display: red ends up on top.
        [$bytes] = AvatarImage::normalize($this->withOrientation($this->landscapeJpeg(), 6));

        [$r, , $b] = $this->rgbAt($bytes, 128, 20);
        $this->assertGreaterThan(200, $r);
        $this->assertLessThan(60, $b);
        [$r, , $b] = $this->rgbAt($bytes, 128, 235);
        $this->assertLessThan(60, $r);
        $this->assertGreaterThan(200, $b);

        $this->assertStringNotContainsString("Exif\x00\x00", $bytes);
    }

    public function test_rejects_non_images_and_huge_images(): void
    {
        try {
            AvatarImage::normalize('definitely not an image');
            $this->fail('Expected an exception.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        $img = imagecreatetruecolor(5000, 4000);
        ob_start();
        imagepng($img, null, 9);
        $png = (string) ob_get_clean();

        $this->expectException(InvalidArgumentException::class);
        AvatarImage::normalize($png);
    }
}
