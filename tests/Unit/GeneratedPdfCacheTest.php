<?php

namespace Tests\Unit;

use App\Services\Documents\GeneratedPdfCache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class GeneratedPdfCacheTest extends TestCase
{
    private string $workDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDirectory = storage_path('framework/testing/generated-pdf-cache-'.bin2hex(random_bytes(5)));
        File::ensureDirectoryExists($this->workDirectory);
        config()->set('sim_pd.documents.cache.directory', $this->workDirectory.'/cache');
        config()->set('sim_pd.documents.cache.version', 'test-v1');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workDirectory);

        parent::tearDown();
    }

    public function test_identical_document_uses_the_existing_private_pdf(): void
    {
        $cache = app(GeneratedPdfCache::class);
        $dependency = $this->sourceFile('template.docx', 'template-v1');
        $generations = 0;
        $generator = function () use (&$generations): string {
            $generations++;

            return $this->sourceFile('generated-'.$generations.'.pdf', "%PDF-1.7\nversion {$generations}");
        };

        $first = $cache->remember(
            'surat-tugas',
            'group-123',
            ['number' => 'SPT/001', 'employees' => ['Andi']],
            [$dependency],
            $generator
        );
        $second = $cache->remember(
            'surat-tugas',
            'group-123',
            ['employees' => ['Andi'], 'number' => 'SPT/001'],
            [$dependency],
            $generator
        );

        $this->assertSame($first, $second);
        $this->assertSame(1, $generations);
        $this->assertFileExists($first);
        $this->assertFileExists(substr($first, 0, -4).'.json');
    }

    public function test_changed_data_or_dependency_creates_a_new_version_and_removes_the_old_one(): void
    {
        $cache = app(GeneratedPdfCache::class);
        $dependency = $this->sourceFile('template.docx', 'template-v1');
        $generations = 0;
        $generator = function () use (&$generations): string {
            $generations++;

            return $this->sourceFile('generated-'.$generations.'.pdf', "%PDF-1.7\nversion {$generations}");
        };

        $first = $cache->remember(
            'travel-report',
            'travel-10',
            ['result' => 'Sebelum diperbarui'],
            [$dependency],
            $generator
        );
        $second = $cache->remember(
            'travel-report',
            'travel-10',
            ['result' => 'Sesudah diperbarui'],
            [$dependency],
            $generator
        );
        file_put_contents($dependency, 'template-v2');
        $third = $cache->remember(
            'travel-report',
            'travel-10',
            ['result' => 'Sesudah diperbarui'],
            [$dependency],
            $generator
        );

        $this->assertSame(3, $generations);
        $this->assertNotSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertFileDoesNotExist($first);
        $this->assertFileDoesNotExist($second);
        $this->assertFileExists($third);
    }

    public function test_failed_generation_does_not_replace_a_valid_older_version(): void
    {
        $cache = app(GeneratedPdfCache::class);
        $valid = $cache->remember(
            'travel-document',
            'travel-20',
            ['total' => 1000],
            [],
            fn (): string => $this->sourceFile('valid.pdf', "%PDF-1.7\nvalid")
        );

        try {
            $cache->remember(
                'travel-document',
                'travel-20',
                ['total' => 2000],
                [],
                fn (): string => $this->sourceFile('invalid.pdf', 'not a pdf')
            );
            $this->fail('Cache seharusnya menolak hasil generator yang bukan PDF.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Generator tidak menghasilkan dokumen PDF yang valid.', $exception->getMessage());
        }

        $this->assertFileExists($valid);
        $this->assertStringStartsWith('%PDF-', (string) file_get_contents($valid));
    }

    private function sourceFile(string $name, string $contents): string
    {
        $path = $this->workDirectory.'/'.$name;
        file_put_contents($path, $contents);

        return $path;
    }
}
