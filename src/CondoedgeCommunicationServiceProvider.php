<?php

namespace Condoedge\Communications;

use Condoedge\Communications\Console\SeedTemplatesCommand;
use Condoedge\Communications\EventsHandling\CommunicationTriggeredListener;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Facades\ContentReplacer;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
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

    protected function loadCrons()
    {
        // TODO WE SHOULD REMOVE OLD VOID GROUP TEMPLATES 
        $schedule = $this->app->make(Schedule::class);
    }
}
