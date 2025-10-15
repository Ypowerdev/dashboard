<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class ObjectList extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'name',
        'description',
        'meeting_at',
    ];

    protected $casts = [
        'meeting_at' => 'datetime',               // или
        // 'meeting_at' => 'datetime:Y-m-d H:i:s',
    ];

    /**
     * Объекты принадлежащие списку
     * @return BelongsToMany
     */
    public function objects(): BelongsToMany
    {
        return $this->belongsToMany(ObjectModel::class, 'object_list_object', 'object_list_id', 'object_id')
            ->withPivot(['id', 'position'])           // <-- важно: берем id пивота
            ->orderBy('object_list_object.position')
            ->orderBy('object_list_object.object_id');
    }

    public function items()
    {
        return $this->hasMany(ObjectListItem::class, 'object_list_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function recalculatePositions(): void
    {
        // Получаем все связи для этого списка, отсортированные по текущему position
        $relations = DB::table('object_list_object')
            ->where('object_list_id', $this->id)
            ->orderBy('position')
            ->orderBy('object_id') // fallback, чтобы избежать неопределённости
            ->pluck('object_id')
            ->values();

        // Обновляем position последовательно: 1, 2, 3, ...
        foreach ($relations as $index => $objectId) {
            DB::table('object_list_object')
                ->where('object_list_id', $this->id)
                ->where('object_id', $objectId)
                ->update(['position' => $index + 1]);
        }
    }
}
