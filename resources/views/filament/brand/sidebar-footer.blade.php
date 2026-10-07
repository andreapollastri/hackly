<div class="hk-sidebar-footer">
    <a href="{{ \App\Filament\Pages\ScannerHealth::getUrl() }}" class="hk-sidebar-footer__health">
        <span @class(['hk-dot', 'hk-dot--ok' => $missing === 0, 'hk-dot--warn' => $missing > 0])></span>
        <span>{{ $missing === 0 ? 'All scanners available' : $missing.' scanner'.($missing === 1 ? '' : 's').' missing' }}</span>
    </a>
    <span class="hk-sidebar-footer__legal">Authorized testing only</span>
</div>
