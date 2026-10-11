<?php

namespace App\Filament\Pages;

use App\Domain\Compliance\PostureScore;
use App\Filament\Support\NavigationGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

class Compliance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Security;

    protected static ?string $navigationLabel = 'Compliance';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'compliance';

    protected string $view = 'filament.pages.compliance';

    public function getTitle(): string|Htmlable
    {
        return 'Compliance';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Evidence packs from scans you already ran. A gap is an open finding. Out of scope means Hackly cannot see that requirement.';
    }

    protected function getViewData(): array
    {
        return [
            'posture' => app(PostureScore::class)->snapshot(),
        ];
    }
}
