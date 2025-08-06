<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class UnitEconomicsController extends Controller
{
    // Константы для настроек
    private const DEEPSEEK_TIMEOUT = 60;
    private const MAX_RETRIES = 2;
    private const JSON_DECODE_DEPTH = 512;
    private const CACHE_TTL_MINUTES = 60;
    private const MAX_REQUEST_SIZE = 5000; // максимальный размер запроса в символах
    private const LOG_PREVIEW_LENGTH = 300;

    public function __construct()
    {
        // Проверяем наличие API ключа при создании контроллера
        $this->validateApiConfiguration();
    }

    public function analyze(Request $request): JsonResponse
    {
        // Проверка общего размера запроса
        $totalRequestSize = strlen($request->getContent());
        if ($totalRequestSize > self::MAX_REQUEST_SIZE) {
            return response()->json(
                [
                    "success" => false,
                    "error" => "Размер запроса превышает допустимый лимит",
                    "code" => "REQUEST_TOO_LARGE",
                ],
                413,
            );
        }

        // Улучшенная валидация с кастомными сообщениями
        try {
            $validated = $request->validate(
                [
                    "startup_idea" => [
                        "required",
                        "string",
                        "max:1000",
                        "min:10",
                        'regex:/^[a-zA-Zа-яА-Я0-9\s\-.,!?()]+$/u', // только безопасные символы
                    ],
                    "description" => [
                        "required",
                        "string",
                        "max:2000",
                        "min:50",
                        'regex:/^[a-zA-Zа-яА-Я0-9\s\-.,!?()\/\$%]+$/u',
                    ],
                    "additional_info" => [
                        "nullable",
                        "string",
                        "max:500",
                        'regex:/^[a-zA-Zа-яА-Я0-9\s\-.,!?()\/\$%]*$/u',
                    ],
                ],
                [
                    "startup_idea.required" =>
                        "Необходимо указать идею стартапа",
                    "startup_idea.min" =>
                        "Идея стартапа слишком короткая (минимум 10 символов)",
                    "startup_idea.regex" =>
                        "Идея содержит недопустимые символы",
                    "description.required" =>
                        "Необходимо подробное описание бизнеса",
                    "description.min" =>
                        "Описание слишком короткое (минимум 50 символов)",
                    "description.regex" =>
                        "Описание содержит недопустимые символы",
                    "additional_info.regex" =>
                        "Дополнительная информация содержит недопустимые символы",
                ],
            );
        } catch (ValidationException $e) {
            return response()->json(
                [
                    "success" => false,
                    "error" => "Ошибка валидации данных",
                    "details" => $e->errors(),
                    "code" => "VALIDATION_ERROR",
                ],
                422,
            );
        }

        // Генерируем уникальный ключ для кеширования
        $cacheKey = "unit_analysis_" . hash("sha256", json_encode($validated));

        // Проверяем кеш
        $cachedResult = Cache::get($cacheKey);
        if ($cachedResult && $this->isValidAnalysisData($cachedResult)) {
            Log::info("Returning cached analysis result", [
                "cache_key" => substr($cacheKey, 0, 16) . "...",
            ]);
            return response()->json([
                "success" => true,
                "analysis" => $cachedResult,
                "from_cache" => true,
                "disclaimer" => $this->getDisclaimer(),
                "timestamp" => now()->toISOString(),
            ]);
        }

        try {
            $analysisData = $this->performAiAnalysis($validated);

            // Проверяем валидность данных перед кешированием
            if ($this->isValidAnalysisData($analysisData)) {
                Cache::put(
                    $cacheKey,
                    $analysisData,
                    now()->addMinutes(self::CACHE_TTL_MINUTES),
                );
            }

            return response()->json([
                "success" => true,
                "analysis" => $analysisData,
                "from_cache" => false,
                "disclaimer" => $this->getDisclaimer(),
                "timestamp" => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            Log::error("Unit economics analysis error", [
                "message" => $e->getMessage(),
                "file" => $e->getFile(),
                "line" => $e->getLine(),
                "request_id" => $request->header(
                    "X-Request-ID",
                    uniqid("req_"),
                ),
                "user_ip" => $request->ip(),
                "input_preview" => substr(
                    json_encode($validated),
                    0,
                    self::LOG_PREVIEW_LENGTH,
                ),
            ]);

            return response()->json(
                [
                    "success" => false,
                    "error" =>
                        "Произошла внутренняя ошибка при анализе. Пожалуйста, попробуйте позже.",
                    "code" => "INTERNAL_ERROR",
                    "request_id" => uniqid("err_"),
                ],
                500,
            );
        }
    }

    private function validateApiConfiguration(): void
    {
        $apiKey = config("services.deepseek.api_key", env("DEEPSEEK_API_KEY"));

        if (empty($apiKey) || $apiKey === "your_deepseek_api_key_here") {
            Log::critical("Deepseek API key is not configured properly");
        }
    }

    private function performAiAnalysis(array $data): array
    {
        $prompt = $this->buildPrompt($data);
        $apiKey = config("services.deepseek.api_key", env("DEEPSEEK_API_KEY"));
        $apiUrl = config(
            "services.deepseek.api_url",
            env(
                "DEEPSEEK_API_URL",
                "https://api.deepseek.com/v1/chat/completions",
            ),
        );

        // Проверяем API ключ еще раз перед запросом
        if (empty($apiKey) || $apiKey === "your_deepseek_api_key_here") {
            throw new \Exception(
                "API ключ Deepseek не настроен. Обратитесь к администратору.",
            );
        }

        try {
            $response = Http::withHeaders([
                "Authorization" => "Bearer " . $apiKey,
                "Content-Type" => "application/json",
                "User-Agent" => "AI-Unit-Economics-Chat/1.0",
                "Accept" => "application/json",
            ])
                ->timeout(self::DEEPSEEK_TIMEOUT)
                ->connectTimeout(10)
                ->post($apiUrl, [
                    "model" => "deepseek/deepseek-r1:free",
                    "messages" => [
                        [
                            "role" => "system",
                            "content" => $this->getSystemPrompt(),
                        ],
                        [
                            "role" => "user",
                            "content" => $prompt,
                        ],
                    ],
                    "max_tokens" => 2500,
                    "temperature" => 0.3,
                    "top_p" => 0.9,
                    "frequency_penalty" => 0.1,
                    "presence_penalty" => 0.1,
                    "stream" => false,

                    // Добавляем дополнительные заголовки для OpenRouter:
                    "HTTP-Referer" => env(
                        "OPENROUTER_SITE_URL",
                        "http://localhost:3000",
                    ),
                    "X-Title" => env(
                        "OPENROUTER_APP_NAME",
                        "AI Unit Economics Chat",
                    ),
                ]);

            if (!$response->successful()) {
                $this->handleApiError($response);
            }

            $aiResponse = $response->json();

            if (!isset($aiResponse["choices"][0]["message"]["content"])) {
                throw new \Exception("Неверный формат ответа от API");
            }

            $aiContent = $aiResponse["choices"][0]["message"]["content"];
            return $this->parseAiResponse($aiContent);
        } catch (\Exception $e) {
            Log::error("AI API request failed", [
                "error" => $e->getMessage(),
                "api_url" => $apiUrl,
            ]);
            throw $e;
        }
    }

    private function handleApiError($response): void
    {
        $status = $response->status();

        // Логируем только статус и краткое описание (без чувствительных данных)
        Log::error("Deepseek API error", [
            "status" => $status,
            "reason" => $response->reason(),
        ]);

        switch ($status) {
            case 401:
                throw new \Exception("Неверный API ключ Deepseek");
            case 429:
                throw new \Exception(
                    "Превышен лимит запросов к API. Попробуйте позже через несколько минут",
                );
            case 503:
                throw new \Exception(
                    "Сервис Deepseek временно недоступен. Попробуйте позже",
                );
            case 400:
                throw new \Exception("Неверный формат запроса к API");
            default:
                throw new \Exception(
                    "API недоступен (код ошибки: " . $status . ")",
                );
        }
    }

    private function getSystemPrompt(): string
    {
        return "Ты эксперт по unit-экономике стартапов с 15-летним опытом в венчурных инвестициях и консалтинге. " .
            "Твоя специализация - анализ бизнес-моделей технологических стартапов и SaaS компаний. " .
            "Ты основываешь свои расчеты на проверенных отраслевых метриках и используешь консервативные оценки. " .
            "Всегда указываешь источники данных и предположения в своих расчетах.";
    }

    private function buildPrompt(array $data): string
    {
        $additionalInfo = !empty($data["additional_info"])
            ? "ℹ️ ДОПОЛНИТЕЛЬНАЯ ИНФОРМАЦИЯ: {$data["additional_info"]}"
            : "ℹ️ ДОПОЛНИТЕЛЬНАЯ ИНФОРМАЦИЯ: Не указана";

        return sprintf(
            "Проведи детальный анализ unit-экономики для следующего стартапа:\n\n" .
                "🚀 ИДЕЯ СТАРТАПА: %s\n\n" .
                "📋 ОПИСАНИЕ БИЗНЕСА: %s\n\n" .
                "%s\n\n" .
                "%s",
            $data["startup_idea"],
            $data["description"],
            $additionalInfo,
            $this->getJsonStructurePrompt(),
        );
    }

    private function getJsonStructurePrompt(): string
    {
        return 'Предоставь структурированный анализ в JSON формате со следующей структурой:

{
    "metrics": {
        "cac": {
            "value": [число в долларах],
            "explanation": "подробное объяснение как рассчитывается CAC для данной модели с указанием предположений"
        },
        "ltv": {
            "value": [число в долларах],
            "explanation": "объяснение расчета LTV с учетом специфики бизнеса и retention rate"
        },
        "churn_rate": {
            "value": [число в процентах без знака %],
            "explanation": "обоснование уровня оттока для данной индустрии с бенчмарками"
        },
        "payback_period": {
            "value": [число месяцев],
            "explanation": "период окупаемости клиента и его обоснование"
        },
        "unit_margin": {
            "value": [число в долларах],
            "explanation": "маржа на единицу продукта/услуги с детализацией затрат"
        },
        "ltv_cac_ratio": {
            "value": [число с 1 десятичным знаком],
            "explanation": "соотношение LTV к CAC и его интерпретация (хорошо >3, отлично >5)"
        }
    },
    "recommendations": [
        "конкретная рекомендация 1 по улучшению метрик с цифрами",
        "конкретная рекомендация 2 по оптимизации бизнес-модели",
        "конкретная рекомендация 3 по росту и масштабированию"
    ],
    "assumptions": [
        "ключевое предположение 1 использованное в расчетах с обоснованием",
        "ключевое предположение 2 о рынке или клиентах",
        "ключевое предположение 3 о модели монетизации"
    ],
    "risk_factors": [
        "основной риск 1 для unit-экономики с оценкой вероятности",
        "основной риск 2 связанный с бизнес-моделью",
        "основной риск 3 влияющий на метрики"
    ],
    "market_insights": {
        "industry_benchmarks": "сравнение с типичными показателями отрасли с конкретными цифрами",
        "competitive_analysis": "краткий анализ конкурентной среды и позиционирования",
        "growth_potential": "оценка потенциала роста и масштабирования с временными рамками"
    }
}

КРИТИЧЕСКИ ВАЖНЫЕ ТРЕБОВАНИЯ:
1. Все метрики должны быть реалистичными и основанными на отраслевых данных
2. Указывай конкретные источники предположений (например: "типично для SaaS B2B")
3. CAC должен учитывать все каналы привлечения
4. LTV должен учитывать реальный churn rate и expansion revenue
5. Предоставь ТОЛЬКО валидный JSON без дополнительного текста
6. Все числовые значения - только числа, без символов валют в value';
    }

    private function parseAiResponse(string $response): array
    {
        $response = preg_replace("/```/", "", $response);
        $response = trim($response);

        // Ищем JSON в ответе с более точным паттерном
        $pattern = "/\{(?:[^{}]|{[^{}]*})*\}/s";
        preg_match($pattern, $response, $matches);

        if (!empty($matches[0])) {
            try {
                $decoded = json_decode(
                    $matches[0],
                    true,
                    self::JSON_DECODE_DEPTH,
                    JSON_THROW_ON_ERROR,
                );

                // Строгая валидация структуры JSON
                if ($this->validateJsonStructure($decoded)) {
                    return $decoded;
                } else {
                    Log::warning("Invalid JSON structure from AI", [
                        "missing_fields" => $this->getMissingFields($decoded),
                    ]);
                }
            } catch (\JsonException $e) {
                Log::error("JSON parsing error", [
                    "error" => $e->getMessage(),
                    "response_preview" => substr(
                        $response,
                        0,
                        self::LOG_PREVIEW_LENGTH,
                    ),
                ]);
            }
        }

        // Fallback с полной структурой
        return $this->getFallbackAnalysis();
    }

    private function validateJsonStructure(array $data): bool
    {
        $requiredFields = [
            "metrics" => [
                "cac",
                "ltv",
                "churn_rate",
                "payback_period",
                "unit_margin",
                "ltv_cac_ratio",
            ],
            "recommendations" => [],
            "assumptions" => [],
            "risk_factors" => [],
            "market_insights" => [
                "industry_benchmarks",
                "competitive_analysis",
                "growth_potential",
            ],
        ];

        foreach ($requiredFields as $section => $subFields) {
            if (!isset($data[$section])) {
                return false;
            }

            if (!empty($subFields) && is_array($data[$section])) {
                foreach ($subFields as $field) {
                    if (!isset($data[$section][$field])) {
                        return false;
                    }

                    // Для метрик проверяем наличие value и explanation
                    if ($section === "metrics") {
                        if (
                            !isset($data[$section][$field]["value"]) ||
                            !isset($data[$section][$field]["explanation"])
                        ) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    private function getMissingFields(array $data): array
    {
        $missing = [];
        $required = [
            "metrics",
            "recommendations",
            "assumptions",
            "risk_factors",
            "market_insights",
        ];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function isValidAnalysisData($data): bool
    {
        if (!is_array($data) || empty($data)) {
            return false;
        }

        // Проверяем наличие ключевых полей
        return isset($data["metrics"]) &&
            is_array($data["metrics"]) &&
            !empty($data["metrics"]) &&
            !isset($data["error"]);
    }

    private function getFallbackAnalysis(): array
    {
        return [
            "error" =>
                "Не удалось обработать ответ AI в структурированном формате",
            "metrics" => [
                "cac" => [
                    "value" => 0,
                    "explanation" =>
                        "Данные недоступны - ошибка парсинга ответа AI",
                ],
                "ltv" => [
                    "value" => 0,
                    "explanation" =>
                        "Данные недоступны - ошибка парсинга ответа AI",
                ],
                "churn_rate" => [
                    "value" => 0,
                    "explanation" =>
                        "Данные недоступны - ошибка парсинга ответа AI",
                ],
                "payback_period" => [
                    "value" => 0,
                    "explanation" =>
                        "Данные недоступны - ошибка парсинга ответа AI",
                ],
                "unit_margin" => [
                    "value" => 0,
                    "explanation" =>
                        "Данные недоступны - ошибка парсинга ответа AI",
                ],
                "ltv_cac_ratio" => [
                    "value" => 0,
                    "explanation" =>
                        "Данные недоступны - ошибка парсинга ответа AI",
                ],
            ],
            "recommendations" => [
                "Попробуйте отправить запрос еще раз с более подробным описанием бизнеса",
                "Убедитесь, что все поля заполнены корректно",
                "Обратитесь к администратору если проблема повторяется",
            ],
            "assumptions" => [
                "Не удалось определить предположения из-за ошибки парсинга ответа AI",
            ],
            "risk_factors" => [
                "Недостаточно данных для полного анализа рисков",
                "Ошибка обработки ответа от AI сервиса",
            ],
            "market_insights" => [
                "industry_benchmarks" =>
                    "Данные недоступны из-за ошибки парсинга",
                "competitive_analysis" =>
                    "Анализ недоступен из-за ошибки парсинга",
                "growth_potential" => "Оценка недоступна из-за ошибки парсинга",
            ],
        ];
    }

    private function getDisclaimer(): string
    {
        return "Данные расчеты носят ориентировочный характер и основаны на общедоступной информации и отраслевых бенчмарках. " .
            "Результаты не являются финансовой консультацией. Обязательно консультируйтесь с профессиональными " .
            "финансовыми экспертами и проводите собственную due diligence перед принятием инвестиционных решений. " .
            "Анализ выполнен с помощью AI и может содержать неточности.";
    }
}
