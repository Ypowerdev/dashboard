<?php

namespace App\Jobs;

use App\Models\ParseExonFileStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\MaxAttemptsExceededException;

class ProcessExonFilesJob implements ShouldQueue
{
    use Queueable;

    protected ParseExonFileStatus $status;

    // Конфигурация повторных попыток
    public $tries = 3;
    public $backoff = [60, 180];
    // Таймаут выполнения
    public $timeout = 1800; // 30 минут

    private $errorCount = 0;
    private $currentProcessingFile = null;
    private $destinationFile = '';
    private $sanitizedFilename = '';


    /**
     * Постановка ещё одной джобы в очередь на выполнение
     *
     * @param string $file - путь до файла
     * @param string $formattedDate - отформатированный timestamp текущего времени до минут
     */
    public function __construct(
        protected string $file,
        protected string $formattedDate
    ) {
        $this->onQueue('exon-processing');
        Log::channel('ParserExon_success')->info('Создан процесс обработки файла', [
            'file' => $this->file,
            'storage_path' => storage_path(),
            'base_dir' => storage_path('logs/exonParserLogs'),
            'dated_dir' => storage_path("logs/exonParserLogs/{$this->formattedDate}"),
            'user' => get_current_user(),
            'php_user' => exec('whoami')
        ]);
    }

    /**
     * Выполнение джобы по парсингу конкретного файла exon из выгрузки.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            $this->status = ParseExonFileStatus::where('filename', $this->file)->firstOrFail();
            $this->clearAllLogs();
            $this->status->markAsRunning();
            // Заменяем пробелы в названии
            $this->sanitizeFilename();

            Log::channel('ParserExon_success')->info('Начата обработка файла', [$this->file]);

            $fullpath = Storage::disk('exon_samples_TMP')->path($this->file);
            Artisan::call('app:parse-exon', [
                '--file' => $fullpath
            ]);

            Log::channel('ParserExon_success')->info("Завершена обработка файла: {$this->file}");
            $this->status->markAsCompleted();
        } catch (MaxAttemptsExceededException $e) {
            // Специальная обработка для превышения максимальных попыток
            $this->handleMaxAttemptsExceeded($e);
            // Не пробрасываем исключение дальше, так как это финальная ошибка
        } catch (\Throwable $e) {
            Log::channel('ParserExon_error')->error("Выполнение завершилось с ошибкой: {$e->getMessage()}");
            $this->status->markAsFailed();

            // Пробрасываем исключение для механизма повторных попыток
            throw $e;
        } finally {
            $this->mergeLogFiles();

            try {
                // Используем Storage для формирования корректного URL
                $logUrl = Storage::disk('exon_samples_LOGS')->url($this->sanitizedFilename);
                $this->status->update(['log_file_link' => $logUrl]);
            } catch (\Throwable $th) {
                Log::channel('ParserExon_error')->error('Не удалось записать ссылку на лог-файл', [
                    'error' => $th->getMessage(),
                    'file' => $this->file,
                ]);
            }
        }
    }

    /**
     * Обработка превышения максимального количества попыток
     */
    protected function handleMaxAttemptsExceeded(MaxAttemptsExceededException $e): void
    {
        Log::channel('ParserExon_error')->error('Job превысил максимальное количество попыток выполнения', [
            'file' => $this->file,
            'attempts' => $this->attempts(),
            'max_attempts' => $this->tries,
            'error' => $e->getMessage(),
            'redis_queue' => 'exon-processing'
        ]);

        Log::channel('ParserExon_error_short')->error("Job превысил лимит попыток: {$this->file}");
        Log::channel('ParserExon_control_point')->warning('Завершение обработки файла - превышены попытки', [
            'file' => $this->file,
            'end_time' => now()->format('Y-m-d H:i:s'),
            'status' => 'failed_max_attempts',
            'attempts' => $this->attempts(),
            'error' => $e->getMessage()
        ]);

        if (isset($this->status)) {
            $this->status->markAsFailed();
        }

        // Дополнительная диагностика для Redis
        Log::channel('ParserExon_error')->info('Диагностика Redis очереди:', [
            'job_id' => $this->job->getJobId(),
            'queue' => $this->queue,
            'connection' => $this->connection,
        ]);
    }

