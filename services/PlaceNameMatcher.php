<?php
// services/PlaceNameMatcher.php
// Decides whether two place names refer to the same place.
//
// Names are compared as sets of normalised words. Malay words are mapped to their English
// equivalent ("Muzium" = "Museum"), and words that are common among the names being compared
// (the state name, "Masjid", "Jalan") count less than distinctive ones, so
// "Alor Gajah Roundabout" does not match "Pasar Alor Gajah".

class PlaceNameMatcher
{
    private const WORD_MAP = [
        'muzium' => 'museum', 'galeri' => 'gallery', 'masjid' => 'mosque', 'surau' => 'surau', 'gereja' => 'church',
        'tokong' => 'temple', 'kuil' => 'temple', 'candi' => 'temple', 'taman' => 'park', 'pantai' => 'beach',
        'bukit' => 'hill', 'gunung' => 'mount', 'mountain' => 'mount', 'mt' => 'mount', 'pulau' => 'island',
        'makam' => 'mausoleum', 'tomb' => 'mausoleum', 'istana' => 'palace', 'pasar' => 'market',
        'jalan' => 'street', 'road' => 'street', 'jln' => 'street', 'lorong' => 'lane', 'sungai' => 'river', 'sg' => 'river',
        'kampung' => 'village', 'kg' => 'village', 'kampong' => 'village', 'dataran' => 'square', 'medan' => 'square',
        'menara' => 'tower', 'tugu' => 'monument', 'memorial' => 'monument', 'perigi' => 'well', 'rumah' => 'house',
        'pusat' => 'centre', 'center' => 'centre', 'negeri' => 'state', 'kota' => 'fort', 'fortress' => 'fort',
        'gua' => 'cave', 'tasik' => 'lake', 'hutan' => 'forest', 'lipur' => 'recreational', 'rekreasi' => 'recreational',
        'st' => 'saint', 'seni' => 'art', 'lukis' => 'art', 'budaya' => 'culture', 'kebudayaan' => 'culture', 'cultural' => 'culture',
        'warisan' => 'heritage', 'sejarah' => 'history', 'historical' => 'history', 'historic' => 'history',
        'diraja' => 'royal', 'kesultanan' => 'sultanate', 'cina' => 'chinese', 'melayu' => 'malay', 'india' => 'indian',
        'terjun' => 'fall', 'waterfall' => 'fall', 'falls' => 'fall', 'lata' => 'fall', 'selat' => 'strait', 'straits' => 'strait',
        'negara' => 'national', 'kebangsaan' => 'national', 'islam' => 'islamic', 'dunia' => 'world', 'teluk' => 'bay',
        'tanjung' => 'cape', 'tg' => 'cape', 'bandar' => 'town', 'bandaraya' => 'town', 'city' => 'town', 'pekan' => 'town',
        'stesen' => 'station', 'keretapi' => 'railway', 'jambatan' => 'bridge', 'padang' => 'field', 'kompleks' => 'complex',
        'perpustakaan' => 'library', 'dewan' => 'hall', 'gerbang' => 'gate', 'perkuburan' => 'cemetery', 'kubur' => 'cemetery',
        'burung' => 'bird', 'buaya' => 'crocodile', 'penyu' => 'turtle', 'panas' => 'hot', 'lama' => 'old', 'baru' => 'new', 'bahru' => 'new',
        'samudera' => 'maritime', 'maritim' => 'maritime', 'malacca' => 'melaka', 'pinang' => 'penang', 'johore' => 'johor',
        'tentera' => 'army', 'polis' => 'police', 'rakyat' => 'people', 'belia' => 'youth', 'setem' => 'stamp',
    ];

    private const STOP_WORDS = ['the', 'of', 'and', 'a', 'an', 'at', 'in', 'de', 'dan', 'di', 'on', 'for'];

    /** @var array<string,float> */
    private array $weight = [];
    private float $rareWeight = 1.0;

    /** @param string[] $corpus every name that takes part in the comparison */
    public function __construct(array $corpus = [])
    {
        $documentFrequency = [];
        $documents = 0;
        foreach ($corpus as $name) {
            $tokens = self::tokens((string)$name);
            if (!$tokens) continue;
            $documents++;
            foreach ($tokens as $token) {
                $documentFrequency[$token] = ($documentFrequency[$token] ?? 0) + 1;
            }
        }
        foreach ($documentFrequency as $token => $count) {
            $this->weight[$token] = log(1 + $documents / $count);
        }
        $this->rareWeight = log(1 + max(1, $documents));
    }

    /** 0..1: how likely the two names are the same place. Names in brackets are tried on their own too. */
    public function similarity(string $a, string $b): float
    {
        $best = 0.0;
        foreach (self::variants($a) as $va) {
            foreach (self::variants($b) as $vb) {
                $best = max($best, $this->tokenSimilarity(self::tokens($va), self::tokens($vb)));
            }
        }
        return $best;
    }

    /** Normalised distinct words of a name. */
    public static function tokens(string $name): array
    {
        $text = mb_strtolower($name, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if (is_string($ascii) && trim($ascii) !== '') $text = $ascii;
        $text = str_replace(["'", '`'], '', $text);
        $text = preg_replace('/[^a-z0-9]+/', ' ', $text) ?? $text;

        $tokens = [];
        foreach (explode(' ', trim($text)) as $word) {
            if ($word === '' || in_array($word, self::STOP_WORDS, true)) continue;
            $tokens[] = self::WORD_MAP[$word] ?? $word;
        }
        return array_values(array_unique($tokens));
    }

    /** "Sultanate Palace Museum (Muzium Istana)" -> the whole name, the part outside and the part inside the brackets. */
    private static function variants(string $name): array
    {
        $variants = [$name];
        if (preg_match_all('/\(([^)]{3,})\)/u', $name, $m)) {
            $outside = trim(preg_replace('/\([^)]*\)/u', ' ', $name) ?? '');
            if ($outside !== '') $variants[] = $outside;
            foreach ($m[1] as $inside) $variants[] = $inside;
        }
        return array_values(array_unique($variants));
    }

    private function tokenSimilarity(array $ta, array $tb): float
    {
        if (!$ta || !$tb) return 0.0;
        $weightOf = fn(string $token): float => $this->weight[$token] ?? $this->rareWeight;

        $common = array_values(array_intersect($ta, $tb));
        if (!$common) {
            // No shared word: accept only near-identical spellings ("Tengkera" / "Tengkerah").
            similar_text(implode(' ', $ta), implode(' ', $tb), $percent);
            return $percent >= 88 ? 0.8 : 0.0;
        }

        $shared = array_sum(array_map($weightOf, $common));
        $wa = array_sum(array_map($weightOf, $ta));
        $wb = array_sum(array_map($weightOf, $tb));
        $jaccard = $shared / ($wa + $wb - $shared);

        // A short name wholly inside a longer one ("Cheng Hoon Teng" in "Cheng Hoon Teng Temple")
        // is a match when the shared words are distinctive, not when they are just the town's name.
        $shorter = count($ta) <= count($tb) ? $ta : $tb;
        if (count($common) === count($shorter)) {
            $distinctive = max(array_map($weightOf, $common)) >= 0.6 * $this->rareWeight;
            return max($jaccard, $distinctive ? 0.9 : 0.55);
        }
        return $jaccard;
    }
}
