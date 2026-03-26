<?php

namespace Steets\FormsGdpr;

use Illuminate\Console\Scheduling\Schedule;
use Statamic\Providers\AddonServiceProvider;
use Steets\FormsGdpr\Commands\PurgeFormSubmissions;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        PurgeFormSubmissions::class
    ];

    public function bootAddon()
    {
        // Config managed via Statamic CP globals

        $this->app->booted(function () {
            $globalSet = \Statamic\Facades\GlobalSet::find('forms-gdpr');
            if (! $globalSet) {
                return;
            }
            $settings = $globalSet->inDefaultSite()->data();
            if (!($settings['schedule_enabled'] ?? true)) {
                return;
            }

            $schedule = app(Schedule::class);
            $frequency = (string) ($settings['schedule_frequency'] ?? 'daily');

            $event = $schedule->command('forms-gdpr:purge');

            if ($frequency === 'hourly') {
                $event->hourly();
                return;
            }

            // daily
            $time = (string) ($settings['schedule_time'] ?? '03:15');
            $event->dailyAt($time);
        });
    }

    protected function bootBlueprints(): self
    {
        $this->publishes([
            __DIR__.'/../resources/blueprints/globals/forms-gdpr.yaml' => resource_path('blueprints/globals/forms-gdpr.yaml')
        ], 'blueprints');

        $this->publishes([
            __DIR__.'/../content/globals/forms-gdpr.yaml' => base_path('content/globals/forms-gdpr.yaml')
        ], 'globals');
                
        return $this;
    }
}