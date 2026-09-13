<?php

namespace Condoedge\Communications\Components;

use Condoedge\Communications\Models\EmailSuppressionReason;
use Condoedge\Communications\Services\Suppression\EmailSuppressionServiceContract;
use Condoedge\Communications\Services\Suppression\UnsubscribeLinkGenerator;
use Condoedge\Utils\Kompo\Common\ImgFormLayout;

/**
 * The page a recipient lands on from the link in an email. ImgFormLayout is only the default shell:
 * a host subclasses this, overrides render() with its own chrome around content(), and points
 * `kompo-communications.unsubscribe.component` at the subclass.
 *
 * The machine side of RFC 8058 one-click is NOT here — it stays a plain controller, because the
 * provider posts to the header URL with no session and expects a bare 200.
 */
class UnsubscribePage extends ImgFormLayout
{
    public const ID = 'unsubscribe-state';

    protected ?string $email = null;

    /**
     * The token arrives in the query string on the way in, then lives in the store: Kompo posts
     * self-methods to its own endpoint, where the signed URL is not there to read.
     */
    public function created()
    {
        $token = request('token') ?: $this->prop('token');

        $this->store(['token' => $token]);

        $this->email = app(UnsubscribeLinkGenerator::class)->emailFromToken($token);

        abort_if(!$this->email, 404);

        $this->adoptLinkLocale();
    }

    public function rightColumnBody()
    {
        return $this->content();
    }

    /** The page itself, free of chrome, so a host can drop it into its own layout. */
    public function content()
    {
        return _Rows(
            _Html('communications.unsubscribe-title')->class('text-3xl font-bold mb-4'),

            _Panel($this->state())->id(static::ID),
        );
    }

    /** Re-rendered after each action, so the page always shows the state actually stored. */
    public function state()
    {
        return $this->suppressed()
            ? _Rows(
                _Html(__('communications.unsubscribe-already', ['email' => $this->email]))
                    ->class('text-base text-gray-700 mb-6'),
                _Button('communications.unsubscribe-resubscribe-action')
                    ->selfPost('resubscribe')->inPanel(static::ID)->class('w-full'),
            )
            : _Rows(
                _Html(__('communications.unsubscribe-confirm', ['email' => $this->email]))
                    ->class('text-base text-gray-700 mb-6'),
                _Button('communications.unsubscribe-action')
                    ->selfPost('unsubscribe')->inPanel(static::ID)->class('w-full'),
                _Html('communications.unsubscribe-transactional-notice')
                    ->class('text-sm text-gray-500 mt-4'),
            );
    }

    public function unsubscribe()
    {
        $this->suppressions()->suppress($this->email, EmailSuppressionReason::USER_UNSUBSCRIBE, 'landing-page');

        return $this->state();
    }

    public function resubscribe()
    {
        $this->suppressions()->allow($this->email, 'landing-page');

        return $this->state();
    }

    protected function suppressed(): bool
    {
        return $this->suppressions()->isSuppressed($this->email);
    }

    protected function suppressions(): EmailSuppressionServiceContract
    {
        return app(EmailSuppressionServiceContract::class);
    }

    /**
     * The recipient arrives as a guest with no session and no cookie, so without the locale carried
     * in the signed link a francophone member would be answered in English.
     */
    protected function adoptLinkLocale(): void
    {
        $locale = request('locale');

        if ($locale && array_key_exists($locale, config('kompo.locales', []))) {
            app()->setLocale($locale);
        }
    }
}
