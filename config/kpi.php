<?php

/*
|--------------------------------------------------------------------------
| KPI Framework Engine
|--------------------------------------------------------------------------
|
| FR-KPI-008 judges each KPI against "configured thresholds": a KPI is on
| track when its actual reaches the on_track share of the target expected
| by now, at risk from the at_risk share, and below target otherwise. SDT
| has not confirmed its own values, so these are the working defaults.
|
*/

return [
    'thresholds' => [
        'on_track' => (float) env('KPI_ON_TRACK_THRESHOLD', 1.0),
        'at_risk' => (float) env('KPI_AT_RISK_THRESHOLD', 0.75),
    ],

    // Half-yearly cycles after the current one that targets may be set for.
    'target_horizon_cycles' => (int) env('KPI_TARGET_HORIZON_CYCLES', 3),

    // The longest custom range (FR-KPI-009) a dashboard may request.
    'max_range_quarters' => 12,
];
