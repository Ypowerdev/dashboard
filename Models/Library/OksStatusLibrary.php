<?php

namespace App\Models\Library;

use App\Models\MongoChangeLogModel;
use App\Models\ObjectModel;
use App\Models\ObjectOksStatusChanges;
use App\Services\TelegramService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * БИБЛИОТЕКА: ОКС Статусы
 * Модель для связи таблицы oks_status_library и objects
 *
 * @property int $id Уникальный идентификатор статуса.
 * @property string $name Название статуса.
 * @property string|null $comments Комментарии к статусу.
 */
class OksStatusLibrary extends Model
{
    protected $table = 'oks_status_library';

    public $timestamps = false;

    protected $fillable = ['name', 'comments'];

    /**
     * Получить объекты, связанные с этим ОКС статусом.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function objects()
    {
        return $this->belongsToMany(ObjectModel::class, 'object_oks_status_changes', 'oks_status_library_id', 'object_id')
            ->withTimestamps()
            ->withPivot('user_id');
    }

    /**
     * Сохраняет или получает ID Статус ОКС на основе данных из записи от Парсера
     *
     * @param object $record Запись из удаленной базы данных.
     * @return int|null ID Статус ОКС или null, если данные отсутствуют.
     */
    public static function saveAndGetOksStatusID($record): int|null
    {
        // Чистим название от цифр в начале, если есть
        $cleanName = preg_replace('/^\d+\s*/', '', $record->{'Статус ОКС'});
        $cleanName = mb_strtolower($cleanName, 'UTF-8');
        $cacheKey = 'oks_status_' . $cleanName;
        $oksStatusId = cache()->get($cacheKey);
        if(!$oksStatusId){
            $oksStatus = self::where('name', $cleanName)->first();
            if (!$oksStatus) {
                $oksStatus = new self();
                $oksStatus->name = $cleanName;
                $oksStatus->save();
            } else {
                $oksStatusId = $oksStatus->id;
            }
            cache()->put($cacheKey, $oksStatusId, 3600);
        }

        if ($oksStatusId > 7) {
            $uin = $record?->{'УИН'} ?? null;
            $master_code_dc = $record?->{'Мастер код ДС'} ?? null;

            $errorMessage = 'Из записи пришёл неожиданный ОКС. Сравните его номер с таблицей ниже. Если он есть в списке - то мы его заменили на нужный.
                
                А если его нет - срочно примите меры!!!';

            $arrContext = [
                'Полученный статус ОКС' => $oksStatusId,
                'УИН' => $uin,
                'Мастер код ДС' => $master_code_dc,
                'Превращения данных возможные' => [
                    '35' => '1',
                    '36' => '2',
                    '38' => '6',
                    '39' => '4',
                    '40' => '2',
                    '41' => '1',
                    '42' => '6',
                    '43' => '1'
                ]
            ];

            Log::channel('ParserExon_error_short')->error($errorMessage,$arrContext);
            Log::channel('ParserJson_error_short')->error($errorMessage,$arrContext);

            if ($oksStatusId == 35) return 1;
            if ($oksStatusId == 36) return 2;
            if ($oksStatusId == 38) return 6;
            if ($oksStatusId == 39) return 4;
            if ($oksStatusId == 40) return 2;
            if ($oksStatusId == 41) return 1;
            if ($oksStatusId == 42) return 6;
            if ($oksStatusId == 43) return 1;
        }

        return $oksStatusId;
    }

    public static function updateOksStatusChanges(
        $object,
        $newOksStatusId,
        $userId = null,
        $initiatorUserId = null,
        $initiatorUserName = null,
    ) {
        if ((int)$object->oks_status_id < (int)$newOksStatusId) {
            // Проверяем, изменился ли статус
            $attributes = [
                'object_id' => $object->id,
                'oks_status_library_id' => $newOksStatusId,
                'oks_status_library_previous_id' => $object->oks_status_id,
                'user_id' => $userId,
                'created_at' => Carbon::now(),
            ];
            $model = ObjectOksStatusChanges::create($attributes);

            if ($model) {
                MongoChangeLogModel::logCreate(
                    record: $model,
                    entity_type: 'ОКС статус',
                    initiator_user_id: $initiatorUserId ?? null,
                    initiator_user_name: $initiatorUserName ?? null,
                    json_user_id: $userId ?? null,
                );
            }

            // Обновляем статус в объекте
            $object->oks_status_id = $newOksStatusId;
            $object->save();

            return true;
        }
        return false;
    }

}
