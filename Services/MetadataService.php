<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MetadataService
{
    public static function getLabel(string $modelClass, string $column): string
    {
        $model = new $modelClass;
        $table = (new $modelClass)->getTable();
        
        $comment = DB::selectOne("
            SELECT pgd.description
            FROM pg_catalog.pg_description pgd
            JOIN pg_catalog.pg_class pgc ON pgd.objoid = pgc.oid
            WHERE pgc.relname = ? 
            AND pgd.objsubid = (
                SELECT ordinal_position 
                FROM information_schema.columns 
                WHERE table_name = ? 
                AND column_name = ?
            )
        ", [$table, $table, $column])->description ?? '';

        return $comment ? explode('|', $comment)[0] : Str::headline($column);
    }

    public static function getHint(string $modelClass, string $column): ?string
    {
        $model = new $modelClass;
        $table = (new $modelClass)->getTable();
        
        $comment = DB::selectOne("...")->description ?? ''; // Аналогично getLabel
        
        return isset(explode('|', $comment)[1]) 
            ? trim(explode('|', $comment)[1])
            : null;
    }
}