<?php

namespace App\Filament\Resources\GithubCredentials;

use App\Domain\RepoScanning\Services\GithubClient;
use App\Filament\Resources\GithubCredentials\Pages\ManageGithubCredentials;
use App\Filament\Support\NavigationGroup;
use App\Models\GithubCredential;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class GithubCredentialResource extends Resource
{
    protected static ?string $model = GithubCredential::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?string $navigationLabel = 'GitHub tokens';

    protected static ?string $modelLabel = 'GitHub token';

    protected static ?string $pluralModelLabel = 'GitHub tokens';

    protected static ?string $slug = 'github-tokens';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(static::formComponents());
    }

    /**
     * @return list<Component>
     */
    public static function formComponents(): array
    {
        return [
            TextInput::make('name')
                ->label('Label')
                ->required()
                ->maxLength(120)
                ->placeholder('e.g. Security scanning (read-only)'),
            TextInput::make('token')
                ->label('Personal access token')
                ->password()
                ->revealable()
                ->autocomplete('off')
                ->placeholder('github_pat_… or ghp_…')
                ->required(fn (?GithubCredential $record): bool => $record === null)
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->helperText(new HtmlString(
                    'Prefer a <a href="https://github.com/settings/personal-access-tokens/new" target="_blank" rel="noopener" class="hk-inline-link">fine-grained token</a> '
                    .'limited to the repositories you scan, with <strong>Contents: Read</strong> and <strong>Metadata: Read</strong>. '
                    .'Tokens are encrypted at rest with APP_KEY.'
                )),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function createCredential(array $data): GithubCredential
    {
        $credential = GithubCredential::query()->create([
            'name' => $data['name'],
            'token' => $data['token'],
            'token_hint' => GithubCredential::hintFromToken((string) $data['token']),
            'validation_status' => 'unknown',
            'created_by' => auth()->id(),
        ]);

        try {
            app(GithubClient::class)->validateToken($credential);
        } catch (\Throwable) {
            $credential->update(['validation_status' => 'invalid', 'last_validated_at' => now()]);
        }

        return $credential;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Label')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    ->description(fn (GithubCredential $record): ?string => filled($record->meta['login'] ?? null)
                        ? 'Authenticated as @'.$record->meta['login']
                        : null),
                TextColumn::make('token_hint')
                    ->label('Token')
                    ->fontFamily('mono')
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('validation_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'valid' => 'Valid',
                        'invalid' => 'Invalid',
                        default => 'Not checked',
                    })
                    ->icon(fn (string $state): Heroicon => match ($state) {
                        'valid' => Heroicon::OutlinedCheckCircle,
                        'invalid' => Heroicon::OutlinedXCircle,
                        default => Heroicon::OutlinedQuestionMarkCircle,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'valid' => 'success',
                        'invalid' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_validated_at')
                    ->label('Checked')
                    ->since()
                    ->placeholder('Never')
                    ->color('gray'),
                TextColumn::make('repositories_count')
                    ->counts('repositories')
                    ->label('Repositories')
                    ->numeric(),
            ])
            ->recordActions([
                Action::make('validate')
                    ->label('Check token')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->button()
                    ->size('sm')
                    ->outlined()
                    ->action(function (GithubCredential $record): void {
                        try {
                            $info = app(GithubClient::class)->validateToken($record);

                            Notification::make()
                                ->title('Token is valid')
                                ->body('Authenticated as @'.$info['login'].(empty($info['scopes']) ? '' : ' · scopes: '.implode(', ', $info['scopes'])))
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            $record->update([
                                'validation_status' => 'invalid',
                                'last_validated_at' => now(),
                            ]);

                            Notification::make()
                                ->title('Token rejected by GitHub')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();
                        }
                    }),
                ActionGroup::make([
                    EditAction::make()
                        ->mutateDataUsing(function (array $data): array {
                            if (! empty($data['token'])) {
                                $data['token_hint'] = GithubCredential::hintFromToken($data['token']);
                                $data['validation_status'] = 'unknown';
                            }

                            return $data;
                        }),
                    DeleteAction::make()
                        ->modalDescription('Repositories using this token are deleted too, together with their scans and findings.'),
                ])->tooltip('More'),
            ])
            ->emptyStateIcon(Heroicon::OutlinedKey)
            ->emptyStateHeading('No GitHub tokens yet')
            ->emptyStateDescription('Add a read-only personal access token to start scanning repositories.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGithubCredentials::route('/'),
        ];
    }
}
