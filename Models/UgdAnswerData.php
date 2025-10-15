<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UgdAnswerData extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'ugd_answer_data';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'uin',
        'ugd_answer',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Find by UIN or create new instance
     */
    public static function findByUinOrNew(string $uin): self
    {
        return static::firstOrNew(['uin' => $uin]);
    }

    /**
     * Update or create UGD answer data
     */
    public static function updateOrCreateData(string $uin, string $ugdAnswer): self
    {
        return static::updateOrCreate(
            ['uin' => $uin],
            ['ugd_answer' => $ugdAnswer]
        );
    }
}
