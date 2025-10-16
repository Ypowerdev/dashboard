<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
use App\Models\ObjectModel;
use App\Services\DaDataService;
class UpdateLatitudeLongitude extends Command
{
    protected $signature = 'app:update_geo';
    protected $description = 'Обновление широты и долготы для каждого объекта на основе raw_land_plot_cad_num.';
    protected $dadataService;
    public function __construct(DaDataService $dadataService)
    {
        parent::__construct();
        $this->dadataService = $dadataService;
    }
    public function handle()
    {
        // Получение всех объектов для обновления
        $objects = ObjectModel::whereNotNull('raw_land_plot_cad_num')->whereNull('latitude_longitude')->get();
        foreach ($objects as $object) {
            // Получение координат через сервис DaData
            $info = $this->dadataService->getCoordinatesByCadastralNumber($object->raw_land_plot_cad_num);

            $this->info("Ответ от DADAService:: ". json_encode($info));

            if ($info) {
                $data = $info[0]['data'] ?? null;
                if ($data && isset($data['geo_lat'], $data['geo_lon'])) {
                    $object->update([
                        'latitude_longitude' => json_encode(['latitude' => $data['geo_lat'], 'longitude' => $data['geo_lon']]),
                    ]);
                    $this->info("Координаты успешно обновлены для Raw Land Plot Cad Num: {$object->raw_land_plot_cad_num}");
                } else {
                    $this->warn("Координаты не найдены для: {$object->raw_land_plot_cad_num}");
                }
            } else {
                $this->warn("Ошибка при получении данных для: {$object->raw_land_plot_cad_num}");
            }
        }
    }
}