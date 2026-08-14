<?php

namespace Condoedge\Communications\Tests\Unit;

use Condoedge\Communications\Recipients\RecipientKey;
use Condoedge\Communications\Recipients\RecipientOverride;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Tests\TestCase;

/**
 * The identity of a grouped email recipient — several addresses, ONE message, ONE key.
 *
 * WHAT BREAKS WITHOUT THIS, and it is invisible from the outside: RecipientKey::for() is what
 * CommunicationTriggeredListener::idempotencyKeyFor() (line 237) builds the replay-window signature
 * from. A group's identity therefore has to depend on WHICH addresses are in it and not on the
 * order they came back in. The order is not stable in practice — it is whatever the host's query
 * returned, and Coolecto resolves an organisation's list through an unordered relation — so an
 * order-sensitive key makes a genuine accidental double-fire look like two different sends and
 * mails the whole organisation twice. Nothing errors; the duplicate is only ever noticed by the
 * people who received it.
 *
 * THE OPPOSITE MISTAKE IS ALSO PINNED HERE: sorting must not flatten the difference between two
 * groups that really are different. A key that ignored the tail would let one organisation's send
 * suppress another's inside the replay window, which loses a message entirely — the worse of the
 * two failures, and the reason the differing-tail case gets its own test rather than being folded
 * into the ordering one.
 *
 * Order still matters where it is actually observable: the To: header renders in declared order.
 * Identity and presentation are deliberately separated, which is why these assertions live against
 * RecipientKey rather than against the handler.
 */
class RecipientKeyGroupIdentityTest extends TestCase
{
    public function test_the_same_addresses_in_a_different_order_are_the_same_recipient()
    {
        $ab = new KeyProbeGroup(['a@example.test', 'b@example.test', 'c@example.test']);
        $cba = new KeyProbeGroup(['c@example.test', 'b@example.test', 'a@example.test']);

        $this->assertSame(
            RecipientKey::for($ab),
            RecipientKey::for($cba),
            'address ORDER is a presentation detail of the To: header and must not change identity',
        );
    }

    public function test_two_groups_differing_by_one_address_are_different_recipients()
    {
        $ab = new KeyProbeGroup(['a@example.test', 'b@example.test']);
        $abc = new KeyProbeGroup(['a@example.test', 'b@example.test', 'c@example.test']);

        $this->assertNotSame(
            RecipientKey::for($ab),
            RecipientKey::for($abc),
            'a group that reaches one more mailbox is a different audience and must not be deduplicated away',
        );
    }

    /**
     * Case is not part of a mailbox's identity, and the host does not normalise for us
     * (EmailGroup::addressesOf deliberately leaves case alone), so the key has to.
     */
    public function test_identity_ignores_address_case()
    {
        $lower = new KeyProbeGroup(['a@example.test', 'b@example.test']);
        $mixed = new KeyProbeGroup(['A@Example.TEST', 'b@EXAMPLE.test']);

        $this->assertSame(RecipientKey::for($lower), RecipientKey::for($mixed));
    }

    /**
     * A GROUP IS NOT ITS ONLY MEMBER, even when it has exactly one.
     *
     * The handler treats a one-address group exactly like a plain recipient — one message, one
     * address — and that equivalence is asserted in EmailGroupSeamTest. Identity is deliberately
     * NOT equivalent: a group is a standing audience that can gain a member between two fires,
     * while the plain recipient is one person. Giving them the same key would let a send to the
     * organisation and a send to the one person who currently staffs it suppress each other inside
     * the replay window, and the message that goes missing is the one nobody is watching for.
     */
    public function test_a_one_address_group_is_not_the_same_recipient_as_that_address_alone()
    {
        $group = new KeyProbeGroup(['solo@example.test']);
        $plain = new KeyProbeSingle('solo@example.test');

        $this->assertNotSame(RecipientKey::for($group), RecipientKey::for($plain));
    }

    /**
     * AN EMPTY GROUP MUST NOT COLLAPSE INTO ONE SHARED IDENTITY.
     *
     * Hashing the address list would give every currently-unreachable organisation the SAME key,
     * because they all hash the empty list. Two different organisations would then deduplicate each
     * other inside the replay window — and an organisation with no address today is exactly the one
     * whose list is about to be filled in, so the collision would land on real sends.
     */
    public function test_two_empty_groups_are_still_distinguishable()
    {
        $one = new KeyProbeGroup([], 'org-one');
        $two = new KeyProbeGroup([], 'org-two');

        $this->assertNotSame(
            RecipientKey::for($one),
            RecipientKey::for($two),
            'an unreachable group must keep its own identity, not merge with every other unreachable group',
        );
    }

    /**
     * A PLAIN RECIPIENT'S KEY IS UNTOUCHED — the half that keeps SISC correct.
     *
     * SISC wraps App\Models\Crm\Person in RecipientOverride at seven event classes and declares no
     * groups anywhere, so every key it computes must come out byte-identical to before this change.
     * Pinned as a literal rather than compared to a second computation, so that a change to the
     * format cannot make this test agree with itself.
     */
    public function test_a_plain_recipients_key_is_unchanged()
    {
        $this->assertSame(
            'email:solo@example.test',
            RecipientKey::for(new KeyProbeSingle('solo@example.test')),
        );

        $this->assertSame(
            'email:solo@example.test',
            RecipientKey::for(new KeyProbeSingle('Solo@Example.TEST')),
        );
    }

    /**
     * A plain RecipientOverride is not a group and never becomes one — the decorator is stripped by
     * unwrap() and identity stays the inner recipient's, exactly as before.
     */
    public function test_a_plain_override_still_keys_to_its_inner_recipient()
    {
        $inner = new KeyProbeSingle('inner@example.test');

        $this->assertSame(
            RecipientKey::for($inner),
            RecipientKey::for(RecipientOverride::for($inner)->withEmail('other@example.test')),
        );
    }
}

class KeyProbeSingle implements EmailCommunicable
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
        return 'key_probe_single';
    }

    public function label()
    {
        return 'Key probe single';
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

class KeyProbeGroup extends KeyProbeSingle
{
    /** @param string[] $emails */
    public function __construct(private array $emails, private string $discriminator = '')
    {
        parent::__construct(implode(', ', $emails));
    }

    public function getEmailGroup(): array
    {
        return $this->emails;
    }

    public function getContextKey()
    {
        return 'key_probe_group' . $this->discriminator;
    }
}
