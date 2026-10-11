<?php

namespace App\Filament\Resources\PenetrationTests\Pages;

use App\Filament\Resources\PenetrationTests\PenetrationTestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPenetrationTests extends ListRecords
{
    protected static string $resource = PenetrationTestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
