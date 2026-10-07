<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Enums\AssetStatus;
use App\Enums\AssetType;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListAssets extends ListRecords
{
    protected static string $resource = AssetResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return 'Domains you own. Each one is verified through DNS before it can be scanned.';
    }

    protected function getHeaderActions(): array
    {
        return [
            static::createAction(),
        ];
    }

    public static function createAction(): CreateAction
    {
        return CreateAction::make()
            ->label('Add target')
            ->icon(Heroicon::OutlinedPlus)
            ->modalHeading('Add a target')
            ->modalDescription('Next you will publish a DNS TXT record to prove you control the domain.')
            ->modalWidth(Width::Large)
            ->createAnother(false)
            ->mutateDataUsing(function (array $data): array {
                $data['type'] = AssetType::Domain->value;
                $data['created_by'] = auth()->id();
                $data['status'] = AssetStatus::Active->value;

                return $data;
            })
            ->successRedirectUrl(fn (Asset $record): string => AssetResource::getUrl('view', ['record' => $record]));
    }
}
