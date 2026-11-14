<?php
// config/ai_chat_text.php
// Every fixed sentence the AI chat endpoints write, in English (en), Malay (ms) and Simplified
// Chinese (zh). Look a sentence up with ai_text('key', $lang, ['name' => ...]); {name} in the
// text is replaced by the value. A key missing in one language falls back to English.
// Place, hotel and state names are inserted as stored and never translated.

if (!defined('AI_LANGUAGES')) {
    define('AI_LANGUAGES', ['en', 'ms', 'zh']);
}

if (!function_exists('ai_text')) {
    function ai_text(string $key, string $lang = 'en', array $vars = []): string
    {
        $all = ai_chat_text_table();
        $lang = in_array($lang, AI_LANGUAGES, true) ? $lang : 'en';
        $text = $all[$key][$lang] ?? $all[$key]['en'] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }
}

if (!function_exists('ai_ui_labels')) {
    /** Card titles and button labels for assets/trip_ai_chat.js, sent with each chat reply. */
    function ai_ui_labels(string $lang): array
    {
        $labels = [];
        foreach (array_keys(ai_chat_text_table()) as $key) {
            if (str_starts_with($key, 'ui_')) {
                $labels[substr($key, 3)] = ai_text($key, $lang);
            }
        }
        return $labels;
    }
}

