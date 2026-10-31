<?php

namespace App\Support;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;

final class ResidentQrCodePng
{
    /** Render a portable grayscale PNG without requiring the GD extension. */
    public static function make(string $content): string
    {
        $matrix = Encoder::encode($content, ErrorCorrectionLevel::M())->getMatrix();
        $scale = 6;
        $quietZone = 4;
        $modules = $matrix->getWidth();
        $pixels = ($modules + ($quietZone * 2)) * $scale;
        $raw = '';

        for ($y = -$quietZone; $y < $modules + $quietZone; $y++) {
            $row = '';
            for ($x = -$quietZone; $x < $modules + $quietZone; $x++) {
                $dark = $x >= 0 && $y >= 0 && $x < $modules && $y < $modules && $matrix->get($x, $y) === 1;
                $row .= str_repeat($dark ? "\x00" : "\xff", $scale);
            }

            $raw .= str_repeat("\x00".$row, $scale);
        }

        return "\x89PNG\r\n\x1a\n"
            .self::chunk('IHDR', pack('NNC5', $pixels, $pixels, 8, 0, 0, 0, 0))
            .self::chunk('IDAT', gzcompress($raw, 9))
            .self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }
}
