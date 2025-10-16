<?php

namespace App\Console\Commands;

use App\Models\ObjectModel;
use App\Models\UgdAnswerData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Services\UgdMapConverter\MapConvertorService;

class FetchUgdDataForAllUins extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'ugd:fetch-all
                            {uins?* : Список UIN через пробел (можно несколько)}
                            {--uins= : Список UIN через запятую}
                            {--limit= : Ограничить количество UIN для обработки (игнорируется, если переданы УИНы)}
                            {--delay=1 : Задержка между запросами в секундах}
                            {--timeout=30 : Таймаут запроса в секундах}
                            {--force : Обновить существующие записи}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Получает все UIN из БД и отправляет запросы в UGD для каждого';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $mapConvertorService = new MapConvertorService();

        $this->info('Начало получения данных UGD для всех UIN...');

        $argUins = (array) $this->argument('uins') ?? [];
        $optUins = $this->option('uins')
            ? array_filter(array_map('trim', explode(',', $this->option('uins'))))
            : [];

        $explicitUins = array_values(array_unique(array_filter(array_merge($argUins, $optUins))));

        if (!empty($explicitUins)) {
            $uins = $explicitUins; // ← Берём ровно то, что попросили
        } else {
            // 2) Иначе — собираем из ObjectModel
            $query = \App\Models\ObjectModel::query()
                ->whereNotNull('uin')->where('uin', '!=', '');

            if ($this->option('limit')) {
                $query->limit((int) $this->option('limit'));
            }

            $uins = $query->pluck('uin')->all();
        }

        if (empty($uins)) {
            $this->error('UIN не найдены.');
            return 1;
        }

        $this->info("Найдено UIN для обработки: " . count($uins));

        $delay = (float) $this->option('delay');
        $timeout = (int) $this->option('timeout');
        $forceUpdate = $this->option('force');

        $successCount = 0;
        $errorCount = 0;
        $skippedCount = 0;

        $total = count($uins);

        // Обрабатываем каждый UIN
        foreach ($uins as $index => $uin) {
            $current = $index + 1;

            $this->line("[$current/$total] Обработка UIN: {$uin}");
            Log::info('Обработка UIN: ', ['uin' => $uin]);

            // Проверяем существующую запись (если не форсируем обновление)
            if (!$forceUpdate && UgdAnswerData::where('uin', $uin)->exists()) {
                $this->warn("[$current/$total] UIN {$uin} уже существует в базе. Пропускаем. Используйте --force для обновления.");
                $skippedCount++;
                continue;
            }

            try {
                $response = $this->makeUgdRequest($uin, $timeout);

                // Сохраняем в базу данных
                UgdAnswerData::updateOrCreateData($uin, $response);

                // Обрабатываем через сервис
                $mapConvertorService->convertAndSave($response);

                $this->info("[$current/$total] UIN {$uin} успешно обработан и сохранен");
                $successCount++;

            } catch (\Exception $e) {
                $this->error("[$current/$total] Ошибка для UIN {$uin}: " . $e->getMessage());
                Log::error("UGD request failed for UIN {$uin}: " . $e->getMessage());
                $errorCount++;
            }

            // Задержка между запросами (кроме последнего)
            if ($current < $total && $delay > 0) {
                sleep($delay);
            }
        }

        // Сводка
        $this->info("\nОбработка завершена!");
        $this->info("Успешно: {$successCount}");
        $this->info("Ошибок: {$errorCount}");
        $this->info("Пропущено (уже в базе): {$skippedCount}");
        $this->info("Всего UIN в выборке: " . count($uins));

        return 0;
    }

    /**
     * Выполняет запрос к UGD API
     */
    private function makeUgdRequest(string $uin, int $timeout): string
    {
        $ugdConfig = [
            'base_url' => env('UGD_REMOTE_SERVER_URL'),
            'username' => env('UGD_CRED_USERNAME'),
            'password' => env('UGD_CRED_PASSWORD'),
        ];

        // Проверяем наличие конфигурации
        if (empty($ugdConfig['username']) || empty($ugdConfig['password'])) {
            throw new \Exception('Не настроены учетные данные UGD API');
        }

        $xmlBody = $this->createXmlFilterDom($uin);

        $response = Http::withBasicAuth($ugdConfig['username'], $ugdConfig['password'])
            ->timeout($timeout)
            ->withHeaders([
                'Content-Type' => 'application/xml',
                'User-Agent' => 'StroyMonioring Dashboard Client',
                'Accept' => '*/*',
            ])
            ->withBody($xmlBody, 'application/xml')
            ->post($ugdConfig['base_url']);

        if ($response->successful()) {
            return $response->body();
        } else {
            throw new \Exception("HTTP {$response->status()}: {$response->body()}");
        }
    }

    /**
     * Альтернативная реализация создания XML через DOM
     */
    private function createXmlFilterDom(string $uin): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $filters = $dom->createElement('filters');
        $filter = $dom->createElement('filter');

        $attrName = $dom->createAttribute('attr_name');
        $attrName->value = 'uniqueId';
        $filter->appendChild($attrName);

        $attrType = $dom->createAttribute('filt_type');
        $attrType->value = '4';
        $filter->appendChild($attrType);

        $filter->appendChild($dom->createTextNode($uin));
        $filters->appendChild($filter);
        $dom->appendChild($filters);

        return $dom->saveXML();
    }
}
