<?php

namespace App\Filament\Resources\PenetrationTests\Pages;

use App\Filament\Resources\PenetrationTests\PenetrationTestResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePenetrationTest extends CreateRecord
{
    protected static string $resource = PenetrationTestResource::class;

    /**
     * @var list<string>
     */
    protected array $repositoryIds = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->repositoryIds = array_values($data['repository_ids'] ?? []);
        unset($data['repository_ids']);
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->asset?->repositories()->sync($this->repositoryIds);
    }
}