if (!function_exists('ai_chat_text_table')) {
    function ai_chat_text_table(): array
    {
        static $table = null;
        if ($table !== null) {
            return $table;
        }
        $table = [
            // ------------------------------------------------------------ shared
            'unauthorized' => [
                'en' => 'Please log in again to use the travel assistant.',
                'ms' => 'Sila log masuk semula untuk menggunakan pembantu perjalanan.',
                'zh' => '请重新登录后再使用旅行助手。',
            ],
            'invalid_request' => [
                'en' => 'Invalid request.',
                'ms' => 'Permintaan tidak sah.',
                'zh' => '请求无效。',
            ],
            'itinerary_not_found' => [
                'en' => 'Itinerary not found.',
                'ms' => 'Jadual perjalanan tidak dijumpai.',
                'zh' => '找不到这个行程。',
            ],
            'empty_question' => [
                'en' => 'Please type a question about this itinerary.',
                'ms' => 'Sila taip soalan tentang jadual perjalanan ini.',
                'zh' => '请输入关于这个行程的问题。',
            ],
            'empty_question_or_detail' => [
                'en' => 'Please type a question or trip detail.',
                'ms' => 'Sila taip soalan atau butiran perjalanan.',
                'zh' => '请输入问题或行程信息。',
            ],
            'model_unavailable' => [
                'en' => 'The travel assistant is not available right now. Please try again in a moment.',
                'ms' => 'Pembantu perjalanan tidak tersedia sekarang. Sila cuba lagi sebentar lagi.',
                'zh' => '旅行助手暂时无法使用，请稍后再试。',
            ],
            'model_timeout' => [
                'en' => 'The travel assistant is taking longer than usual. Please try again, or ask a shorter question.',
                'ms' => 'Pembantu perjalanan mengambil masa lebih lama daripada biasa. Sila cuba lagi, atau tanya soalan yang lebih ringkas.',
                'zh' => '旅行助手的回复比平时慢。请再试一次，或者把问题问得简短一些。',
            ],
            'model_invalid' => [
                'en' => 'The travel assistant could not prepare an answer. Please try again.',
                'ms' => 'Pembantu perjalanan tidak dapat menyediakan jawapan. Sila cuba lagi.',
                'zh' => '旅行助手未能生成回答，请再试一次。',
            ],

            // ------------------------------------------------------------ quick replies (AiTravelAssistantService)
            'quick_hello' => [
                'en' => 'Hello. Ask me about your itinerary, such as the daily plan, travel time, costs, places to visit, or what you would like to change.',
                'ms' => 'Helo. Tanya saya tentang jadual perjalanan anda, seperti rancangan harian, masa perjalanan, kos, tempat untuk dilawati, atau apa yang ingin anda ubah.',
                'zh' => '你好。你可以问我关于行程的问题，例如每天的安排、交通时间、费用、可去的地方，或者你想修改的内容。',
            ],
            'quick_thanks' => [
                'en' => 'You are welcome. Ask me anything else about your trip.',
                'ms' => 'Sama-sama. Tanyalah apa-apa lagi tentang perjalanan anda.',
                'zh' => '不客气。关于行程还有什么想问的都可以问我。',
            ],
            'quick_next' => [
                'en' => 'Please ask a specific question, for example about a day\'s plan, travel time, costs, or a place you want to change.',
                'ms' => 'Sila tanya soalan yang khusus, contohnya tentang rancangan sesuatu hari, masa perjalanan, kos, atau tempat yang ingin anda tukar.',
                'zh' => '请提出具体的问题，例如某一天的安排、交通时间、费用，或者你想更换的地点。',
            ],
            'quick_help' => [
                'en' => 'I can explain your itinerary and each place, answer questions about routes, travel time, distance and estimated costs, and suggest places, hotels or changes. Changes are only saved after you press the confirmation button.',
                'ms' => 'Saya boleh menerangkan jadual perjalanan dan setiap tempat, menjawab soalan tentang laluan, masa perjalanan, jarak dan anggaran kos, serta mencadangkan tempat, hotel atau perubahan. Perubahan hanya disimpan selepas anda menekan butang pengesahan.',
                'zh' => '我可以解释你的行程和每个地点，回答关于路线、交通时间、距离和预估费用的问题，并推荐地点、酒店或修改方案。只有在你按下确认按钮后，修改才会保存。',
            ],

            // ------------------------------------------------------------ itinerary editor
            'edit_invalid_request' => [
                'en' => 'Invalid itinerary editor request.',
                'ms' => 'Permintaan penyunting jadual perjalanan tidak sah.',
                'zh' => '行程编辑请求无效。',
            ],
            'edit_invalid_replacement' => [
                'en' => 'Invalid replacement selection.',
                'ms' => 'Pilihan penggantian tidak sah.',
                'zh' => '所选的替换无效。',
            ],
            'edit_replacement_not_found' => [
                'en' => 'Replacement item or place was not found.',
                'ms' => 'Hentian atau tempat pengganti tidak dijumpai.',
                'zh' => '找不到要替换的行程项目或地点。',
            ],
            'edit_update_failed' => [
                'en' => 'Could not update the itinerary item.',
                'ms' => 'Tidak dapat mengemas kini hentian dalam jadual perjalanan.',
                'zh' => '无法更新这个行程项目。',
            ],
            'edit_updated' => [
                'en' => 'Itinerary updated. The route and cost summary have been recalculated.',
                'ms' => 'Jadual perjalanan dikemas kini. Laluan dan ringkasan kos telah dikira semula.',
                'zh' => '行程已更新，路线和费用汇总已重新计算。',
            ],
            'edit_invalid_add' => [
                'en' => 'Invalid add-place selection.',
                'ms' => 'Pilihan tempat untuk ditambah tidak sah.',
                'zh' => '所选的新增地点无效。',
            ],
            'edit_place_not_found' => [
                'en' => 'Selected place was not found.',
                'ms' => 'Tempat yang dipilih tidak dijumpai.',
                'zh' => '找不到所选的地点。',
            ],
            'edit_add_failed' => [
                'en' => 'Could not add this place into the itinerary.',
                'ms' => 'Tidak dapat menambah tempat ini ke dalam jadual perjalanan.',
                'zh' => '无法把这个地点加入行程。',
            ],
            'edit_added' => [
                'en' => 'Place added. The route and cost summary have been recalculated.',
                'ms' => 'Tempat ditambah. Laluan dan ringkasan kos telah dikira semula.',
                'zh' => '地点已加入，路线和费用汇总已重新计算。',
            ],
            'edit_invalid_time' => [
                'en' => 'Invalid timetable start time. Please use a time such as 9:00 AM.',
                'ms' => 'Masa mula jadual tidak sah. Sila gunakan masa seperti 9:00 AM.',
                'zh' => '时间表的开始时间无效，请使用类似 9:00 AM 的时间。',
            ],
            'edit_retime_failed' => [
                'en' => 'Could not rearrange the timetable because this itinerary has no scheduled places.',
                'ms' => 'Tidak dapat menyusun semula jadual kerana jadual perjalanan ini tiada tempat yang dijadualkan.',
                'zh' => '无法重新安排时间表，因为这个行程还没有已安排的地点。',
            ],
            'edit_retimed' => [
                'en' => 'Timetable rearranged to start from {time}. The existing rule-based route places were kept; only the schedule time was updated.',
                'ms' => 'Jadual disusun semula supaya bermula pada {time}. Tempat dalam laluan sedia ada dikekalkan; hanya masa jadual dikemas kini.',
                'zh' => '时间表已改为从 {time} 开始。原有路线中的地点保持不变，只更新了时间。',
            ],
            'edit_retime_detected' => [
                'en' => 'I detected a timetable change request, not a starting location change. I can rearrange this itinerary to start from {time} while keeping the current rule-based route places. Nothing will be saved until you confirm.',
                'ms' => 'Saya mengesan permintaan menukar jadual, bukan menukar lokasi permulaan. Saya boleh menyusun semula jadual perjalanan ini supaya bermula pada {time} dan mengekalkan tempat dalam laluan sekarang. Tiada apa yang disimpan sehingga anda mengesahkannya.',
                'zh' => '我识别到这是修改时间表的请求，而不是修改出发地点。我可以把行程改为从 {time} 开始，并保留当前路线中的地点。在你确认之前不会保存任何内容。',
            ],
            'edit_retime_summary' => [
                'en' => 'Rearrange timetable to start from {time}. Places stay based on your saved preference and rule-based itinerary logic.',
                'ms' => 'Susun semula jadual supaya bermula pada {time}. Tempat kekal mengikut keutamaan anda yang disimpan dan peraturan jadual perjalanan.',
                'zh' => '把时间表改为从 {time} 开始。地点仍按照你保存的偏好和行程规则安排。',
            ],
            'edit_retime_all_days' => [
                'en' => 'The new start time applies to every day of the trip.',
                'ms' => 'Masa mula yang baharu digunakan untuk setiap hari perjalanan.',
                'zh' => '新的开始时间会应用到行程的每一天。',
            ],
            'edit_add_none' => [
                'en' => 'I could not find a place that can be added to that day under your itinerary rules (state, travel pace, opening hours and distance). Try another day or a different kind of place.',
                'ms' => 'Saya tidak menjumpai tempat yang boleh ditambah pada hari itu mengikut peraturan jadual perjalanan anda (negeri, rentak perjalanan, waktu buka dan jarak). Cuba hari lain atau jenis tempat yang berbeza.',
                'zh' => '按照你的行程规则（州属、旅行节奏、开放时间和距离），我找不到可以加入那一天的地点。请换一天，或换一种类型的地点。',
            ],
            'edit_add_intro' => [
                'en' => 'I found places that can be added. Nothing has been saved yet; click Confirm Add to update the itinerary.',
                'ms' => 'Saya menjumpai tempat yang boleh ditambah. Belum ada apa-apa yang disimpan; klik Sahkan Tambah untuk mengemas kini jadual perjalanan.',
                'zh' => '我找到了可以加入的地点。目前还没有保存；点击“确认添加”即可更新行程。',
            ],
            'edit_add_line' => [
                'en' => 'Day {day}: add {place} ({district}, {state}).',
                'ms' => 'Hari {day}: tambah {place} ({district}, {state}).',
                'zh' => '第 {day} 天：加入 {place}（{district}，{state}）。',
            ],
            'edit_add_stop_title' => [
                'en' => 'Add new stop',
                'ms' => 'Tambah hentian baharu',
                'zh' => '新增站点',
            ],
            'edit_no_stops' => [
                'en' => 'There are no editable itinerary stops yet. Ask me to add places for a specific day, for example: add a museum on day 2.',
                'ms' => 'Belum ada hentian yang boleh diubah. Minta saya tambah tempat untuk hari tertentu, contohnya: tambah muzium pada hari 2.',
                'zh' => '目前还没有可以修改的行程站点。你可以让我为某一天添加地点，例如：第 2 天加一个博物馆。',
            ],
            'edit_day_empty' => [
                'en' => 'Day {day} has no places that can be changed. Ask me to add a place on day {day} instead.',
                'ms' => 'Hari {day} tiada tempat yang boleh ditukar. Minta saya tambah tempat pada hari {day}.',
                'zh' => '第 {day} 天没有可以修改的地点。你可以让我在第 {day} 天添加地点。',
            ],
            'edit_no_such_stop_day' => [
                'en' => 'Day {day} has {count} stop(s), so there is no stop {stop}. Please check the stop number.',
                'ms' => 'Hari {day} ada {count} hentian, jadi tiada hentian {stop}. Sila semak nombor hentian.',
                'zh' => '第 {day} 天只有 {count} 个站点，没有第 {stop} 站。请检查站点编号。',
            ],
            'edit_no_such_stop_trip' => [
                'en' => 'This itinerary has {count} stop(s), so there is no stop {stop}. Please check the stop number.',
                'ms' => 'Jadual perjalanan ini ada {count} hentian, jadi tiada hentian {stop}. Sila semak nombor hentian.',
                'zh' => '这个行程只有 {count} 个站点，没有第 {stop} 站。请检查站点编号。',
            ],
            'edit_replace_none' => [
                'en' => 'I could not find a replacement for {title} that fits your itinerary rules (state, opening hours, travel time and distance). Try asking for a different kind of place.',
                'ms' => 'Saya tidak menjumpai pengganti untuk {title} yang sesuai dengan peraturan jadual perjalanan anda (negeri, waktu buka, masa perjalanan dan jarak). Cuba minta jenis tempat yang lain.',
                'zh' => '按照你的行程规则（州属、开放时间、交通时间和距离），我找不到可以替换 {title} 的地点。请换一种类型的地点试试。',
            ],
            'edit_replace_answer' => [
                'en' => "Day {day} stop {stop}: replace {current} with {new} ({category}, {cost}). Reason: {reason}.\nNothing has been saved yet. Click Confirm Change to apply it.",
                'ms' => "Hari {day} hentian {stop}: ganti {current} dengan {new} ({category}, {cost}). Sebab: {reason}.\nBelum ada apa-apa yang disimpan. Klik Sahkan Perubahan untuk menggunakannya.",
                'zh' => "第 {day} 天第 {stop} 站：把 {current} 换成 {new}（{category}，{cost}）。原因：{reason}。\n目前还没有保存。点击“确认修改”即可应用。",
            ],
            'edit_free_entry' => [
                'en' => 'free entry',
                'ms' => 'masuk percuma',
                'zh' => '免费入场',
            ],
            'edit_place_word' => [
                'en' => 'place',
                'ms' => 'tempat',
                'zh' => '地点',
            ],
            'reason_list_separator' => [
                'en' => ', ',
                'ms' => ', ',
                'zh' => '、',
            ],
            'reason_same_district' => ['en' => 'same district', 'ms' => 'daerah yang sama', 'zh' => '同一个县区'],
            'reason_same_category' => ['en' => 'same category', 'ms' => 'kategori yang sama', 'zh' => '同一类别'],
            'reason_lower_cost' => ['en' => 'lower cost', 'ms' => 'kos lebih rendah', 'zh' => '费用更低'],
            'reason_opening_hours' => ['en' => 'has opening hours', 'ms' => 'ada waktu buka', 'zh' => '有开放时间信息'],
            'reason_better' => [
                'en' => 'better alternative from the place database',
                'ms' => 'pilihan lebih baik daripada pangkalan data tempat',
                'zh' => '地点数据库中更合适的选择',
            ],
            'reason_requested' => [
                'en' => 'matches the place you asked for',
                'ms' => 'sepadan dengan tempat yang anda minta',
                'zh' => '与你要求的地点相符',
            ],
            'reason_close_to' => [
                'en' => 'close to {title} ({source})',
                'ms' => 'berhampiran {title} ({source})',
                'zh' => '靠近 {title}（{source}）',
            ],
            'reason_highly_rated' => [
                'en' => 'highly rated place in {state}',
                'ms' => 'tempat yang dinilai tinggi di {state}',
                'zh' => '{state} 评分较高的地点',
            ],
            'anchor_last_place_day' => ['en' => 'last place in this day', 'ms' => 'tempat terakhir pada hari ini', 'zh' => '当天最后一个地点'],
            'anchor_prev_hotel' => ['en' => 'previous night hotel', 'ms' => 'hotel malam sebelumnya', 'zh' => '前一晚的酒店'],
            'anchor_prev_last' => ['en' => 'previous day last place', 'ms' => 'tempat terakhir hari sebelumnya', 'zh' => '前一天最后一个地点'],
            'name_no_state' => [
                'en' => 'I could not tell which state this itinerary covers, so I cannot look up "{name}".',
                'ms' => 'Saya tidak dapat mengenal pasti negeri dalam jadual perjalanan ini, jadi saya tidak boleh mencari "{name}".',
                'zh' => '我无法确定这个行程所在的州属，所以无法查找“{name}”。',
            ],
            'name_not_found' => [
                'en' => 'I could not find "{name}" among the cultural places in {states}. Please check the name, or ask for a kind of place instead (for example: a museum).',
                'ms' => 'Saya tidak menjumpai "{name}" dalam senarai tempat kebudayaan di {states}. Sila semak nama itu, atau minta jenis tempat (contohnya: muzium).',
                'zh' => '在 {states} 的文化地点中找不到“{name}”。请检查名称，或者改为说明想要的地点类型（例如：博物馆）。',
            ],
            'name_states_fallback' => [
                'en' => "this itinerary's states",
                'ms' => 'negeri dalam jadual perjalanan ini',
                'zh' => '这个行程的州属',
            ],
            'name_already_this_stop' => [
                'en' => '{name} is already this stop.',
                'ms' => '{name} sudah pun hentian ini.',
                'zh' => '{name} 已经是这个站点了。',
            ],
            'name_already_in_trip' => [
                'en' => '{name} is already in your itinerary.',
                'ms' => '{name} sudah ada dalam jadual perjalanan anda.',
                'zh' => '{name} 已经在你的行程中了。',
            ],
            'name_cannot_replace' => [
                'en' => '{name} cannot replace this stop: {reason}',
                'ms' => '{name} tidak boleh menggantikan hentian ini: {reason}',
                'zh' => '{name} 不能替换这个站点：{reason}',
            ],
            'name_cannot_add' => [
                'en' => '{name} cannot be added on that day: {reason}',
                'ms' => '{name} tidak boleh ditambah pada hari itu: {reason}',
                'zh' => '{name} 不能加入那一天：{reason}',
            ],
            'edit_clarify' => [
                'en' => 'I am not sure what you would like to change. Please tell me the day and the stop, and what to do, for example: "replace day 2 stop 1 with a museum", "add a food place on day 1" or "start the day at 9am".',
                'ms' => 'Saya kurang pasti apa yang ingin anda ubah. Sila nyatakan hari dan hentian, serta apa yang perlu dibuat, contohnya: "ganti hari 2 hentian 1 dengan muzium", "tambah tempat makan pada hari 1" atau "mula hari pada pukul 9 pagi".',
                'zh' => '我不太确定你想修改什么。请告诉我是哪一天、哪一站，以及要怎么改，例如：“把第 2 天第 1 站换成博物馆”、“第 1 天加一个美食地点”或“每天早上 9 点开始”。',
            ],
            'edit_model_down' => [
                'en' => 'The assistant cannot read free-form requests right now, so please use a simple sentence such as "replace day 2 stop 1 with a museum".',
                'ms' => 'Pembantu tidak dapat memahami permintaan bebas buat masa ini. Sila tulis dalam bahasa Inggeris yang ringkas, contohnya "replace day 2 stop 1 with a museum".',
                'zh' => '助手暂时无法理解自由格式的请求。请用简单的英文句子，例如 "replace day 2 stop 1 with a museum"。',
            ],
            'edit_not_available' => [
                'en' => 'I understood that you want to {what}. This change cannot be made from the chat yet. Nothing has been changed.',
                'ms' => 'Saya faham anda mahu {what}. Perubahan ini belum boleh dibuat melalui chat. Tiada apa yang diubah.',
                'zh' => '我明白你想{what}。目前还不能通过聊天完成这个修改，行程没有任何改动。',
            ],
            'what_remove' => [
                'en' => 'remove {title} from day {day}',
                'ms' => 'membuang {title} daripada hari {day}',
                'zh' => '把 {title} 从第 {day} 天删除',
            ],
            'what_move' => [
                'en' => 'move {title} to day {day}{position}',
                'ms' => 'memindahkan {title} ke hari {day}{position}',
                'zh' => '把 {title} 移到第 {day} 天{position}',
            ],
            'what_move_position' => [
                'en' => ', position {position}',
                'ms' => ', kedudukan {position}',
                'zh' => '第 {position} 个位置',
            ],
            'what_regenerate_day' => [
                'en' => 'plan day {day} again',
                'ms' => 'merancang semula hari {day}',
                'zh' => '重新规划第 {day} 天',
            ],
            'what_optimise_day' => [
                'en' => 'reorder the stops of day {day} for a shorter route',
                'ms' => 'menyusun semula hentian hari {day} supaya laluan lebih pendek',
                'zh' => '重新排列第 {day} 天的站点，让路线更短',
            ],
            'what_optimise_trip' => [
                'en' => 'reorder the stops for a shorter route',
                'ms' => 'menyusun semula hentian supaya laluan lebih pendek',
                'zh' => '重新排列站点，让路线更短',
            ],
            'what_retime_stop' => [
                'en' => 'start {title} at {time}',
                'ms' => 'memulakan {title} pada {time}',
                'zh' => '把 {title} 改到 {time} 开始',
            ],
            'pending_not_available' => [
                'en' => 'Not available in the chat yet.',
                'ms' => 'Belum tersedia dalam chat.',
                'zh' => '聊天中暂不支持。',
            ],

            // ------------------------------------------------------------ editor: remove, move, plan again, optimise, day start
            'edit_confirm_offer' => [
                'en' => 'I can {what}. The planner times the day again by your trip\'s rules. Nothing will be saved until you confirm.',
                'ms' => 'Saya boleh {what}. Perancang akan menjadualkan semula hari itu mengikut peraturan perjalanan anda. Tiada apa yang disimpan sehingga anda mengesahkannya.',
                'zh' => '我可以{what}。规划器会按照你的行程规则重新安排当天的时间。在你确认之前不会保存任何内容。',
            ],
            'edit_optimise_offer' => [
                'en' => 'I can reorder the stops of every day for the shortest route; each stop stays on its own day and the times are planned again. Nothing will be saved until you confirm.',
                'ms' => 'Saya boleh menyusun semula hentian setiap hari supaya laluan paling pendek; setiap hentian kekal pada harinya dan masa dirancang semula. Tiada apa yang disimpan sehingga anda mengesahkannya.',
                'zh' => '我可以重新排列每一天的站点，让路线最短；每个站点仍留在原来的那一天，时间会重新安排。在你确认之前不会保存任何内容。',
            ],
            'what_more' => [
                'en' => ', with more {list}',
                'ms' => ', dengan lebih banyak {list}',
                'zh' => '，多安排{list}',
            ],
            'what_less' => [
                'en' => ', with fewer {list}',
                'ms' => ', dengan kurang {list}',
                'zh' => '，少安排{list}',
            ],
            'list_separator' => ['en' => ', ', 'ms' => ', ', 'zh' => '、'],
            'cat_museum' => ['en' => 'museums', 'ms' => 'muzium', 'zh' => '博物馆'],
            'cat_heritage' => ['en' => 'heritage sites', 'ms' => 'tapak warisan', 'zh' => '古迹'],
            'cat_culture' => ['en' => 'cultural places', 'ms' => 'tempat budaya', 'zh' => '文化景点'],
            'cat_food' => ['en' => 'food places', 'ms' => 'tempat makan', 'zh' => '美食'],
            'cat_nature' => ['en' => 'nature places', 'ms' => 'tempat semula jadi', 'zh' => '自然景点'],
            'cat_shopping' => ['en' => 'shopping places', 'ms' => 'tempat membeli-belah', 'zh' => '购物地点'],
            'cat_festival' => ['en' => 'festivals', 'ms' => 'perayaan', 'zh' => '节庆'],
            'pending_remove_summary' => [
                'en' => 'Remove {title} from day {day}. The day is timed again by the planner\'s rules.',
                'ms' => 'Buang {title} daripada hari {day}. Hari itu dijadualkan semula mengikut peraturan perancang.',
                'zh' => '把 {title} 从第 {day} 天删除。当天会按照规划规则重新安排时间。',
            ],
            'pending_move_summary' => [
                'en' => 'Move {title} to day {day}{position}. The days are timed again by the planner\'s rules.',
                'ms' => 'Pindahkan {title} ke hari {day}{position}. Hari-hari itu dijadualkan semula mengikut peraturan perancang.',
                'zh' => '把 {title} 移到第 {day} 天{position}。相关日期会按照规划规则重新安排时间。',
            ],
            'pending_regenerate_summary' => [
                'en' => 'Plan day {day} again{wishes}. Its must-see places stay; the other days do not change.',
                'ms' => 'Rancang semula hari {day}{wishes}. Tempat wajib lawat kekal; hari-hari lain tidak berubah.',
                'zh' => '重新规划第 {day} 天{wishes}。必去地点会保留，其他日期不变。',
            ],
            'pending_optimise_summary' => [
                'en' => 'Reorder the stops of every day for the shortest route and plan the times again.',
                'ms' => 'Susun semula hentian setiap hari untuk laluan paling pendek dan rancang semula masanya.',
                'zh' => '重新排列每一天的站点，让路线最短，并重新安排时间。',
            ],
            'edit_retime_day_detected' => [
                'en' => 'I can start day {day} at {time} and keep its places; the day is timed again from then by your trip\'s rules, and later changes keep this start. Nothing will be saved until you confirm.',
                'ms' => 'Saya boleh memulakan hari {day} pada {time} dan mengekalkan tempatnya; hari itu dijadualkan semula dari masa itu mengikut peraturan perjalanan anda, dan perubahan seterusnya mengekalkan masa mula ini. Tiada apa yang disimpan sehingga anda mengesahkannya.',
                'zh' => '我可以让第 {day} 天从 {time} 开始，并保留当天的地点；当天会从这个时间起按照行程规则重新安排，之后的修改也会保留这个开始时间。在你确认之前不会保存任何内容。',
            ],
            'edit_retime_day_summary' => [
                'en' => 'Start day {day} at {time}. The places stay; the day is timed again by the planner\'s rules.',
                'ms' => 'Mulakan hari {day} pada {time}. Tempat kekal; hari itu dijadualkan semula mengikut peraturan perancang.',
                'zh' => '第 {day} 天从 {time} 开始。地点不变，当天按照规划规则重新安排时间。',
            ],
            'edit_retime_stop_offer' => [
                'en' => 'A single stop cannot be pinned to a clock time: the planner times each stop from the one before it, its opening hours and the rest you need. I can move {title} to another position or day, or change the time day {day} starts. For example: "move {title} to day {day} position 1" or "start day {day} at {time}".',
                'ms' => 'Satu hentian tidak boleh ditetapkan pada masa tertentu: perancang menjadualkan setiap hentian berdasarkan hentian sebelumnya, waktu bukanya dan masa rehat anda. Saya boleh memindahkan {title} ke kedudukan atau hari lain, atau menukar masa hari {day} bermula. Contohnya: "pindahkan {title} ke hari {day} kedudukan 1" atau "mula hari {day} pukul {time}".',
                'zh' => '单个站点不能固定在某个时间：规划器会根据前一站、开放时间和你需要的休息时间来安排每一站。我可以把 {title} 移到当天的其他位置或其他日期，或者修改第 {day} 天的开始时间。例如：“把 {title} 移到第 {day} 天第 1 站”或“第 {day} 天 {time} 开始”。',
            ],
            'edit_done_removed' => [
                'en' => '{title} was removed from day {day}, and the day was timed again.',
                'ms' => '{title} telah dibuang daripada hari {day}, dan hari itu telah dijadualkan semula.',
                'zh' => '{title} 已从第 {day} 天删除，当天的时间已重新安排。',
            ],
            'edit_done_moved' => [
                'en' => '{title} moved to day {day} as stop {position}, and the days were timed again.',
                'ms' => '{title} telah dipindahkan ke hari {day} sebagai hentian {position}, dan hari-hari itu telah dijadualkan semula.',
                'zh' => '{title} 已移到第 {day} 天第 {position} 站，相关日期的时间已重新安排。',
            ],
            'edit_done_regenerated' => [
                'en' => 'Day {day} was planned again with {stops} stop(s).',
                'ms' => 'Hari {day} telah dirancang semula dengan {stops} hentian.',
                'zh' => '第 {day} 天已重新规划，共 {stops} 个站点。',
            ],
            'edit_done_optimised' => [
                'en' => 'Day(s) {days} are now in a shorter order. Times are up to date.',
                'ms' => 'Hari {days} kini dalam susunan yang lebih pendek. Masa telah dikemas kini.',
                'zh' => '第 {days} 天的站点已改为更短的顺序，时间已更新。',
            ],
            'edit_done_optimised_same' => [
                'en' => 'Every day is already in its best order. Times are up to date.',
                'ms' => 'Setiap hari sudah dalam susunan terbaik. Masa telah dikemas kini.',
                'zh' => '每一天都已经是最佳顺序，时间已更新。',
            ],
            'edit_done_optimised_removed' => [
                'en' => 'Removed because no order keeps them within your trip\'s rules: {list}.',
                'ms' => 'Dibuang kerana tiada susunan yang mengekalkannya dalam peraturan perjalanan anda: {list}.',
                'zh' => '以下站点因为任何顺序都无法符合行程规则而被移除：{list}。',
            ],
            'edit_done_day_start' => [
                'en' => 'Day {day} now starts at {time}, and its times were planned again. Later changes keep this start.',
                'ms' => 'Hari {day} kini bermula pada {time}, dan masanya telah dirancang semula. Perubahan seterusnya mengekalkan masa mula ini.',
                'zh' => '第 {day} 天现在从 {time} 开始，时间已重新安排。之后的修改会保留这个开始时间。',
            ],
            'edit_done_trip_start' => [
                'en' => 'Every day now starts at {time}, and the times were planned again. Later changes keep this start.',
                'ms' => 'Setiap hari kini bermula pada {time}, dan masanya telah dirancang semula. Perubahan seterusnya mengekalkan masa mula ini.',
                'zh' => '每一天现在都从 {time} 开始，时间已重新安排。之后的修改会保留这个开始时间。',
            ],
            'edit_refused' => [
                'en' => 'This change was not made: {reason}. Nothing in your itinerary was changed.',
                'ms' => 'Perubahan ini tidak dibuat: {reason}. Tiada apa dalam jadual perjalanan anda yang diubah.',
                'zh' => '这个修改没有完成：{reason}。行程没有任何改动。',
            ],
            'edit_refused_detail' => [
                'en' => 'This change was not made. {message} Nothing in your itinerary was changed.',
                'ms' => 'Perubahan ini tidak dibuat. {message} Tiada apa dalam jadual perjalanan anda yang diubah.',
                'zh' => '这个修改没有完成。{message} 行程没有任何改动。',
            ],
            // Reason codes of PlannerRules::REASONS, for a refused change.
            'reason_code_DUPLICATE' => ['en' => 'the place is already in the itinerary', 'ms' => 'tempat itu sudah ada dalam jadual perjalanan', 'zh' => '这个地点已经在行程中'],
            'reason_code_STATE' => ['en' => 'the place is outside the chosen states', 'ms' => 'tempat itu di luar negeri yang dipilih', 'zh' => '这个地点不在所选的州属内'],
            'reason_code_REGION' => ['en' => 'the place is across the sea between Peninsular and East Malaysia', 'ms' => 'tempat itu di seberang laut antara Semenanjung dan Malaysia Timur', 'zh' => '这个地点与行程隔着半岛和东马之间的海'],
            'reason_code_FESTIVAL_DATES' => ['en' => 'the festival dates are unknown', 'ms' => 'tarikh perayaan tidak diketahui', 'zh' => '节庆日期未知'],
            'reason_code_FESTIVAL_DAY' => ['en' => 'the festival is not on that day', 'ms' => 'perayaan itu bukan pada hari tersebut', 'zh' => '节庆不在那一天'],
            'reason_code_DIETARY' => ['en' => 'it does not meet your dietary preference', 'ms' => 'ia tidak memenuhi keutamaan pemakanan anda', 'zh' => '不符合你的饮食偏好'],
            'reason_code_STEP_FREE' => ['en' => 'it is not step-free', 'ms' => 'ia tidak bebas tangga', 'zh' => '那里不是无障碍（无台阶）通行'],
            'reason_code_LONG_OUTDOOR' => ['en' => 'it would be a long outdoor visit', 'ms' => 'ia lawatan luar yang terlalu lama', 'zh' => '户外游览时间太长'],
            'reason_code_LEG_DISTANCE' => ['en' => 'a stop would be too far from the one before it', 'ms' => 'satu hentian terlalu jauh dari hentian sebelumnya', 'zh' => '有一站离前一站太远'],
            'reason_code_DAY_DISTANCE' => ['en' => 'the day would go over its distance limit', 'ms' => 'hari itu akan melebihi had jarak harian', 'zh' => '当天会超过每日距离上限'],
            'reason_code_CLOSED' => ['en' => 'a place would be closed that day', 'ms' => 'satu tempat tutup pada hari itu', 'zh' => '有地点在那一天不开放'],
            'reason_code_OPEN_HOURS' => ['en' => 'a place would not be open for the whole visit at the planned time', 'ms' => 'satu tempat tidak dibuka sepanjang lawatan pada masa yang dirancang', 'zh' => '有地点在计划时间内无法开放整个游览时段'],
            'reason_code_HEAT' => ['en' => 'an outdoor visit would fall in the midday heat', 'ms' => 'lawatan luar akan berlaku dalam panas tengah hari', 'zh' => '户外游览会落在中午最热的时段'],
            'reason_code_DAY_END' => ['en' => 'the day would end after its latest end', 'ms' => 'hari itu akan tamat selepas masa tamat paling lewat', 'zh' => '当天会超过最晚结束时间'],
            'reason_code_WAIT' => ['en' => 'a stop would wait too long for opening', 'ms' => 'satu hentian perlu menunggu terlalu lama untuk dibuka', 'zh' => '有一站需要等待开门太久'],
            'reason_code_BUDGET' => ['en' => 'it would go over your budget', 'ms' => 'ia akan melebihi bajet anda', 'zh' => '会超出你的预算'],
            'reason_code_FINISH' => ['en' => 'the end point of the trip could not be reached in time', 'ms' => 'titik akhir perjalanan tidak dapat dicapai tepat pada masanya', 'zh' => '无法及时到达行程终点'],
            'reason_code_ROOM' => ['en' => 'there is no room left on the days it can be visited', 'ms' => 'tiada ruang lagi pada hari ia boleh dilawati', 'zh' => '可以游览它的日子已经没有空位'],
            'reason_code_MEAL' => ['en' => 'it would leave no time for lunch or dinner', 'ms' => 'ia tidak meninggalkan masa untuk makan tengah hari atau makan malam', 'zh' => '会没有时间吃午餐或晚餐'],
            'reason_code_UNAVAILABLE' => ['en' => 'the place is not an active place with map coordinates', 'ms' => 'tempat itu bukan tempat aktif dengan koordinat peta', 'zh' => '这个地点不是带地图坐标的有效地点'],
            'reason_code_AVOID' => ['en' => 'the place is on your list of places to avoid', 'ms' => 'tempat itu dalam senarai tempat yang anda elakkan', 'zh' => '这个地点在你的避开列表中'],
            'reason_code_DAY_FULL' => ['en' => 'the day already has its planned number of stops', 'ms' => 'hari itu sudah mempunyai bilangan hentian yang dirancang', 'zh' => '那一天的站点数量已满'],
            'reason_code_SAME_LANDMARK' => ['en' => 'the same landmark as a place already in the itinerary', 'ms' => 'mercu tanda yang sama dengan tempat yang sudah ada dalam jadual perjalanan', 'zh' => '与行程中已有的地点是同一个地标'],
            'reason_code_LAST_STOP' => ['en' => 'it is the last stop of its day', 'ms' => 'ia hentian terakhir pada hari itu', 'zh' => '它是当天唯一剩下的站点'],
            'reason_code_NOT_FOUND' => ['en' => 'it was not found in this itinerary', 'ms' => 'ia tidak dijumpai dalam jadual perjalanan ini', 'zh' => '在这个行程中找不到它'],
            'reason_code_NOT_EDITABLE' => ['en' => 'this row cannot be changed this way', 'ms' => 'baris ini tidak boleh diubah dengan cara ini', 'zh' => '这一项不能这样修改'],
            'reason_code_LOCKED' => ['en' => 'the day holds a row the planner cannot re-time', 'ms' => 'hari itu mempunyai baris yang tidak boleh dijadualkan semula oleh perancang', 'zh' => '那一天有规划器无法重新安排时间的项目'],
            'reason_code_NO_CHANGE' => ['en' => 'nothing would change', 'ms' => 'tiada apa yang akan berubah', 'zh' => '不会有任何变化'],
            'reason_code_EARLY_START' => ['en' => 'a day cannot start before 06:00', 'ms' => 'hari tidak boleh bermula sebelum 06:00', 'zh' => '一天不能早于 06:00 开始'],
            'reason_code_other' => ['en' => 'it would break a rule of your trip', 'ms' => 'ia akan melanggar peraturan perjalanan anda', 'zh' => '会违反你的行程规则'],

            // ------------------------------------------------------------ travel assistant (dates, origin, weather)
            'date_invalid' => [
                'en' => 'I could not understand that trip date. Please use a valid date.',
                'ms' => 'Saya tidak faham tarikh perjalanan itu. Sila gunakan tarikh yang sah.',
                'zh' => '我无法识别这个出行日期，请使用有效的日期。',
            ],
            'date_updated' => [
                'en' => 'Trip start date updated to {date}. Reloading the itinerary with the confirmed date.',
                'ms' => 'Tarikh mula perjalanan dikemas kini kepada {date}. Jadual perjalanan dimuat semula dengan tarikh yang disahkan.',
                'zh' => '出行开始日期已更新为 {date}。正在按确认的日期重新加载行程。',
            ],
            'origin_invalid' => [
                'en' => 'I could not confirm that starting location. Please choose a valid map location.',
                'ms' => 'Saya tidak dapat mengesahkan lokasi permulaan itu. Sila pilih lokasi yang sah pada peta.',
                'zh' => '无法确认这个出发地点，请在地图上选择有效的位置。',
            ],
            'origin_updated' => [
                'en' => 'Starting location updated to {name}. Reloading the itinerary with the confirmed origin.',
                'ms' => 'Lokasi permulaan dikemas kini kepada {name}. Jadual perjalanan dimuat semula dengan lokasi yang disahkan.',
                'zh' => '出发地点已更新为 {name}。正在按确认的出发地重新加载行程。',
            ],
            'pending_date_summary' => [
                'en' => 'Update this itinerary start date to {date}',
                'ms' => 'Kemas kini tarikh mula jadual perjalanan ini kepada {date}',
                'zh' => '把这个行程的开始日期更新为 {date}',
            ],
            'pending_origin_summary' => [
                'en' => 'Update starting location to {name}',
                'ms' => 'Kemas kini lokasi permulaan kepada {name}',
                'zh' => '把出发地点更新为 {name}',
            ],
            'describe_origin' => [
                'en' => 'your starting location as {name}',
                'ms' => 'lokasi permulaan anda sebagai {name}',
                'zh' => '你的出发地点为 {name}',
            ],
            'describe_date' => [
                'en' => 'your trip start date as {date}',
                'ms' => 'tarikh mula perjalanan anda sebagai {date}',
                'zh' => '你的出行开始日期为 {date}',
            ],
            'detected_confirm_weather' => [
                'en' => 'I detected {what}. Please confirm if this is correct.',
                'ms' => 'Saya mengesan {what}. Sila sahkan jika ini betul.',
                'zh' => '我识别到{what}。如果正确，请确认。',
            ],
            'detected_confirm' => [
                'en' => 'I detected {what}. Click the confirmation button to update the itinerary. I will not save it until you confirm.',
                'ms' => 'Saya mengesan {what}. Klik butang pengesahan untuk mengemas kini jadual perjalanan. Saya tidak akan menyimpannya sehingga anda mengesahkannya.',
                'zh' => '我识别到{what}。点击确认按钮即可更新行程。在你确认之前我不会保存。',
            ],
            'weather_no_point' => [
                'en' => 'I cannot check weather because this itinerary does not have a usable place coordinate yet.',
                'ms' => 'Saya tidak dapat menyemak cuaca kerana jadual perjalanan ini belum mempunyai koordinat tempat yang boleh digunakan.',
                'zh' => '这个行程还没有可用的地点坐标，所以无法查询天气。',
            ],
            'weather_unreachable' => [
                'en' => 'I could not reach the weather service right now. You can still plan normally, but check weather again before travelling.',
                'ms' => 'Saya tidak dapat menghubungi perkhidmatan cuaca sekarang. Anda masih boleh merancang seperti biasa, tetapi semak cuaca sekali lagi sebelum bertolak.',
                'zh' => '暂时无法连接天气服务。你仍然可以正常规划，但出发前请再查看一次天气。',
            ],
            'weather_forecast_for' => [
                'en' => 'Forecast for {date} near {place}:',
                'ms' => 'Ramalan cuaca untuk {date} berhampiran {place}:',
                'zh' => '{date} {place} 附近的天气预报：',
            ],
            'weather_today_near' => [
                'en' => 'Today near {place}:',
                'ms' => 'Hari ini berhampiran {place}:',
                'zh' => '今天 {place} 附近：',
            ],
            'weather_too_far' => [
                'en' => 'There is no forecast for {date} yet: forecasts reach 16 days ahead, so ask again closer to the date.',
                'ms' => 'Belum ada ramalan untuk {date}: ramalan hanya meliputi 16 hari ke hadapan, jadi tanya lagi apabila tarikh itu hampir.',
                'zh' => '{date} 还没有天气预报：预报最多覆盖未来 16 天，请在日期临近时再问。',
            ],
            'weather_past' => [
                'en' => '{date} is in the past, so there is no forecast for it.',
                'ms' => '{date} sudah berlalu, jadi tiada ramalan untuknya.',
                'zh' => '{date} 已经过去，所以没有天气预报。',
            ],

            // ------------------------------------------------------------ hotel assistant
            'hotel_invalid_request' => [
                'en' => 'Invalid hotel assistant request.',
                'ms' => 'Permintaan pembantu hotel tidak sah.',
                'zh' => '酒店助手请求无效。',
            ],
            'hotel_one_day' => [
                'en' => 'A one-day trip has no night to book a hotel for.',
                'ms' => 'Perjalanan sehari tiada malam untuk menempah hotel.',
                'zh' => '一日游没有需要住宿的晚上。',
            ],
            'hotel_gone' => [
                'en' => 'That hotel is no longer in the list. Please ask for hotel suggestions again and choose another one.',
                'ms' => 'Hotel itu sudah tiada dalam senarai. Sila minta cadangan hotel sekali lagi dan pilih yang lain.',
                'zh' => '这家酒店已不在列表中。请重新获取酒店推荐并选择另一家。',
            ],
            'hotel_save_failed' => [
                'en' => 'Could not save the selected hotel. Please try again.',
                'ms' => 'Tidak dapat menyimpan hotel yang dipilih. Sila cuba lagi.',
                'zh' => '无法保存所选的酒店，请再试一次。',
            ],
            'hotel_confirmed' => [
                'en' => 'Hotel confirmed: {name}. It is now in the itinerary, the route and the cost summary.',
                'ms' => 'Hotel disahkan: {name}. Ia kini dalam jadual perjalanan, laluan dan ringkasan kos.',
                'zh' => '酒店已确认：{name}。它现在已加入行程、路线和费用汇总。',
            ],
            'hotel_none' => [
                'en' => 'I could not find a place to stay that matches near your last stop. Try a higher price limit, or ask again without one.',
                'ms' => 'Saya tidak menjumpai tempat penginapan yang sesuai berhampiran hentian terakhir anda. Cuba had harga yang lebih tinggi, atau tanya lagi tanpa had harga.',
                'zh' => '在你最后一站附近找不到符合条件的住宿。请提高价格上限，或者不设上限再问一次。',
            ],
            'hotel_default_question' => [
                'en' => 'Recommend a suitable hotel for this itinerary.',
                'ms' => 'Cadangkan hotel yang sesuai untuk jadual perjalanan ini.',
                'zh' => '为这个行程推荐一家合适的酒店。',
            ],
            'hotel_suggested' => [
                'en' => 'Suggested: {name} ({kind}), about RM {price} per night{distance}.',
                'ms' => 'Cadangan: {name} ({kind}), kira-kira RM {price} semalam{distance}.',
                'zh' => '推荐：{name}（{kind}），每晚约 RM {price}{distance}。',
            ],
            'hotel_distance' => [
                'en' => ', {km} km from your last stop',
                'ms' => ', {km} km dari hentian terakhir anda',
                'zh' => '，距离最后一站 {km} 公里',
            ],
            'hotel_room_budget' => [
                'en' => 'Your room budget is about RM {price} per night.',
                'ms' => 'Bajet bilik anda kira-kira RM {price} semalam.',
                'zh' => '你的住宿预算约为每晚 RM {price}。',
            ],
            'hotel_fallback_tail' => [
                'en' => 'Prices are planning estimates. Look through the options below and click Confirm Hotel to add one to the itinerary.',
                'ms' => 'Harga ialah anggaran perancangan. Lihat pilihan di bawah dan klik Sahkan Hotel untuk menambahnya ke dalam jadual perjalanan.',
                'zh' => '价格只是规划用的估算。请查看下面的选项，点击“确认酒店”把其中一家加入行程。',
            ],
            'hotel_estimate_word' => [
                'en' => 'estimate',
                'ms' => 'anggaran',
                'zh' => '估',
            ],
            'hotel_estimate_note' => [
                'en' => 'Prices are planning estimates, not offers.',
                'ms' => 'Harga ialah anggaran perancangan, bukan tawaran.',
                'zh' => '价格只是规划用的估算，并非报价。',
            ],
            'hotel_others' => [
                'en' => 'Other options nearby: {list}.',
                'ms' => 'Pilihan lain berhampiran: {list}.',
                'zh' => '附近的其他选择：{list}。',
            ],
            'hotel_other_item' => [
                'en' => '{name} ({kind}, about RM {price})',
                'ms' => '{name} ({kind}, kira-kira RM {price})',
                'zh' => '{name}（{kind}，约 RM {price}）',
            ],
            'hotel_rule_confirm' => [
                'en' => 'Do not say the hotel is booked or saved. Tell the user to click Confirm Hotel if they want to add it.',
                'ms' => 'Do not say the hotel is booked or saved. Tell the user to click the button "Sahkan Hotel" if they want to add it.',
                'zh' => 'Do not say the hotel is booked or saved. Tell the user to click the button "确认酒店" if they want to add it.',
            ],

            // ------------------------------------------------------------ pre-generation assistant
            'pre_login' => [
                'en' => 'Please log in again before using the travel assistant.',
                'ms' => 'Sila log masuk semula sebelum menggunakan pembantu perjalanan.',
                'zh' => '请重新登录后再使用旅行助手。',
            ],
            'pre_login_plan' => [
                'en' => 'Please log in again before planning your trip.',
                'ms' => 'Sila log masuk semula sebelum merancang perjalanan anda.',
                'zh' => '请重新登录后再规划行程。',
            ],
            'pre_ask_about' => [
                'en' => 'Please ask about your start date, starting location, selected preference, or route readiness.',
                'ms' => 'Sila tanya tentang tarikh mula, lokasi permulaan, keutamaan yang dipilih, atau kesediaan laluan anda.',
                'zh' => '请询问出发日期、出发地点、所选偏好或路线准备情况。',
            ],
            'pre_hello' => [
                'en' => 'Hello. I can help you check your selected preference, choose a starting location, review route readiness, and confirm what is needed before generating the itinerary.',
                'ms' => 'Helo. Saya boleh membantu anda menyemak keutamaan yang dipilih, memilih lokasi permulaan, menyemak kesediaan laluan, dan mengesahkan perkara yang diperlukan sebelum menjana jadual perjalanan.',
                'zh' => '你好。我可以帮你检查所选的偏好、选择出发地点、检查路线准备情况，并确认生成行程前还需要什么。',
            ],
            'pre_identity' => [
                'en' => 'I am the Smart Travel Assistant for this itinerary generator. I help with preference checks, starting location, route readiness, and pre-generation planning guidance.',
                'ms' => 'Saya Pembantu Perjalanan Pintar untuk penjana jadual perjalanan ini. Saya membantu menyemak keutamaan, lokasi permulaan, kesediaan laluan dan panduan perancangan sebelum menjana.',
                'zh' => '我是这个行程生成器的智能旅行助手，可以帮你检查偏好、出发地点、路线准备情况，并在生成行程前提供规划建议。',
            ],
            'pre_can_do' => [
                'en' => 'I can explain your selected preference, suggest a starting location, check route readiness, review cost and travel time considerations, and tell you what to confirm before clicking Generate Itinerary.',
                'ms' => 'Saya boleh menerangkan keutamaan yang dipilih, mencadangkan lokasi permulaan, menyemak kesediaan laluan, meneliti kos dan masa perjalanan, serta memberitahu apa yang perlu disahkan sebelum klik Generate Itinerary.',
                'zh' => '我可以解释你所选的偏好、推荐出发地点、检查路线准备情况、考虑费用和交通时间，并告诉你点击 Generate Itinerary 前需要确认什么。',
            ],
            'pre_repeat' => [
                'en' => 'That happened because your earlier message was interpreted as a preference-readiness check. Ask me a specific item such as starting location, start date, route readiness, cost, or what to confirm before generation.',
                'ms' => 'Ini berlaku kerana mesej anda sebelum ini difahami sebagai semakan kesediaan keutamaan. Tanya saya perkara khusus seperti lokasi permulaan, tarikh mula, kesediaan laluan, kos, atau apa yang perlu disahkan sebelum menjana.',
                'zh' => '这是因为你之前的消息被理解为偏好准备情况的检查。请问我具体的问题，例如出发地点、出发日期、路线准备情况、费用，或生成前需要确认的内容。',
            ],
            'pre_next' => [
                'en' => 'Next, confirm your start date and starting location. After that, you can click Generate Itinerary and let the system rules create the official plan.',
                'ms' => 'Seterusnya, sahkan tarikh mula dan lokasi permulaan anda. Selepas itu, anda boleh klik Generate Itinerary dan biarkan peraturan sistem menghasilkan rancangan rasmi.',
                'zh' => '接下来，请确认出发日期和出发地点。之后点击 Generate Itinerary，由系统规则生成正式的行程。',
            ],
            'pre_use_origin' => [
                'en' => 'Use {name} as the starting location if that is where the traveller will begin the trip. If not, update it before generating so route time and cost are more accurate.',
                'ms' => 'Gunakan {name} sebagai lokasi permulaan jika di situ perjalanan bermula. Jika tidak, kemas kini sebelum menjana supaya masa dan kos laluan lebih tepat.',
                'zh' => '如果行程从 {name} 出发，就把它作为出发地点。如果不是，请在生成前更新，这样路线时间和费用会更准确。',
            ],
            'pre_choose_origin' => [
                'en' => 'Choose a central and easy-to-reach point in {area}, such as the hotel, main bus terminal, train station, or town centre. Confirm it before generating the itinerary.',
                'ms' => 'Pilih titik yang strategik dan mudah dihubungi di {area}, seperti hotel, terminal bas utama, stesen kereta api atau pusat bandar. Sahkannya sebelum menjana jadual perjalanan.',
                'zh' => '请在 {area} 选择一个位置居中、交通方便的地点，例如酒店、主要巴士总站、火车站或市中心，并在生成行程前确认。',
            ],
            'pre_area_fallback' => [
                'en' => 'the selected destination area',
                'ms' => 'kawasan destinasi yang dipilih',
                'zh' => '所选的目的地区域',
            ],
            'pre_confirm_ready' => [
                'en' => 'Before clicking Generate Itinerary, confirm the start date, starting location, budget, transport type, travel days, destination area, and interests. Your preference looks ready, but the official itinerary will still be generated by system rules.',
                'ms' => 'Sebelum klik Generate Itinerary, sahkan tarikh mula, lokasi permulaan, bajet, jenis pengangkutan, bilangan hari, kawasan destinasi dan minat. Keutamaan anda kelihatan sedia, tetapi jadual perjalanan rasmi tetap dijana oleh peraturan sistem.',
                'zh' => '点击 Generate Itinerary 之前，请确认出发日期、出发地点、预算、交通方式、旅行天数、目的地区域和兴趣。你的偏好看起来已经齐全，但正式行程仍由系统规则生成。',
            ],
            'pre_confirm_missing' => [
                'en' => 'Before clicking Generate Itinerary, complete these missing items: {items}. This helps the system calculate route order, time, and cost more accurately.',
                'ms' => 'Sebelum klik Generate Itinerary, lengkapkan perkara yang belum diisi: {items}. Ini membantu sistem mengira susunan laluan, masa dan kos dengan lebih tepat.',
                'zh' => '点击 Generate Itinerary 之前，请补充以下缺少的信息：{items}。这能帮助系统更准确地计算路线顺序、时间和费用。',
            ],
            'pre_ready' => [
                'en' => 'Your selected preference looks suitable for itinerary generation. Confirm the start date and starting location one more time, then use Generate Itinerary to create the official plan.',
                'ms' => 'Keutamaan yang anda pilih kelihatan sesuai untuk menjana jadual perjalanan. Sahkan tarikh mula dan lokasi permulaan sekali lagi, kemudian gunakan Generate Itinerary untuk menghasilkan rancangan rasmi.',
                'zh' => '你所选的偏好适合生成行程。请再确认一次出发日期和出发地点，然后使用 Generate Itinerary 生成正式行程。',
            ],
            'pre_not_ready' => [
                'en' => 'Your selected preference is not fully ready yet. Please complete {items} before generating the itinerary.',
                'ms' => 'Keutamaan yang anda pilih belum lengkap. Sila lengkapkan {items} sebelum menjana jadual perjalanan.',
                'zh' => '你所选的偏好还不完整。请在生成行程前补充：{items}。',
            ],
            'missing_start_date' => ['en' => 'start date', 'ms' => 'tarikh mula', 'zh' => '出发日期'],
            'missing_origin' => ['en' => 'starting location', 'ms' => 'lokasi permulaan', 'zh' => '出发地点'],
            'missing_days' => ['en' => 'travel days', 'ms' => 'bilangan hari perjalanan', 'zh' => '旅行天数'],
            'missing_budget' => ['en' => 'budget', 'ms' => 'bajet', 'zh' => '预算'],
            'missing_transport' => ['en' => 'transport type', 'ms' => 'jenis pengangkutan', 'zh' => '交通方式'],
            'missing_area' => ['en' => 'destination state or district', 'ms' => 'negeri atau daerah destinasi', 'zh' => '目的地州属或县区'],
            'missing_interests' => ['en' => 'travel interests', 'ms' => 'minat perjalanan', 'zh' => '旅行兴趣'],

            // ------------------------------------------------------------ labels for assets/trip_ai_chat.js
            'ui_confirm_location_title' => ['en' => 'Confirm starting location', 'ms' => 'Sahkan lokasi permulaan', 'zh' => '确认出发地点'],
            'ui_confirm_location' => ['en' => 'Confirm Location', 'ms' => 'Sahkan Lokasi', 'zh' => '确认地点'],
            'ui_confirm_timetable_title' => ['en' => 'Confirm timetable change', 'ms' => 'Sahkan perubahan jadual', 'zh' => '确认修改时间表'],
            'ui_confirm_timetable' => ['en' => 'Confirm Timetable', 'ms' => 'Sahkan Jadual', 'zh' => '确认时间表'],
            'ui_confirm_date_title' => ['en' => 'Confirm trip date', 'ms' => 'Sahkan tarikh perjalanan', 'zh' => '确认出行日期'],
            'ui_confirm_date' => ['en' => 'Confirm Date', 'ms' => 'Sahkan Tarikh', 'zh' => '确认日期'],
            'ui_confirm_hotel' => ['en' => 'Confirm Hotel', 'ms' => 'Sahkan Hotel', 'zh' => '确认酒店'],
            'ui_confirm_add' => ['en' => 'Confirm Add', 'ms' => 'Sahkan Tambah', 'zh' => '确认添加'],
            'ui_confirm_change' => ['en' => 'Confirm Change', 'ms' => 'Sahkan Perubahan', 'zh' => '确认修改'],
            'ui_day_add_stop' => ['en' => 'Day {day}: add new stop', 'ms' => 'Hari {day}: tambah hentian baharu', 'zh' => '第 {day} 天：新增站点'],
            'ui_day_stop' => ['en' => 'Day {day} stop {stop}: {title}', 'ms' => 'Hari {day} hentian {stop}: {title}', 'zh' => '第 {day} 天第 {stop} 站：{title}'],
            'ui_add_prefix' => ['en' => 'Add ', 'ms' => 'Tambah ', 'zh' => '添加 '],
            'ui_replace_prefix' => ['en' => 'Replace with ', 'ms' => 'Ganti dengan ', 'zh' => '替换为 '],
            'ui_per_night' => ['en' => '/night (estimate)', 'ms' => '/malam (anggaran)', 'zh' => '/晚（估算）'],
            'ui_km_away' => ['en' => 'km away', 'ms' => 'km jauhnya', 'zh' => '公里'],
            'ui_writing' => ['en' => 'Writing answer...', 'ms' => 'Sedang menulis jawapan...', 'zh' => '正在生成回答...'],
            'ui_no_answer' => ['en' => 'The assistant could not answer this request.', 'ms' => 'Pembantu tidak dapat menjawab permintaan ini.', 'zh' => '助手无法回答这个请求。'],
            'ui_network_error' => ['en' => 'Network error. Please try again.', 'ms' => 'Ralat rangkaian. Sila cuba lagi.', 'zh' => '网络错误，请再试一次。'],
            'ui_too_slow' => ['en' => 'The assistant took too long to respond. Please try again.', 'ms' => 'Pembantu mengambil masa terlalu lama untuk menjawab. Sila cuba lagi.', 'zh' => '助手响应时间过长，请再试一次。'],
            'ui_saving' => ['en' => 'Saving...', 'ms' => 'Sedang menyimpan...', 'zh' => '正在保存...'],
            'ui_saved' => ['en' => 'Saved. Reloading...', 'ms' => 'Disimpan. Memuat semula...', 'zh' => '已保存，正在重新加载...'],
            'ui_save_failed' => ['en' => 'Could not save this change.', 'ms' => 'Tidak dapat menyimpan perubahan ini.', 'zh' => '无法保存这个修改。'],
            'ui_confirm_remove_title' => ['en' => 'Confirm removing a stop', 'ms' => 'Sahkan membuang hentian', 'zh' => '确认删除站点'],
            'ui_confirm_remove' => ['en' => 'Confirm Remove', 'ms' => 'Sahkan Buang', 'zh' => '确认删除'],
            'ui_confirm_move_title' => ['en' => 'Confirm moving a stop', 'ms' => 'Sahkan memindahkan hentian', 'zh' => '确认移动站点'],
            'ui_confirm_move' => ['en' => 'Confirm Move', 'ms' => 'Sahkan Pindah', 'zh' => '确认移动'],
            'ui_confirm_regenerate_title' => ['en' => 'Confirm planning a day again', 'ms' => 'Sahkan merancang semula hari', 'zh' => '确认重新规划这一天'],
            'ui_confirm_regenerate' => ['en' => 'Confirm Re-plan', 'ms' => 'Sahkan Rancang Semula', 'zh' => '确认重新规划'],
            'ui_confirm_optimise_title' => ['en' => 'Confirm a shorter route', 'ms' => 'Sahkan laluan lebih pendek', 'zh' => '确认优化路线'],
            'ui_confirm_optimise' => ['en' => 'Confirm Optimise', 'ms' => 'Sahkan Optimum', 'zh' => '确认优化'],
            'ui_location_not_found' => [
                'en' => 'Could not find coordinates for that location. Try a clearer city, hotel, landmark, or address.',
                'ms' => 'Tidak dapat mencari koordinat lokasi itu. Cuba nama bandar, hotel, mercu tanda atau alamat yang lebih jelas.',
                'zh' => '找不到这个地点的坐标。请换一个更明确的城市、酒店、地标或地址。',
            ],
        ];
        return $table;
    }
}
