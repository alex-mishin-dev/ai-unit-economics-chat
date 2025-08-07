<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\UnitEconomicsController;

Route::get("/user", function (Request $request) {
    return $request->user();
})->middleware("auth:sanctum");

// API маршруты для анализа unit-экономики
Route::prefix("v1")->group(function () {
    // Основной endpoint для анализа
    Route::post("/analyze", [UnitEconomicsController::class, "analyze"]);

    // Healthcheck endpoint
    Route::get("/health", function () {
        return response()->json([
            "status" => "OK",
            "service" => "AI Unit Economics Chat API",
            "timestamp" => now()->toISOString(),
            "version" => "1.0.0",
            "database" => "Connected",
            "ai_service" => env("DEEPSEEK_API_KEY")
                ? "Configured"
                : "Not configured",
        ]);
    });

    // Информация о поддерживаемых метриках
    Route::get("/metrics-info", function () {
        return response()->json([
            "supported_metrics" => [
                "cac" => 'Customer Acquisition Cost ($)',
                "ltv" => 'Lifetime Value ($)',
                "churn_rate" => "Monthly Churn Rate (%)",
                "payback_period" => "Payback Period (months)",
                "unit_margin" => 'Unit Economics Margin ($)',
                "ltv_cac_ratio" => "LTV to CAC Ratio",
            ],
            "required_fields" => [
                "startup_idea" =>
                    "Brief description of the startup idea (max 1000 chars)",
                "description" =>
                    "Detailed business model description (max 2000 chars)",
                "additional_info" =>
                    "Optional additional information (max 500 chars)",
            ],
            "example_request" => [
                "startup_idea" => "Сервис доставки еды для офисов",
                "description" =>
                    'B2B платформа доставки здорового питания в офисы. Подписка 15$/месяц за сотрудника, целевая аудитория IT компании 50-200 человек.',
                "additional_info" => "Планируемый запуск в 3 городах",
            ],
        ]);
    });
});

Route::get('/status', function() {
    return response()->json([
        'uptime' => 'OK',
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true)
    ]);
});
