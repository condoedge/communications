<?php

namespace Condoedge\Communications\Tests\Feature;

use Condoedge\Communications\EventsHandling\CommunicationTriggeredListener;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Models\CommunicationSending;
use Condoedge\Communications\Models\CommunicationSendingRecipientStatus as RowStatus;
use Condoedge\Communications\Models\CommunicationSendingStatus;
use Condoedge\Communications\Models\CommunicationTemplate;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Models\CommunicationType;
use Condoedge\Communications\Services\CommunicationHandlers\Contracts\EmailCommunicable;
use Condoedge\Communications\Services\CommunicationHandlers\Layout\DefaultLayoutEmailCommunicable;
use Condoedge\Communications\Tests\TestCase;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/**
 * An event that names its groups and declares an idempotency key has one sending, which the same
 * key handled again continues instead of starting over.
 */
class ContinuedSendingTest extends TestCase
{
    protected CommunicationTemplateGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        StoppingLayout::$built = 0;
        StoppingLayout::$throwsOn = null;

        $this->group = new CommunicationTemplateGroup;
        $this->group->trigger = ContinuedProbeEvent::class;
        $this->group->title = 'Continued probe';
        $this->group->save();

        $template = new CommunicationTemplate;
        $template->type = CommunicationType::EMAIL;
        $template->template_group_id = $this->group->id;
        $template->subject = json_encode(['en' => 'Continued probe']);
        $template->content = json_encode(['en' => '<p>Continued probe</p>']);
        $template->save();
    }

    public function test_a_run_stopped_midway_and_handled_again_mails_only_the_rest(): void
    {
        $event = $this->event('occurrence-1', 10);

        $this->handleUntilTheProcessDiesOnMessage(5, $event);

        $sending = CommunicationSending::sole();

        $this->assertSame(CommunicationSendingStatus::PENDING, $sending->status);
        $this->assertSame([4, 6], [$this->rows($sending, RowStatus::SENT), $this->rows($sending, RowStatus::PENDING)]);
        $this->assertSame($this->addresses(1, 4), $this->mailedAddresses());

        $this->handle($event);

        $sending = CommunicationSending::sole();

        $this->assertSame($this->addresses(1, 10), $this->mailedAddresses());
        $this->assertSame(CommunicationSendingStatus::SENT, $sending->status);
        $this->assertSame(10, $this->rows($sending, RowStatus::SENT));
        $this->assertSame(10, $sending->recipients()->whereNotNull('sent_at')->count());
    }

    public function test_a_recipient_gone_before_the_run_is_handled_again_does_not_shift_the_others(): void
    {
        $this->handleUntilTheProcessDiesOnMessage(3, $this->event('occurrence-1', 6));

        $this->handle(new ContinuedProbeEvent([$this->group->id], 'occurrence-1', array_values(array_diff($this->addresses(1, 6), ['probe2@example.test', 'probe4@example.test']))));

        $this->assertSame(['probe1@example.test', 'probe2@example.test', 'probe3@example.test', 'probe5@example.test', 'probe6@example.test'], $this->mailedAddresses());
        $this->assertSame(['probe4@example.test'], CommunicationSending::sole()->recipients()->where('status', RowStatus::PENDING->value)->pluck('email')->all());
    }

    public function test_a_completed_key_raised_again_after_the_cache_is_gone_mails_nobody(): void
    {
        $this->handle($this->event('occurrence-1', 3));

        // As a run leaves it when it stops after its last recipient and before it closes the sending.
        CommunicationSending::query()->update(['status' => CommunicationSendingStatus::PENDING->value]);
        Cache::flush();

        $this->handle($this->event('occurrence-1', 3));

        Mail::assertSentCount(3);
        $this->assertSame(CommunicationSendingStatus::SENT, CommunicationSending::sole()->status);
    }

    public function test_an_event_without_a_key_is_sent_as_often_as_it_is_handled(): void
    {
        $this->handle($this->event(null, 3));
        Cache::flush();
        $this->handle($this->event(null, 3));

        Mail::assertSentCount(6);
        $this->assertSame([null, null], CommunicationSending::orderBy('id')->pluck('idempotency_key')->all());
        $this->assertSame([CommunicationSendingStatus::SENT, CommunicationSendingStatus::SENT], CommunicationSending::orderBy('id')->get()->pluck('status')->all());
    }

    public function test_one_recipient_whose_send_throws_leaves_the_others_sent_and_is_not_tried_again(): void
    {
        StoppingLayout::$throwsOn = 2;

        $this->handle($this->event('occurrence-1', 3));

        $sending = CommunicationSending::sole();

        $this->assertSame(['probe1@example.test', 'probe3@example.test'], $this->mailedAddresses());
        $this->assertSame(CommunicationSendingStatus::PARTIAL, $sending->status);
        $this->assertSame(['probe2@example.test'], $sending->recipients()->where('status', RowStatus::FAILED->value)->pluck('email')->all());

        StoppingLayout::$throwsOn = null;
        Cache::flush();

        $this->handle($this->event('occurrence-1', 3));

        Mail::assertSentCount(2);
        $this->assertSame(1, CommunicationSending::count());
    }

    public function test_the_queued_job_takes_its_tries_timeout_and_backoff_from_the_config(): void
    {
        Queue::fake();
        Event::listen(ContinuedProbeEvent::class, CommunicationTriggeredListener::class);

        event($this->event('occurrence-1', 1));

        Config::set('kompo-communications.queue', ['tries' => 3, 'timeout' => 120, 'backoff' => [30, 120]]);

        event($this->event('occurrence-2', 1));

        $jobs = Queue::pushed(CallQueuedListener::class)->map(fn ($job) => [$job->tries, $job->timeout, $job->backoff])->all();

        $this->assertSame([[null, null, null], [3, 120, [30, 120]]], $jobs);
    }

    protected function event(?string $key, int $recipients): ContinuedProbeEvent
    {
        return new ContinuedProbeEvent([$this->group->id], $key, $this->addresses(1, $recipients));
    }

    /** @return string[] */
    protected function addresses(int $from, int $to): array
    {
        return array_map(fn (int $i) => "probe{$i}@example.test", range($from, $to));
    }

    protected function handle(CommunicableEvent $event): void
    {
        (new CommunicationTriggeredListener)->handle($event);
    }

    /** Nothing after the message that fails is run: the first error logged ends the run, as a process that exits there. */
    protected function handleUntilTheProcessDiesOnMessage(int $message, CommunicableEvent $event): void
    {
        $log = Log::getFacadeRoot();
        Log::spy()->shouldReceive('error')->andThrow(new ProcessDied);
        StoppingLayout::$throwsOn = $message;

        try {
            $this->handle($event);
            $this->fail('the run was expected to stop');
        } catch (ProcessDied $e) {
        } finally {
            Log::swap($log);
            StoppingLayout::$throwsOn = null;
        }
    }

    protected function rows(CommunicationSending $sending, RowStatus $status): int
    {
        return $sending->recipients()->where('status', $status->value)->count();
    }

    /** @return string[] in the order they were mailed */
    protected function mailedAddresses(): array
    {
        return Mail::sent(StoppingLayout::class)->map(fn ($mailable) => $mailable->to[0]['address'])->all();
    }
}

