<?php

namespace App\Console\Commands;

use App\Models\ObjectModel;
use Illuminate\Console\Command;

class TransferGeoFromUgdToOurDB extends Command
{
    /**
     * ПЕРЕД ЗАПУСКОМ ПОЧИСТИ НАЧАЛО И КОНЕЦ В ФАЙЛЕ python_microservices/take_geo_from_ugd/final_results.txt
     *
     * @var string
     */
    protected $signature = 'app:geofromugd';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $filePath = base_path('storage/ugd_geo_final_results.txt');

        if (!file_exists($filePath)) {
            $this->error('Не существует файла: storage/ugd_geo_final_results.txt');
            return;
        }

        $file = fopen($filePath, 'r');
        if ($file) {
            while (($line = fgets($file)) !== false) {
                // Ожидаем такой формат записи: "УИН *** raw_land_plot_cad_num *** coordinates"
                [$uin, $cadNum, $coordinates] = explode(' *** ', trim($line));

                // Проверка формата `raw_land_plot_cad_num` с использованием регулярного выражения
                $cadNumPattern = '/^77:\d{2}:\d{7}(?::\d{1,5})?$/';
                if (preg_match($cadNumPattern, $cadNum)) {
                    $this->info("Корректный формат raw_land_plot_cad_num: $cadNum");
                } else {
                    $this->warn("Некорректный формат для raw_land_plot_cad_num: $cadNum, установка значения в NULL.");
                    $cadNum = null;
                }

                // Валидация формата: `coordinates`
                if (preg_match('/^(GEOMETRYCOLLECTION|MULTIPOLYGON|POLYGON)/', $coordinates)) {
                    $this->info("Валидный формат: $coordinates");
                } else {
                    $this->warn("Не валидный формат: $coordinates, ставим NULL.");
                    $coordinates = null;
                }

                // Вставка или обновление таблицы OBJECTS
                $object = ObjectModel::firstOrNew(['uin' => $uin]);
                $object->raw_land_plot_cad_num = $cadNum;
                $object->coordinates = $coordinates;
                $object->save();
                $this->info("Запись данных для УИН: $uin обработана.");
            }
            fclose($file);
        } else {
            $this->error('Не могу открыть файл: storage/ugd_geo_final_results.txt');
        }

        $this->info('Файл обработан, все записи перенесены в нашу БД');
    }
}
