<?php
// services/PlaceTypeClassifier.php
// Gives a place a finer type than its seven categories (mosque, hindu_temple, beach, ...).
// A pure class: it reads one place row and returns a type. It never touches the database.
//
//   [$type, $how] = PlaceTypeClassifier::classify([
//       'name' => 'Masjid Jamiul Ehsan', 'description' => '...', 'category' => 'food',
//       'osm_kind' => 'worship',                  // optional: the kind the place sync stored
//       'osm_tags' => ['religion' => 'muslim'],   // optional: raw OpenStreetMap tags
//   ]);
//
// $how says how sure the answer is, from strongest to weakest:
//   name         a word in the name decided it (Malay and English; Chinese for the common ones)
//   tags         the OpenStreetMap tags or the stored kind decided it
//   description  the first sentence of the description decided it
//   category     nothing fitted, so the type follows from the category
// The name decides before the description, and a more specific word before a general one.

class PlaceTypeClassifier
{
    public const GROUPS = [
        'worship' => 'Places of worship',
        'history' => 'History and heritage',
        'living_culture' => 'Living culture',
        'food' => 'Food',
        'nature' => 'Nature',
        'other' => 'Other',
    ];

    /** type => [label, group]. The one list every other file reads. */
    public const TYPES = [
        'mosque' => ['Mosque', 'worship'],
        'hindu_temple' => ['Hindu temple', 'worship'],
        'chinese_temple' => ['Chinese temple', 'worship'],
        'buddhist_temple' => ['Buddhist temple', 'worship'],
        'church' => ['Church', 'worship'],
        'sikh_temple' => ['Sikh temple (gurdwara)', 'worship'],
        'other_worship' => ['Other place of worship', 'worship'],

        'museum' => ['Museum', 'history'],
        'gallery' => ['Gallery', 'history'],
        'memorial' => ['Monument or memorial', 'history'],
        'palace' => ['Palace', 'history'],
        'fort' => ['Fort', 'history'],
        'historic_building' => ['Historic building', 'history'],
        'historic_site' => ['Historic site', 'history'],
        'mausoleum' => ['Mausoleum or tomb', 'history'],

        'cultural_village' => ['Cultural village', 'living_culture'],
        'cultural_centre' => ['Cultural or craft centre', 'living_culture'],
        'heritage_street' => ['Heritage street', 'living_culture'],
        'market' => ['Market', 'living_culture'],
        'mall' => ['Shopping mall', 'living_culture'],

        'food_street' => ['Food street or hawker centre', 'food'],
        'restaurant' => ['Restaurant', 'food'],
        'cafe' => ['Cafe or coffee shop', 'food'],

        'park' => ['Park or garden', 'nature'],
        'beach' => ['Beach', 'nature'],
        'waterfall' => ['Waterfall', 'nature'],
        'cave' => ['Cave', 'nature'],
        'hill' => ['Hill or mountain', 'nature'],
        'island' => ['Island', 'nature'],
        'lake_river' => ['Lake or river', 'nature'],
        'forest' => ['Forest or nature reserve', 'nature'],
        'wildlife' => ['Wildlife and zoo', 'nature'],

        'viewpoint' => ['Viewpoint or tower', 'other'],
        'festival' => ['Festival', 'other'],
        'other' => ['Other', 'other'],
    ];

    /** The type a place gets when no word, tag or description fits: it follows from the category. */
    private const CATEGORY_FALLBACK = [
        'culture' => 'other',
        'heritage' => 'historic_building',
        'museum' => 'museum',
        'food' => 'restaurant',
        'festival' => 'festival',
        'nature' => 'park',
        'shopping' => 'mall',
    ];

    /** Categories a type normally sits in (by type, else by group). Outside the list = contradiction. */
    private const CATEGORY_FIT = [
        'worship' => ['culture', 'heritage'],
        'history' => ['culture', 'heritage', 'museum'],
        'cultural_village' => ['culture', 'heritage', 'museum', 'festival'],
        'cultural_centre' => ['culture', 'heritage', 'museum', 'festival', 'shopping'],
        'heritage_street' => ['culture', 'heritage', 'shopping', 'food'],
        'market' => ['shopping', 'culture', 'heritage', 'food'],
        'mall' => ['shopping', 'culture'],
        'food_street' => ['food', 'culture', 'heritage', 'shopping'],
        'restaurant' => ['food'],
        'cafe' => ['food', 'culture'],
        'nature' => ['nature', 'culture', 'heritage'],
        'viewpoint' => ['nature', 'culture', 'heritage'],
        'festival' => ['festival', 'culture'],
    ];

