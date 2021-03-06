<?php

namespace PragmaRX\Health;

use Event;
use Artisan;
use Illuminate\Routing\Router;
use PragmaRX\Yaml\Package\Yaml;
use PragmaRX\Health\Support\Cache;
use Illuminate\Console\Scheduling\Schedule;
use PragmaRX\Health\Support\ResourceLoader;
use PragmaRX\Health\Events\RaiseHealthIssue;
use PragmaRX\Health\Support\ResourceChecker;
use PragmaRX\Health\Listeners\NotifyHealthIssue;
use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;

class ServiceProvider extends IlluminateServiceProvider
{
    /**
     * The application instance.
     *
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

    /**
     * Resource loader instance.
     *
     * @var
     */
    protected $resourceLoader;

    /**
     * The health service.
     *
     * @var
     */
    private $healthService;

    /**
     * All artisan commands.
     *
     * @var
     */
    private $commands;

    /**
     * The router.
     *
     * @var
     */
    private $router;

    /**
     * Cache closure.
     *
     * @var
     */
    private $cacheClosure;

    /**
     * Resource checker closure.
     *
     * @var
     */
    private $resourceCheckerClosure;

    /**
     * Health service closure.
     *
     * @var
     */
    private $healthServiceClosure;

    /**
     * Configure package paths.
     */
    private function configurePaths()
    {
        $this->publishes([
            __DIR__.'/config/config.php' => config_path('health.php'),
        ]);

        $this->publishes([
            __DIR__.'/views/' => resource_path('views/vendor/pragmarx/health/'),
        ]);
    }

    /**
     * Configure package folder views.
     */
    private function configureViews()
    {
        $this->loadViewsFrom(realpath(__DIR__.'/views'), 'pragmarx/health');
    }

    /**
     * Create health service.
     */
    private function createHealthService()
    {
        $resourceChecker = call_user_func($this->resourceCheckerClosure);

        $cache = call_user_func($this->cacheClosure);

        $this->healthServiceClosure = function () use ($resourceChecker, $cache) {
            return $this->instantiateService($resourceChecker, $cache);
        };

        $this->healthService = call_user_func($this->healthServiceClosure);
    }

    /**
     * Create resource checker.
     */
    private function createResourceChecker()
    {
        $this->resourceLoader = new ResourceLoader(new Yaml());

        $this->cacheClosure = $this->getCacheClosure();

        $this->resourceCheckerClosure = $this->getResourceCheckerClosure($this->resourceLoader, call_user_func($this->cacheClosure));
    }

    /**
     * Get the cache closure for instantiation.
     *
     * @return \Closure
     */
    private function getCacheClosure()
    {
        $cacheClosure = function () {
            return new Cache();
        };

        return $cacheClosure;
    }

    /**
     * Return the health service.
     *
     * @return mixed
     */
    public function getHealthService()
    {
        return $this->healthService;
    }

    /**
     * Get the resource checker closure for instantiation.
     *
     * @param $resourceLoader
     * @param $cache
     * @return \Closure
     */
    private function getResourceCheckerClosure($resourceLoader, $cache)
    {
        $resourceCheckerInstance = function () use ($resourceLoader, $cache) {
            return new ResourceChecker($resourceLoader, $cache);
        };

        return $resourceCheckerInstance;
    }

    /**
     * Get the current router.
     *
     * @return mixed
     */
    private function getRouter()
    {
        if (! $this->router) {
            $this->router = $this->app->router;

            if (! $this->router instanceof Router) {
                $this->router = app()->router;
            }
        }

        return $this->router;
    }

    /**
     * Get the list of routes.
     *
     * @return array
     */
    private function getRoutes()
    {
        return config('health.routes.list');
    }

    /**
     * Instantiate commands.
     *
     * @return \Illuminate\Foundation\Application|mixed
     */
    private function instantiateCommands()
    {
        return $this->commands = instantiate(Commands::class, [$this->healthService]);
    }

    /**
     * Instantiate the main service.
     *
     * @param $resourceChecker
     * @param $cache
     * @return Service
     */
    private function instantiateService($resourceChecker, $cache)
    {
        return $this->healthService = new Service($resourceChecker, $cache);
    }

    /**
     * Merge configuration.
     */
    private function mergeConfig()
    {
        $this->mergeConfigFrom(
            __DIR__.'/config/config.php', 'health'
        );
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->mergeConfig();

        $this->configureViews();

        $this->configurePaths();

        $this->registerServices();

        $this->registerRoutes();

        $this->registerTasks();

        $this->registerEventListeners();

        $this->registerConsoleCommands();
    }

    private function registerResourcesRoutes()
    {
        collect($this->resourceLoader->getResources())->each(function ($item) {
            if (isset($item['routes'])) {
                collect($item['routes'])->each(function ($route, $key) {
                    $this->registerRoute($route, $key);
                });
            }
        });
    }

    /**
     * Register console commands.
     */
    private function registerConsoleCommands()
    {
        $commands = $this->commands;

        Artisan::command('health:panel', function () use ($commands) {
            $commands->panel($this);
        })->describe('Show all resources and their current health states.');

        Artisan::command('health:check', function () use ($commands) {
            $commands->check($this);
        })->describe('Check resources health and send error notifications.');

        Artisan::command('health:export', function () use ($commands) {
            $commands->export($this);
        })->describe('Export "array" resources to .yml files');

        Artisan::command('health:publish', function () use ($commands) {
            $commands->publish($this);
        })->describe('Publish all .yml resouces files to config directory.');
    }

    /**
     * Register event listeners.
     */
    private function registerEventListeners()
    {
        Event::listen(RaiseHealthIssue::class, NotifyHealthIssue::class);
    }

    /**
     * @param $route
     * @param null $name
     */
    private function registerRoute($route, $name = null)
    {
        $action = isset($route['controller'])
                    ? "{$route['controller']}@{$route['action']}"
                    : $route['action'];

        $router = $this->getRouter()->get($route['uri'], [
            'as' => $name ?: $route['name'],
            'uses' => $action,
        ]);

        if (isset($route['middleware'])) {
            $router->middleware($route['middleware']);
        }
    }

    /**
     * Register routes.
     */
    private function registerRoutes()
    {
        collect($routes = $this->getRoutes())->each(function ($route) {
            $this->registerRoute($route);
        });

        $this->registerResourcesRoutes();
    }

    /**
     * Register service.
     */
    private function registerServices()
    {
        $this->createServices();

        $this->app->singleton('pragmarx.health.cache', $this->cacheClosure);

        $this->app->singleton('pragmarx.health.resource.checker', $this->resourceCheckerClosure);

        $this->app->singleton('pragmarx.health', $this->healthServiceClosure);

        $this->app->singleton('pragmarx.health.commands', $this->instantiateCommands());
    }

    /**
     * Create services.
     */
    public function createServices()
    {
        $this->createResourceChecker();

        $this->createHealthService();
    }

    /**
     * Register scheduled tasks.
     */
    private function registerTasks()
    {
        if (config('health.scheduler.enabled') &&
            ($frequency = config('health.scheduler.frequency')) &&
            config('health.notifications.enabled')
        ) {
            $scheduler = instantiate(Schedule::class);

            $scheduler->call($this->healthService->getSilentChecker())->{$frequency}();
        }
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return [
            'pragmarx.health.cache',
            'pragmarx.health.resource.checker',
            'pragmarx.health',
            'pragmarx.health.commands',
        ];
    }
}
