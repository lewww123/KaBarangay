<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\DB;
use RuntimeException;

trait UsesCertificateNlqDatabase
{
    protected function useCertificateNlqDatabase(): void
    {
        $path = __DIR__.'/../Fixtures/certificate-nlq.sqlite.sql';
        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException('Unable to load certificate NLQ fixture.');
        }

        foreach (explode(';', $sql) as $statement) {
            $statement = trim($statement);

            if ($statement !== '') {
                DB::statement($statement);
            }
        }
    }

    protected function certificateNlqResidentId(): int
    {
        return 9101;
    }

    protected function certificateNlqBarangayId(): int
    {
        return 9101;
    }
}
