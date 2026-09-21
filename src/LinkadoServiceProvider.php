<?php

declare(strict_types=1);

namespace Linkado\Laravel;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;
use Linkado\Laravel\Actions\CreateSsoLink;
use Linkado\Laravel\Actions\RecordLinkadoEvent;
use Linkado\Laravel\Console\Commands\DiagnoseCommand;
use Linkado\Laravel\Console\Commands\HealthCommand;
use Linkado\Laravel\Console\Commands\InstallCommand;
use Linkado\Laravel\Console\Commands\PruneCommand;
use Linkado\Laravel\Console\Commands\RecoverCommand;
use Linkado\Laravel\Console\Commands\RetryCommand;
use Linkado\Laravel\Contracts\DeterminesLinkadoEligibility;
use Linkado\Laravel\Contracts\ResolvesLinkadoSsoUser;
use Linkado\Laravel\Exceptions\InvalidLinkadoConfiguration;
use Linkado\Laravel\Http\Middleware\CapturePendingAttribution;
use Linkado\Laravel\Http\Middleware\EnsureLinkadoVisitor;
use Linkado\Laravel\Support\Attribution\AttributionManager;
use Linkado\Laravel\Support\Database\RequiresActiveTransaction;
use Linkado\Laravel\Support\Events\EventFeatureMap;
use Linkado\Laravel\Support\Events\EventPayloadCodec;
use Linkado\Laravel\Support\LinkadoConfiguration;
use Linkado\Laravel\Support\Tracking\TrackingGate;
use Linkado\Laravel\Support\Tracking\TrackingRenderer;
use Linkado\PhpSdk\LinkadoConnector;

class LinkadoServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/linkado.php', 'linkado');

        $this->app->singleton(LinkadoConfiguration::class);

        $this->app->singleton(LinkadoConnector::class, function (Application $app): LinkadoConnector {
            $configuration = $app->make(LinkadoConfiguration::class);

            return new LinkadoConnector(
                token: $configuration->requiredToken(),
                baseUrl: $configuration->requiredBaseUrl(),
            );
        });

        $this->app->singleton(EventFeatureMap::class);

        $this->app->singleton(AttributionManager::class);

        $this->app->bind(CreateSsoLink::class, fn (Application $app): CreateSsoLink => new CreateSsoLink(
            configuration: $app->make(LinkadoConfiguration::class),
            eligibility: $app->make(DeterminesLinkadoEligibility::class),
            userResolver: $app->make(ResolvesLinkadoSsoUser::class),
            connectorResolver: fn (): LinkadoConnector => $app->make(LinkadoConnector::class),
        ));

        $this->app->singleton(RecordLinkadoEvent::class, fn (Application $app): RecordLinkadoEvent => new RecordLinkadoEvent(
            transaction: $app->make(RequiresActiveTransaction::class),
            codec: $app->make(EventPayloadCodec::class),
            events: $app->make(Dispatcher::class),
            configuration: $app->make(LinkadoConfiguration::class),
            featureMap: $app->make(EventFeatureMap::class),
            bus: $app->make(BusDispatcher::class),
            cache: $app->make(CacheRepository::class),
            eligibilityResolver: fn (): DeterminesLinkadoEligibility => $app->make(DeterminesLinkadoEligibility::class),
        ));

        $this->app->singleton(Linkado::class);

        $this->app->singleton(DeterminesLinkadoEligibility::class, fn (Application $app): DeterminesLinkadoEligibility => $app->make(Linkado::class));

        $this->app->singleton(ResolvesLinkadoSsoUser::class, fn (Application $app): ResolvesLinkadoSsoUser => $app->make(Linkado::class));

        $this->app->bind(TrackingGate::class, fn (Application $app): TrackingGate => new TrackingGate(
            configuration: $app->make(LinkadoConfiguration::class),
            eligibilityResolver: fn (): DeterminesLinkadoEligibility => app(DeterminesLinkadoEligibility::class),
        ));

        $this->app->bind(TrackingRenderer::class, fn (Application $app): TrackingRenderer => new TrackingRenderer(
            configuration: $app->make(LinkadoConfiguration::class),
            tracking: $app->make(TrackingGate::class),
            views: $app->make('view'),
            application: $app,
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/linkado.php');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'linkado');

        $this->loadTranslationsFrom(__DIR__.'/../lang', 'linkado');

        $configuration = $this->app->make(LinkadoConfiguration::class);

        try {
            EncryptCookies::except([
                $configuration->trackingClickCookie(),
                $configuration->trackingReferralCookie(),
            ]);
        } catch (InvalidLinkadoConfiguration) {
            // The request-time tracking gate rejects unusable cookie configuration.
        }

        $router = $this->app->make(Router::class);
        $router->pushMiddlewareToGroup('web', EnsureLinkadoVisitor::class);
        $this->callAfterResolving(HttpKernel::class, function (Kernel $kernel): void {
            $kernel->appendMiddlewareToGroup('web', EnsureLinkadoVisitor::class);
        });
        $router->aliasMiddleware('linkado.attribution', CapturePendingAttribution::class);

        Blade::directive(
            'linkadoTracking',
            static fn (): string => '<?php echo app(\''.TrackingRenderer::class.'\')->render(); ?>',
        );

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/linkado.php' => config_path('linkado.php'),
        ], ['linkado', 'linkado-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/linkado'),
        ], ['linkado', 'linkado-lang']);

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['linkado', 'linkado-migrations']);

        $this->commands([
            DiagnoseCommand::class,
            HealthCommand::class,
            InstallCommand::class,
            PruneCommand::class,
            RecoverCommand::class,
            RetryCommand::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(RecoverCommand::class)
                ->everyFiveMinutes()
                ->withoutOverlapping();

            $schedule->command(PruneCommand::class)
                ->daily()
                ->withoutOverlapping();
        });
    }
}