    // ------------------------------------------------------------------ the lists

    public static function types(): array
    {
        return self::TYPES;
    }

    public static function groups(): array
    {
        return self::GROUPS;
    }

    /** group => [type => label], in display order. For a grouped select. */
    public static function grouped(): array
    {
        $out = [];
        foreach (self::GROUPS as $group => $_) $out[$group] = [];
        foreach (self::TYPES as $type => [$label, $group]) $out[$group][$type] = $label;
        return $out;
    }

    public static function isValid(?string $type): bool
    {
        return $type !== null && isset(self::TYPES[$type]);
    }

    public static function groupOf(string $type): string
    {
        return self::TYPES[$type][1] ?? 'other';
    }

    public static function labelOf(string $type): string
    {
        return self::TYPES[$type][0] ?? $type;
    }

    /** True when a type clearly does not belong to the category the place is filed under. */
    public static function contradictsCategory(string $type, string $category): bool
    {
        if (!isset(self::TYPES[$type]) || $type === 'other') return false;
        $fit = self::CATEGORY_FIT[$type] ?? self::CATEGORY_FIT[self::TYPES[$type][1]] ?? null;
        return $fit !== null && !in_array($category, $fit, true);
    }

    // ------------------------------------------------------------------ the classifier

    /**
     * @param array $row name, description, category and optionally osm_kind and osm_tags
     * @return array{0:string,1:string} [type, how sure: name|tags|description|category]
     */
    public static function classify(array $row): array
    {
        $name = self::normalise((string)($row['name'] ?? ''));
        $description = (string)($row['description'] ?? '');
        $category = (string)($row['category'] ?? '');
        $tags = is_array($row['osm_tags'] ?? null) ? $row['osm_tags'] : [];
        $kind = (string)($row['osm_kind'] ?? '');

        // 1. the name
        $type = self::fromText($name, false);
        if ($type === 'temple') $type = self::resolveTemple($tags, $description);
        if ($type !== null) return [$type, 'name'];

        // 2. the OpenStreetMap tags, or the kind the place sync stored
        $type = self::fromOsm($tags, $kind, $description);
        if ($type !== null) return [$type, 'tags'];

        // 3. the first sentence of the description: "X is a ..."
        $head = self::descriptionHead($description);
        if ($head !== '') {
            $type = self::fromText($head, true);
            if ($type === 'temple') $type = self::resolveTemple($tags, $description);
            if ($type !== null) return [$type, 'description'];
        }

        // 4. the category
        return [self::CATEGORY_FALLBACK[$category] ?? 'other', 'category'];
    }

    // ------------------------------------------------------------------ rules on text

