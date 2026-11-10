<?php

namespace App\Support\Chat;

use Anthropic\Client;
use App\Support\Settings;
use RuntimeException;

/** Claude über das offizielle Anthropic-SDK (Beta-Endpunkt wegen der serverseitigen Ausweichmodelle). */
class AnthropicChatModel implements ChatModel
{
    public function __construct(private Settings $settings) {}

    public function create(array $params): array
    {
        $key = trim((string) $this->settings->get('anthropic_api_key'));
        if ($key === '') {
            throw new RuntimeException('Kein Anthropic-API-Key in den Einstellungen.');
        }
        $client = new Client(apiKey: $key);
        $response = $client->beta->messages->create(...array_filter($params, fn ($v) => $v !== null));

        // so gespeichert, wie die API es liefert – Blöcke (auch Denk-Blöcke) gehen unverändert zurück
        return json_decode(json_encode($response), true);
    }
}
