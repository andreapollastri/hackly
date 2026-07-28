<?php

namespace App\Filament\Resources\ScanPolicies\Pages;

use App\Filament\Resources\ScanPolicies\ScanPolicyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageScanPolicies extends ManageRecords
{
    protected static string $resource = ScanPolicyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
