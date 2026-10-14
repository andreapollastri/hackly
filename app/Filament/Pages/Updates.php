<?php

namespace App\Filament\Pages;

use App\Filament\Support\NavigationGroup;
use App\Updates\UpdateFailed;
use App\Updates\Updater;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * Settings → Updates: the installed release, the channel, the nightly update, and an
 * update now. The scheduler runs what is asked here (app:update pending).
 */
class Updates extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?string $navigationLabel = 'Updates';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'updates';

    protected string $view = 'filament.pages.updates';

    /** Hackly has no roles: every user who can sign in to the panel is an administrator. */
    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Updates';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'The release Hackly runs, the builds it follows and its nightly update.';
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('check')
                ->label('Check now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->disabled(fn (): bool => ! $this->updater()->managed())
                ->action(function (): void {
                    try {
                        $this->updater()->check();
                    } catch (UpdateFailed $e) {
                        Notification::make()->title('Check failed')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    $available = $this->updater()->available();

                    Notification::make()
                        ->title($available ? "{$available} is available." : 'Hackly is on the newest build of its channel.')
                        ->success()
                        ->send();
                }),

            Action::make('forceUpdate')
                ->label('Force update')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('gray')
                ->disabled(fn (): bool => ! $this->updater()->managed() || $this->busy())
                ->requiresConfirmation()
                ->modalHeading('Force an update now?')
                ->modalDescription('Hackly installs the newest build of its channel even when it is the one already installed, with the same database backup and rollback as every update. It never goes back to an older build.')
                ->modalSubmitActionLabel('Force update')
                ->action(fn () => $this->requestUpdate(force: true)),

            Action::make('update')
                ->label(fn (): string => 'Update to '.$this->updater()->available())
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible(fn (): bool => $this->updater()->available() !== null)
                ->disabled(fn (): bool => ! $this->updater()->managed() || $this->busy())
                ->requiresConfirmation()
                ->modalHeading(fn (): string => 'Update Hackly to '.$this->updater()->available().' now?')
                ->modalDescription('It backs up the database, installs and migrates the new build, and puts everything back if a step fails. Hackly is in maintenance for a moment.')
                ->modalSubmitActionLabel('Update')
                ->action(fn () => $this->requestUpdate(force: false)),
        ];
    }

    /** Stable or beta, from the two buttons of the Channel section. */
    public function channelAction(): Action
    {
        return Action::make('channel')
            ->label(fn (array $arguments): string => ($arguments['channel'] ?? null) === 'beta' ? 'Beta (main branch)' : 'Stable releases')
            ->color(fn (array $arguments): string => $this->updater()->channel() === ($arguments['channel'] ?? null) ? 'primary' : 'gray')
            ->size(Size::Small)
            ->disabled(fn (): bool => ! $this->updater()->managed() || $this->busy())
            ->action(function (array $arguments): void {
                $channel = $arguments['channel'] ?? null;

                if (! in_array($channel, ['stable', 'beta'], true)) {
                    return;
                }

                $this->updater()->setChannel($channel);

                Notification::make()
                    ->title($channel === 'beta' ? 'Hackly now follows the main branch.' : 'Hackly now follows the release tags.')
                    ->success()
                    ->send();
            });
    }

    public function automaticAction(): Action
    {
        return Action::make('automatic')
            ->label(fn (): string => $this->updater()->automatic() ? 'Turn off' : 'Turn on')
            ->color('gray')
            ->size(Size::Small)
            ->disabled(fn (): bool => ! $this->updater()->managed())
            ->action(function (): void {
                $this->updater()->setAutomatic(! $this->updater()->automatic());

                Notification::make()
                    ->title($this->updater()->automatic() ? 'Automatic updates are on.' : 'Automatic updates are off.')
                    ->success()
                    ->send();
            });
    }

    /**
     * Polled while an update is asked for or running; reloads the page when it is done,
     * so it runs on the new build. A 503 during maintenance is ignored by the view.
     */
    public function pollUpdate(): void
    {
        if (! $this->busy()) {
            $this->js('window.location.reload()');
        }
    }

    protected function getViewData(): array
    {
        $updater = $this->updater();
        $state = $updater->state();
        $channel = $updater->channel();

        return [
            'updater' => $updater,
            'state' => $state,
            'managed' => $updater->managed(),
            'busy' => $this->busy(),
            'channel' => $channel,
            'available' => $updater->available(),
            'waiting' => $updater->waiting(),
            'installed' => $state['current'] ?? $updater->installed(),
            'newest' => $channel === 'beta' ? ($state['main'] ?? null) : ($state['latest'] ?? null),
        ];
    }

    private function requestUpdate(bool $force): void
    {
        abort_unless($this->updater()->managed(), 404);

        if ($this->busy()) {
            Notification::make()->title('An update is already under way.')->danger()->send();

            return;
        }

        $this->updater()->request($force, auth()->user()?->email);

        Notification::make()->title('The update starts within a minute.')->success()->send();
    }

    private function busy(): bool
    {
        return $this->updater()->running() || $this->updater()->requested();
    }

    private function updater(): Updater
    {
        return app(Updater::class);
    }
}
