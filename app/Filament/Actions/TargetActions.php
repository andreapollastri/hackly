<?php

namespace App\Filament\Actions;

use App\Domain\Scanning\Services\DnsOwnershipVerifier;
use App\Enums\AssetStatus;
use App\Models\Asset;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class TargetActions
{
    /**
     * Single guided flow: shows the TXT record to publish and checks DNS on submit.
     */
    public static function verify(): Action
    {
        return Action::make('verifyOwnership')
            ->label('Verify ownership')
            ->icon(Heroicon::OutlinedShieldCheck)
            ->color('warning')
            ->visible(fn (Asset $record): bool => ! $record->isVerified())
            ->modalIcon(Heroicon::OutlinedShieldCheck)
            ->modalIconColor('warning')
            ->modalHeading(fn (Asset $record): string => "Verify {$record->value}")
            ->modalDescription('Prove you control this domain before Hackly is allowed to scan it.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalContent(fn (Asset $record) => view('filament.targets.verification', [
                'asset' => $record,
                'token' => app(DnsOwnershipVerifier::class)->ensureToken($record),
            ]))
            ->modalSubmitActionLabel('Check DNS now')
            ->modalCancelActionLabel('Later')
            ->action(function (Asset $record, Action $action): void {
                try {
                    app(DnsOwnershipVerifier::class)->verify($record);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('TXT record not found yet')
                        ->body($e->getMessage())
                        ->warning()
                        ->persistent()
                        ->send();

                    $action->halt();

                    return;
                }

                Notification::make()
                    ->title("{$record->value} verified")
                    ->body('Ownership confirmed. You can start scanning this target.')
                    ->success()
                    ->send();
            });
    }

    public static function regenerateToken(): Action
    {
        return Action::make('regenerateToken')
            ->label('New verification token')
            ->icon(Heroicon::OutlinedKey)
            ->visible(fn (Asset $record): bool => ! $record->isVerified())
            ->requiresConfirmation()
            ->modalDescription('The current TXT value stops working. Only do this if the token leaked or you want to start over.')
            ->action(fn (Asset $record) => app(DnsOwnershipVerifier::class)->issueToken($record))
            ->successNotificationTitle('New token issued — update your TXT record');
    }

    public static function toggleStatus(): Action
    {
        return Action::make('toggleStatus')
            ->label(fn (Asset $record): string => $record->isActive() ? 'Pause target' : 'Resume target')
            ->icon(fn (Asset $record): Heroicon => $record->isActive() ? Heroicon::OutlinedPauseCircle : Heroicon::OutlinedPlayCircle)
            ->action(fn (Asset $record) => $record->update([
                'status' => $record->isActive() ? AssetStatus::Paused : AssetStatus::Active,
            ]))
            ->successNotificationTitle(fn (Asset $record): string => $record->isActive() ? 'Target resumed' : 'Target paused');
    }
}
