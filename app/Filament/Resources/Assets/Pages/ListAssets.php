<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Enums\AssetStatus;
use App\Filament\Resources\Assets\AssetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New target')
                ->modalWidth(Width::Medium)
                ->mutateFormDataUsing(function (array $data): array {
                    $data['created_by'] = auth()->id();
                    $data['status'] = AssetStatus::Active->value;

                    return $data;
                }),
        ];
    }
}
