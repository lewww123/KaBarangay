<?php

namespace App\Support;

use Closure;
use RuntimeException;
use Throwable;

final class StagedDocument
{
    public static function write(string $outputPath, Closure $build): void
    {
        $temporary = tempnam(sys_get_temp_dir(), 'kabarangay_document_');
        if ($temporary === false) {
            throw new RuntimeException('Unable to create document staging file.');
        }
        $created = false;
        try {
            $build($temporary);
            // Open each UUID destination once. This avoids Windows CopyFile/ZIP
            // replacement races and never overwrites a previously issued file.
            $target = false;
            for ($attempt = 0; $attempt < 5 && $target === false; $attempt++) {
                $target = @fopen($outputPath, 'xb');
                if ($target === false && ! file_exists($outputPath)) {
                    usleep(50000 * ($attempt + 1));
                } elseif ($target === false) {
                    break;
                }
            }
            if ($target === false) {
                throw new RuntimeException('Unable to create the completed document.');
            }
            $created = true;
            $source = null;
            try {
                $source = fopen($temporary, 'rb');
                if (! $source || stream_copy_to_stream($source, $target) !== filesize($temporary) || ! fflush($target)) {
                    throw new RuntimeException('Unable to save the completed document.');
                }
            } finally {
                if (is_resource($source)) {
                    fclose($source);
                }
                fclose($target);
            }
        } catch (Throwable $error) {
            if ($created) {
                @unlink($outputPath);
            }
            throw $error;
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
