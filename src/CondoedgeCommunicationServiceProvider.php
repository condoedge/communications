<?php

namespace Condoedge\Communications;

use Condoedge\Communications\Console\SeedTemplatesCommand;
use Condoedge\Communications\EventsHandling\CommunicationTriggeredListener;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Reminders\Console\PruneReminderClaimsCommand;
use Condoedge\Communications\Reminders\Console\SendScheduledRemindersCommand;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\ContextEnhancer;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\MessageContentReplacer;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Parsers\BraceMentionParser;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\Parsers\HtmlMentionParser;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\VariablesManager\VariablesManager;
use Condoedge\Communications\Services\MailElements\MailElement;
use Condoedge\Communications\Services\TemplateSeeding\TemplateSeedingService;
use Condoedge\Communications\Services\TemplateSeeding\TemplateSeedingServiceContract;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class CondoedgeCommunicationServiceProvider extends ServiceProvider
{
    use \Kompo\Routing\Mixins\ExtendsRoutingTrait;

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadHelpers();

        $this->extendRouting(); //otherwise Route::layout doesn't work

        $this->loadJSONTranslationsFrom(__DIR__.'/../resources/lang');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'condoedge-comms');

        //Usage: php artisan vendor:publish --tag="condoedge-communications-js"
        $this->publishes([
            __DIR__.'/../resources/js' => resource_path('js'),
        ], 'condoedge-communications-js');

        //Usage: php artisan vendor:publish --tag="condoedge-communications-config"
        $this->publishes([
            __DIR__.'/../config/kompo-communications.php' => config_path('kompo-communications.php'),
        ], 'condoedge-communications-config');

        $this->loadConfig();

        $this->loadListeners();

        $this->loadCrons();

        if ($this->app->runningInConsole()) {
            $this->commands([
                SeedTemplatesCommand::class,
                SendScheduledRemindersCommand::class,
                PruneReminderClaimsCommand::class,
            ]);
        }
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //Best way to load routes. This ensures loading at the very end (after fortifies' routes for ex.)
        $this->booted(function () {
            \Route::middleware('web')->group(__DIR__.'/../routes/web.php');
        });

        $this->app->singleton('communication-variables-manager', function () {
            return new VariablesManager();
        });

        $this->app->singleton('communcations-context-enhancer', function () {
            return new ContextEnhancer();
        });

        $this->app->singleton('communcations-content-replacer', function () {
            return new MessageContentReplacer();
        });

        $this->app->bind(TemplateSeedingServiceContract::class, TemplateSeedingService::class);

        // Team-inheritance template resolution: pure resolver wrapped in a per-request cache
        // decorator (mirrors the Cached* / AuthCacheLayer pattern). The cache flushes at request
        // termination via the auth provider's lifecycle cleanup.
        $this->app->singleton(\Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolver::class);

        $this->app->singleton(
            \Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolverContract::class,
            fn ($app) => new \Condoedge\Communications\Services\TemplateResolution\CachedEffectiveTemplateResolver(
                $app->make(\Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolver::class),
                $app->make(\Kompo\Auth\Teams\Cache\AuthCacheLayer::class),
            )
        );

        $this->app->bind(
            \Condoedge\Communications\Services\Stats\CommunicationStatsServiceContract::class,
            \Condoedge\Communications\Services\Stats\CommunicationStatsService::class
        );

        // No trigger grouping by default — the admin Templates tab hides the group column + filter.
        // A host app binds its own adapter over its domain grouping.
        $this->app->bind(
            \Condoedge\Communications\Services\Grouping\TriggerGroupResolverContract::class,
            \Condoedge\Communications\Services\Grouping\NullTriggerGroupResolver::class
        );

        // The send path deliberately uses the UNCACHED resolver. The decorator memoizes for the
        // lifetime of a request and is flushed at request termination, which a queue worker never
        // reaches between jobs — a worker that once resolved NONE/DISABLED would keep suppressing
        // sends until redeploy, long after an admin fixed the template. One resolve per dispatch
        // costs nothing next to actually sending.
        $this->app->bind(
            \Condoedge\Communications\Services\Dispatch\CommunicationDispatchServiceContract::class,
            fn ($app) => new \Condoedge\Communications\Services\Dispatch\CommunicationDispatchService(
                $app->make(\Condoedge\Communications\Services\TemplateResolution\EffectiveTemplateResolver::class),
            )
        );

        ContentReplacer::setPostProcessors([
            function ($result) {
                if ($result instanceof MailElement) {
                    return $result->getHtml();
                }

                return $result;
            }
        ]);

        ContentReplacer::addParsers([
            new BraceMentionParser(),
            new HtmlMentionParser(),
        ]);
    }

    protected function loadHelpers()
    {
        $helpersDir = __DIR__.'/Helpers';

        $autoloadedHelpers = collect(\File::allFiles($helpersDir))->map(fn($file) => $file->getRealPath());

        $autoloadedHelpers->each(function ($path) {
            if (file_exists($path)) {
                require_once $path;
            }
        });
    }

    protected function loadConfig()
    {
        $dirs = [
            'kompo-communications' => __DIR__.'/../config/kompo-communications.php',
        ];

        foreach ($dirs as $key => $path) {
            $this->mergeConfigFrom($path, $key);
        }
    }

    /**
     * Loads the listeners.
     */
    protected function loadListeners()
    {
        $this->verifyCommunicationTriggers();
        $this->verifyReminders();

        Event::listen(CommunicationTemplateGroup::getTriggers(), CommunicationTriggeredListener::class);
    }

    protected function verifyCommunicationTriggers()
    {
        $triggers = CommunicationTemplateGroup::getTriggers();

        foreach ($triggers as $trigger) {
            if (!class_exists($trigger)) {
                throw new \Exception("The trigger class $trigger does not exist.");
            }

            if (!in_array(CommunicableEvent::class, class_implements($trigger))) {
                throw new \Exception("The trigger class $trigger must implement ".CommunicableEvent::class);
            }
        }
    }

    /**
     * Fails closed at boot, exactly as verifyCommunicationTriggers() does.
     *
     * A reminder registered under a name that does not exist, or sharing a key with another, is a
     * deploy-time exception rather than a fatal in an unattended 3am cron.
     */
    protected function verifyReminders()
    {
        app(\Condoedge\Communications\Reminders\ReminderRegistry::class)->validate();
    }

    protected function loadCrons()
    {
        if (!$this->app->runningInConsole()) {
            // Guarded rather than run unconditionally, because resolving Schedule::class is not the
            // cheap lookup it looks like: the singleton's resolver (verified in framework 11.55.0 at
            // Illuminate/Foundation/Providers/FoundationServiceProvider.php:107) is
            // $app->make(ConsoleKernel::class)->resolveConsoleSchedule(), which CONSTRUCTS the
            // Console Kernel and runs the HOST's entire schedule() definition. Doing that on every
            // web request buys nothing — no web request ever runs a scheduled event.
            return;
        }

        // Deferred into booted() rather than registered inline, because the Schedule singleton's
        // resolver runs the host's own schedule() method (see the guard above). Resolving it from a
        // provider's boot() would therefore execute the host's schedule definition part-way through
        // the boot cycle, before providers registered after this one have booted — a host whose
        // schedule() touches a later provider's binding would fail at boot with an error naming
        // neither the schedule nor this package. condoedge/finance and condoedge/utils both defer
        // the same way (CondoedgeFinanceServiceProvider.php:241, CondoedgeUtilsServiceProvider.php:273).
        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);

            // Hourly rather than daily because a scope carries its own send time: a once-a-day
            // command can only ever honour one hour. 23 of the 24 runs short-circuit before any
            // subject query. withoutOverlapping because an hourly command can meet itself; the
            // claim ledger makes that safe, but a doubled console log is a support ticket nobody
            // needs.
            //
            // 55 minutes rather than the framework default of 1440. The mutex is released only on a
            // clean finish or a caught throw, so a sweep killed by SIGKILL or OOM leaves an orphan
            // that expires on TTL alone; 55 < the 60-minute cadence guarantees it is dead before the
            // next firing. At 1440 one hard kill suppresses 24 consecutive sweeps — and a suppressed
            // sweep is not deferred, because the run hour comes from the wall clock, so that day's
            // reminders are lost outright with exit code 0 and no log line.
            //
            // Scheduled from the package rather than left to each host to write, because the
            // CADENCE IS PACKAGE KNOWLEDGE: a host reading "send reminders" would reasonably write
            // ->daily(), and would then silently drop every scope whose send hour is not the one it
            // picked. That failure is invisible — no error, just reminders that never arrive.
            //
            // Known consequence, accepted deliberately: a host that ALSO schedules this command by
            // name gets two entries and sweeps hourly instead of at its chosen hour. Bounded, not
            // dangerous — the sweep is idempotent per subject and offset via the claim table's
            // unique index, so the extra runs claim nothing and send nothing; they cost queries and
            // log lines, not duplicate emails.
            //
            // The host's own schedule line is deleted when it adopts this layer and its hour moves
            // into reminders.default_hour — but note what does NOT move: ReminderScope::hour()
            // discards the minute, so the cron minute here is the only minute that exists. SISC's
            // dailyAt('09:30') was chosen so a volunteer never got two emails in the same minute as
            // its 09:00 chase; default_hour = 9 reinstates that collision. A host that needs to stay
            // off another job's minute must pick a different HOUR.
            $schedule->command('communications:send-reminders')
                ->hourly()
                ->onOneServer()
                ->withoutOverlapping(55);

            $schedule->command('communications:prune-reminder-claims')
                ->weeklyOn(1, '03:00')
                ->onOneServer();
        });

        // TODO: a cron to remove old void group templates still needs adding here. Unrelated to the
        // reminder schedule above — it concerns CommunicationTemplateGroup, not reminders.
    }
}
