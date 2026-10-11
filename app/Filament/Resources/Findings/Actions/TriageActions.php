<?php

namespace App\Filament\Resources\Findings\Actions;

use App\Enums\FindingStatus;
use App\Models\Finding;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
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
            $action = Action::make('triage_'.$target)
                ->label($config['label'])
                ->icon($config['icon'])
                ->color($config['color'])
                ->visible(fn (?Finding $record): bool => $record !== null && in_array($record->status, $config['from'], true))
                ->action(function (Finding $record, array $data) use ($target): void {
                    $record->applyTriage(
                        FindingStatus::from($target),
                        $data['false_positive_note'] ?? null,
                        auth()->id(),
                    );
                })
                ->cancelParentActions($closesParent)
                ->successNotificationTitle('Finding '.$config['done']);

            if ($target === FindingStatus::FalsePositive->value) {
                $action->schema(self::noteSchema())
                    ->modalHeading('Mark as false positive')
                    ->modalDescription('Explain why this is not a real issue. The note stays on the finding, including after later scans, and is included when you copy or share it.')
                    ->modalSubmitActionLabel('Mark as false positive');
            }

            $actions[] = $action;
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
            $action = BulkAction::make('bulk_triage_'.$target)
                ->label($config['label'])
                ->icon($config['icon'])
                ->color($config['color'])
                ->action(function (Collection $records, array $data) use ($target): void {
                    $status = FindingStatus::from($target);
                    $note = $data['false_positive_note'] ?? null;
                    $userId = auth()->id();

                    $records->each(function (Finding $record) use ($status, $note, $userId): void {
                        $record->applyTriage($status, $note, $userId);
                    });
                })
                ->deselectRecordsAfterCompletion()
                ->successNotificationTitle('Selected findings '.$config['done']);

            if ($target === FindingStatus::FalsePositive->value) {
                $action->schema(self::noteSchema())
                    ->modalHeading('Mark selected as false positive')
                    ->modalDescription('One note is stored on every selected finding.')
                    ->modalSubmitActionLabel('Mark as false positive');
            }

            $actions[] = $action;
        }

        return BulkActionGroup::make($actions)->label('Triage selected');
    }

    /**
     * @return list<Textarea>
     */
    private static function noteSchema(): array
    {
        return [
            Textarea::make('false_positive_note')
                ->label('Note')
                ->required()
                ->minLength(Finding::FALSE_POSITIVE_NOTE_MIN)
                ->maxLength(Finding::FALSE_POSITIVE_NOTE_MAX)
                ->rows(4)
                ->helperText('Required. Say why this match is not a real issue. At least '.Finding::FALSE_POSITIVE_NOTE_MIN.' characters.'),
        ];
    }
}
