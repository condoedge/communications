<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Tests\TestCase;

class LongSubjectTest extends TestCase
{
    public function test_a_subject_longer_than_255_characters_is_kept_whole_in_every_locale(): void
    {
        $group = new CommunicationTemplateGroup;
        $group->title = 'Long subject probe';
        $group->save();

        $subject = ['en' => str_repeat('A long subject. ', 40), 'fr' => str_repeat('Un long sujet. ', 40)];

        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->template_group_id = $group->id;
        $template->subject = $subject;
        $template->content = ['en' => '<p>Body</p>'];
        $template->save();

        $this->assertSame($subject, $template->fresh()->getTranslations('subject'));
    }
}
