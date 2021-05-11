<?php

namespace Adavalley\Watcher;

use Closure;
use Adavalley\Watcher\Exceptions\CouldNotStartWatcher;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class Watch
{
    const EVENT_TYPE_FILE_CREATED = 'fileCreated';
    const EVENT_TYPE_FILE_UPDATED = 'fileUpdated';
    const EVENT_TYPE_FILE_DELETED = 'fileDeleted';
    const EVENT_TYPE_DIRECTORY_CREATED = 'directoryCreated';
    const EVENT_TYPE_DIRECTORY_DELETED = 'directoryDeleted';

    protected $paths = [];

    /** @var callable[] */
    protected $onFileCreated = [];

    /** @var callable[] */
    protected $onFileUpdated = [];

    /** @var callable[] */
    protected $onFileDeleted = [];

    /** @var callable[] */
    protected $onDirectoryCreated = [];

    /** @var callable[] */
    protected $onDirectoryDeleted = [];

    /** @var callable[] */
    protected $onAny = [];

    protected $shouldContinue;

    public static function path(string $path): self
    {
        return (new self())->setPaths($path);
    }

    public static function paths(...$paths): self
    {
        return (new self())->setPaths($paths);
    }

    public function __construct()
    {
        $this->shouldContinue = function () {
            return true;
        };
    }

    public function setPaths($paths): self
    {
        if (is_string($paths)) {
            $paths = func_get_args();
        }

        $this->paths = $paths;

        return $this;
    }

    public function onFileCreated(callable $onFileCreated): self
    {
        $this->onFileCreated[] = $onFileCreated;

        return $this;
    }

    public function onFileUpdated(callable $onFileUpdated): self
    {
        $this->onFileUpdated[] = $onFileUpdated;

        return $this;
    }

    public function onFileDeleted(callable $onFileDeleted): self
    {
        $this->onFileDeleted[] = $onFileDeleted;

        return $this;
    }

    public function onDirectoryCreated(callable $onDirectoryCreated): self
    {
        $this->onDirectoryCreated[] = $onDirectoryCreated;

        return $this;
    }

    public function onDirectoryDeleted(callable $onDirectoryDeleted): self
    {
        $this->onDirectoryDeleted[] = $onDirectoryDeleted;

        return $this;
    }

    public function onAnyChange(callable $callable): self
    {
        $this->onAny[] = $callable;

        return $this;
    }

    public function shouldContinue(Closure $shouldContinue): self
    {
        $this->shouldContinue = $shouldContinue;

        return $this;
    }

    public function start(): void
    {
        $watcher = $this->getWatchProcess();

        while (true) {
            if (! $watcher->isRunning()) {
                throw CouldNotStartWatcher::make($watcher);
            }

            if ($output = $watcher->getIncrementalOutput()) {
                $this->actOnOutput($output);
            }

            if (! ($this->shouldContinue)()) {
                break;
            }

            usleep(500 * 1000);
        }
    }

    protected function getWatchProcess(): Process
    {
        $command = [
            (new ExecutableFinder)->find('node'),
            'file-watcher.js',
            json_encode($this->paths),
        ];

        $process = new Process(
            $command,
            realpath(__DIR__ . '/../bin'),
            null,
            null,
            null,
        );

        $process->start();

        return $process;
    }

    protected function actOnOutput(string $output): void
    {
        $lines = explode(PHP_EOL, $output);

        $lines = array_filter($lines);

        foreach ($lines as $line) {
            [$type, $path] = explode(' - ', $line, 2);

            $path = trim($path);

            switch ($type) {
                case static::EVENT_TYPE_FILE_CREATED:
                    $this->callAll($this->onFileCreated, $path);
                    break;
                
                case static::EVENT_TYPE_FILE_UPDATED: 
                    $this->callAll($this->onFileUpdated, $path);
                    break;
                
                case static::EVENT_TYPE_FILE_DELETED: 
                    $this->callAll($this->onFileDeleted, $path);
                    break;

                case static::EVENT_TYPE_DIRECTORY_CREATED: 
                    $this->callAll($this->onDirectoryCreated, $path);
                    break;

                case static::EVENT_TYPE_DIRECTORY_DELETED: 
                    $this->callAll($this->onDirectoryDeleted, $path);
                    break;
                
                default: 
                    break;
            }

            foreach ($this->onAny as $onAnyCallable) {
                $onAnyCallable($type, $path);
            }
        }
    }

    protected function callAll(array $callables, string $path): void
    {
        foreach ($callables as $callable) {
            $callable($path);
        }
    }
}
