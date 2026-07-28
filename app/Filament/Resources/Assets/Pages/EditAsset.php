<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Models\Asset;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditAsset extends EditRecord
{
    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        /** @var Asset $record */
        $record = $this->record;

        if ($record->wasChanged(['value', 'type']) && ! $record->isVerified()) {
            Notification::make()
                ->title('Verification reset')
                ->body('Target changed — issue a new DNS token and verify ownership again before scanning.')
                ->warning()
                ->send();
        }
    }
}
