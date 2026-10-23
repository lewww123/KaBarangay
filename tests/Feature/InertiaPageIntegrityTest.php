<?php

namespace Tests\Feature;

use Tests\TestCase;

class InertiaPageIntegrityTest extends TestCase
{
    /**
     * Confirmed existing defects only: Inertia component targets referenced by
     * Inertia::render() or Route::inertia() whose page file does not exist.
     * Value format: "<call site> — <reason> (<fix batch>)".
     * Any entry whose page now exists is stale and fails this suite.
     *
     * Batch P0-2 resolved every previously listed target (pages created, and the
     * dead Sector\AnnouncementController removed), so the allowlist is now empty.
     * New entries must document a genuinely broken target.
     */
    private const KNOWN_BROKEN = [];

    public function test_every_inertia_component_target_has_a_page_file(): void
    {
        $targets = $this->componentTargets();

        $this->assertNotSame(
            [],
            $targets,
            'Scanner collected no Inertia component targets — the integrity check would pass vacuously.'
        );

        $unreferenced = array_diff(array_keys(self::KNOWN_BROKEN), array_keys($targets));

        $this->assertSame(
            [],
            $unreferenced,
            "KNOWN_BROKEN entries no longer referenced by any Inertia::render()/Route::inertia() call — remove them from the allowlist:\n".implode("\n", $unreferenced)
        );

        $missing = [];

        foreach ($targets as $page => $locations) {
            if (! $this->pageExists($page)) {
                $missing[$page] = $locations;
            }
        }

        $unexpected = array_diff_key($missing, self::KNOWN_BROKEN);

        $details = [];
        foreach ($unexpected as $page => $locations) {
            $details[] = sprintf('- %s referenced at %s', $page, implode(', ', $locations));
        }

        $this->assertSame(
            [],
            $unexpected,
            "Inertia component targets without a resources/js/pages/{page}.tsx file:\n".implode("\n", $details)
        );
    }

    public function test_known_broken_allowlist_has_no_stale_entries(): void
    {
        $stale = [];

        foreach (array_keys(self::KNOWN_BROKEN) as $page) {
            if ($this->pageExists($page)) {
                $stale[] = $page;
            }
        }

        $this->assertSame(
            [],
            $stale,
            "KNOWN_BROKEN entries whose page file now exists — remove them from the allowlist:\n".implode("\n", $stale)
        );
    }

    public function test_inertia_render_component_names_are_string_literals(): void
    {
        $dynamic = [];

        foreach ($this->phpSourceFiles() as $path) {
            $source = file_get_contents($path);
            $relative = $this->relativePath($path);
            $needle = 'Inertia::render(';
            $offset = 0;

            while (($pos = strpos($source, $needle, $offset)) !== false) {
                $after = ltrim(substr($source, $pos + strlen($needle)), " \t\n\r\0\x0B");

                if ($after === '' || ($after[0] !== "'" && $after[0] !== '"')) {
                    $dynamic[] = sprintf('%s:%d', $relative, $this->lineAt($source, $pos));
                }

                $offset = $pos + strlen($needle);
            }
        }

        $this->assertSame(
            [],
            $dynamic,
            "Inertia::render() calls without a string literal component name:\n".implode("\n", $dynamic)
        );
    }

    private function componentTargets(): array
    {
        $targets = [];

        foreach ($this->phpSourceFiles() as $path) {
            $source = file_get_contents($path);
            $relative = $this->relativePath($path);

            foreach ([
                '/Inertia::render\(\s*[\'"]([^\'"]+)[\'"]/',
                '/Route::inertia\(\s*[\'"][^\'"]+[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/',
            ] as $pattern) {
                preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $targets[$match[1][0]][] = sprintf(
                        '%s:%d',
                        $relative,
                        $this->lineAt($source, $match[0][1])
                    );
                }
            }
        }

        return $targets;
    }

    private function pageExists(string $page): bool
    {
        return is_file(resource_path('js/pages/'.str_replace('/', DIRECTORY_SEPARATOR, $page).'.tsx'));
    }

    private function phpSourceFiles(): array
    {
        $files = [];

        foreach (['app', 'routes'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(base_path($directory), \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }

    private function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }
}
