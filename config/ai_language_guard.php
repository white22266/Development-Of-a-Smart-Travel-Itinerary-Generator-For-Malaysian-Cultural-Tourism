<?php
// Blocks non-English AI chat input before it reaches Gemini/Ollama or rule parsers.

if (!function_exists('ai_contains_chinese')) {
    function ai_contains_chinese(string $text): bool
    {
        return (bool) preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text);
    }
}

if (!function_exists('ai_english_only_response')) {
    function ai_english_only_response(): array
    {
        return [
            'status' => 'success',
            'answer' => 'Only English is accepted. Please enter English.',
            'reply' => 'Only English is accepted. Please enter English.',
            'source' => 'language_guard',
            'pending_action' => null,
            'pending_actions' => [],
        ];
    }
}

if (!function_exists('ai_reject_chinese_json')) {
    function ai_reject_chinese_json(string $text, string $replyKey = 'answer'): void
    {
        if (!ai_contains_chinese($text)) {
            return;
        }

        $payload = ai_english_only_response();
        if ($replyKey === 'reply') {
            unset($payload['answer']);
        } else {
            unset($payload['reply']);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
