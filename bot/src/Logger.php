<?php

declare(strict_types=1);

namespace Akapack\Bot;

/**
 * Logger sederhana: tulis baris JSON ke file + stderr.
 */
final class Logger
{
    public function __construct(
        private readonly string $file,
        private readonly bool $toStderr = false,
    ) {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    public function info(string $msg, array $ctx = []): void
    {
        $this->write('INFO', $msg, $ctx);
    }

    public function warn(string $msg, array $ctx = []): void
    {
        $this->write('WARN', $msg, $ctx);
    }

    public function error(string $msg, array $ctx = []): void
    {
        $this->write('ERROR', $msg, $ctx);
    }

    private function write(string $level, string $msg, array $ctx): void
    {
        $line = json_encode([
            'ts' => date('c'),
            'level' => $level,
            'msg' => $msg,
            'ctx' => $ctx,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line === false) {
            $line = "{$level}: {$msg}";
        }

        @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($this->toStderr) {
            fwrite(STDERR, $line . "\n");
        }
    }
}
