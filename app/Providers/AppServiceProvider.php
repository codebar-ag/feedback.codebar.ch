<?php

declare(strict_types=1);

namespace App\Providers;

use App\Checks\DatabaseCheck;
use App\Checks\DatabaseConnectionCountCheck;
use App\Checks\EntraClientSecretCheck;
use App\Checks\FailedJobsCheck;
use App\Checks\JobsCheck;
use App\Checks\NightwatchIssuesCheck;
use App\Checks\PendingMigrationsCheck;
use App\Checks\PostmarkTokenCheck;
use App\Checks\ProductionConfigCheck;
use App\Checks\SchedulePolicyCheck;
use App\Checks\StorageRoundTripCheck;
use App\Http\Middleware\ResolveProject;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Spatie\Activitylog\Models\Activity;
use Spatie\Health\Checks\Checks\CacheCheck;
use Spatie\Health\Checks\Checks\DebugModeCheck;
use Spatie\Health\Checks\Checks\EnvironmentCheck;
use Spatie\Health\Checks\Checks\ScheduleCheck;
use Spatie\Health\Facades\Health;
use Spatie\SecurityAdvisoriesHealthCheck\SecurityAdvisoriesCheck;

final class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureRateLimiting();
        $this->configureActivityLog();
        $this->configureHealthChecks();
    }

    /**
     * Checks for Oh Dear (OH_DEAR_HEALTH_CHECK_*) and `php artisan health:check`:
     * the standard set agreed for all Laravel Cloud apps.
     */
    private function configureHealthChecks(): void
    {
        Health::checks([
            DebugModeCheck::new(),
            EnvironmentCheck::new()->if(app()->isProduction()),
            DatabaseCheck::new(),
            DatabaseConnectionCountCheck::new(),
            CacheCheck::new(),
            // A database store, because the heartbeat is written by the scheduler and the
            // deploy command but read by a web request: on Laravel Cloud these run in
            // separate containers, and a file cache is local to each of them. Not the
            // default store: Cache::flush() and optimize:clear would wipe it.
            ScheduleCheck::new()->useCacheStore('health')->heartbeatMaxAgeInMinutes(120),
            SecurityAdvisoriesCheck::new(),
            NightwatchIssuesCheck::new()->if(filled(config('default.nightwatch.api_token')) && filled(config('default.nightwatch.application_id'))),
            JobsCheck::new(),
            FailedJobsCheck::new(),
            PendingMigrationsCheck::new(),
            ProductionConfigCheck::new()->if(app()->isProduction()),
            SchedulePolicyCheck::new(),
            StorageRoundTripCheck::new(),
            EntraClientSecretCheck::new(),
            PostmarkTokenCheck::new(),
        ]);
    }

    /**
     * Limits per IP and project, per project and per project and day. Values
     * come from config/feedback.php and the project, without defaults.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('feedback', function (Request $request): Limit|array {
            $project = ResolveProject::project($request);
            $perIp = config('feedback.limits.per_ip_per_minute');
            $perProject = config('feedback.limits.per_project_per_minute');
            $limits = [];

            if (is_int($perIp)) {
                $limits[] = Limit::perMinute($perIp)->by("feedback:ip:{$project->id}:{$request->ip()}");
            }

            if (is_int($perProject)) {
                $limits[] = Limit::perMinute($perProject)->by("feedback:project:{$project->id}");
            }

            if ($project->daily_limit !== null) {
                $limits[] = Limit::perDay($project->daily_limit)->by("feedback:day:{$project->id}");
            }

            return $limits === [] ? Limit::none() : $limits;
        });
    }

    /** Every log entry records how the change was made (via) and by whom (--by). */
    private function configureActivityLog(): void
    {
        Activity::creating(function (Activity $activity): void {
            $activity->properties = ($activity->properties ?? collect())->merge(array_filter([
                'via' => $this->app->runningInConsole() ? 'artisan' : 'http',
                'by' => Context::get('activity_by'),
            ], fn (mixed $value): bool => $value !== null));
        });
    }
}
