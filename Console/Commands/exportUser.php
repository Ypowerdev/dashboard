<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class exportUser extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'export:user';

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
        app('App\Services\ExportUserService')->exportUsers();
        return 0;
    }
}
