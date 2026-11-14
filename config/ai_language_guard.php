<?php
// config/ai_language_guard.php
// Language helper for the AI chat endpoints. The chat accepts English, Malay and Chinese:
// ai_detect_language() tells which one a message is written in, so fixed replies come from
// config/ai_chat_text.php in that language and the model is told to answer in it.

require_once __DIR__ . '/ai_chat_text.php';

if (!function_exists('ai_contains_chinese')) {
    function ai_contains_chinese(string $text): bool
    {
        return (bool) preg_match('/[\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text);
    }
}

if (!function_exists('ai_detect_language')) {
    /**
     * 'zh' when the text has Chinese characters, 'ms' when it reads as Malay, otherwise 'en'.
     * Place names are often Malay ("Muzium Negara", "Masjid Jamek"), so place words are not
     * counted, and a Malay reading needs at least as much Malay as English.
     */
    function ai_detect_language(string $text): string
    {
        if (ai_contains_chinese($text)) {
            return 'zh';
        }

        $words = preg_split('/[^a-z]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($words)) {
            return 'en';
        }

        // Words that are clearly Malay and rarely part of a place name.
        static $strong = [
            'saya', 'aku', 'kami', 'kita', 'awak', 'anda', 'nak', 'mahu', 'hendak', 'ingin', 'boleh', 'tidak', 'tak',
            'tukar', 'tukarkan', 'ganti', 'gantikan', 'tambah', 'tambahkan', 'buang', 'padam', 'hapus', 'keluarkan',
            'pindah', 'pindahkan', 'alih', 'alihkan', 'susun', 'semula', 'jadual', 'hari', 'tempat', 'pukul',
            'bagaimana', 'berapa', 'kenapa', 'mengapa', 'bila', 'terima', 'kasih', 'tolong', 'sila', 'cuaca',
            'untuk', 'dengan', 'yang', 'lawatan', 'perjalanan', 'mula', 'mulakan', 'bermula', 'esok', 'pagi',
            'petang', 'malam', 'tengahari', 'sahaja', 'saja', 'murah', 'lebih', 'kurang', 'pertama', 'kedua',
            'ketiga', 'keempat', 'terakhir', 'akhir', 'selamat', 'apakah', 'adakah', 'hentian', 'destinasi',
            'makanan', 'penginapan', 'bilik', 'jangan', 'sudah', 'belum', 'juga', 'sekarang', 'macam', 'mana',
        ];
        // Common Malay words that also occur in English text or in names: they count half.
        static $weak = ['di', 'ke', 'dan', 'ini', 'itu', 'apa', 'ada', 'lagi', 'makan', 'hai', 'helo', 'ok', 'baik'];
        static $english = [
            'the', 'a', 'an', 'to', 'of', 'in', 'on', 'at', 'is', 'are', 'be', 'can', 'could', 'please', 'with',
            'my', 'me', 'i', 'you', 'what', 'how', 'when', 'where', 'why', 'which', 'and', 'or', 'for', 'from',
            'change', 'replace', 'add', 'remove', 'move', 'day', 'stop', 'place', 'time', 'start', 'want', 'it',
            'this', 'that', 'do', 'does', 'will', 'would', 'should', 'there', 'have', 'has', 'more', 'less',
        ];

        $malay = 0.0;
        $englishScore = 0;
        foreach ($words as $word) {
            if (in_array($word, $strong, true)) {
                $malay += 1.0;
            } elseif (in_array($word, $weak, true)) {
                $malay += 0.5;
            } elseif (in_array($word, $english, true)) {
                $englishScore++;
            }
        }

        if ($malay >= 1.0 && $malay >= $englishScore) {
            return 'ms';
        }
        return 'en';
    }
}

if (!function_exists('ai_request_language')) {
    /**
     * The language for this request. Confirm buttons send no message, so the page passes back
     * the 'lang' it received with the proposal; otherwise the message decides.
     */
    function ai_request_language(string $message): string
    {
        $posted = strtolower(trim((string) ($_POST['lang'] ?? '')));
        if (trim($message) === '' && in_array($posted, AI_LANGUAGES, true)) {
            return $posted;
        }
        return trim($message) === '' ? 'en' : ai_detect_language($message);
    }
}

if (!function_exists('ai_language_name')) {
    /** The language's name in English, for model instructions. */
    function ai_language_name(string $lang): string
    {
        return ['zh' => 'Simplified Chinese', 'ms' => 'Malay (Bahasa Melayu)'][$lang] ?? 'English';
    }
}

if (!function_exists('ai_reply_language_rule')) {
    /** The instruction that makes the model answer in the traveller's language. */
    function ai_reply_language_rule(string $lang): string
    {
        $name = ai_language_name($lang);
        $rule = "Write the whole reply in {$name}, the language of the traveller's message. "
            . 'Copy place names, hotel names and addresses exactly as they are written in the context, in their original letters; never translate or transliterate them.';
        if ($lang === 'zh') {
            $rule .= ' Example: write "A Famosa Fortress" and "Cheng Ho Cultural Museum", not a Chinese name.';
        }
        return $rule;
    }
}
