<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationSendingRecipientStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\Suppression\EmailSuppressionServiceContract;
use Condoedge\Communications\Services\Suppression\UnsubscribeLinkGenerator;
use Condoedge\Communications\Tests\TestCase;
use Condoedge\Communications\Triggers\ManualTrigger;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\Mail;

/**
 * Where the group seam meets consent. A shared message has a single List-Unsubscribe header, so a
 * group cannot share one and still give each member a link of their own.
 */
class EmailGroupUnsubscribeTest extends TestCase
{
    /** @return array<int, array<int, string>> one entry per message, holding that message's To: */
    protected function sentMessages(): array
    {
        return collect(Mail::getSymfonyTransport()->messages())
            ->map(fn ($sent) => collect($sent->getOriginalMessage()->getTo())
                ->map(fn ($address) => $address->getAddress())
                ->sort()
                ->values()
                ->all())
            ->values()
            ->all();
    }

    /** @return array<string, string|null> To: address => the address its List-Unsubscribe link acts on */
    protected function unsubscribeTargets(): array
    {
        return collect(Mail::getSymfonyTransport()->messages())
            ->mapWithKeys(function ($sent) {
                $message = $sent->getOriginalMessage();
                $header = $message->getHeaders()->get('List-Unsubscribe');

                preg_match('/<(https?:[^>]+)>/', $header?->getBodyAsString() ?? '', $matches);
                parse_str((string) parse_url($matches[1] ?? '', PHP_URL_QUERY), $query);

                return [
                    $message->getTo()[0]->getAddress() => app(UnsubscribeLinkGenerator::class)
                        ->emailFromToken($query['token'] ?? null),
                ];
            })
            ->all();
    }

    /** A manual send is MARKETING, which an unsubscribe silences. */
    protected function dispatchSuppressible(array $communicables)
    {
        $group = new CommunicationTemplateGroup;
        $group->trigger = ManualTrigger::class;

        $template = new CommunicationTemplate;
        $template->subject = json_encode(['en' => 'Consent probe', 'fr' => 'Consent probe']);
        $template->content = json_encode(['en' => '<p>Consent probe</p>', 'fr' => '<p>Consent probe</p>']);
        $template->setRelation('group', $group);

        return CommunicationType::EMAIL->handler($template)->notify(collect($communicables), []);
    }

    protected function unsubscribe(string $email): void
    {
        app(EmailSuppressionServiceContract::class)->suppress($email);
    }

    public function test_a_suppressible_group_receives_one_message_per_member()
    {
        $this->dispatchSuppressible([new ConsentGroupRecipient(['a@example.test', 'b@example.test'])]);

        $this->assertSame([['a@example.test'], ['b@example.test']], $this->sentMessages());
    }

    public function test_each_member_of_a_suppressible_group_gets_a_link_that_unsubscribes_only_them()
    {
        $this->dispatchSuppressible([new ConsentGroupRecipient(['a@example.test', 'b@example.test'])]);

        $this->assertSame(
            ['a@example.test' => 'a@example.test', 'b@example.test' => 'b@example.test'],
            $this->unsubscribeTargets(),
            'a link shared across the group would let one member withdraw another member\'s consent',
        );
    }

    public function test_a_suppressible_group_still_occupies_one_delivery_report_position()
    {
        $report = $this->dispatchSuppressible([new ConsentGroupRecipient(['a@example.test', 'b@example.test'])]);

        $this->assertCount(1, $report->outcomes());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SENT));
    }

    public function test_every_copy_of_a_suppressible_group_is_written_in_the_groups_locale()
    {
        Mail::fake();

        $this->dispatchSuppressible([new ConsentLocalisedGroupRecipient(['a@example.test', 'b@example.test'], 'fr')]);

        Mail::assertSentCount(2);
        Mail::assertSent(fn ($mailable) => $mailable->locale === 'fr' && $mailable->hasTo('a@example.test'));
        Mail::assertSent(fn ($mailable) => $mailable->locale === 'fr' && $mailable->hasTo('b@example.test'));
    }

    public function test_an_unsubscribed_member_is_dropped_and_the_rest_of_the_group_still_receives()
    {
        $this->unsubscribe('b@example.test');

        $report = $this->dispatchSuppressible([
            new ConsentGroupRecipient(['a@example.test', 'b@example.test', 'c@example.test']),
        ]);

        $this->assertSame([['a@example.test'], ['c@example.test']], $this->sentMessages());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SENT));
    }

    public function test_a_group_whose_every_member_unsubscribed_is_skipped_as_unsubscribed()
    {
        $this->unsubscribe('a@example.test');
        $this->unsubscribe('b@example.test');

        $report = $this->dispatchSuppressible([new ConsentGroupRecipient(['a@example.test', 'b@example.test'])]);

        $this->assertSame([], $this->sentMessages());
        $this->assertSame(1, $report->countOf(CommunicationSendingRecipientStatus::SKIPPED));
        $this->assertSame('unsubscribed', $report->outcomes()[0]['error']);
    }

    public function test_a_transactional_group_ignores_unsubscribes_and_keeps_its_shared_message()
    {
        $this->unsubscribe('b@example.test');

        $template = new CommunicationTemplate;
        $template->subject = json_encode(['en' => 'Consent probe']);
        $template->content = json_encode(['en' => '<p>Consent probe</p>']);

        CommunicationType::EMAIL->handler($template)->notify(
            collect([new ConsentGroupRecipient(['a@example.test', 'b@example.test'])]),
            [],
        );

        $this->assertSame([['a@example.test', 'b@example.test']], $this->sentMessages());
    }
}

class ConsentGroupRecipient implements EmailCommunicable
{
    /** @param string[] $emails */
    public function __construct(private array $emails)
    {
    }

    public function getEmailGroup(): array
    {
        return $this->emails;
    }

    public function getEmail()
    {
        return implode(', ', $this->emails);
    }

    public function getContextKey()
    {
        return 'consent_group';
    }

    public function label()
    {
        return 'Consent group';
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

class ConsentLocalisedGroupRecipient extends ConsentGroupRecipient implements HasLocalePreference
{
    public function __construct(array $emails, private string $locale)
    {
        parent::__construct($emails);
    }

    public function preferredLocale()
    {
        return $this->locale;
    }
}