    /**
     * Rules in the order they are tried: worship and museums first, then food and shops, then history,
     * then nature. Each pattern is a regular expression without delimiters. Names are normalised
     * first: lower case, punctuation gone, so "St. Paul's Church" is "st pauls church".
     */
    private const RULES = [
        // places of worship (the mosque first: its Chinese name ends in 寺, which the Buddhist rule reads)
        ['museum', '^(muzium|museum)\b'],
        ['festival', 'thaipusam|\bfestival\b|karnival|carnival|kaamatan'],
        ['mosque', 'masjid|mosque|surau|musolla|musalla|mushalla|清真寺'],
        ['sikh_temple', 'gurdwara|gurudwara|gurdawara|sikh temple'],
        ['buddhist_temple', '\bwat\b|buddhist|buddha|\bvihara\b|\bviharn\b|dhamma|dharma|lankaramaya|pagoda|monastery|sakyamuni|寺'],
        ['chinese_temple', 'tokong|kuil cina|kuil tionghua|kwan ?yin|kuan ?yin|guan ?yin|guanyin|tua ?pek ?kong|tua ?peh ?kong|tow ?boo ?kong|kongsi|\bmiao\b|\bkeng\b|tin ?hau|thean ?hou|tian ?hou|\bmazu\b|ma ?zu|sam ?poh|taoist|daoist|joss house|chinese temple|\bkong\b|\bkung\b|\bgong\b|city god|cheng ?huang|庙|祠|庵|道观'],
        ['hindu_temple', 'hindu|\bkuil\b|kovil|mandir|devasthanam|arulmigu|mariamman|murugan|subraman|ganesh|vinayag|pillaiyar|perumal|ayyappan|thandayuthapani|\bsivan\b|\bshiva\b|\bkali\b|batu caves|\bpura\b|\bsri\b.*\btemple\b|\bsree\b.*\btemple\b|印度庙'],
        ['church', 'church|gereja|cathedral|katedral|chapel|kapel|basilica|basilika|教堂|礼拜堂'],
        ['temple', '\btemple\b|\bcandi\b'],
        ['other_worship', 'shrine|keramat|synagogue|place of worship|house of worship|ibadat|ashram|baha i'],

        // museums and galleries
        ['museum', 'museum|muzium|musium|planetarium|博物馆'],
        ['gallery', 'gallery|galeri|美术馆|画廊|art space'],
        ['heritage_street', 'street art|\bmurals?\b'],

        // food
        ['food_street', 'food street|food court|food haven|food hall|food paradise|food centre|food center|jalan alor|medan selera|hawker|pusat penjaja|penjaja|gerai|lorong selamat|night food'],
        ['cafe', 'cafe|caffe|kafe|coffee|kopitiam|\bkopi\b|tea ?house|bakery|dessert|ice cream|ais krim|\btoast\b|bubble tea|boba|teh tarik'],
        ['restaurant', 'restoran|restaurant|\bresto\b|rumah makan|kedai makan|mamak|\bnasi\b|\bmee\b|laksa|satay|cendol|bak kut teh|\bbbq\b|seafood|makanan laut|kitchen|dapur|warung|bistro|cuisine|\bgrill\b|steamboat|banana leaf|eatery|dim sum|ikan bakar|\bayam\b|dining|chicken rice|rendang|roti canai|tomyam|tom yam|burger|pizza|noodle|kuey teow|\brojak\b|buffet|catering'],

        // shopping and markets
        ['mall', '\bmall\b|megamall|shopping (centre|center|complex|plaza|arcade|gallery|village)|\bplaza\b|outlets?|emporium|galleria|hypermarket|department store|duty free|times square|shopping district|bukit bintang|^pavilion|sunway pyramid|suria klcc|suria sabah|mid valley|queensbay|one utama|1 utama|商场'],
        ['market', '\bpasar\b|\bmarket\b|marketplace|bazaar|bazar|pekan sari|pekan rabu|\btamu\b|medan niaga|市场|夜市'],

        ['festival', 'festival|\bpesta\b|karnival|carnival|perayaan|regatta|fiesta|gawai|kaamatan|thaipusam|deepavali|cap go meh|lantern|mooncake|dragon boat|parade|upacara'],

        // living culture
        ['cultural_village', 'cultural village|heritage village|traditional village|ethnic village|kampung budaya|kampung warisan|kampung tradisional|kampung morten|rumah panjang|longhouse|long house|mari mari|portuguese settlement|kampung portugis|chitty village|kampung chitty|water village|kampung air|orang asli (village|settlement)|文化村'],
        ['cultural_centre', 'cultural (centre|center|complex|hall)|istana budaya|pusat (kebudayaan|kraf|seni|budaya|kraftangan|warisan)|kompleks (budaya|kraf)|craft (centre|center|complex|village)|\bkraf\b|kraftangan|handicraft|handcraft|arts? (centre|center)|theatre|theater|panggung|wayang|\bbatik\b|songket|pewter|royal selangor|heritage (centre|center)|dewan (budaya|kebudayaan)|kebudayaan|\bbalai budaya\b'],

        // history
        ['palace', 'istana|palace|astana|balairong|王宫|皇宫'],
        ['mausoleum', 'makam|maqam|mausoleum|\btomb|kubur|perkuburan|cemetery|burial|陵'],
        ['fort', '\bfort\b|fortress|\bkota\b(?!\s+(kinabalu|bharu|baru|bahru|tinggi|belud|marudu|samarahan|damansara|kemuning|warisan|sentral|raja))|a famosa|porta de santiago|bastion|\bkubu\b(?!\s+baru)|benteng|炮台|古堡'],
        ['memorial', 'monument|monumen|\btugu\b|statue|patung|memorial|cenotaph|obelisk|clock ?tower|menara jam|jam besar|sculpture|peringatan|inscription|\barch\b|gerbang|gapura|纪念|雕像'],
        ['historic_building', 'stadthuys|mansion|bungalow|town hall|dewan bandaraya|court ?house|mahkamah|railway station|stesen keretapi|train station|old [a-z ]*(building|house|school|post office|hospital|prison|jail|station|bank|residence|cinema)|colonial|heritage (house|building|home|hotel|mansion|bungalow)|ancestral (house|home)|rumah (penghulu|warisan|lama|kutai|tok|datuk|api|kelahiran|merdeka|tradisional|limas|adat|bendahara|panglima)|lighthouse|menara api|residency|clan house|shophouse|shop house|peranakan|nyonya|\bbaba\b|\bgedung\b|bangunan (lama|bersejarah|warisan)|古迹'],
        ['historic_site', 'dataran (merdeka|pahlawan)|merdeka square|independence square|padang kota|\bruins?\b|runtuhan|archaeolog\w*|arkeolog\w*|historic(al)? (site|park|area|trail|village)|heritage (site|trail|walk|zone|area|park)|tapak (warisan|bersejarah|arkeologi|sejarah)|battlefield|medan perang|surrender point|bujang valley|lembah bujang|megalith|prehistoric|rock art|tin mine|lombong|dredge|lenggong|world heritage|unesco|遗址'],
        ['heritage_street', 'jonker|heritage (street|lane)|armenian street|harmony street|petaling street|chinatown|little india|old town|bandar lama|pekan lama|\bstreet\b|\blorong\b|\blebuh\b|\bjalan\b|\bjln\b|\blane\b|pedestrian'],
        ['cultural_centre', '\bcultural\b|\bbudaya\b'],
        ['historic_site', 'heritage|historic|historical|warisan|bersejarah|sejarah'],

        // attractions that are not nature
        ['other', 'theme park|water ?park|waterpark|aqua ?park|taman tema|wonderland|amusement|adventure park|funfair|fun fair|legoland|playland|\bmaze\b|go ?kart|paintball|bungee|zip ?line|snow world'],

        // nature (a tower or cable car beats the island or hill it stands on)
        ['viewpoint', 'viewpoint|view ?point|view deck|lookout|observation|observatory|\btower\b|menara|skywalk|sky ?walk|skybridge|sky bridge|cable car|rooftop|panorama|scenic|belvedere|铁塔|观景'],
        ['wildlife', '\bzoo\b|aquarium|oceanarium|bird park|taman burung|butterfly|rama rama|wildlife|hidupan liar|sanctuary|orang ?utan|turtle (sanctuary|hatchery|centre|center|island|park|conservation)|penyu|elephant|gajah|conservation|monkey|monyet|firefly|kelip kelip|safari|reptile|crocodile|buaya|deer park|marine park|taman laut|primate|hornbill|tapir|sea ?life|underwater world|动物园'],
        ['waterfall', 'air terjun|waterfalls?|\bfalls?\b|\blata\b|\bjeram\b|cascade|rapids|瀑布'],
        ['cave', '\bgua\b|\bcaves?\b|cavern|grotto|\bgoa\b|石洞'],
        ['beach', 'pantai|beach|seaside|\bcove\b|sandbar|海滩|沙滩'],
        ['island', 'pulau(?! (pinang|penang))|\bisland\b|\bpulo\b|kepulauan|\bisle\b|atoll|岛'],
        ['forest', 'forest|hutan|rimba|rainforest|jungle|canopy|mangrove|bakau|wetland|\bpaya\b|arboretum|\bfrim\b|\breserve\b|rizab|taman negara|national park|taman negeri|state park|森林|雨林'],
        ['lake_river', 'tasik|\blake\b|lagoon|lagun|sungai|sungei|\briver\b|riverwalk|riverfront|waterfront|reservoir|empangan|\bdam\b|kolam|air panas|hot spring|\bspring\b|湖|温泉'],
        ['park', '\btaman\b|dataran|\bsquare\b|\bparks?\b|gardens?|kebun|botanic|botani|recreation|rekreasi|playground|esplanade|geopark|\bpadang\b|公园|花园'],
        ['hill', 'bukit|gunung|\bmount\b|\bmt\b|\bhills?\b|\bpeak\b|puncak|banjaran|\bgunong\b|cliff|山|峰'],
        ['beach', 'teluk|tanjung|\bbay\b'],
    ];

