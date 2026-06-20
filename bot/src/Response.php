<?php

declare(strict_types=1);

namespace Akapack\Bot;

/** Nilai respon HTTP sederhana untuk endpoint webhook. */
final class Response
{
    public function __construct(
        public readonly int $code,
        public readonly string $body,
    ) {
    }
}
