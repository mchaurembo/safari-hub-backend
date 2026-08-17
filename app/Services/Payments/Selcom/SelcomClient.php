<?php

namespace App\Services\Payments\Selcom;

use Selcom\ApigwClient\Client;

/**
 * Thin wrapper around Selcom's official API client (signing + HTTP).
 */
final class SelcomClient
{
    private Client $client;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        string $apiSecret,
    ) {
        $this->client = new Client(rtrim($baseUrl, '/'), $apiKey, $apiSecret);
    }

    public static function fromConfig(): ?self
    {
        $config = config('payments.selcom', []);
        $vendor = (string) ($config['vendor'] ?? '');
        $apiKey = (string) ($config['api_key'] ?? '');
        $apiSecret = (string) ($config['api_secret'] ?? '');
        $baseUrl = (string) ($config['base_url'] ?? 'https://apigw.selcommobile.com');

        if ($vendor === '' || $apiKey === '' || $apiSecret === '') {
            return null;
        }

        return new self($baseUrl, $apiKey, $apiSecret);
    }

    public function vendor(): string
    {
        return (string) config('payments.selcom.vendor');
    }

    /** @param array<string, mixed> $payload */
    public function post(string $path, array $payload): ?array
    {
        $response = $this->client->postFunc($path, $payload);

        return is_array($response) ? $response : null;
    }

    /** @param array<string, mixed> $query */
    public function get(string $path, array $query): ?array
    {
        $response = $this->client->getFunc($path, $query);

        return is_array($response) ? $response : null;
    }

    /**
     * Validate inbound Selcom webhook digest (HS256).
     *
     * @param  array<string, mixed>  $payload
     */
    public function validateWebhookDigest(array $headers, array $payload): bool
    {
        $apiSecret = (string) config('payments.selcom.api_secret', '');
        if ($apiSecret === '') {
            return false;
        }

        $timestamp = $this->headerValue($headers, 'timestamp');
        $digest = $this->headerValue($headers, 'digest');
        $signedFields = $this->headerValue($headers, 'signed-fields');

        if (! $timestamp || ! $digest || ! $signedFields) {
            return false;
        }

        $data = "timestamp={$timestamp}";
        foreach (explode(',', $signedFields) as $field) {
            $field = trim($field);
            if ($field === '' || ! array_key_exists($field, $payload)) {
                return false;
            }
            $data .= "&{$field}=".strval($payload[$field]);
        }

        $expected = base64_encode(hash_hmac('sha256', $data, $apiSecret, true));

        return hash_equals($expected, $digest);
    }

    /** @param array<string, array<int, string>|string> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $lower) {
                continue;
            }
            if (is_array($value)) {
                return isset($value[0]) ? (string) $value[0] : null;
            }

            return (string) $value;
        }

        return null;
    }
}
