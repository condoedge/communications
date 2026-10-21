<?php

use Condoedge\Communications\Components\UnsubscribePage;
use Condoedge\Communications\Http\Controllers\UnsubscribeController;
use Condoedge\Communications\Services\Suppression\UnsubscribeLinkGenerator;
use Illuminate\Support\Facades\Route;

$config = config('kompo-communications.unsubscribe', []);
$prefix = $config['route_prefix'] ?? 'communications';
$component = $config['component'] ?? UnsubscribePage::class;
$layout = $config['layout'] ?? null;

// Public consent endpoints. Signed, unauthenticated, throttled.
//
// The throttle carries an explicit `unsub` prefix: Laravel's unnamed limiter keys on domain|ip with
// no route in the key, so without one these would share a counter with every other unnamed throttled
// route and a campaign's one-click POSTs could 429 unrelated guests.
Route::middleware(['signed', 'throttle:30,1,unsub'])->group(function () use ($prefix, $component, $layout) {
    // The recipient's own page. `disable-automatic-security` because the visitor is a guest with no
    // role, and the emailed token — not a permission — is what authorizes them.
    $page = function () use ($prefix, $component) {
        Route::middleware(['web', 'disable-automatic-security'])
            ->get($prefix . '/unsubscribe', $component)
            ->name(UnsubscribeLinkGenerator::ROUTE_UNSUBSCRIBE);
    };

    $layout ? Route::layout($layout)->group($page) : $page();

    // RFC 8058 one-click. Deliberately outside `web`: the provider posts with no session and no CSRF
    // token, so the URL signature is the protection.
    Route::post($prefix . '/unsubscribe', UnsubscribeController::class)
        ->name(UnsubscribeLinkGenerator::ROUTE_UNSUBSCRIBE_POST);
});
