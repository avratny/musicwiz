<?php

namespace App\Support\Chat;

/** Ein Aufruf an Claude (Messages-API); Antwort als Array im Format der API (content, stop_reason, usage, model). */
interface ChatModel
{
    public function create(array $params): array;
}
