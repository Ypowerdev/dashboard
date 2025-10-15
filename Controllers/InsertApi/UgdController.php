<?php

namespace App\Http\Controllers\InsertApi;

use App\Models\Library\MapCoordinatesLibrary;
use App\Services\UgdMapConverter\PointTransformer;
use App\Services\UgdMapConverter\UgdRequestProcessService;
use App\Services\UgdXmlBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UgdController
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private int $timeout;
    private int $retries;

    public function __construct(
        protected UgdXmlBuilderService $ugdXmlBuilderService,
        protected PointTransformer $pt,
        protected MapCoordinatesLibrary $mapCoordinatesLibrary,
    ) {
        $cfg = config('services.ugd');

        $this->baseUrl  = Arr::get($cfg, 'api_url');
        $this->username = Arr::get($cfg, 'username');
        $this->password = Arr::get($cfg, 'password');
        $this->timeout  = (int)Arr::get($cfg, 'timeout', 30);
        $this->retries  = (int)Arr::get($cfg, 'retry_attempts', 3);
    }

    /**
     * Обработка записи по одному объекту из УГД по УИН
     *
     * @param Request $request HTTP запрос
     * @return \Illuminate\Http\JsonResponse
     */
    public function ugdResponseProcessor(Request $request): JsonResponse
    {
        $uin = $request->route('uin');
        try {
            $rawData = $this->makeUgdRequest($uin);
        }catch (\Throwable $e) {
            Log::error('UGD service is  unavailable', [
                'uin' => $uin,
                'msg' => $e->getMessage(),
            ]);
            return response()->json(['error' => 'UGD service unavailable'],502);
        }

        $raw = trim((string) $rawData);

        if ($raw === '') {
            return response()->json(['error' => 'Пустой ответ UGD'], 502);
        }

        $noResultsMsg = 'По данному фильтру не найдено ни одного объекта капитального строительства!';
        if ($raw === $noResultsMsg) {
            return response()->json(['results' => [], 'message' => $noResultsMsg], 200);
        }

        $data = json_decode($rawData, true);
        $result = [];

        foreach ($data as $item) {
            $uniqueId = $item['docHeader']['id']['uniqueId'] ?? null;
            $coordsRaw = $item['docContent']['basicDataCco']['cords'] ?? '';

            $parsed = UgdRequestProcessService::parseWktWithCentroid($coordsRaw, fn($x, $y) => $this->pt->convertToWGS84($x, $y));

            $coords = $parsed['coords'] ?? [];
            $coordsJson = is_string($coords)
                ? $coords
                : json_encode($coords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
            $centroid = $parsed['centroid'] ?? [null, null];

            $latitude  = isset($centroid[0]) ? (float)$centroid[0] : null;
            $longitude = isset($centroid[1]) ? (float)$centroid[1] : null;

            $this->mapCoordinatesLibrary->updateOrCreate(
                ['uin' => $uniqueId],
                [
                    'latitude'   => $latitude,
                    'longitude'  => $longitude,
                    'coordinates'=> $coordsJson,
                ]
            );

            $result[] = [
                'uniqueId' => $uniqueId,
                'coords'   => $parsed['coords'],
                'centroid' => $parsed['centroid'],
            ];
        }

        return response()->json($result, 200, [], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);

    }

    public function makeUgdRequest(string $uin): string
    {
        // Проверяем наличие конфигурации
        if (!$this->username) {
            throw new \Exception('Не настроены учетные данные UGD API');
        }

        $xmlBody = $this->ugdXmlBuilderService->makeUinFilter($uin);
        $response = Http::withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout)
            ->retry($this->retries)
            ->withHeaders([
                'Content-Type' => 'application/xml',
                'User-Agent' => 'StroyMonioring Dashboard Client',
                'Accept' => '*/*',
            ])
            ->withBody($xmlBody, 'application/xml')
            ->post((string)$this->baseUrl);

        if ($response->successful()) {
            return $response->body();
        } else {
            throw new \Exception("HTTP {$response->status()}: {$response->body()}");
        }
    }
}
