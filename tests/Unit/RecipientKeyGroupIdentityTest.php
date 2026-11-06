<?php

namespace Condoedge\Communications\Tests\Unit;

use Condoedge\Communications\Recipients\RecipientKey;
use Condoedge\Communications\Recipients\RecipientOverride;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Tests\TestCase;

/**
 * The identity of a grouped email recipient — several addresses, one message, one key.
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

    public function test_identity_ignores_address_case()
    {
        $lower = new KeyProbeGroup(['a@example.test', 'b@example.test']);
        $mixed = new KeyProbeGroup(['A@Example.TEST', 'b@EXAMPLE.test']);

        $this->assertSame(RecipientKey::for($lower), RecipientKey::for($mixed));
    }

    public function test_a_one_address_group_is_not_the_same_recipient_as_that_address_alone()
    {
        $group = new KeyProbeGroup(['solo@example.test']);
        $plain = new KeyProbeSingle('solo@example.test');

        $this->assertNotSame(RecipientKey::for($group), RecipientKey::for($plain));
    }

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
