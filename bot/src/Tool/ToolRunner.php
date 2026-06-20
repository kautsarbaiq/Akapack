<?php

declare(strict_types=1);

namespace Akapack\Bot\Tool;

/**
 * LLM yang menjalankan loop tool-use (function calling) sampai selesai.
 */
interface ToolRunner
{
    /**
     * @param array<int,array{role:string,content:mixed}> $messages
     * @param array<int,array<string,mixed>> $tools definisi tool (name/description/inputSchema)
     * @return array{text:string,refused:bool}
     */
    public function run(string $system, array $messages, array $tools, ToolExecutor $executor): array;
}
