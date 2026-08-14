<?php

namespace App\Providers;

use App\Models\Alert;
use App\Models\ContentItem;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\KpiActual;
use App\Models\KpiDefinition;
use App\Models\KpiProfile;
use App\Models\KpiTarget;
use App\Models\Mission;
use App\Models\PeriodicReport;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\ReportTemplateSection;
use App\Models\User;
use App\Observers\ModelObserver;
use App\Policies\KpiPolicy;
use App\Policies\ReferralPolicy;
use App\Policies\ReportPolicy;
use DateTimeInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Not enforced: audit_logs.affected_entity_type is a freeform varchar
        // covering entities beyond this map (roles, missions, ministries, etc.);
        // unmapped values fall back to raw class-string resolution.
        Relation::morphMap([
            'alert' => Alert::class,
            'inquiry' => Inquiry::class,
            'directive' => Directive::class,
            'periodic_report' => PeriodicReport::class,
            'content_item' => ContentItem::class,
            'user' => User::class,
        ]);

        // NFR-SEC-004 (Session 38): the 'local' disk driver has no built-in
        // temporaryUrl() support (unlike S3) — this wires it up so
        // AlertController/ReferralController's downloadAttachment() can
        // still hand back a 15-minute signed link instead of ever exposing
        // the raw file path. See UploadStreamController, the only route
        // that resolves this signed URL.
        Storage::disk('uploads')->buildTemporaryUrlsUsing(
            fn (string $path, DateTimeInterface $expiration, array $options = []) => URL::temporarySignedRoute(
                'uploads.stream',
                $expiration,
                array_merge($options, ['path' => $path]),
            ),
        );

        // NFR-SEC-004 (Session 38, made configurable Session 39): named limiter behind
        // routes/api.php's throttle:login middleware, replacing a hardcoded
        // throttle:5,1. Production and every committed default stay at 5/minute
        // (config/auth.php's own default); see config/auth.php's docblock for why this
        // needed to become configurable rather than a fixed literal.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(
            (int) config('auth.login_throttle_per_minute', 5),
        )->by($request->ip()));

        // ReferralPolicy governs both ReferralEntry and ReferralOrganisation
        // (Session 12); neither model's name matches the policy's name, so
        // Laravel's convention-based auto-discovery would not find it.
        Gate::policy(ReferralEntry::class, ReferralPolicy::class);
        Gate::policy(ReferralOrganisation::class, ReferralPolicy::class);

        // ReportPolicy governs both PeriodicReport (report instances) and
        // ReportTemplateSection (template configuration); neither model's
        // name matches "ReportPolicy", so Laravel's convention-based
        // auto-discovery would not find it (Session 25).
        Gate::policy(PeriodicReport::class, ReportPolicy::class);
        Gate::policy(ReportTemplateSection::class, ReportPolicy::class);

        // KpiPolicy governs KpiDefinition, KpiProfile, KpiTarget, and
        // (Session 33) KpiActual — none of their names match "KpiPolicy"
        // for all four, so auto-discovery would not find it for
        // KpiDefinition/KpiProfile/KpiActual (it would for KpiTarget alone).
        Gate::policy(KpiDefinition::class, KpiPolicy::class);
        Gate::policy(KpiProfile::class, KpiPolicy::class);
        Gate::policy(KpiTarget::class, KpiPolicy::class);
        Gate::policy(KpiActual::class, KpiPolicy::class);

        // Session 09 task 2: generic created/updated/deleted audit trail.
        // For User and Mission this runs alongside the fine-grained manual
        // AuditService::record() calls already in Admin\UserController /
        // Admin\MissionController / Auth\LoginController (Sessions 5-7) —
        // those produce specific action names (user.login.succeeded,
        // mission.deactivated, ...); this observer additionally produces a
        // generic {model}.updated/created row on the same mutation. Known,
        // accepted duplication, not a bug — see CLAUDE.md Section 12.
        foreach ([
            User::class,
            Mission::class,
            Alert::class,
            Inquiry::class,
            Directive::class,
            PeriodicReport::class,
            ReferralEntry::class,
            ContentItem::class,
            KpiDefinition::class,
            KpiProfile::class,
            KpiTarget::class,
            KpiActual::class,
        ] as $observedModel) {
            $observedModel::observe(ModelObserver::class);
        }
    }
}
