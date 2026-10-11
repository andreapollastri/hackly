<?php

namespace App\Filament\Resources\PenetrationTests\Pages;

use App\Filament\Resources\PenetrationTests\PenetrationTestResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePenetrationTest extends CreateRecord
{
    protected static string $resource = PenetrationTestResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        return $data;
    }
}
