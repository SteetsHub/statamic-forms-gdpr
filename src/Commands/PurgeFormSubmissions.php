<?php

namespace Steets\FormsGdpr\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Statamic\Facades\Form;
use Statamic\Facades\Asset as AssetFacade;

class PurgeFormSubmissions extends Command
{
    protected $signature = 'forms-gdpr:purge {--form=* : Only purge specific form handles} {--dry-run : Do not delete, only report}';
    protected $description = 'Purge old Statamic form submissions and optionally delete referenced assets';

    public function handle(): int
    {
        $globalSet = \Statamic\Facades\GlobalSet::find('forms-gdpr');
        if (! $globalSet) {
            $this->error('Global set forms-gdpr not found.');
            return self::FAILURE;
        }
        $settings = $globalSet->inDefaultSite()->data();

        $days = (int) ($settings['days_to_keep'] ?? 30);
        if ($days < 0) {
            $this->error('forms-gdpr.days_to_keep must be >= 0');
            return self::FAILURE;
        }

        $deleteAssets = (bool) ($settings['delete_assets'] ?? false);
        $cutoff = Carbon::now()->subDays($days);

        $allowedForms = collect((array) $this->option('form'))->filter()->values();
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Cutoff date: {$cutoff->toDateTimeString()}");
        $this->info("Delete assets: " . ($deleteAssets ? 'yes' : 'no'));
        $this->info("Dry run: " . ($dryRun ? 'yes' : 'no'));

        $forms = Form::all();

        if ($allowedForms->isNotEmpty()) {
            $forms = $forms->filter(fn ($f) => $allowedForms->contains($f->handle()));
        }

        $totalDeleted = 0;
        $totalAssetsDeleted = 0;

        foreach ($forms as $form) {
            $handle = $form->handle();
            $submissions = $form->submissions();

            $toDelete = $submissions->filter(function ($submission) use ($cutoff) {
                // Submission date is usually Carbon
                $date = $submission->date();
                return $date instanceof Carbon ? $date->lt($cutoff) : false;
            });

            if ($toDelete->isEmpty()) {
                continue;
            }

            $this->line("Form: {$handle} | deletions: {$toDelete->count()}");

            foreach ($toDelete as $submission) {
                $assetsToDelete = [];

                if ($deleteAssets) {
                    $assetsToDelete = $this->extractAssetsFromSubmission($submission->data());
                }

                if ($dryRun) {
                    $totalDeleted++;
                    $this->line("  - would delete submission: {$submission->id()} ({$submission->date()?->toDateTimeString()})");
                    foreach ($assetsToDelete as $asset) {
                        $this->line("    - would delete asset: {$asset->container()->handle()}::{$asset->path()}");
                    }
                    continue;
                }

                // Delete submission first (or after assets, your preference)
                $submission->delete();
                $totalDeleted++;

                foreach ($assetsToDelete as $asset) {
                    try {
                        $asset->delete();
                        $totalAssetsDeleted++;
                    } catch (\Throwable $e) {
                        $this->warn("    Could not delete asset {$asset->id()}: {$e->getMessage()}");
                    }
                }
            }
        }

        $this->info("Deleted submissions: {$totalDeleted}");
        $this->info("Deleted assets: {$totalAssetsDeleted}");

        return self::SUCCESS;
    }

    private function extractAssetsFromSubmission(array|Collection $data): array
    {
        $data = $data instanceof Collection ? $data->all() : $data;
        $globalSet = \Statamic\Facades\GlobalSet::find('forms-gdpr');
        $settings = $globalSet ? $globalSet->inDefaultSite()->data() : [];
        $containersAllowlist = collect((array) ($settings['asset_containers'] ?? []))
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->values();

        $pathPrefixes = collect((array) ($settings['asset_path_prefixes'] ?? []))
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->values();

        $assetIds = [];

        $walk = function ($value) use (&$walk, &$assetIds) {
            if (is_string($value)) {
                $v = trim($value);
                if ($v === '') {
                    return;
                }

                // most secure: only container::path values
                if (str_contains($v, '::')) {
                    $assetIds[] = $v;
                }

                return;
            }
            if (is_array($value)) {
                foreach ($value as $v) {
                    $walk($v);
                }
            }
        };

        foreach ($data as $value) {
            $walk($value);
        }

        $assets = [];
        $seen = [];

        foreach ($assetIds as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            // Try Statamic asset lookup
            $asset = AssetFacade::find($candidate);

            if (! $asset) {
                // Some values ​​are "container::path"
                if (str_contains($candidate, '::')) {
                    $asset = AssetFacade::findById($candidate);
                }
            }

            if (! $asset) {
                continue;
            }

            $id = $asset->id();
            if (isset($seen[$id])) {
                continue;
            }

            // Container allowlist check
            if ($containersAllowlist->isNotEmpty() && ! $containersAllowlist->contains($asset->container()->handle())) {
                continue;
            }

            // Path prefix check
            if ($pathPrefixes->isNotEmpty()) {
                $path = ltrim((string) $asset->path(), '/');
                $ok = $pathPrefixes->some(fn ($p) => str_starts_with($path, ltrim($p, '/')));
                if (! $ok) {
                    continue;
                }
            }

            $seen[$id] = true;
            $assets[] = $asset;
        }

        return $assets;
    }
}