<?php
// services/ItineraryEditIntentService.php
// Reads what a traveller wants to change in a saved itinerary, in English, Malay or Chinese.
// The local Ollama model is shown the message and a compact list of the itinerary's stops and
// returns ONE JSON object; PHP then checks every field strictly. The model never changes data:
// the result only decides which proposal the chat offers, and nothing is saved before the
// traveller presses a confirmation button.
//
// The intent (every key is always present):
//   action     'replace' | 'add' | 'remove' | 'retime' | 'move' | 'regenerate_day' | 'optimise' | 'none'
//   day        int day number inside the trip, or null. For 'move' it is the destination day.
//   stop_id    int itinerary_items.item_id of a stop in this itinerary, or null
//   category   one of self::CATEGORIES, or null (the kind of place wanted)
//   place_name string name of a specific place asked for (as typed), or null
//   time       'HH:MM' (24-hour), or null
//   position   int wanted position in the day (1 = first), or null
//   wish       string free wishes in a few words ("fewer temples, more food"), or null
//   language   'en' | 'ms' | 'zh', the detected language of the message
//   source     'model'

require_once __DIR__ . '/OllamaClient.php';

class ItineraryEditIntentService
{
    public const ACTIONS = ['replace', 'add', 'remove', 'retime', 'move', 'regenerate_day', 'optimise', 'none'];
    public const CATEGORIES = ['museum', 'heritage', 'culture', 'food', 'nature', 'shopping', 'festival'];

    private OllamaClient $ollama;
    private string $lastFailure = '';
    private string $lastRaw = '';

    public function __construct(?string $model = null, ?string $baseUrl = null)
    {
        $this->ollama = new OllamaClient($model, $baseUrl);
    }

    /**
     * Why the last detect() returned null: 'unreachable', 'timeout', 'model_error',
     * 'invalid_json' or 'invalid_intent'; '' after a valid intent.
     */
    public function lastFailure(): string
    {
        return $this->lastFailure;
    }

    /** The model's raw reply of the last call, for checks and logs only. */
    public function lastRaw(): string
    {
        return $this->lastRaw;
    }

    /**
     * @param array $items     The itinerary's editable stops (no hotels), each with item_id, day_no,
     *                         sequence_no, item_title, category or item_type, start_time, end_time.
     * @param int   $totalDays Number of days in the trip.
     * @param string $language 'en', 'ms' or 'zh' (the detected language of the message).
     * @return array|null The validated intent, or null when the model failed or gave no valid intent.
     */
    public function detect(string $message, array $items, int $totalDays, string $language = 'en'): ?array
    {
        $this->lastFailure = '';
        $this->lastRaw = '';
        $message = trim($message);
        if ($message === '' || $totalDays < 1) {
            $this->lastFailure = 'invalid_intent';
            return null;
        }
        if (mb_strlen($message, 'UTF-8') > 500) {
            $message = mb_substr($message, 0, 500, 'UTF-8');
        }

        $user = "Itinerary:\n" . self::describeItinerary($items, $totalDays)
            . "\n\nRequest: " . $message . "\nJSON:";

        $reply = $this->ollama->chat(self::systemPrompt(), $user, [
            'format' => 'json',
            'temperature' => 0,
            'num_predict' => 160,
            'timeout' => (int)OllamaClient::setting('OLLAMA_INTENT_TIMEOUT', 20),
        ]);
        if (!$reply['ok']) {
            $this->lastFailure = !empty($reply['unreachable']) ? 'unreachable' : ($reply['timed_out'] ? 'timeout' : 'model_error');
            error_log('ItineraryEditIntentService: ' . $reply['error']);
            return null;
        }

        $this->lastRaw = $reply['text'];
        $data = json_decode($reply['text'], true);
        if (!is_array($data)) {
            $this->lastFailure = 'invalid_json';
            return null;
        }

        $intent = self::validate($data, $items, $totalDays, $message);
        if ($intent === null) {
            $this->lastFailure = 'invalid_intent';
            return null;
        }
        $intent['language'] = in_array($language, ['en', 'ms', 'zh'], true) ? $language : 'en';
        $intent['source'] = 'model';
        return $intent;
    }

