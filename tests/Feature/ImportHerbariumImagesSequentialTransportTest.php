<?php

namespace Tests\Feature;

use Tests\TestCase;

class ImportHerbariumImagesSequentialTransportTest extends TestCase
{
    public function test_view_uses_single_file_livewire_uploads_without_multiple_model_binding(): void
    {
        $view = file_get_contents(resource_path('views/livewire/herbarium/import-images.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('$wire.upload(', $view);
        $this->assertStringContainsString("'incomingFile'", $view);
        $this->assertStringContainsString('multiple', $view);
        $this->assertStringContainsString('x-on:change="addFiles($event.target.files)"', $view);
        $this->assertStringContainsString('if (this.uploading || this.analyzing)', $view);
        $this->assertStringContainsString('await $wire.analyzePendingRows()', $view);
        $this->assertStringNotContainsString('$wire.uploadMultiple', $view);
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]+type="file"[^>]+wire:model/s',
            $view,
        );
        $this->assertSame('throttle:120,1', config('livewire.temporary_file_upload.middleware'));
    }
}
