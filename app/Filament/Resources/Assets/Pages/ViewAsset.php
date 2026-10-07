<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Actions\StartTargetScanAction;
use App\Filament\Actions\TargetActions;
use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewAsset extends ViewRecord
{
    protected static string $resource = AssetResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->value;
    }

    public function getSubheading(): string|Htmlable|null
    {
        /** @var Asset $asset */
        $asset = $this->getRecord();

        return $asset->isVerified()
            ? 'Target · ownership verified '.$asset->verified_at->diffForHumans()
            : 'Target · ownership not verified yet';
    }

    protected function getHeaderActions(): array
    {
        return [
            TargetActions::verify(),
            StartTargetScanAction::make()
                ->visible(fn (): bool => $this->getRecord()->isVerified()),
            ActionGroup::make([
                EditAction::make(),
                TargetActions::toggleStatus(),
                TargetActions::regenerateToken(),
                DeleteAction::make(),
            ])->tooltip('More'),
        ];
    }

    public function getContentTabLabel(): ?string
    {
        return 'Overview';
    }
}