    /** Types the description may decide. Place and street words in a description are mostly noise. */
    private const DESCRIPTION_TYPES = [
        'sikh_temple', 'buddhist_temple', 'chinese_temple', 'hindu_temple', 'mosque', 'church', 'temple',
        'museum', 'gallery', 'cafe', 'restaurant', 'mall', 'market', 'festival', 'cultural_village',
        'palace', 'mausoleum', 'fort', 'memorial', 'historic_building', 'wildlife', 'waterfall', 'cave',
        'beach', 'island', 'viewpoint', 'forest', 'park', 'other',
    ];

    private static array $compiled = [];

    private static function normalise(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace(["\u{2019}", "'", '`'], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /** The first rule that fits, or null. 'temple' means a temple whose religion the text does not say. */
    private static function fromText(string $text, bool $forDescription): ?string
    {
        if ($text === '') return null;
        foreach (self::RULES as $i => [$type, $pattern]) {
            if ($forDescription && !in_array($type, self::DESCRIPTION_TYPES, true)) continue;
            if (!isset(self::$compiled[$i])) self::$compiled[$i] = '~' . $pattern . '~u';
            if (preg_match(self::$compiled[$i], $text)) return $type;
        }
        return null;
    }

    /** A temple with no religion in its name: ask the tags, then the description, then assume Chinese. */
    private static function resolveTemple(array $tags, string $description): string
    {
        $byReligion = self::worshipByReligion((string)($tags['religion'] ?? ''));
        if ($byReligion !== null) return $byReligion;
        $text = self::normalise(mb_substr($description, 0, 400));
        foreach ([
            'hindu_temple' => 'hindu|tamil|deity|kuil',
            'buddhist_temple' => 'buddhis|theravada|thai temple|sri lankan',
            'chinese_temple' => 'chinese|taoist|daoist|hokkien|cantonese|teochew|hakka',
        ] as $type => $pattern) {
            if (preg_match('~' . $pattern . '~u', $text)) return $type;
        }
        return 'chinese_temple';
    }

    private static function worshipByReligion(string $religion): ?string
    {
        return match (strtolower($religion)) {
            'muslim', 'islam' => 'mosque',
            'hindu' => 'hindu_temple',
            'buddhist' => 'buddhist_temple',
            'taoist', 'chinese_folk', 'confucian' => 'chinese_temple',
            'christian' => 'church',
            'sikh' => 'sikh_temple',
            default => null,
        };
    }

    /** "X is a Hindu temple in Perak ..." gives "hindu temple in perak ...". Empty when there is no such phrase. */
    private static function descriptionHead(string $description): string
    {
        $flat = self::normalise(mb_substr($description, 0, 300));
        if (!preg_match('~\b(?:is|are|was) (?:a|an|the|one of the) (.{0,90})~u', $flat, $m)) return '';
        return $m[1];
    }

    // ------------------------------------------------------------------ rules on OpenStreetMap data

    private static function fromOsm(array $tags, string $kind, string $description): ?string
    {
        $t = static fn(string $key): string => strtolower((string)($tags[$key] ?? ''));

        if ($tags) {
            $worship = $t('amenity') === 'place_of_worship'
                || in_array($t('building'), ['mosque', 'temple', 'church', 'cathedral', 'shrine', 'chapel', 'synagogue'], true);
            if ($worship) {
                $byReligion = self::worshipByReligion($t('religion'));
                if ($byReligion !== null) return $byReligion;
                $byBuilding = ['mosque' => 'mosque', 'church' => 'church', 'cathedral' => 'church', 'chapel' => 'church'][$t('building')] ?? null;
                return $byBuilding ?? self::worshipFromDescription($description) ?? 'other_worship';
            }
            $map = [
                ['tourism', ['museum' => 'museum', 'gallery' => 'gallery', 'zoo' => 'wildlife', 'aquarium' => 'wildlife',
                             'theme_park' => 'other', 'viewpoint' => 'viewpoint']],
                ['historic', ['castle' => 'fort', 'fort' => 'fort', 'city_gate' => 'fort', 'monument' => 'memorial',
                              'memorial' => 'memorial', 'ruins' => 'historic_site', 'archaeological_site' => 'historic_site',
                              'building' => 'historic_building', 'house' => 'historic_building', 'manor' => 'historic_building',
                              'tomb' => 'mausoleum']],
                ['natural', ['waterfall' => 'waterfall', 'beach' => 'beach', 'cave_entrance' => 'cave', 'peak' => 'hill',
                             'hot_spring' => 'lake_river', 'water' => 'lake_river', 'wood' => 'forest']],
                ['leisure', ['park' => 'park', 'garden' => 'park', 'nature_reserve' => 'forest', 'water_park' => 'other']],
                ['boundary', ['national_park' => 'forest', 'protected_area' => 'forest']],
                ['amenity', ['marketplace' => 'market', 'restaurant' => 'restaurant', 'fast_food' => 'restaurant',
                             'food_court' => 'food_street', 'cafe' => 'cafe', 'theatre' => 'cultural_centre',
                             'arts_centre' => 'cultural_centre', 'planetarium' => 'museum']],
                ['shop', ['mall' => 'mall', 'department_store' => 'mall']],
                ['highway', ['pedestrian' => 'heritage_street']],
                ['man_made', ['lighthouse' => 'historic_building', 'tower' => 'viewpoint']],
            ];
            foreach ($map as [$key, $values]) {
                if (isset($values[$t($key)])) return $values[$t($key)];
            }
            if ($t('historic') !== '' && $t('historic') !== 'no') return 'historic_building';
        }

        // The place sync stores one "kind" per place (see PlaceSyncService::classify).
        if ($kind === 'worship') {
            return self::worshipByReligion($t('religion')) ?? self::worshipFromDescription($description) ?? 'other_worship';
        }
        return [
            'museum' => 'museum', 'gallery' => 'gallery', 'planetarium' => 'museum', 'zoo' => 'wildlife',
            'aquarium' => 'wildlife', 'monument' => 'memorial', 'fort' => 'fort', 'ruins' => 'historic_site',
            'historic' => 'historic_building', 'lighthouse' => 'historic_building', 'peak' => 'hill',
            'waterfall' => 'waterfall', 'beach' => 'beach', 'hot_spring' => 'lake_river', 'cave' => 'cave',
            'national_park' => 'forest', 'nature_reserve' => 'forest', 'viewpoint' => 'viewpoint', 'tower' => 'viewpoint',
            'park' => 'park', 'market' => 'market', 'mall' => 'mall', 'arts_centre' => 'cultural_centre',
            'street' => 'heritage_street', 'theme_park' => 'other', 'water_park' => 'other',
        ][$kind] ?? null;
    }

    /** The sync writes "X is a mosque / a Hindu temple / a church ..." for worship places. */
    private static function worshipFromDescription(string $description): ?string
    {
        $head = self::descriptionHead($description);
        if ($head === '') return null;
        foreach ([
            'mosque' => 'mosque|masjid',
            'hindu_temple' => 'hindu',
            'buddhist_temple' => 'buddhist',
            'chinese_temple' => 'chinese temple|taoist',
            'church' => 'church|cathedral|chapel',
            'sikh_temple' => 'gurdwara|sikh',
        ] as $type => $pattern) {
            if (preg_match('~' . $pattern . '~u', $head)) return $type;
        }
        return null;
    }
}
