<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\MongoChangeLogModel;

class testConnection extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:test-connection';

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
        // Создание записи
        MongoChangeLogModel::create([
            'entity_type' => 'construction_objects',
            'entity_id' => 'TT0000-10-0017-002',
            'changed_by' => 10000,
            'changed_at' => now(),
            'changes' => [
                [
                    'field' => 'name',
                    'old_value' => 'Old Project Name',
                    'new_value' => 'New Project Name'
                ]
            ],
            'before' => [
                [
                    'field' => 'name',
                    'old_value' => 'Old Project Name',
                    'new_value' => 'New Project Name'
                ]
            ],
            'after' => [
                [
                    'field' => 'name',
                    'old_value' => 'Old Project Name',
                    'new_value' => 'New Project Name'
                ]
            ],

            // 'entity_type' => 'construction_stage',
            // 'entity_id' => '60a1b2c3d4e5f6a1b2c3d4e6',
            // 'changed_by' => 1,
            // 'changed_at' => now(),
            // 'changes' => [
            //     [
            //         'field' => 'name',
            //         'old_value' => 'Old Project Name',
            //         'new_value' => 'New Project Name'
            //     ]
            // ],
            // 'metadata' => [
            //     'ip_address' => '127.0.0.1',
            //     'user_agent' => 'Mozilla/5.0'
            // ]
        ]);
    
        $logs = MongoChangeLogModel::get();

        dd($logs);
    }
}
