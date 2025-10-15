<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegulatoryAuthority extends Model
{
    protected $table = 'regulatory_authorities';
    protected $fillable = ['name'];

    public $timestamps = false;

    /**
     * Получить замечания к культуре производства, где эта организация является автором
     */
    public function cultureManufactures()
    {
        return $this->morphMany(CultureManufacture::class, 'author_organization', 'author_org_type', 'author_org_id');
    }

}
