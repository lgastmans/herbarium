<?php

namespace App\Livewire;

use App\Exceptions\HerbariumImageImportException;
use App\Models\Herbarium;
use App\Models\User;
use App\Services\HerbariumImageStorage\HerbariumImageAssignmentType;
use App\Services\HerbariumImageStorage\HerbariumImageImportSource;
use App\Services\HerbariumImageStorage\HerbariumImageStorageService;
use App\Services\HerbariumImageStorage\HerbariumImageStorageStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Throwable;

class UploadHerbariumImage extends Component
{
    use WithFileUploads;

    public mixed $photo = null;

    #[Locked]
    public int $herbariumId;

    public ?string $statusMessage = null;

    public ?string $statusType = null;

    public function mount(int $herbariumId): void
    {
        $this->herbariumId = $herbariumId;
    }

    public function save(HerbariumImageStorageService $storageService): void
    {
        $user = $this->authenticatedVerifiedUser();
        $this->statusMessage = null;
        $this->statusType = null;
        $this->resetErrorBag('photo');
        $this->validate();

        if (! $this->photo instanceof TemporaryUploadedFile) {
            $this->addError('photo', 'Choose a JPEG or PNG image to upload.');

            return;
        }

        $herbarium = Herbarium::query()->find($this->herbariumId);

        if ($herbarium === null) {
            $this->addError('photo', 'This herbarium collection is no longer available. Close the viewer and try again.');

            return;
        }

        $originalFilename = $this->photo->getClientOriginalName();

        try {
            $result = $storageService->import(
                $herbarium,
                $this->photo,
                $originalFilename,
                HerbariumImageAssignmentType::Manual,
                HerbariumImageImportSource::SingleUploader,
                null,
                $user,
            );
        } catch (HerbariumImageImportException $exception) {
            Log::warning('A single herbarium image upload failed safe storage.', [
                'herbarium_id' => $this->herbariumId,
                'user_id' => $user->getKey(),
                'original_filename' => $originalFilename,
                'exception' => $exception,
            ]);

            $this->addError('photo', 'The image could not be uploaded safely. Choose a valid JPEG or PNG no larger than 5 MiB and try again.');

            return;
        } catch (Throwable $exception) {
            Log::error('An unexpected single herbarium image upload failure occurred.', [
                'herbarium_id' => $this->herbariumId,
                'user_id' => $user->getKey(),
                'original_filename' => $originalFilename,
                'exception' => $exception,
            ]);

            $this->addError('photo', 'An unexpected error prevented the image upload. Please try again.');

            return;
        }

        $this->reset('photo');

        if ($result->status === HerbariumImageStorageStatus::Duplicate) {
            $this->statusType = 'duplicate';
            $this->statusMessage = 'This image already exists for the selected herbarium collection.';
        } else {
            $this->statusType = 'success';
            $this->statusMessage = "{$originalFilename} was uploaded successfully.";
        }

        $this->dispatch('refreshHerbariumImageTable');
    }

    public function render()
    {
        return view('livewire.upload-herbarium-image');
    }

    /** @return array<string, list<string>> */
    protected function rules(): array
    {
        return [
            'photo' => [
                'required',
                'file',
                'mimetypes:image/jpeg,image/png',
                'max:'.intdiv(HerbariumImageStorageService::MAX_FILE_SIZE, 1024),
            ],
        ];
    }

    private function authenticatedVerifiedUser(): User
    {
        if (! Auth::check()) {
            abort(401);
        }

        $user = Auth::user();

        if (! $user instanceof User || ! $user->hasVerifiedEmail()) {
            abort(403);
        }

        return $user;
    }
}
