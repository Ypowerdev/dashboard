<?php

namespace App\Jobs;

use App\Models\Library\MapCoordinatesLibrary;
use App\Services\UgdMapConverter\PointTransformer;
use App\Services\UgdMapConverter\UgdRequestProcessService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SyncSingleUgdCoordinateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 60;
    public $backoff = [30, 60];


    public function __construct(
        protected string $uin,
        protected string $batchId
    ){}

    public function handle(
        UgdRequestProcessService $ugdService,
        MapCoordinatesLibrary $mapCoordinatesLibrary,
        PointTransformer $pt
    ) {
        Log::info("Job started for UIN: {$this->uin}", ['batch_id' => $this->batchId]);
        Log::info('Cache debug', [
            'driver' => config('cache.default'),
            'store'  => Cache::getDefaultDriver(),
            'prefix' => config('cache.prefix'),
            'redis'  => config('database.redis.default.database'),
            'app'    => config('app.name'),
        ]);
        try {
            $rawData = $ugdService->makeUgdRequest($this->uin);
            $raw = trim((string) $rawData);

            // Пустой ответ
            if ($raw === '') {
                $this->updateStats('skipped', "Пустой ответ от УГД");
                return;
            }

            // Объект не найден
            $noResultsMsg = 'По данному фильтру не найдено ни одного объекта капитального строительства!';
            if ($raw === $noResultsMsg) {
                $this->updateStats('skipped', "Объект не найден в УГД");
                return;
            }

            $data = json_decode($rawData, true);

            if (!is_array($data) || empty($data)) {
                $this->updateStats('error', "Некорректный формат данных");
                return;
            }

            foreach ($data as $item) {
                $uniqueId = $item['docHeader']['id']['uniqueId'] ?? null;
                $coordsRaw = $item['docContent']['basicDataCco']['cords'] ?? '';

                if (!$coordsRaw) {
                    $this->updateStats('skipped', "Отсутствуют координаты");
                    continue;
                }

                $parsed = UgdRequestProcessService::parseWktWithCentroid(
                    $coordsRaw,
                    fn($x, $y) => $pt->convertToWGS84($x, $y)
                );

                $coords = $parsed['coords'] ?? [];
                $coordsJson = is_string($coords)
                    ? $coords
                    : json_encode($coords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

                $centroid = $parsed['centroid'] ?? [null, null];
                $latitude  = isset($centroid[0]) ? (float)$centroid[0] : null;
                $longitude = isset($centroid[1]) ? (float)$centroid[1] : null;

                $mapCoordinatesLibrary->updateOrCreate(
                    ['uin' => $uniqueId],
                    [
                        'latitude'   => $latitude,
                        'longitude'  => $longitude,
                        'coordinates'=> $coordsJson,
                    ]
                );

                $this->updateStats('success', "Успешно обновлены координаты");
            }
            Log::info("Job completed for UIN: {$this->uin}");
        } catch (\Throwable $e) {
            Log::error("Job failed for UIN: {$this->uin}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->updateStats('error', $e->getMessage());

            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    protected function updateStats(string $status, string $message)
    {
        $prefix = "ugd_bulk_sync_{$this->batchId}";
        $ttl = now()->addHours(24);
        $cache  = Cache::store('sync');

        $cache->increment("{$prefix}:processed", 1);
        $cache->increment("{$prefix}:{$status}", 1);


        $cache->lock("{$prefix}:lock", 10)->block(5, function () use ($cache, $prefix, $status, $message, $ttl) {
            $rows   = $cache->get("{$prefix}:rows", []);
            $rows[] = [
                'uin'       => $this->uin,
                'status'    => $status,
                'message'   => $message,
                'timestamp' => now()->toDateTimeString(),
            ];

            if (count($rows) > 1000) {
                $rows = array_slice($rows, -1000);
            }

            $cache->put("{$prefix}:rows", $rows, $ttl);
        });
    }

    public function failed(\Throwable $exception)
    {
        Log::error("UGD Bulk Sync Job final failure for UIN {$this->uin}", [
            'error' => $exception->getMessage(),
            'batch_id' => $this->batchId,
        ]);

        $this->updateStats('error', "Финальная ошибка: " . $exception->getMessage());
    }
}
