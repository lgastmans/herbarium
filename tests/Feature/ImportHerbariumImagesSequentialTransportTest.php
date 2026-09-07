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
        $this->assertStringContainsString('if (this.uploading || this.waitingBetweenFiles || this.analyzing)', $view);
        $this->assertStringContainsString('await $wire.analyzePendingRows()', $view);
        $this->assertStringNotContainsString('$wire.uploadMultiple', $view);
        $this->assertSame(1, substr_count($view, '$wire.upload('));
        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]+type="file"[^>]+wire:model/s',
            $view,
        );
        $this->assertSame('throttle:120,1', config('livewire.temporary_file_upload.middleware'));
    }

    public function test_queue_has_one_guarded_inter_file_timer_and_analyzes_once_after_the_group(): void
    {
        $view = file_get_contents(resource_path('views/livewire/herbarium/import-images.blade.php'));

        $this->assertIsString($view);
        $this->assertStringContainsString('if (!this.uploading) return;', $view);
        $this->assertStringContainsString('if (this.paceTimer !== null) return;', $view);
        $this->assertStringContainsString('this.waitingBetweenFiles = true;', $view);
        $this->assertStringContainsString('this.paceTimer = window.setTimeout(() => {', $view);
        $this->assertStringContainsString('window.clearTimeout(this.paceTimer);', $view);
        $this->assertStringContainsString('this.waitingBetweenFiles = false;', $view);
        $this->assertStringContainsString('}, 250);', $view);
        $this->assertStringContainsString(
            'this.uploading || this.waitingBetweenFiles || this.analyzing || this.queue.length > 0 || this.stagedCount > 0',
            $view,
        );
        $this->assertSame(1, substr_count($view, 'window.setTimeout('));
        $this->assertSame(1, substr_count($view, 'window.clearTimeout('));
        $this->assertSame(1, substr_count($view, 'await $wire.analyzePendingRows()'));
        $this->assertStringNotContainsString('setInterval(', $view);
        $this->assertStringContainsString(
            'x-bind:disabled="uploading || waitingBetweenFiles || analyzing || !@js($canImport)"',
            $view,
        );
    }
}
