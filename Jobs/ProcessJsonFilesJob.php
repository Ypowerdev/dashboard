<?php

namespace App\Jobs;

use App\Models\ParseJsonFileStatus;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Queue\MaxAttemptsExceededException;

class ProcessJsonFilesJob implements ShouldQueue
{
    use Queueable;

    protected ParseJsonFileStatus $status;

    // Конфигурация повторных попыток
    public $tries = 3;
    public $backoff = [60, 180];
    // Таймаут выполнения
    public $timeout = 1800; // 30 минут

    private $errorCount = 0;
    private $currentProcessingFile = null; // Текущий обрабатываемый файл
    private $finalLogFileForUser = ''; // файл лога конкретного файла
    private $sanitizedFilename = '';


    /**
     * @param string $file - путь до файла
     * @param string $formattedDate - отформатированный timestamp текущего времени до минут
     */
    public function __construct(
        protected string $file,
        protected string $formattedDate
    ) {
        $this->onQueue('json-processing');
    }

    /**
     * Выполнение джобы по парсингу конкретного файла json из выгрузки.
     *
     * @return void
     */
    public function handle(): void
    {
        try {
            Log::channel('ParserJson_success')->info('Создан процесс обработки файла:', ['file' => $this->file]);

            $this->status = ParseJsonFileStatus::where('filename', $this->file)->firstOrFail();
            $this->clearAllLogs();
            $this->status->markAsRunning();
            // Заменяем пробелы в названии
            $this->sanitizeFilename();

            Log::channel('ParserJson_success')->info('Начата обработка файла', ['file' => $this->file]);
            Log::channel('ParserJson_control_point')->warning('Начало обработки файла', [
                'file' => $this->file,
                'start_time' => now()->format('Y-m-d H:i:s')
            ]);

            $fullpath = Storage::disk('json_samples_TMP')->path($this->file);
            Artisan::call('app:parse-json', [
                '--file' => $fullpath
            ]);

            Log::channel('ParserJson_success')->info("Завершена обработка файла: {$this->file}");
            Log::channel('ParserJson_success')->info("Файл успешно обработан", [
                'file' => $this->file,
                'completion_time' => now()->format('Y-m-d H:i:s')
            ]);
            Log::channel('ParserJson_control_point')->warning('Завершение обработки файла', [
                'file' => $this->file,
                'end_time' => now()->format('Y-m-d H:i:s'),
                'status' => 'success'
            ]);

            $this->status->markAsCompleted();
        } catch (MaxAttemptsExceededException $e) {
            // Специальная обработка для превышения максимальных попыток
            $this->handleMaxAttemptsExceeded($e);
            // Не пробрасываем исключение дальше, так как это финальная ошибка
        } catch (\Throwable $e) {
            Log::channel('ParserJson_error')->error("Выполнение завершилось с ошибкой: {$e->getMessage()}");
            Log::channel('ParserJson_error')->error("Критическая ошибка обработки файла", [
                'file' => $this->file,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            Log::channel('ParserJson_error_short')->error("Ошибка: {$e->getMessage()} - Файл: {$this->file}");
            Log::channel('ParserJson_control_point')->warning('Завершение обработки файла с ошибкой', [
                'file' => $this->file,
                'end_time' => now()->format('Y-m-d H:i:s'),
                'status' => 'failed',
                'error' => $e->getMessage()
            ]);

            $this->status->markAsFailed();

            // Пробрасываем исключение для механизма повторных попыток
            throw $e;
        } finally {
            $this->mergeLogFiles();

            try {
                $logUrl = Storage::disk('json_samples_LOGS')->url($this->sanitizedFilename);
                $this->status->update(['log_file_link' => $logUrl]);
            } catch (\Throwable $th) {
                Log::channel('ParserJson_error')->error('Не удалось записать ссылку на лог-файл', [
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
        Log::channel('ParserJson_error')->error('Job превысил максимальное количество попыток выполнения', [
            'file' => $this->file,
            'attempts' => $this->attempts(),
            'max_attempts' => $this->tries,
            'error' => $e->getMessage(),
            'redis_queue' => 'json-processing' // Указываем очередь Redis для диагностики
        ]);

        Log::channel('ParserJson_error_short')->error("Job превысил лимит попыток: {$this->file}");
        Log::channel('ParserJson_control_point')->warning('Завершение обработки файла - превышены попытки', [
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
        Log::channel('ParserJson_error')->info('Диагностика Redis очереди:', [
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
                'ParserJsonSuccess.log' => "УСПЕШНЫЕ ОПЕРАЦИИ:\n\n",
                'ParserJsonError.log' => "ПОЛНЫЙ ЛОГ ОШИБОК:\n\n",
                'ParserJsonErrorShort.log' => "КРАТКАЯ СВОДКА ОШИБОК:\n\n",
            ];

            $finalLogFileForUser = str_replace('.json', '.log', str_replace(" ", "_", $this->sanitizedFilename));

            $content = "ЛОГ ОБРАБОТКИ ФАЙЛА: {$finalLogFileForUser}\n";
            $content .= "ВРЕМЯ НАЧАЛА: " . now()->format('Y-m-d H:i:s') . "\n";
            $content .= "КОЛИЧЕСТВО ПОПЫТОК: " . $this->attempts() . "\n";
            $content .= "============================================\n\n";

            foreach ($sourceFiles as $sourceFile => $header) {
                if (Storage::disk('json_samples_LOGS')->exists('tmp/' . $sourceFile)) {
                    $fileContent = Storage::disk('json_samples_LOGS')->get('tmp/' . $sourceFile);
                    if (!empty(trim($fileContent))) {
                        $content .= $header;
                        $content .= $fileContent . PHP_EOL;
                        $content .= "--------------------------------------------\n\n";
                    }
                }
            }

            Storage::disk('json_samples_LOGS')->put($finalLogFileForUser, $content);

        } catch (\Throwable $th) {
            Log::error('Ошибка при объединении лог-файлов', [
                'error' => $th->getMessage(),
                'file' => $this->file,
            ]);
        }
    }

    /*
     * Замена пробелов в названии
     */
    private function sanitizeFilename(): void {
        $this->sanitizedFilename = str_replace('.json', '.log', str_replace(" ", "_", $this->file));
    }

    /*
     * Очистка всех временных логов перед запуском парсера
     */
    private function clearAllLogs(): void
    {
        $logFiles = [
            'ParserJsonError.log',
            'ParserJsonErrorShort.log',
            'ParserJsonControlPoint.log',
            'ParserJsonSuccess.log',
        ];

        foreach ($logFiles as $logFile) {
            File::put(storage_path("app/public/logs/jsonParserLogs/tmp/{$logFile}"), '');
        }

        Log::channel('ParserJson_control_point')->warning('Временные логи очищены перед обработкой файла', [
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
            Log::channel('ParserJson_error')->error('Job завершился с ошибкой', [
                'file' => $this->file,
                'error' => $exception->getMessage(),
                'trace' => $exception->getTraceAsString()
            ]);

            Log::channel('ParserJson_error_short')->error("Job failed: {$exception->getMessage()} - File: {$this->file}");

            if (isset($this->status)) {
                $this->status->markAsFailed();
            }
        }

        // Все равно пытаемся объединить логи при падении job
        $this->mergeLogFiles();

        // Дополнительная запись в основной лог Laravel для диагностики
        Log::error('ProcessJsonFilesJob полностью провалился', [
            'file' => $this->file,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
            'queue' => 'json-processing'
        ]);
    }
}