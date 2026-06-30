<?php

declare(strict_types=1);

namespace Akapack\Bot;

/**
 * Konfigurasi tipe-aman yang dibaca dari environment.
 */
final class Config
{
    private function __construct(
        public readonly string $verifyToken,
        public readonly string $appSecret,
        public readonly bool $allowInsecureWebhook,
        public readonly string $accessToken,
        public readonly string $phoneNumberId,
        public readonly string $graphApiVersion,
        public readonly string $sendDriver,
        public readonly string $botName,
        public readonly string $companyName,
        public readonly string $adminWaBandung,
        public readonly string $adminWaGarut,
        public readonly string $queueDriver,
        public readonly string $redisDsn,
        public readonly float $debounceSeconds,
        public readonly int $dedupTtl,
        public readonly float $retryBackoffSeconds,
        public readonly int $maxRetryAgeSeconds,
        public readonly string $dataDir,
        public readonly string $anthropicApiKey,
        public readonly string $claudeModel,
        public readonly string $geminiApiKey,
        public readonly string $geminiModel,
        public readonly string $llmProvider,
        public readonly string $supabaseUrl,
        public readonly string $supabaseAnonKey,
        public readonly string $tenantId,
        public readonly string $outletBandung,
        public readonly string $outletGarut,
    ) {
    }

    public static function fromEnv(string $baseDir): self
    {
        $get = static function (string $key, string $default = ''): string {
            $v = getenv($key);
            return ($v === false || $v === '') ? $default : $v;
        };
        $bool = static fn (string $key): bool => in_array(
            strtolower((string) getenv($key)),
            ['1', 'true', 'yes', 'on'],
            true
        );

        $dataDir = $get('DATA_DIR', 'var');
        if (!str_starts_with($dataDir, '/')) {
            $dataDir = rtrim($baseDir, '/') . '/' . $dataDir;
        }

        return new self(
            verifyToken: $get('WA_VERIFY_TOKEN'),
            appSecret: $get('WA_APP_SECRET'),
            allowInsecureWebhook: $bool('WA_ALLOW_INSECURE_WEBHOOK'),
            accessToken: $get('WA_ACCESS_TOKEN'),
            phoneNumberId: $get('WA_PHONE_NUMBER_ID'),
            graphApiVersion: $get('WA_GRAPH_API_VERSION', 'v21.0'),
            sendDriver: strtolower($get('WA_SEND_DRIVER', 'api')),
            botName: $get('BOT_NAME', 'Mbak Aka'),
            companyName: $get('COMPANY_NAME', 'Akapack'),
            adminWaBandung: preg_replace('/[^0-9]/', '', $get('ADMIN_WA_BANDUNG')) ?? '',
            adminWaGarut: preg_replace('/[^0-9]/', '', $get('ADMIN_WA_GARUT')) ?? '',
            queueDriver: strtolower($get('QUEUE_DRIVER', 'file')),
            redisDsn: $get('REDIS_DSN', 'tcp://127.0.0.1:6379'),
            debounceSeconds: max(0.0, (float) $get('DEBOUNCE_SECONDS', '4')),
            dedupTtl: max(60, (int) $get('DEDUP_TTL', '86400')),
            retryBackoffSeconds: max(1.0, (float) $get('RETRY_BACKOFF_SECONDS', '30')),
            maxRetryAgeSeconds: max(60, (int) $get('MAX_RETRY_AGE_SECONDS', '600')),
            dataDir: $dataDir,
            anthropicApiKey: $get('ANTHROPIC_API_KEY'),
            claudeModel: $get('CLAUDE_MODEL', 'claude-sonnet-4-6'),
            geminiApiKey: $get('GEMINI_API_KEY'),
            geminiModel: $get('GEMINI_MODEL', 'gemini-2.5-flash'),
            llmProvider: strtolower($get('LLM_PROVIDER')),
            supabaseUrl: rtrim($get('SUPABASE_URL'), '/'),
            supabaseAnonKey: $get('SUPABASE_ANON_KEY'),
            tenantId: $get('TENANT_ID', '00000000-0000-0000-0000-000000000001'),
            outletBandung: $get('OUTLET_BANDUNG', '00000000-0000-0000-0000-000000000002'),
            outletGarut: $get('OUTLET_GARUT', '00000000-0000-0000-0000-000000000003'),
        );
    }
}
