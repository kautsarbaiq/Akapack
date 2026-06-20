<?php

declare(strict_types=1);

namespace Akapack\Bot\Tool;

/**
 * Eksekutor tool yang dipanggil Claude (function-call). Mengembalikan konten
 * tool_result sebagai string (biasanya JSON) untuk dikirim balik ke model.
 */
interface ToolExecutor
{
    /** @param array<string,mixed> $input argumen dari Claude */
    public function execute(string $name, array $input): string;
}
