<?php

namespace App\Services;

use Dadata\DadataClient;

class DaDataService
{
    protected $client;

    public function __construct()
    {
        $token = config('services.dadata.token');
        $secret = config('services.dadata.secret');
        $this->client = new DadataClient($token, $secret);
    }

    public function getCoordinatesByCadastralNumber(string $cadNumber): ?array
    {
        $response = $this->client->suggest("address", $cadNumber);

        if (empty($response)) {
            return null;
        }

        $data = $response[0]['data'] ?? null;

        if (!$data || empty($data['geo_lat']) || empty($data['geo_lon'])) {
            return null;
        }

        return $response;
    }
}
