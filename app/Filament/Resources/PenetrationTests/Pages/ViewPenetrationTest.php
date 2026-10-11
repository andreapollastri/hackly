<?php

namespace App\Filament\Resources\PenetrationTests\Pages;

use App\Filament\Resources\PenetrationTests\PenetrationTestResource;
use Filament\Resources\Pages\ViewRecord;

class ViewPenetrationTest extends ViewRecord
{
    protected static string $resource = PenetrationTestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PenetrationTestResource::issueAction(),
            PenetrationTestResource::checkAction(),
            PenetrationTestResource::startAction(),
            PenetrationTestResource::editHeaderAction(),
        ];
    }
}
