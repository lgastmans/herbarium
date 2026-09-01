<?php

namespace Tests\Feature;

use Tests\TestCase;

class ImportHerbariumImagesUiPolishTest extends TestCase
{
    public function test_app_layout_includes_livewire_styles_exactly_once(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertIsString($layout);
        $this->assertSame(1, substr_count($layout, '@livewireStyles'));
        $this->assertSame(1, substr_count($layout, '@livewireScripts'));
    }

    public function test_failure_and_import_loading_states_start_hidden_and_toggle_with_their_directives(): void
    {
        $view = $this->importView();

        $this->assertMatchesRegularExpression(
            '/<div\s+[^>]*x-show="failures\.length > 0"[^>]*x-cloak[^>]*>/s',
            $view,
        );
        $this->assertStringContainsString('x-on:click="failures = []"', $view);
        $this->assertMatchesRegularExpression(
            '/<span\s+wire:loading\.remove\s+wire:target="importBatch">Import assigned images<\/span>/',
            $view,
        );
        $this->assertMatchesRegularExpression(
            '/<span\s+wire:loading\.delay\.short\s+wire:target="importBatch">Importing…<\/span>/',
            $view,
        );
    }

    public function test_only_the_styled_file_chooser_is_visible_while_transport_attributes_are_preserved(): void
    {
        $view = $this->importView();
        $matched = preg_match('/<input\s+[^>]*id="herbarium-image-chooser"[^>]*>/s', $view, $matches);

        $this->assertSame(1, $matched);
        $input = $matches[0];
        $this->assertStringContainsString('type="file"', $input);
        $this->assertStringContainsString('class="sr-only"', $input);
        $this->assertStringContainsString('multiple', $input);
        $this->assertStringContainsString('accept="image/jpeg,image/png,.jpg,.jpeg,.png"', $input);
        $this->assertStringContainsString('x-ref="fileInput"', $input);
        $this->assertStringContainsString('x-on:change="addFiles($event.target.files)"', $input);
        $this->assertStringContainsString('x-bind:disabled="uploading || analyzing || remainingCapacity === 0"', $input);
        $this->assertSame(1, preg_match_all('/<input\s+[^>]*type="file"[^>]*>/s', $view));
        $this->assertStringContainsString('for="herbarium-image-chooser"', $view);
        $this->assertStringContainsString('Choose images', $view);
        $this->assertStringNotContainsString('chooser below', $view);
        $this->assertStringContainsString('x-on:drop.prevent="addFiles($event.dataTransfer.files)"', $view);
        $this->assertStringContainsString('$wire.upload(', $view);
        $this->assertStringNotContainsString('$wire.uploadMultiple', $view);
    }

    private function importView(): string
    {
        $view = file_get_contents(resource_path('views/livewire/herbarium/import-images.blade.php'));
        $this->assertIsString($view);

        return $view;
    }
}
