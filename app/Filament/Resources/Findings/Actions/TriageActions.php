<?php

namespace App\Filament\Resources\Findings\Actions;

use App\Enums\FindingStatus;
use App\Models\Finding;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Triage workflow shared by every findings table and detail view:
 * open → acknowledged → fixed, or false positive. Anything closed can be reopened.
 */
class TriageActions
{
    /**
     * @return array<string, array{label: string, icon: Heroicon, color: string, from: list<FindingStatus>, done: string}>
     */
    private static function transitions(): array
    {
        return [
            FindingStatus::Ack->value => [
                'label' => 'Acknowledge',
                'icon' => Heroicon::OutlinedEye,
                'color' => 'warning',
                'from' => [FindingStatus::Open],
                'done' => 'acknowledged',
            ],
            FindingStatus::Fixed->value => [
                'label' => 'Mark as fixed',
                'icon' => Heroicon::OutlinedCheckCircle,
                'color' => 'success',
                'from' => [FindingStatus::Open, FindingStatus::Ack],
                'done' => 'marked as fixed',
            ],
            FindingStatus::FalsePositive->value => [
                'label' => 'False positive',
                'icon' => Heroicon::OutlinedNoSymbol,
                'color' => 'gray',
                'from' => [FindingStatus::Open, FindingStatus::Ack],
                'done' => 'marked as false positive',
            ],
            FindingStatus::Open->value => [
                'label' => 'Reopen',
                'icon' => Heroicon::OutlinedArrowUturnLeft,
                'color' => 'danger',
                'from' => [FindingStatus::Ack, FindingStatus::Fixed, FindingStatus::FalsePositive],
                'done' => 'reopened',
            ],
        ];
    }

    /**
     * Individual actions (use inside a record action group or modal footer).
     *
     * @return list<Action>
     */
    public static function make(bool $closesParent = false): array
    {
        $actions = [];

        foreach (self::transitions() as $target => $config) {
            $actions[] = Action::make('triage_'.$target)
                ->label($config['label'])
                ->icon($config['icon'])
                ->color($config['color'])
                ->visible(fn (?Finding $record): bool => $record !== null && in_array($record->status, $config['from'], true))
                ->action(function (Finding $record) use ($target): void {
                    $record->update(['status' => FindingStatus::from($target)]);
                })
                ->cancelParentActions($closesParent)
                ->successNotificationTitle('Finding '.$config['done']);
        }

        return $actions;
    }

    public static function group(): ActionGroup
    {
        return ActionGroup::make(self::make())
            ->label('Triage')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->tooltip('Triage')
            ->color('gray');
    }

    public static function bulkGroup(): BulkActionGroup
    {
        $actions = [];

        foreach (self::transitions() as $target => $config) {
            $actions[] = BulkAction::make('bulk_triage_'.$target)
                ->label($config['label'])
                ->icon($config['icon'])
                ->color($config['color'])
                ->action(function (Collection $records) use ($target): void {
                    Finding::query()
                        ->whereKey($records->modelKeys())
                        ->update(['status' => $target]);
                })
                ->deselectRecordsAfterCompletion()
                ->successNotificationTitle('Selected findings '.$config['done']);
        }

        return BulkActionGroup::make($actions)->label('Triage selected');
    }
}