    /**
     * One line per stop, grouped by day, e.g.
     *   Day 1:
     *     stop 1: id 689, Tan Beng Swee Clock Tower, culture, 08:37-10:07
     */
    public static function describeItinerary(array $items, int $totalDays): string
    {
        $byDay = [];
        foreach ($items as $item) {
            $byDay[(int)$item['day_no']][] = $item;
        }
        $lines = ["The trip has {$totalDays} day(s)."];
        for ($day = 1; $day <= $totalDays; $day++) {
            $lines[] = "Day {$day}:";
            $dayItems = $byDay[$day] ?? [];
            if (empty($dayItems)) {
                $lines[] = '  (no stops)';
                continue;
            }
            usort($dayItems, fn($a, $b) => (int)$a['sequence_no'] <=> (int)$b['sequence_no']);
            foreach ($dayItems as $item) {
                $title = preg_replace('/\s+/', ' ', trim((string)$item['item_title'])) ?? '';
                if (mb_strlen($title, 'UTF-8') > 70) {
                    $title = mb_substr($title, 0, 70, 'UTF-8');
                }
                $category = strtolower(trim((string)($item['category'] ?? '') ?: (string)($item['item_type'] ?? '')));
                $times = self::shortTime($item['start_time'] ?? null) . '-' . self::shortTime($item['end_time'] ?? null);
                $lines[] = '  stop ' . (int)$item['sequence_no'] . ': id ' . (int)$item['item_id'] . ', ' . $title
                    . ($category !== '' ? ', ' . $category : '') . ($times !== '-' ? ', ' . $times : '');
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Check every field. Anything unknown or out of range makes the whole intent invalid (null).
     */
    public static function validate(array $data, array $items, int $totalDays, string $message = ''): ?array
    {
        $action = strtolower(trim((string)($data['action'] ?? '')));
        if (!in_array($action, self::ACTIONS, true)) {
            return null;
        }

        $intent = [
            'action' => $action,
            'day' => null,
            'stop_id' => null,
            'category' => null,
            'place_name' => null,
            'time' => null,
            'position' => null,
            'wish' => null,
        ];
        if ($action === 'none') {
            return $intent;
        }

        // Numbers may come back as numbers or numeric strings; anything else is invalid.
        $day = self::optionalInt($data['day'] ?? null);
        $stopId = self::optionalInt($data['stop_id'] ?? null);
        $position = self::optionalInt($data['position'] ?? null);
        if ($day === false || $stopId === false || $position === false) {
            return null;
        }
        if ($day !== null && ($day < 1 || $day > $totalDays)) {
            return null;
        }

        // The stop: the model names it (stop_name), copies its id (stop_id) or gives its number in
        // the day (stop_no). A small model names stops more reliably than it copies ids, so a name
        // that matches one stop wins; an id must belong to this itinerary.
        $stopNo = self::optionalInt($data['stop_no'] ?? null);
        if ($stopNo === false) {
            return null;
        }
        $names = [self::optionalString($data['stop_name'] ?? null, 120)];
        if (in_array($action, ['remove', 'move', 'retime'], true)) {
            $names[] = self::optionalString($data['place_name'] ?? null, 120);
        }
        $stop = null;
        $stopByName = false;
        foreach (array_filter($names) as $name) {
            $stop = self::stopByName($items, $name);
            if ($stop !== null) {
                $stopByName = true;
                break;
            }
        }
        if ($stop === null && $stopId !== null) {
            foreach ($items as $item) {
                if ((int)$item['item_id'] === $stopId) {
                    $stop = $item;
                    break;
                }
            }
            if ($stop === null) {
                return null;   // not a stop of this itinerary
            }
        }
        if ($stop === null && $stopNo !== null && $day !== null && $action !== 'move') {
            foreach ($items as $item) {
                if ((int)$item['day_no'] === $day && (int)$item['sequence_no'] === $stopNo) {
                    $stop = $item;
                    break;
                }
            }
            if ($stop === null) {
                return null;   // that day has no such stop
            }
        }
        if ($stop !== null && $stopByName && $action !== 'move') {
            $day = (int)$stop['day_no'];   // the named stop decides the day
        }
        if ($stopByName && in_array($action, ['remove', 'move', 'retime'], true)) {
            $data['place_name'] = null;    // it named the existing stop, not a new place
        }

        $category = self::optionalString($data['category'] ?? null, 30);
        if ($category !== null) {
            $category = strtolower($category);
            if (!in_array($category, self::CATEGORIES, true)) {
                return null;
            }
        }

        $placeName = self::optionalString($data['place_name'] ?? null, 120);
        if ($placeName !== null) {
            $placeName = trim(strip_tags($placeName), " \t\"'.,;:!?");
            // "a museum", "美食" or "tempat makan" is a kind of place, not a name.
            $kind = self::categoryWord($placeName, true);
            if ($kind !== null) {
                if ($category === null) {
                    $category = $kind;
                }
                $placeName = null;
            } elseif ($placeName === '') {
                $placeName = null;
            }
        }

        $time = null;
        $rawTime = self::optionalString($data['time'] ?? null, 10);
        if ($rawTime !== null) {
            if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $rawTime, $m)) {
                return null;
            }
            $time = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            // A small model sometimes copies a stop's time from the list ("08:37" for "8am").
            // When the minutes are not in the message, read them from it: half past or o'clock.
            if ($message !== '' && $m[2] !== '00' && !preg_match('/(?<!\d)' . $m[2] . '(?!\d)/', $message)) {
                $half = (bool)preg_match('/半|setengah|half/iu', $message);
                $time = sprintf('%02d:%s', (int)$m[1], $half ? '30' : '00');
            }
        }

        $wish = self::optionalString($data['wish'] ?? null, 200);
        if ($wish !== null) {
            $wish = trim(strip_tags($wish));
            if ($wish === '') {
                $wish = null;
            }
        }

        // The model gave no kind of place for a new stop: take one only when the message names one.
        if ($category === null && $placeName === null && in_array($action, ['replace', 'add'], true) && $message !== '') {
            $category = self::categoryWord($message, false);
        }

        // What each action needs.
        switch ($action) {
            case 'replace':
            case 'remove':
                if ($stop === null) return null;
                if ($day !== null && $day !== (int)$stop['day_no']) return null;
                $day = (int)$stop['day_no'];
                break;
            case 'move':
                if ($stop === null) return null;
                if ($day === null) $day = (int)$stop['day_no'];
                if ($day === (int)$stop['day_no'] && $position === null) return null;   // nowhere to move to
                break;
            case 'retime':
                if ($time === null) return null;
                if ($stop !== null) {
                    if ($day !== null && $day !== (int)$stop['day_no']) return null;
                    $day = (int)$stop['day_no'];
                }
                break;
            case 'regenerate_day':
                if ($day === null) {
                    if ($totalDays !== 1) return null;
                    $day = 1;
                }
                break;
            case 'add':
            case 'optimise':
                if ($stop !== null && $day !== null && $day !== (int)$stop['day_no']) return null;
                break;
        }

        if ($position !== null) {
            $dayCount = 0;
            foreach ($items as $item) {
                if ((int)$item['day_no'] === (int)$day) $dayCount++;
            }
            if ($position < 1 || $position > $dayCount + 1) {
                return null;
            }
        }

        $intent['day'] = $day;
        $intent['stop_id'] = $stop !== null ? (int)$stop['item_id'] : null;
        $intent['category'] = $category;
        $intent['place_name'] = $placeName;
        $intent['time'] = $time;
        $intent['position'] = $position;
        $intent['wish'] = $wish;
        return $intent;
    }

    private static function systemPrompt(): string
    {
        $categories = implode(', ', self::CATEGORIES);
        return <<<PROMPT
You read a traveller's request about their saved trip and answer with ONE JSON object.
The request may be in English, Malay or Chinese.
Keys: "action", "day", "stop_no", "stop_name", "stop_id", "category", "place_name", "time", "position", "wish".
action, exactly one of:
- "replace": swap an existing stop for another place
- "add": add a new stop
- "remove": delete or skip a stop
- "retime": change the clock time a day or a stop starts
- "move": put an existing stop on another day or at another position
- "regenerate_day": plan a whole day again (plan again, rancang semula, 重新安排, 重新规划)
- "optimise": only reorder the same stops for a shorter route (shorter route, laluan lebih pendek, 重新排序, 顺路)
- "none": a question, a greeting, or anything that is not a change
day: the day number the change is for (for "move": the day the stop goes to), else null.
stop_no: the number of the existing stop the traveller means within its day ("stop 2", "kedua", "第二站" = 2), else null.
stop_name: the name of the existing stop the traveller means, copied from the itinerary list, else null.
stop_id: the id of that existing stop from the itinerary list, else null. Never invent an id.
category: the kind of NEW place wanted, one of {$categories}, or null.
  food = food, makan, makanan, tempat makan, 美食, 吃, 餐厅; museum = muzium, 博物馆; nature = alam semula jadi, taman, 自然, 公园;
  heritage = temples, mosques, churches, historic sites, warisan, 古迹, 寺庙; shopping = membeli-belah, 购物; culture = budaya, 文化.
place_name: the name of a specific new place the traveller asks for, else null.
time: the clock time the traveller asks for, 24-hour "HH:MM" (9am = "09:00", pukul 2.30 petang = "14:30", 下午3点 = "15:00"), else null. Never copy a time from the itinerary list.
position: the wanted position within the day (1 = first; "last" = number of stops that day + 1 when moving to the end), else null.
wish: other wishes in a few English words, such as "cheaper" or "fewer temples, more food", else null.
Use null for anything not said.

Examples for this itinerary:
Day 1: stop 1: id 880001, Muzium Negara, museum; stop 2: id 880002, Masjid Jamek, heritage; stop 3: id 880003, Central Market, shopping
Day 2: stop 1: id 880004, Batu Caves, heritage; stop 2: id 880005, Jalan Alor, food
Request: replace day 1 stop 2 with a museum
{"action":"replace","day":1,"stop_no":2,"stop_name":"Masjid Jamek","stop_id":880002,"category":"museum","place_name":null,"time":null,"position":null,"wish":null}
Request: 把第二天的Batu Caves换成国家纪念碑
{"action":"replace","day":2,"stop_no":1,"stop_name":"Batu Caves","stop_id":880004,"category":null,"place_name":"国家纪念碑","time":null,"position":null,"wish":null}
Request: tukar hentian kedua hari 2 kepada tempat makan
{"action":"replace","day":2,"stop_no":2,"stop_name":"Jalan Alor","stop_id":880005,"category":"food","place_name":null,"time":null,"position":null,"wish":null}
Request: tambah tempat makan pada hari 1
{"action":"add","day":1,"stop_no":null,"stop_name":null,"stop_id":null,"category":"food","place_name":null,"time":null,"position":null,"wish":null}
Request: buang Central Market
{"action":"remove","day":1,"stop_no":3,"stop_name":"Central Market","stop_id":880003,"category":null,"place_name":null,"time":null,"position":null,"wish":null}
Request: 第一天早上9点开始
{"action":"retime","day":1,"stop_no":null,"stop_name":null,"stop_id":null,"category":null,"place_name":null,"time":"09:00","position":null,"wish":null}
Request: move Jalan Alor to day 1
{"action":"move","day":1,"stop_no":null,"stop_name":"Jalan Alor","stop_id":880005,"category":null,"place_name":null,"time":null,"position":null,"wish":null}
Request: rancang semula hari 2, kurang kuil, lebih banyak makanan
{"action":"regenerate_day","day":2,"stop_no":null,"stop_name":null,"stop_id":null,"category":null,"place_name":null,"time":null,"position":null,"wish":"fewer temples, more food"}
Request: 第一天的路线太绕了，帮我重新排序
{"action":"optimise","day":1,"stop_no":null,"stop_name":null,"stop_id":null,"category":null,"place_name":null,"time":null,"position":null,"wish":null}
Request: how far is Batu Caves from the hotel?
{"action":"none","day":null,"stop_no":null,"stop_name":null,"stop_id":null,"category":null,"place_name":null,"time":null,"position":null,"wish":null}
The example ids are not in the real itinerary: use only ids from the itinerary you are given.
PROMPT;
    }

    /**
     * The category a word names, in English, Malay or Chinese. $whole: the text must be just
     * that word (for a place_name); otherwise the first category word found in the text.
     */
    private static function categoryWord(string $text, bool $whole): ?string
    {
        static $words = [
            'food' => ['food', 'food place', 'restaurant', 'eat', 'makan', 'makanan', 'tempat makan', '美食', '吃', '餐厅', '小吃'],
            'museum' => ['museum', 'gallery', 'muzium', 'galeri', '博物馆', '美术馆'],
            'nature' => ['nature', 'park', 'beach', 'alam semula jadi', 'semula jadi', 'taman', 'pantai', '自然', '公园', '海滩'],
            'shopping' => ['shopping', 'mall', 'membeli-belah', 'membeli belah', '购物', '商场'],
            'heritage' => ['heritage', 'historic site', 'temple', 'mosque', 'church', 'warisan', 'kuil', 'masjid', '古迹', '寺庙', '遗产'],
            'culture' => ['culture', 'cultural place', 'budaya', '文化'],
            'festival' => ['festival', 'perayaan', 'pesta', '节日', '节庆'],
        ];
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($whole) {
            $text = preg_replace('/^(?:a|an|the|some|satu|sebuah|一个|一家|一些)\s*/u', '', $text) ?? $text;
            $text = preg_replace('/(?<=[a-z])s$/', '', $text) ?? $text;
            $text = trim(preg_replace('/\s+(?:place|spot|tempat)$/u', '', $text) ?? $text);
        }
        foreach ($words as $category => $list) {
            foreach ($list as $word) {
                if ($whole ? $text === $word : self::containsWord($text, $word)) {
                    return $category;
                }
            }
        }
        return null;
    }

    private static function containsWord(string $text, string $word): bool
    {
        if (preg_match('/\p{Han}/u', $word)) {
            return str_contains($text, $word);
        }
        return (bool)preg_match('/(?<![a-z])' . preg_quote($word, '/') . 's?(?![a-z])/u', $text);
    }

    /**
     * The one stop whose title matches the name: exact first, then the longest title contained
     * in the name, then a title that contains the name. Null when nothing or several match.
     */
    private static function stopByName(array $items, string $name): ?array
    {
        $needle = self::normaliseName($name);
        if (mb_strlen($needle, 'UTF-8') < 3) {
            return null;
        }
        $exact = [];
        $titleInName = [];
        $nameInTitle = [];
        foreach ($items as $item) {
            $title = self::normaliseName((string)$item['item_title']);
            if ($title === '') continue;
            if ($title === $needle) {
                $exact[] = $item;
            } elseif (str_contains($needle, $title)) {
                $titleInName[] = [mb_strlen($title, 'UTF-8'), $item];
            } elseif (str_contains($title, $needle)) {
                $nameInTitle[] = $item;
            }
        }
        if (count($exact) === 1) return $exact[0];
        if (count($exact) > 1) return null;
        if ($titleInName) {
            usort($titleInName, fn($a, $b) => $b[0] <=> $a[0]);
            if (count($titleInName) === 1 || $titleInName[0][0] > $titleInName[1][0]) return $titleInName[0][1];
            return null;
        }
        return count($nameInTitle) === 1 ? $nameInTitle[0] : null;
    }

    private static function normaliseName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? $name;
        return trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    }

    /** @return int|null|false null when absent, false when present but not a whole number */
    private static function optionalInt($value)
    {
        if ($value === null || $value === '' || (is_string($value) && strtolower(trim($value)) === 'null')) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int)$value;
        }
        if (is_string($value) && preg_match('/^\s*\d{1,6}\s*$/', $value)) {
            return (int)$value;
        }
        return false;
    }

    private static function optionalString($value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || strtolower($value) === 'null') {
            return null;
        }
        return mb_strlen($value, 'UTF-8') > $maxLength ? mb_substr($value, 0, $maxLength, 'UTF-8') : $value;
    }

    private static function shortTime($time): string
    {
        return is_string($time) && preg_match('/^(\d{1,2}):(\d{2})/', $time, $m) ? sprintf('%02d:%s', (int)$m[1], $m[2]) : '';
    }
}
