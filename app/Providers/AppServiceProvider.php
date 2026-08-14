<?php

namespace App\Providers;

use App\Models\Alert;
use App\Models\ContentItem;
use App\Models\Directive;
use App\Models\Inquiry;
use App\Models\Mission;
use App\Models\PeriodicReport;
use App\Models\ReferralEntry;
use App\Models\ReferralOrganisation;
use App\Models\ReportTemplateSection;
use App\Models\User;
use App\Observers\ModelObserver;
use App\Policies\ReferralPolicy;
use App\Policies\ReportPolicy;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
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
        ] as $observedModel) {
            $observedModel::observe(ModelObserver::class);
        }
    }
}
