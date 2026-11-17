<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Components\EditsCommunicationChannels;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Tests\Stubs\TitleOnlyTrigger;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Support\Facades\Config;

/**
 * The channels of a group edited from a form that is not the package's own modal, and a group's
 * templates copied to another group.
 */
class ChannelsEditorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('kompo.locales', ['en' => 'English', 'fr' => 'Français']);
        Config::set('kompo-communications.triggers', [TitleOnlyTrigger::class]);
    }

    protected function group(): CommunicationTemplateGroup
    {
        $group = new CommunicationTemplateGroup;
        $group->trigger = TitleOnlyTrigger::class;
        $group->title = 'Probe';
        $group->save();

        return $group;
    }

    public function test_leaving_a_channel_saves_it_on_the_group_the_host_names()
    {
        $group = $this->group();
        $host = new ChannelsEditorHost($group);

        request()->replace([
            'previous_communication_type' => CommunicationType::SMS->value,
            'communication_type' => CommunicationType::EMAIL->value,
            'subject' => ['en' => 'Reminder'],
            'content' => ['en' => 'See you tonight'],
        ]);

        $host->saveAndGetNewForm();

        $sms = $group->findCommunicationTemplate(CommunicationType::SMS->value);

        $this->assertSame(['en' => 'See you tonight'], $sms->getTranslations('content'));
        $this->assertEquals(0, $sms->is_draft);
        $this->assertNull($group->findCommunicationTemplate(CommunicationType::EMAIL->value));
    }

    public function test_a_host_gives_a_channel_its_own_form()
    {
        $host = new ChannelsEditorHost($this->group());
        $host->ownForms = [CommunicationType::EMAIL->value => 'own-email-form'];

        request()->replace(['communication_type' => CommunicationType::EMAIL->value]);

        $this->assertSame('own-email-form', $host->saveAndGetNewForm());
    }

    public function test_a_group_copies_its_templates_to_another_group_except_the_channels_left_out()
    {
        $from = $this->group();
        $to = $this->group();

        CommunicationType::EMAIL->handler(null)->save($from->id, ['subject' => ['en' => 'Mail'], 'content' => ['en' => '<p>Mail</p>']]);
        CommunicationType::SMS->handler(null)->save($from->id, ['subject' => ['en' => 'Text'], 'content' => ['en' => 'Text']]);

        $from->copyTemplatesTo($to, [CommunicationType::EMAIL->value]);

        $copied = CommunicationTemplate::where('template_group_id', $to->id)->get();

        $this->assertSame([CommunicationType::SMS], $copied->pluck('type')->all());
        $this->assertSame(['en' => 'Text'], $copied->first()->getTranslations('content'));
        $this->assertSame(2, $from->communicationTemplates()->count());
    }
}

class ChannelsEditorHost
{
    use EditsCommunicationChannels {
        saveAndGetNewForm as public;
    }

    /** @var array<int, mixed> */
    public array $ownForms = [];

    public function __construct(protected CommunicationTemplateGroup $group)
    {
    }

    protected function channelsGroup(): CommunicationTemplateGroup
    {
        return $this->group;
    }

    protected function channelForm(CommunicationType $type, ?CommunicationTemplate $template, $trigger)
    {
        return $this->ownForms[$type->value] ?? 'package-form-of-' . $type->configKey();
    }
}
