<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ExcelExportService;

class GenerateExcelExport extends Command
{
    protected $signature = 'export:excel {--user_id=}';
    protected $description = 'Generate Excel report and return download link';

    public function handle(ExcelExportService $exportService)
    {
        try {
            $userId = $this->option('user_id');
            
            $url = $exportService->generateExcelReport(userId: $userId);
            $this->info('Report generated successfully!');
            $this->info('Download URL: ' . url($url));

            // Возвращаем URL для использования в других скриптах
            return $url;
        } catch (\Exception $e) {
            $this->error('Error generating report: ' . $e->getMessage());
            return 1;
        }
    }
}
