<?php

namespace App\Filament\Resources\PenetrationTests\Pages;

use App\Filament\Resources\PenetrationTests\PenetrationTestResource;
use Filament\Resources\Pages\EditRecord;

class EditPenetrationTest extends EditRecord
{
    protected static string $resource = PenetrationTestResource::class;

    /**
     * @var list<string>
     */
    protected array $repositoryIds = [];

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['repository_ids'] = $this->record->asset?->repositories()->pluck('repositories.id')->all() ?? [];

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->repositoryIds = array_values($data['repository_ids'] ?? []);
        unset($data['repository_ids']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->record->asset?->repositories()->sync($this->repositoryIds);
    }
}
