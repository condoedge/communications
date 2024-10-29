<?php

namespace Condoedge\Communications;

use Condoedge\Communications\EventsHandling\CommunicationTriggeredListener;
use Condoedge\Communications\EventsHandling\Contracts\CommunicableEvent;
use Condoedge\Communications\Models\CommunicationTemplateGroup;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\ContextEnhancer;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\MessageContentReplacer;
use Condoedge\Communications\Services\EnhancedEditor\ReplacerManager\VariablesManager\VariablesManager;
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
