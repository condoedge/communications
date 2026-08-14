<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Recipients\RecipientGroupOverride;
use Condoedge\Communications\Recipients\RecipientOverride;
use Condoedge\Communications\Services\CommunicationHandlers\AbstractCommunicationHandler;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\Mail;

/**
 * The locale seam on RecipientOverride: which language ONE decorated send is written in.
 *
 * WHY IT EXISTS. Both readers of HasLocalePreference test `instanceof` on the OUTER object
 * (EmailCommunicationHandler.php:52, AbstractCommunicationHandler.php:241), so before this class
 * declared the interface a decorated send could not carry a language at all and rendered in whatever
 * locale the process happened to be on — config('app.locale') for a queued send. Nothing failed; the
 * message shipped in the wrong language.
 *
 * WHAT THE OTHER HALF OF THIS FILE PROTECTS, and it is the half a refactor will come for: an
 * override nobody called withLocale() on answers null and changes NOTHING, including when its inner
 * carries a locale of its own. Forwarding to the inner is the obvious "improvement" and it is not
 * inert — SISC wraps App\Models\Crm\Person, a HasLocalePreference, at seven event classes, so
 * forwarding would change the language of live sends in a host that made no edit. The assertion
 * below is what stops that arriving as a package upgrade.
 */
class RecipientOverrideLocaleTest extends TestCase
{
    protected function dispatch(array $communicables)
    {
        $template = new CommunicationTemplate;
        $template->subject = json_encode(['en' => 'Locale probe', 'fr' => 'Locale probe']);
        $template->content = json_encode(['en' => '<p>Locale probe</p>', 'fr' => '<p>Locale probe</p>']);

        return CommunicationType::EMAIL->handler($template)->notify(collect($communicables), []);
    }

    // ---- inertness: no locale asked for, nothing changed -----------------

    public function test_an_override_is_a_locale_carrier_only_once_a_caller_says_so()
    {
        $plain = RecipientOverride::for(new LocaleSeamRecipient('plain@example.test'));

        $this->assertInstanceOf(
            HasLocalePreference::class,
            $plain,
            'the interface must be declared on the class, because both readers test instanceof on the OUTER object',
        );

        $this->assertNull($plain->preferredLocale());
    }

    /**
     * THE SISC-INERTNESS PIN. An override whose inner carries a locale STILL answers null.
     *
     * Every RecipientOverride call site in SISC wraps a Person, which implements HasLocalePreference;
     * measured 2026-08-12, 91 of its 338,653 persons resolve a non-null preferredLocale(). If this
     * class forwarded to its inner, those sends would switch language on a package upgrade alone —
     * no host edit, no gate, and nothing red anywhere. A host that wants the inner's language passes
     * it explicitly.
     */
    public function test_an_override_does_not_borrow_the_locale_of_the_recipient_it_wraps()
    {
        $inner = new LocalisedLocaleSeamRecipient('inner@example.test', 'fr');

        $this->assertSame('fr', $inner->preferredLocale(), 'the fixture must actually carry a locale or this proves nothing');

        $this->assertNull(
            RecipientOverride::for($inner)->preferredLocale(),
            'forwarding to the inner would change the language of live sends in hosts that made no edit',
        );
    }

    public function test_an_override_with_no_locale_leaves_the_ambient_locale_alone()
    {
        app()->setLocale('en');

        $seen = AbstractCommunicationHandler::withRecipientLocale(
            RecipientOverride::for(new LocalisedLocaleSeamRecipient('inner@example.test', 'fr')),
            fn () => app()->getLocale(),
        );

        $this->assertSame('en', $seen);
        $this->assertSame('en', app()->getLocale());
    }

    // ---- the seam --------------------------------------------------------

    public function test_the_locale_a_caller_passed_is_what_the_recipient_answers()
    {
        $override = RecipientOverride::for(new LocaleSeamRecipient('one@example.test'))->withLocale('fr');

        $this->assertSame('fr', $override->preferredLocale());
    }

    /**
     * Asserted through withRecipientLocale() rather than only on the getter, because the getter
     * answering correctly is not the property that matters: a locale that no reader applies renders
     * in the worker's ambient locale and looks identical from the recipient's side.
     */
    public function test_the_locale_a_caller_passed_is_active_while_the_message_renders()
    {
        app()->setLocale('en');

        $seen = AbstractCommunicationHandler::withRecipientLocale(
            RecipientOverride::for(new LocaleSeamRecipient('one@example.test'))->withLocale('fr'),
            fn () => app()->getLocale(),
        );

        $this->assertSame('fr', $seen);
        $this->assertSame('en', app()->getLocale(), 'the ambient locale must be restored after the send');
    }

    public function test_a_null_locale_is_the_same_as_never_having_asked()
    {
        $override = RecipientOverride::for(new LocalisedLocaleSeamRecipient('inner@example.test', 'fr'))
            ->withLocale(null);

        $this->assertNull(
            $override->preferredLocale(),
            'withLocale(null) must degrade to today\'s behaviour, which is what ->withLocale($campaign?->getLanguage()) '
            . 'does when the campaign is gone',
        );
    }

    /**
     * The group inherits the seam, and one message carries ONE locale for every address in it.
     *
     * Asserted on the Mailable because that is where the handler's ->locale() call lands; a group
     * whose locale nothing applied answers the same from preferredLocale() and still renders in the
     * ambient locale.
     */
    public function test_a_group_override_carries_the_locale_for_the_whole_message()
    {
        Mail::fake();

        $group = RecipientGroupOverride::forGroup(
            new LocaleSeamRecipient('inner@example.test'),
            ['a@example.test', 'b@example.test'],
        )->withLocale('fr');

        $this->dispatch([$group]);

        Mail::assertSent(DefaultLayoutEmailCommunicable::class, function ($mailable) {
            $this->assertSame('fr', $mailable->locale);

            $this->assertSame(
                ['a@example.test', 'b@example.test'],
                collect($mailable->to)->pluck('address')->sort()->values()->all(),
            );

            return true;
        });

        Mail::assertSentCount(1);
    }
}

class LocaleSeamRecipient implements EmailCommunicable
{
    public function __construct(private string $email)
    {
    }

    public function getEmail()
    {
        return $this->email;
    }

    public function getContextKey()
    {
        return 'locale_seam';
    }

    public function label()
    {
        return 'Locale seam';
    }

    public function scopeValidForCommunication($query)
    {
        throw new \LogicException('not queryable');
    }

    public function scopeSearch($query, $search)
    {
        throw new \LogicException('not queryable');
    }
}

/** The shape SISC wraps: a recipient that carries a language of its own. */
class LocalisedLocaleSeamRecipient extends LocaleSeamRecipient implements HasLocalePreference
{
    public function __construct(string $email, private string $locale)
    {
        parent::__construct($email);
    }

    public function preferredLocale()
    {
        return $this->locale;
    }
}
