<?php

namespace App\Filament\Auth;

use App\Models\User;
use App\Support\ApiTokenAbilities;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;

class EditProfile extends BaseEditProfile
{
    public ?string $plainTextToken = null;

    public ?string $issuedTokenName = null;

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                ...Arr::wrap($this->getMultiFactorAuthenticationContentComponent()),
                $this->getApiTokenComponent(),
            ]);
    }

    protected function getApiTokenComponent(): Section
    {
        return Section::make('API tokens')
            ->description('Personal access tokens for your Hackly API. The secret is shown once and stored only as a hash.')
            ->icon(Heroicon::OutlinedKey)
            ->headerActions([
                Action::make('createApiToken')
                    ->label('New token')
                    ->icon(Heroicon::OutlinedPlus)
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->placeholder('CI, laptop, automation')
                            ->required()
                            ->maxLength(120),
                        Select::make('ability')
                            ->label('Access')
                            ->options([
                                '*' => 'Full access — can also create and revoke tokens',
                                'write' => 'Read and write',
                                'read' => 'Read only',
                            ])
                            ->default('*')
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $user = $this->getUser();

                        if (! $user instanceof User) {
                            return;
                        }

                        $issued = $user->createToken($data['name'], ApiTokenAbilities::expand($data['ability']));
                        $this->plainTextToken = $issued->plainTextToken;
                        $this->issuedTokenName = $data['name'];

                        Notification::make()
                            ->title('Token created')
                            ->body('Copy it now. Hackly will not show it again.')
                            ->success()
                            ->send();
                    }),
            ])
            ->schema([
                View::make('filament.profile.api-tokens')
                    ->viewData(fn (): array => [
                        'tokens' => $this->getUser()->tokens()->latest()->get(),
                        'plainTextToken' => $this->plainTextToken,
                        'issuedTokenName' => $this->issuedTokenName,
                    ]),
            ]);
    }

    public function revokeApiToken(int $tokenId): void
    {
        $user = $this->getUser();

        if (! $user instanceof User) {
            return;
        }

        $user->tokens()->whereKey($tokenId)->delete();

        Notification::make()->title('Token revoked')->success()->send();
    }
}
