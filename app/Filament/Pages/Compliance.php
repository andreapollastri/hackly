<?php

namespace App\Filament\Pages;

use App\Domain\Compliance\ComplianceSubject;
use App\Domain\Compliance\PostureScore;
use App\Filament\Support\NavigationGroup;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use InvalidArgumentException;
use UnitEnum;

class Compliance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Security;

    protected static ?string $navigationLabel = 'Compliance';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'compliance';

    protected string $view = 'filament.pages.compliance';

    public string $subject = '';

    public function getTitle(): string|Htmlable
    {
        return 'Compliance';
    }

    public function getSubheading(): string|Htmlable|null
    {
        return 'Evidence packs from scans you already ran. A gap is an open finding. Out of scope means Hackly cannot see that requirement.';
    }

    public function updatedSubject(string $value): void
    {
        if ($value === '') {
            return;
        }

        try {
            ComplianceSubject::fromKey($value);
        } catch (ModelNotFoundException|InvalidArgumentException) {
            $this->subject = '';
        }
    }

    protected function getViewData(): array
    {
        try {
            $subject = ComplianceSubject::fromKey($this->subject === '' ? null : $this->subject);
        } catch (ModelNotFoundException|InvalidArgumentException) {
            $this->subject = '';
            $subject = ComplianceSubject::all();
        }

        return [
            'posture' => app(PostureScore::class)->snapshot(subject: $subject),
            'subjects' => ComplianceSubject::options(),
            'subject' => $this->subject,
        ];
    }
}
