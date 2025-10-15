<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * СУЩНОСТЬ: Фотографии. Хранит ссылки на фотографии объекта. ССЫЛКИ НА ФОТО выглядят так: site.ru/objphotos/{taken_at}/{photo_name}
 *
 * @property int $id Первичный ключ
 * @property string $photo_url Ссылка на фото
 * @property int $object_id Ссылка на объект
 * @property \DateTime $taken_at Дата съемки фото
 */
class Photo extends Model
{
    protected $table = 'photos';

    public $timestamps = false;

    protected $fillable = [
        'photo_url',
        'object_uin',
        'taken_at'
    ];

    protected $dates = [
        'taken_at'
    ];

    protected $casts = [
        'taken_at' => 'datetime:Y-m-d',
    ];

    public function object()
    {
        return $this->belongsTo(ObjectModel::class, 'object_uin','uin');
    }
}
