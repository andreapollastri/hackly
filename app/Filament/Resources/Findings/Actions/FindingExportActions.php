<?php

namespace App\Filament\Resources\Findings\Actions;

use App\Domain\Findings\FindingBrief;
use App\Models\Finding;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Js;

/**
 * Copy the full finding as Markdown or as an AI prompt, and toggle a read-only public link.
 */
class FindingExportActions
{
    /**
     * @return list<Action>
     */
    public static function make(): array
    {
        return [
            self::copy('copy_markdown', 'Copy Markdown', Heroicon::OutlinedClipboardDocument, 'markdown'),
            self::copy('copy_prompt', 'Copy AI prompt', Heroicon::OutlinedSparkles, 'prompt'),
            self::share(),
        ];
    }

    private static function copy(string $name, string $label, Heroicon $icon, string $kind): Action
    {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->action(function (Finding $record, Action $action) use ($kind): void {
                $text = $kind === 'prompt'
                    ? FindingBrief::prompt($record)
                    : FindingBrief::markdown($record);

                $livewire = $action->getLivewire();

                if ($livewire && method_exists($livewire, 'js')) {
                    $livewire->js('window.hacklyCopy('.Js::from($text).')');
                }

                Notification::make()
                    ->title($kind === 'prompt' ? 'AI prompt copied' : 'Markdown copied')
                    ->success()
                    ->send();
            });
    }

    private static function share(): Action
    {
        return Action::make('toggle_share')
            ->label(fn (Finding $record): string => $record->isPublished() ? 'Unshare' : 'Share link')
            ->icon(Heroicon::OutlinedLink)
            ->color('gray')
            ->action(function (Finding $record, Action $action): void {
                if ($record->isPublished()) {
                    $record->unpublish();

                    Notification::make()
                        ->title('Public link removed')
                        ->body('The previous address no longer opens this finding.')
                        ->success()
                        ->send();

                    return;
                }

                $record->publish(auth()->id());
                $url = (string) $record->publicUrl();
                $livewire = $action->getLivewire();

                if ($livewire && method_exists($livewire, 'js')) {
                    $livewire->js('window.hacklyCopy('.Js::from($url).')');
                }

                Notification::make()
                    ->title('Public link copied')
                    ->body($url)
                    ->success()
                    ->send();
            });
    }
}
