@include('filament.targets.verification', [
    'asset' => $getRecord(),
    'token' => app(\App\Domain\Scanning\Services\DnsOwnershipVerifier::class)->ensureToken($getRecord()),
])