class ProcessDied extends \RuntimeException
{
}

class StoppingLayout extends DefaultLayoutEmailCommunicable
{
    public static int $built = 0;

    public static ?int $throwsOn = null;

    public function __construct($communication, $params = [])
    {
        if (++static::$built === static::$throwsOn) {
            throw new \RuntimeException('This message cannot be built.');
        }

        parent::__construct($communication, $params);
    }
}

class ContinuedProbeEvent implements CommunicableEvent
{
    public function __construct(private array $groupIds, private ?string $key, private array $addresses)
    {
    }

    public function getSpecificCommunicationsIds(): array
    {
        return $this->groupIds;
    }

    public function getIdempotencyKey(): ?string
    {
        return $this->key;
    }

    public function getParams(): array
    {
        return ['layout' => StoppingLayout::class];
    }

    public function getCommunicables(): Collection|array
    {
        return array_map(fn (string $address) => new ContinuedProbeRecipient($address), $this->addresses);
    }

    public static function getName(): string
    {
        return 'Continued probe';
    }

    public static function validVariablesIds($specificField = null, $context = []): ?array
    {
        return null;
    }
}

class ContinuedProbeRecipient implements EmailCommunicable
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
        return 'continued_probe';
    }

    public function label()
    {
        return 'Continued probe';
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
