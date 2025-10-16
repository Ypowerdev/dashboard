<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CommandWithIsolatedWarning extends Command
{
    // ... ваш существующий код ...
    const VERBOSITY_NORMAL = null;
    protected $signature = 'app:CommandWithIsolatedWarning';
    protected $description = 'CommandWithIsolatedWarning';

    /**
     * Проверяет, можно ли выполнять логирование.
     * 
     * @return bool
     */
    private function shouldLog(): bool
    {
        return PHP_SAPI === 'cli' && $this->output !== null;
    }

    /**
     * Безопасный вывод информации в консоль.
     * 
     * @param string $string
     * @param int $verbosity
     * @return void
     */
    public function info($string, $verbosity = self::VERBOSITY_NORMAL): void
    {
        if ($this->shouldLog()) {
            parent::info($string, $verbosity);
        } 
        Log::channel('parser')->info($string);
    }

    /**
     * Безопасный вывод предупреждения в консоль.
     * 
     * @param string $string
     * @param int $verbosity
     * @return void
     */
    public function warn($string, $verbosity = self::VERBOSITY_NORMAL): void
    {
        if ($this->shouldLog()) {
            parent::warn($string, $verbosity);
        } 
        Log::channel('parser')->warning($string);
    }

    /**
     * Безопасный вывод ошибки в консоль.
     * 
     * @param string $string
     * @param int $verbosity
     * @return void
     */
    public function error($string, $verbosity = self::VERBOSITY_NORMAL): void
    {
        if ($this->shouldLog()) {
            parent::error($string, $verbosity);
        } 
        Log::channel('parser')->error($string);
    }

    /**
     * Безопасный вывод строки в консоль.
     * 
     * @param string $string
     * @param int $verbosity
     * @param bool $decorated
     * @return void
     */
    public function line($string, $verbosity = self::VERBOSITY_NORMAL, $decorated = null): void
    {
        if ($this->shouldLog()) {
            parent::line($string, $verbosity, $decorated);
        }
    }

    /**
     * Безопасный вывод вопроса в консоль.
     * 
     * @param string $string
     * @param mixed $default
     * @param array $autocomplete
     * @return mixed
     */
    public function ask($string, $default = null, $autocomplete = null)
    {
        return $this->shouldLog()
            ? parent::ask($string, $default, $autocomplete)
            : $default;
    }

    // /**
    //  * Безопасный выбор из списка.
    //  * 
    //  * @param string $question
    //  * @param array $choices
    //  * @param mixed $default
    //  * @return mixed
    //  */
    // public function choice($question, array $choices, $default = null)
    // {
    //     return $this->shouldLog()
    //         ? parent::choice($question, $choices, $default)
    //         : $default;
    // }
}