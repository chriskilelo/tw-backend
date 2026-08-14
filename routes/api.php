<?php

use App\Http\Controllers\Api\Admin\AuditLogController;
use App\Http\Controllers\Api\Admin\MasterDataController;
use App\Http\Controllers\Api\Admin\MissionController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Alerts\AlertController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Api\Directives\DirectiveController;
use App\Http\Controllers\Api\Governance\MfaAwarenessController;
use App\Http\Controllers\Api\Governance\MissionActivityController;
use App\Http\Controllers\Api\Inquiries\InquiryController;
use App\Http\Controllers\Api\Kpi\KpiActualController;
use App\Http\Controllers\Api\Kpi\KpiComparisonController;
use App\Http\Controllers\Api\Kpi\KpiDefinitionController;
use App\Http\Controllers\Api\Kpi\KpiProfileController;
use App\Http\Controllers\Api\Kpi\KpiReportController;
use App\Http\Controllers\Api\Kpi\KpiTargetController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Referrals\ReferralController;
use App\Http\Controllers\Api\Reports\PeriodicReportController;
use App\Http\Controllers\Api\Reports\ReportTemplateController;
use App\Http\Controllers\Api\Sdt\ActingPsController;
use App\Http\Controllers\Api\Sdt\ConfigController;
use App\Http\Controllers\Api\Sdt\DirectivesController as SdtDirectivesController;
use App\Http\Controllers\Api\Sdt\HqWorkspaceController;
use App\Http\Controllers\Api\Sdt\HrmdDashboardController;
use App\Http\Controllers\Api\Sdt\PsDashboardController;
use App\Http\Controllers\Api\Sdt\ReportsController as SdtReportsController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\Api\UploadStreamController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    // NFR-SEC-004 (Session 38): the only route that ever serves an
    // 'uploads'-disk file's bytes. Reachable only via a 15-minute signed
    // URL minted by Storage::disk('uploads')->temporaryUrl() (see
    // AppServiceProvider::boot()) — 'signed' verifies the path query
    // parameter hasn't been tampered with before the controller runs, so
    // this route deliberately sits outside the auth:web group (the
    // signature itself is the access control, same as any Laravel signed
    // URL).
    Route::get('/uploads/stream', [UploadStreamController::class, 'show'])
        ->name('uploads.stream')
        ->middleware('signed');

    // NFR-SEC-004: pre-audit hardening. throttle:{max},{decay-minutes} is
    // Laravel's built-in ThrottleRequests middleware, keyed per-IP for
    // these unauthenticated routes (no authenticated user to key on yet).
    // /login uses the named 'login' limiter (AppServiceProvider, config/auth.php)
    // instead of a literal throttle:N,1 so its rate is configurable per environment
    // without changing the production-safe default (Session 39).
    Route::post('/login', LoginController::class)->middleware('throttle:login');
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:3,60');
    Route::post('/password/reset', [PasswordResetController::class, 'reset']);

    Route::middleware('auth:web')->group(function (): void {
        Route::post('/logout', LogoutController::class);
        Route::get('/me', [MeController::class, 'show']);
        Route::patch('/me/preferences', [MeController::class, 'updatePreferences']);

        Route::prefix('users')->group(function (): void {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::get('/{user}', [UserController::class, 'show']);
            Route::patch('/{user}', [UserController::class, 'update']);
            Route::post('/{user}/deactivate', [UserController::class, 'deactivate']);
            Route::post('/{user}/reactivate', [UserController::class, 'reactivate']);
            Route::post('/{user}/resend-activation', [UserController::class, 'resendActivation']);
        });

        Route::prefix('missions')->group(function (): void {
            Route::get('/', [MissionController::class, 'index']);
            Route::post('/', [MissionController::class, 'store']);
            Route::patch('/{mission}', [MissionController::class, 'update']);
            Route::post('/{mission}/deactivate', [MissionController::class, 'deactivate']);
        });

        Route::prefix('audit-logs')->group(function (): void {
            Route::get('/', [AuditLogController::class, 'index']);
        });

        Route::prefix('notifications')->group(function (): void {
            Route::get('/', [NotificationController::class, 'index']);
            Route::patch('/{notification}/read', [NotificationController::class, 'markRead']);
            Route::post('/mark-all-read', [NotificationController::class, 'markAllRead']);
        });

        // Layer 2 engine: ministry.scope binds current_ministry_id so the
        // Alert model's global scope (Session 5) filters every query
        // (CLAUDE.md Section 4, Rule 1; NFR-SEC-006).
        Route::prefix('alerts')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [AlertController::class, 'index']);
            Route::post('/', [AlertController::class, 'store']);
            Route::get('/{alert}', [AlertController::class, 'show']);
            Route::patch('/{alert}', [AlertController::class, 'update']);
            Route::post('/{alert}/attachments', [AlertController::class, 'storeAttachment']);
            Route::get('/{alert}/attachments/{attachment}', [AlertController::class, 'downloadAttachment']);
            Route::post('/{alert}/delegate', [AlertController::class, 'delegate']);
            Route::post('/{alert}/acknowledge', [AlertController::class, 'acknowledge']);
            Route::post('/{alert}/feedback', [AlertController::class, 'postFeedback']);
        });

        // Layer 2 engine: ministry.scope binds current_ministry_id so the
        // Inquiry model's global scope filters every query (CLAUDE.md
        // Section 4, Rule 1; NFR-SEC-006).
        Route::prefix('inquiries')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [InquiryController::class, 'index']);
            // NFR-SEC-004: the session spec calls this route "public" (an
            // unauthenticated inquiry-intake endpoint), but no such route
            // exists anywhere in this API — inquiries are always logged by
            // an authenticated Ministry Attache (POST /inquiries here sits
            // inside the auth:web + ministry.scope group, per API-001).
            // Applying the specified throttle to the actual, reachable
            // POST /inquiries endpoint rather than inventing a new public
            // intake surface, which is out of scope for a hardening
            // session — see CLAUDE.md's established "surfaced, not fixed"
            // convention for spec/implementation mismatches like this one.
            Route::post('/', [InquiryController::class, 'store'])->middleware('throttle:10,1');
            Route::get('/{inquiry}', [InquiryController::class, 'show']);
            Route::patch('/{inquiry}', [InquiryController::class, 'update']);
            Route::patch('/{inquiry}/status', [InquiryController::class, 'updateStatus']);
            Route::post('/{inquiry}/events', [InquiryController::class, 'storeEvent']);
            Route::post('/{inquiry}/notes', [InquiryController::class, 'storeNote']);
            Route::post('/{inquiry}/close', [InquiryController::class, 'close']);
            // FR-INQ-019 (Ministry HQ Officer only, see InquiryPolicy::link()).
            Route::post('/{inquiry}/link', [InquiryController::class, 'link']);
            Route::post('/{inquiry}/referrals', [ReferralController::class, 'store']);
        });

        // Layer 2 engine: Directive and Tasking (FR-DIR-002 to 013).
        // ministry.scope binds current_ministry_id so the Directive
        // model's global scope filters every query (CLAUDE.md Section 4,
        // Rule 1; NFR-SEC-006). '/summary' must be registered before
        // '/{directive}' so it is never swallowed by that wildcard (same
        // precedent as periodic-reports' '/compliance').
        Route::prefix('directives')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [DirectiveController::class, 'index']);
            Route::get('/summary', [DirectiveController::class, 'summary']);
            Route::post('/', [DirectiveController::class, 'store']);
            Route::get('/{directive}', [DirectiveController::class, 'show']);
            Route::patch('/{directive}/status', [DirectiveController::class, 'updateStatus']);
            Route::post('/{directive}/notes', [DirectiveController::class, 'storeNote']);
        });

        // Layer 2 engine: Referral Register (FR-REF-001 to 006, BR-021).
        // ministry.scope binds current_ministry_id so
        // ReferralEntry/ReferralOrganisation's global scope filters every
        // query (CLAUDE.md Section 4, Rule 1; NFR-SEC-006). Recording a
        // referral never triggers external communication (BR-021,
        // FR-REF-003) — see App\Services\ReferralService.
        Route::prefix('referral-organisations')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [ReferralController::class, 'indexOrganisations']);
        });

        Route::prefix('referrals')->middleware('ministry.scope')->group(function (): void {
            Route::get('/summary', [ReferralController::class, 'summary']);
            Route::post('/{referralEntry}/attachments', [ReferralController::class, 'storeAttachment']);
            Route::get('/{referralEntry}/attachments/{attachment}', [ReferralController::class, 'downloadAttachment']);
        });

        // Session 25: FR-RPT-002, BR-006 (Periodic Report Engine template
        // versions). ReportTemplateSection carries ministry_id and uses the
        // default HasMinistryScope column, so ministry.scope is applied
        // here (unlike master-data, whose model never adopted the scope).
        // System Administrator only, enforced via ReportPolicy::manageTemplate().
        Route::prefix('report-templates')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [ReportTemplateController::class, 'index']);
            Route::post('/', [ReportTemplateController::class, 'store']);
        });

        // Session 25/26: FR-RPT-003 to 011, 014, 016, 018 (Periodic Report
        // Engine). ministry.scope binds current_ministry_id so the
        // PeriodicReport model's global scope filters every query
        // (CLAUDE.md Section 4, Rule 1; NFR-SEC-006). '/compliance' must be
        // registered before '/{periodicReport}' so it is never swallowed by
        // that wildcard.
        Route::prefix('periodic-reports')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [PeriodicReportController::class, 'index']);
            Route::get('/compliance', [PeriodicReportController::class, 'compliance']);
            Route::post('/', [PeriodicReportController::class, 'store']);
            Route::get('/{periodicReport}', [PeriodicReportController::class, 'show']);
            Route::patch('/{periodicReport}/sections/{section}', [PeriodicReportController::class, 'updateSection']);
            Route::post('/{periodicReport}/data-rows', [PeriodicReportController::class, 'storeDataRow']);
            Route::delete('/{periodicReport}/data-rows/{dataRow}', [PeriodicReportController::class, 'destroyDataRow']);
            Route::post('/{periodicReport}/carry-forward', [PeriodicReportController::class, 'carryForward']);
            Route::post('/{periodicReport}/submit', [PeriodicReportController::class, 'submit']);
        });

        // Session 32: FR-KPI-001 to 005 (KPI Framework Engine). ministry.scope
        // binds current_ministry_id so KpiDefinition/KpiProfile/KpiTarget's
        // global scope filters every query (CLAUDE.md Section 4, Rule 1;
        // NFR-SEC-006) — though System Administrator (kpi-definitions,
        // kpi-profiles) bypasses it entirely per the established bypass-role
        // precedent (Session 5). See App\Policies\KpiPolicy.
        Route::prefix('kpi-definitions')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [KpiDefinitionController::class, 'index']);
            Route::post('/', [KpiDefinitionController::class, 'store']);
            Route::patch('/{kpiDefinition}', [KpiDefinitionController::class, 'update']);
        });

        Route::prefix('kpi-profiles')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [KpiProfileController::class, 'index']);
            Route::post('/', [KpiProfileController::class, 'store']);
            Route::patch('/{kpiProfile}/assign', [KpiProfileController::class, 'assign']);
        });

        Route::prefix('kpi-targets')->middleware('ministry.scope')->group(function (): void {
            Route::post('/', [KpiTargetController::class, 'store']);
        });

        // Session 33: FR-KPI-006 to 008, 013, 015 (actuals, national
        // comparison matrix, downloadable performance report). Same
        // ministry.scope precedent as kpi-definitions/kpi-profiles/
        // kpi-targets above — also the set of paths
        // App\Http\Middleware\MinistryScope's FR-SDT-018 HRM&D Officer
        // guard treats as KPI-scoped ("api/v1/kpi-*").
        Route::prefix('kpi-actuals')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [KpiActualController::class, 'index']);
            Route::post('/', [KpiActualController::class, 'store']);
        });

        Route::prefix('kpi-comparison')->middleware('ministry.scope')->group(function (): void {
            Route::get('/', [KpiComparisonController::class, 'show']);
        });

        Route::prefix('kpi-reports')->middleware('ministry.scope')->group(function (): void {
            Route::get('/{missionId}', [KpiReportController::class, 'show']);
        });

        // FR-HOM-001 to 003: read-only, no ministry.scope — the Head of
        // Mission/Deputy Head of Mission activity feed is deliberately
        // cross-ministry (every ministry with an attache at their own
        // mission), scoped instead via MissionPolicy::viewOwnActivity()
        // and App\Services\GovernanceService.
        Route::prefix('mission-activity')->group(function (): void {
            Route::get('/', [MissionActivityController::class, 'index']);
            Route::get('/summary', [MissionActivityController::class, 'summary']);
        });

        // FR-MFA-001 to 003: read-only, aggregate-only cross-mission,
        // cross-ministry counts for MFA HQ Officer / MFA Principal
        // Secretary. See App\Policies\MissionPolicy and
        // App\Services\GovernanceService.
        Route::prefix('mfa-awareness')->group(function (): void {
            Route::get('/', [MfaAwarenessController::class, 'index']);
            Route::get('/missions/{mission}', [MfaAwarenessController::class, 'missionSummary']);
            Route::get('/national-overview', [MfaAwarenessController::class, 'nationalOverview']);
        });

        // FR-MDATA-001, FR-MDATA-002: shared, platform-wide reference data.
        // GET is open to any authenticated user; write actions are System
        // Administrator only, enforced via MasterDataPolicy.
        Route::prefix('master-data')->group(function (): void {
            Route::get('/', [MasterDataController::class, 'index']);
            Route::post('/', [MasterDataController::class, 'store']);
            Route::patch('/{masterDataEntry}', [MasterDataController::class, 'update']);
        });

        // FR-SEARCH-001 to 004 (Knowledge Search Engine). ministry.scope
        // binds current_ministry_id so each queried model's global scope
        // (Alert, Inquiry, PeriodicReport) filters results (CLAUDE.md
        // Section 4, Rule 1; FR-SEARCH-001 AC2). No dedicated policy — see
        // App\Http\Controllers\Api\SearchController.
        // NFR-SEC-004: throttle:30,1 per the session spec.
        Route::prefix('search')->middleware(['ministry.scope', 'throttle:30,1'])->group(function (): void {
            Route::get('/', [SearchController::class, 'search']);
            Route::get('/countries/{country}', [SearchController::class, 'countryProfile']);
        });

        // Session 14: FR-SDT-001 (PS dashboard), FR-SDT-004 to 006 (Acting
        // PS), FR-SDT-012/013 (HQ Officer workspace), FR-SDT-019/020/023
        // (SDT admin config screens). See App\Policies\MinistryPolicy,
        // App\Services\SdtService.
        Route::prefix('sdt')->group(function (): void {
            // Ministry-scoped: relies on the Alert/Inquiry/Directive
            // models' global scope, bound by ministry.scope (CLAUDE.md
            // Section 4, Rule 1).
            Route::middleware('ministry.scope')->group(function (): void {
                Route::get('/dashboard', [PsDashboardController::class, 'show']);
                Route::get('/hq-workspace', [HqWorkspaceController::class, 'show']);

                // Session 26: FR-SDT-007, FR-SDT-015. Ministry PS (and
                // Acting PS) only, via MinistryPolicy::viewPsDashboard() —
                // same boundary as /sdt/dashboard above.
                Route::prefix('reports')->group(function (): void {
                    Route::get('/compliance', [SdtReportsController::class, 'compliance']);
                    Route::get('/submission-summary', [SdtReportsController::class, 'submissionSummary']);
                });

                // Session 27: FR-SDT-008 (PS-level directive overview),
                // FR-SDT-009 (completion rate dashboard). Same boundary as
                // /sdt/reports above — Ministry PS (and Acting PS) only,
                // via MinistryPolicy::viewPsDashboard().
                Route::prefix('directives')->group(function (): void {
                    Route::get('/overview', [SdtDirectivesController::class, 'overview']);
                    Route::get('/compliance', [SdtDirectivesController::class, 'compliance']);
                });

                // Session 33: FR-SDT-016, FR-SDT-017, FR-KPI-011. HRM&D
                // Officer only — see KpiPolicy::viewHrmdDashboard()/
                // generateAttacheSummary(). '/hrmd-dashboard' is also one
                // of the path patterns App\Http\Middleware\MinistryScope's
                // FR-SDT-018 guard treats as KPI-scoped.
                Route::prefix('hrmd-dashboard')->group(function (): void {
                    Route::get('/', [HrmdDashboardController::class, 'show']);
                    Route::get('/{userId}/summary', [HrmdDashboardController::class, 'attacheSummary']);
                });
            });

            // Not ministry-scoped: acts on explicit User/Ministry ids, not
            // a ministry-scoped model (mirrors Session 13's reasoning for
            // master-data/mfa-awareness).
            Route::post('/acting-ps/activate', [ActingPsController::class, 'activate']);
            Route::post('/acting-ps/deactivate', [ActingPsController::class, 'deactivate']);

            Route::prefix('config')->group(function (): void {
                Route::get('/alert-fields', [ConfigController::class, 'alertFields']);
                Route::post('/alert-fields', [ConfigController::class, 'storeAlertField']);
                Route::patch('/alert-fields/{masterDataEntry}', [ConfigController::class, 'updateAlertField']);

                Route::get('/inquiry-settings', [ConfigController::class, 'inquirySettings']);
                Route::post('/inquiry-settings', [ConfigController::class, 'storeInquirySetting']);
                Route::patch('/inquiry-settings/{masterDataEntry}', [ConfigController::class, 'updateInquirySetting']);

                // Session 27: FR-SDT-010. See ConfigController::storeDirectiveSetting().
                Route::post('/directive-settings', [ConfigController::class, 'storeDirectiveSetting']);

                Route::get('/referral-organisations', [ConfigController::class, 'referralOrganisations']);
                Route::post('/referral-organisations', [ConfigController::class, 'storeReferralOrganisation']);
                Route::patch('/referral-organisations/{referralOrganisation}', [ConfigController::class, 'updateReferralOrganisation']);

                // Session 26/31: FR-SDT-022. See
                // ConfigController::aieBudgetCodes()/storeAieBudgetCode()/updateAieBudgetCode().
                Route::get('/aie-budget-codes', [ConfigController::class, 'aieBudgetCodes']);
                Route::post('/aie-budget-codes', [ConfigController::class, 'storeAieBudgetCode']);
                Route::patch('/aie-budget-codes/{masterDataEntry}', [ConfigController::class, 'updateAieBudgetCode']);

                // Session 32: FR-SDT-021. See
                // ConfigController::kpiSettings()/storeKpiSetting()/updateKpiSetting().
                Route::get('/kpi-settings', [ConfigController::class, 'kpiSettings']);
                Route::post('/kpi-settings', [ConfigController::class, 'storeKpiSetting']);
                Route::patch('/kpi-settings/{kpiDefinition}', [ConfigController::class, 'updateKpiSetting']);
            });
        });
    });
});