    /**
     * Объединение лог-файлов в один файл.
     *
     * @return void
     */
    private function mergeLogFiles(): void
    {
        try {
            $sourceFiles = [
                'ParserExonControlPoint.log' => "КОНТРОЛЬНЫЕ ТОЧКИ:\n\n",
                'ParserExonSuccess.log' => "УСПЕШНЫЕ ОПЕРАЦИИ:\n\n",
                'ParserExonErrorShort.log' => "КРАТКАЯ СВОДКА ОШИБОК:\n\n",
                'ParserExonError.log' => "ПОЛНЫЙ ЛОГ ОШИБОК:\n\n",
            ];

            $finalLogFileForUser = str_replace('.json', '.log', str_replace(" ", "_", $this->sanitizedFilename));

            // Объединяем содержимое лог-файлов
            $content = "ЛОГ ОБРАБОТКИ ФАЙЛА: {$finalLogFileForUser}\n";
            $content .= "ВРЕМЯ НАЧАЛА: " . now()->format('Y-m-d H:i:s') . "\n";
            $content .= "КОЛИЧЕСТВО ПОПЫТОК: " . $this->attempts() . "\n";
            $content .= "============================================\n\n";

            foreach ($sourceFiles as $sourceFile => $header) {
                if (Storage::disk('exon_samples_LOGS')->exists('tmp/' . $sourceFile)) {
                    $fileContent = Storage::disk('exon_samples_LOGS')->get('tmp/' . $sourceFile);
                    if (!empty(trim($fileContent))) {
                        $content .= $header;
                        $content .= $fileContent . PHP_EOL;
                        $content .= "--------------------------------------------\n\n";
                    }
                }
            }

            // Записываем объединенный контент
            Storage::disk('exon_samples_LOGS')->put($finalLogFileForUser, $content);
        } catch (\Throwable $th) {
            Log::error('Ошибка при объединении лог-файлов', [
                'error' => $th->getMessage(),
                'file' => $this->file,
            ]);
        }
    }

    private function sanitizeFilename(): void
    {
        $this->sanitizedFilename = str_replace('.json', '.log', str_replace(" ", "_", $this->file));
    }

    /*
     * Очистка всех логов перед запуском парсера
     */
    private function clearAllLogs():void {
        $logFiles = [
            'ParserExonError.log',
            'ParserExonErrorShort.log',
            'ParserExonControlPoint.log',
            'ParserExonSuccess.log',
        ];

        foreach ($logFiles as $logFile) {
            File::put(storage_path("app/public/logs/exonParserLogs/tmp/{$logFile}"), '');
        }

        Log::channel('ParserExon_control_point')->warning('Временные логи очищены перед обработкой файла', [
            'file' => $this->file
        ]);
    }

    /*
     * Обработка неудачного выполнения job
     */
    public function failed(\Throwable $exception): void
    {
        // Специальная обработка для MaxAttemptsExceededException
        if ($exception instanceof MaxAttemptsExceededException) {
            $this->handleMaxAttemptsExceeded($exception);
        } else {
            Log::channel('ParserExon_error')->error('Job завершился с ошибкой', [
                'file' => $this->file,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            Log::channel('ParserExon_error_short')->error("Job failed: {$exception->getMessage()} - File: {$this->file}");

            if (isset($this->status)) {
                $this->status->markAsFailed();
            }
        }

        // Все равно пытаемся объединить логи при падении job
        $this->mergeLogFiles();

        // Дополнительная запись в основной лог Laravel для диагностики
        \Log::error('ProcessExonFilesJob полностью провалился', [
            'file' => $this->file,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'queue' => 'exon-processing'
        ]);
    }
}