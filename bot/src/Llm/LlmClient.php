<?php

declare(strict_types=1);

namespace Akapack\Bot\Llm;

/**
 * Abstraksi panggilan LLM (memisahkan handler dari SDK supaya bisa diuji
 * dengan fake tanpa kredensial / jaringan).
 */
interface LlmClient
{
    /**
     * @param string $system  system prompt (di-cache)
     * @param array<int,array{role:string,content:string}> $messages riwayat ringkas
     * @return array{text:string,refused:bool} teks balasan; refused=true bila
     *         classifier menolak (stop_reason=refusal).
     */
    public function reply(string $system, array $messages): array;
}
