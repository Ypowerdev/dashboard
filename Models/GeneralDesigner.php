<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeneralDesigner extends Model
{
    protected $fillable = ['inn', 'name', 'full_name'];

    public function objects()
    {
        return $this->hasMany(ObjectModel::class, 'general_designer_id');
    }
}
