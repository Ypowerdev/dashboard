<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Модель для хранения замечаний к культуре производства
 */
class CultureManufacture extends Model
{
    protected $table = 'culture_manufactures';
    protected $fillable = [
        'object_id',
        'responsible_name',
        'author_name',
        'author_org_type',
        'author_org_id',
        'suid_link', // Суиид ссылка
        'status',
        'link',
        'content', // Содержание замечания
        'plan_date', // Срок устранения (план)
        'fact_date', // Срок устранения (факт)
        'master_code_dc', // Мастер код ДС

    ];

    protected $dates = [
        'plan_date',
        'fact_date'
    ];

    /**
     * Получить объект, к которому относится замечание
     */
    public function object()
    {
        return $this->belongsTo(ObjectModel::class, 'object_id');
    }

    /**
     * Получить организацию автора (полиморфная связь)
     */
    public function authorOrganization()
    {
        return $this->morphTo('author_organization', 'author_org_type', 'author_org_id');
    }
}
