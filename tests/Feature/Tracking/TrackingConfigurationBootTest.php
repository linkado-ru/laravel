<?php

declare(strict_types=1);

namespace Linkado\Laravel\Tests\Feature\Tracking;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Linkado\Laravel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

final class TrackingConfigurationBootTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        [$key, $value, $mode] = $this->providedData();
        $app['config']->set('linkado', require __DIR__.'/../../../config/linkado.php');
        $app['config']->set('linkado.mode', $mode);
        $app['config']->set('linkado.features.tracking', true);
        $app['config']->set('linkado.tracking.'.$key, $value);
    }

    #[Test]
    #[DataProvider('invalidCookieConfiguration')]
    public function invalid_tracking_cookie_configuration_does_not_break_boot_or_issue_a_visitor(string $key, mixed $value, string $mode): void
    {
        Route::middleware(['web', 'linkado.attribution'])->get('/_tracking-boot', fn () => response(
            'host-content'.Blade::render('@linkadoTracking', deleteCachedView: true),
        ));

        $this->get('/_tracking-boot?ref=partner')->assertOk()
            ->assertContent('host-content')->assertCookieMissing('linkado_visitor');
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function invalidCookieConfiguration(): iterable
    {
        foreach (['click_cookie', 'referral_cookie'] as $key) {
            foreach ([null, '', []] as $index => $value) {
                foreach (['off', 'live'] as $mode) {
                    yield $key.'-'.$index.'-'.$mode => [$key, $value, $mode];
                }
            }
        }
    }
}
