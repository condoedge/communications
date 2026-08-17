<?php

namespace Condoedge\Communications\Http\Controllers;

use Condoedge\Communications\Models\EmailSuppressionReason;
use Condoedge\Communications\Services\Suppression\EmailSuppressionServiceContract;
use Condoedge\Communications\Services\Suppression\UnsubscribeLinkGenerator;
use Illuminate\Http\Request;

/**
 * The machine side of RFC 8058 one-click only. The recipient's own page is a Kompo component
 * (UnsubscribePage); this exists because a mail provider posts to the header URL with no session
 * and no CSRF token, and expects a bare 200 rather than a page it will never show.
 */
class UnsubscribeController
{
    public function __construct(
        protected EmailSuppressionServiceContract $suppressions,
        protected UnsubscribeLinkGenerator $links,
    ) {
    }

    public function __invoke(Request $request)
    {
        $email = $this->links->emailFromToken($request->query('token'));

        abort_if(!$email, 404);

        $this->suppressions->suppress($email, EmailSuppressionReason::USER_UNSUBSCRIBE, 'one-click');

        return response('', 200);
    }
}
