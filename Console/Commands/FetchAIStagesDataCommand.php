<?php

namespace App\Console\Commands;

use App\Models\ObjectModel;
use App\Services\ExternalAIPhotoService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class FetchAIStagesDataCommand extends Command
{
    protected $signature = 'ai:fetch-stages 
                            {--uins= : Список УИН через запятую для обработки}
                            {--limit= : Ограничение количества УИН для обработки}
                            {--test : Тестовый режим (только логирование)}';

    protected $description = 'Получение данных AI по этапам строительства для всех УИН';

    protected ExternalAIPhotoService $aiService;
    protected TelegramService $telegramService;
    protected array $globalResults = [
        'total_processed' => 0,
        'successful' => 0,
        'failed' => 0,
        'errors' => [],
        'processed_uins' => [],
        'start_time' => null,
        'end_time' => null,
        'duration' => null
    ];

    public function __construct(ExternalAIPhotoService $aiService, TelegramService $telegramService)
    {
        parent::__construct();
        $this->aiService = $aiService;
        $this->telegramService = $telegramService;
    }

    public function handle(): int
    {
        $this->globalResults['start_time'] = now();

        $testMode = $this->option('test');
        $limit = $this->option('limit');

        $this->info("Запуск получения данных AI ");
        $this->info("Тестовый режим: " . ($testMode ? 'ДА' : 'НЕТ'));

        // Получаем список УИН для обработки
        $uins = $this->getUinsToProcess();

        if (empty($uins)) {
            $this->error('Не найдено УИН для обработки');
            return 1;
        }

        $this->info("Найдено УИН для обработки: " . count($uins));

        if ($limit) {
            $uins = array_slice($uins, 0, (int)$limit);
            $this->info("Ограничение: обработаем первые {$limit} УИН");
        }

        $progressBar = $this->output->createProgressBar(count($uins));
        $progressBar->start();

        foreach ($uins as $uin) {
            try {
                $this->processUin($uin, $testMode);
                $this->globalResults['processed_uins'][] = $uin;
            } catch (\Exception $e) {
                $errorMsg = "Ошибка обработки УИН {$uin}: " . $e->getMessage();
                $this->globalResults['errors'][] = $errorMsg;
                $this->error($errorMsg);
            }

            $progressBar->advance();

            // Небольшая пауза между запросами
            if (!$testMode) {
                sleep(1);
            }
        }

        $progressBar->finish();
        $this->newLine(2);

        // Формируем финальный отчет
        $this->globalResults['end_time'] = now();
        $this->globalResults['duration'] = $this->globalResults['start_time']->diffInSeconds($this->globalResults['end_time']);

        $this->sendFinalReport();

        return 0;
    }

    protected function getUinsToProcess(): array
    {
        // Если указаны конкретные УИН
        if ($this->option('uins')) {
            return array_map('trim', explode(',', $this->option('uins')));
        }

        // Иначе получаем все активные УИН из базы
        return ObjectModel::whereNotNull('uin')
            ->where('uin', '!=', '')
            ->pluck('uin')
            ->unique()
            ->toArray();
    }

    protected function processUin(string $uin, bool $testMode): void
    {
        $this->globalResults['total_processed']++;

        try {
            if ($testMode) {
                $this->info("[TEST] Обработка УИН: {$uin}");
                return;
            }

            // Формируем URL для запроса данных
            $apiUrl = $this->aiService->getBaseUrl() . '/get_last_report_db';

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->aiService->getToken(),
                'Accept' => 'application/json',
            ])->timeout(30)
//                ->get($apiUrl, [
//                    'uin' => $uin,
//                ]);
                ->get($apiUrl . '?uin=' . urlencode($uin));

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['stages'])) {
                    // Обрабатываем данные через наш сервис
                    $results = $this->aiService->processConstructionStagesData($data, $uin);

                    if ($results['failed'] === 0) {
                        $this->globalResults['successful']++;
                        $this->info("УИН {$uin}: успешно обработан ({$results['success']} этапов)");
                    } else {
                        $this->globalResults['failed']++;
                        $errorMsg = "УИН {$uin}: обработан с ошибками ({$results['failed']} ошибок)";
                        $this->globalResults['errors'][] = $errorMsg;
                        $this->warn($errorMsg);
                    }
                } else {
                    $this->globalResults['failed']++;
                    $errorMsg = "УИН {$uin}: неверный формат ответа от API";
                    $this->globalResults['errors'][] = $errorMsg;
                    $this->error($errorMsg);
                }
            } else {
                $this->globalResults['failed']++;
                $errorMsg = "УИН {$uin}: ошибка API - " . $response->status();
                $this->globalResults['errors'][] = $errorMsg;
                $this->error($errorMsg);

                Log::error("Ошибка запроса для УИН {$uin}", [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }

        } catch (\Exception $e) {
            $this->globalResults['failed']++;
            $errorMsg = "УИН {$uin}: исключение - " . $e->getMessage();
            $this->globalResults['errors'][] = $errorMsg;
            $this->error($errorMsg);

            Log::error("Исключение при обработке УИН {$uin}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    protected function sendFinalReport(): void
    {
        $successRate = $this->globalResults['total_processed'] > 0
            ? round(($this->globalResults['successful'] / $this->globalResults['total_processed']) * 100, 2)
            : 0;

        $report = [
            '📊 Отчет по сбору данных AI',
            '',
            '📅 Дата обработки: ' . $this->globalResults['start_time']->format('d.m.Y H:i:s'),
            '⏱️ Время выполнения: ' . $this->globalResults['duration'] . ' сек.',
            '',
            '📈 Статистика:',
            '• Всего УИН: ' . $this->globalResults['total_processed'],
            '• Успешно: ' . $this->globalResults['successful'] . ' (' . $successRate . '%)',
            '• С ошибками: ' . $this->globalResults['failed'],
            '',
            '❌ Ошибок: ' . count($this->globalResults['errors'])
        ];

        if (!empty($this->globalResults['errors'])) {
            $report[] = '';
            $report[] = 'Последние 5 ошибок:';
            $recentErrors = array_slice($this->globalResults['errors'], -5);
            foreach ($recentErrors as $error) {
                $report[] = '• ' . $error;
            }
        }

        $message = implode("\n", $report);

        // Отправляем в Telegram
        try {
            $this->telegramService->sendMessage($message);
            $this->info('Отчет отправлен в Telegram');
        } catch (\Exception $e) {
            $this->error('Ошибка отправки отчета в Telegram: ' . $e->getMessage());
            Log::error('Ошибка отправки Telegram отчета', ['error' => $e->getMessage()]);
        }

        // Выводим отчет в консоль
        $this->info($message);
    }
}