<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * Vertical-named fixtures only. Production defaults stay generic.
 */
final class LinePersonalisationFixtures
{
    public static function jpeg(int $width = 1, int $height = 1, ?int $padBytes = null): UploadedFile
    {
        if (class_exists(\Imagick::class)) {
            $im = new \Imagick;
            $im->newImage(max(1, $width), max(1, $height), new \ImagickPixel('#2980b9'));
            $im->setImageFormat('jpeg');
            $jpeg = $im->getImageBlob();
            $im->clear();
            $im->destroy();
            if ($padBytes) {
                $jpeg .= str_repeat('x', $padBytes);
            }
            $path = tempnam(sys_get_temp_dir(), 'lpfix');
            file_put_contents($path, $jpeg);

            return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
        }

        $jpeg = hex2bin('ffd8ffe000104a46494600010101000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc40014100000000000000000000000000000000000ffda00080001010100003f00fbffd9');
        $sof = strpos($jpeg, "\xFF\xC0");
        if ($sof !== false) {
            $jpeg = substr_replace($jpeg, pack('n', $height).pack('n', $width), $sof + 5, 4);
        }
        if ($padBytes) {
            $jpeg .= str_repeat('x', $padBytes);
        }
        $path = tempnam(sys_get_temp_dir(), 'lpjpg');
        file_put_contents($path, $jpeg);

        return new UploadedFile($path, 'photo.jpg', 'image/jpeg', null, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function bakery(): array
    {
        return [
            [
                'slug' => 'message',
                'label' => 'Message on the cake',
                'kind' => 'text',
                'required' => true,
                'max_chars' => 80,
                'pattern' => 'no-emoji',
                'help' => 'Printed on the top',
            ],
            [
                'slug' => 'photo',
                'label' => 'Photo for the cake',
                'kind' => 'image',
                'required' => false,
                'max_files' => 1,
                'help' => 'Optional reference',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function florist(): array
    {
        return [
            [
                'slug' => 'card-message',
                'label' => 'Card message',
                'kind' => 'textarea',
                'required' => true,
                'max_chars' => 250,
                'pattern' => null,
                'help' => '',
            ],
            [
                'slug' => 'reference-photo',
                'label' => 'Reference photo',
                'kind' => 'image',
                'required' => false,
                'max_files' => 2,
                'help' => '',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function generic(): array
    {
        return [
            [
                'slug' => 'engraving',
                'label' => 'Engraving',
                'kind' => 'text',
                'required' => true,
                'max_chars' => 40,
                'pattern' => 'letters-digits-spaces',
                'help' => '',
            ],
            [
                'slug' => 'colour',
                'label' => 'Colour',
                'kind' => 'choice',
                'required' => true,
                'options' => ['Silver', 'Gold', 'Rose gold'],
                'help' => '',
            ],
        ];
    }
}
