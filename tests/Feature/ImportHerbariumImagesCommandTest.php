<?php

namespace Tests\Feature;

use App\Console\Commands\ImportHerbariumImages;
use App\Models\Herbarium;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class ImportHerbariumImagesCommandTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir().'/dryherbarium-command-test-'.bin2hex(random_bytes(8));

        (new Filesystem())->makeDirectory($this->temporaryDirectory.'/source', 0755, true);
        (new Filesystem())->makeDirectory($this->temporaryDirectory.'/storage/logs', 0755, true);
        (new Filesystem())->makeDirectory($this->temporaryDirectory.'/storage/app/public/herbarium', 0755, true);

        $this->app->useStoragePath($this->temporaryDirectory.'/storage');
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->temporaryDirectory);

        parent::tearDown();
    }

    public function test_command_reports_and_skips_ambiguous_filename_without_a_database(): void
    {
        file_put_contents($this->temporaryDirectory.'/source/00123.JPG', 'image contents');

        $first = $this->herbarium(101, '123');
        $second = $this->herbarium(102, '00123');

        $command = new class([$first, $second]) extends ImportHerbariumImages
        {
            /** @param  list<Herbarium>  $candidates */
            public function __construct(private readonly array $candidates)
            {
                parent::__construct();
            }

            protected function herbariumCandidates(): iterable
            {
                return $this->candidates;
            }
        };

        $command->setLaravel($this->app);
        $output = new BufferedOutput();
        $exitCode = $command->run(
            new ArrayInput(['path' => $this->temporaryDirectory.'/source']),
            $output,
        );

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Ambiguous match for: 00123.JPG (herbarium IDs: 101, 102)',
            $output->fetch(),
        );
        $this->assertFileDoesNotExist(
            $this->temporaryDirectory.'/storage/app/public/herbarium/00123.JPG',
        );

        $reports = glob($this->temporaryDirectory.'/storage/logs/herbarium-import-*.log');
        $this->assertCount(1, $reports);
        $this->assertStringContainsString(
            'Ambiguous match for: 00123.JPG (herbarium IDs: 101, 102)',
            file_get_contents($reports[0]),
        );
        $this->assertStringContainsString('Imported 0 images.', file_get_contents($reports[0]));
    }

    private function herbarium(int $id, string $collectionNumber): Herbarium
    {
        $herbarium = new Herbarium(['collection_number' => $collectionNumber]);
        $herbarium->setAttribute('id', $id);

        return $herbarium;
    }
}
