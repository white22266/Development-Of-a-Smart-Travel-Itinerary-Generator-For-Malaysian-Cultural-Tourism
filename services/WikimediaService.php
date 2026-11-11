<?php
// services/WikimediaService.php
// Facts, descriptions and freely licensed photos from Wikidata, Wikipedia and Wikimedia
// Commons (free, no key). Wikipedia text is CC BY-SA and Commons photos carry their own
// licence, so callers must keep the source URL and credit that these methods return.
//
// Everything is requested in batches: Wikimedia limits clients that ask item by item.

require_once __DIR__ . '/OpenDataHttp.php';

class WikimediaService
{
    private const TTL = 2592000;   // 30 days

    /**
     * @param string[] $ids Wikidata ids such as "Q185221"
     * @return array<string,array{label:?string,label_ms:?string,aliases:string[],description:?string,
     *               image:?string,website:?string,enwiki:?string,mswiki:?string,instance_of:string[]}>
     */
    public static function entities(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn($id) => (bool)preg_match('/^Q\d+$/', (string)$id))));
        sort($ids);
        $out = [];
        foreach (array_chunk($ids, 45) as $chunk) {
            $url = 'https://www.wikidata.org/w/api.php?' . http_build_query([
                'action' => 'wbgetentities',
                'ids' => implode('|', $chunk),
                'props' => 'labels|aliases|descriptions|claims|sitelinks',
                'languages' => 'en|ms',
                'sitefilter' => 'enwiki|mswiki',
                'format' => 'json',
            ]);
            $json = OpenDataHttp::getJson($url, self::TTL, ['timeout' => 60]);
            foreach (($json['entities'] ?? []) as $id => $entity) {
                if (isset($entity['missing'])) continue;
                $claims = $entity['claims'] ?? [];
                $out[$id] = [
                    'label' => $entity['labels']['en']['value'] ?? null,
                    'label_ms' => $entity['labels']['ms']['value'] ?? null,
                    'aliases' => array_column($entity['aliases']['en'] ?? [], 'value'),
                    'description' => $entity['descriptions']['en']['value'] ?? null,
                    'image' => self::claimValue($claims, 'P18'),
                    'website' => self::claimValue($claims, 'P856'),
                    'enwiki' => $entity['sitelinks']['enwiki']['title'] ?? null,
                    'mswiki' => $entity['sitelinks']['mswiki']['title'] ?? null,
                    'instance_of' => self::claimItems($claims, 'P31'),
                ];
            }
        }
        return $out;
    }

    /**
     * Lead sections of Wikipedia articles as plain text.
     *
     * @param string[] $titles
     * @return array<string,array{extract:string,url:string,title:string}> keyed by the title as given;
     *         missing articles and disambiguation pages are left out
     */
    public static function wikipediaSummaries(string $lang, array $titles): array
    {
        if (!preg_match('/^[a-z]{2,3}$/', $lang)) return [];
        $titles = array_values(array_unique(array_filter(array_map('trim', $titles), static fn($t) => $t !== '' && !str_contains($t, '|'))));
        sort($titles);

        $out = [];
        // 20 per request is the limit of the API for intro extracts.
        foreach (array_chunk($titles, 20) as $chunk) {
            $url = "https://{$lang}.wikipedia.org/w/api.php?" . http_build_query([
                'action' => 'query',
                'prop' => 'extracts|info|pageprops',
                'exintro' => 1,
                'explaintext' => 1,
                'exlimit' => 20,
                'inprop' => 'url',
                'ppprop' => 'disambiguation',
                'redirects' => 1,
                'titles' => implode('|', $chunk),
                'format' => 'json',
            ]);
            $json = OpenDataHttp::getJson($url, self::TTL, ['timeout' => 60]);
            $query = $json['query'] ?? null;
            if (!is_array($query)) continue;

            $pages = [];
            foreach (($query['pages'] ?? []) as $page) {
                if (isset($page['missing']) || isset($page['pageprops']['disambiguation'])) continue;
                $extract = trim((string)($page['extract'] ?? ''));
                if ($extract === '') continue;
                $pages[(string)$page['title']] = [
                    'extract' => $extract,
                    'url' => (string)($page['fullurl'] ?? ''),
                    'title' => (string)$page['title'],
                ];
            }
            foreach (self::resolvedTitles($query, $chunk) as $given => $final) {
                if (isset($pages[$final])) $out[$given] = $pages[$final];
            }
        }
        return $out;
    }

    /**
     * Wikimedia Commons photos: where to download a web-sized copy, and what attribution needs.
     *
     * @param string[] $fileNames with or without the "File:" prefix
     * @return array<string,array{download_url:string,page_url:string,mime:string,license:string,artist:string}>
     *         keyed by the file name as given; files that are not photos are left out
     */
    public static function commonsImages(array $fileNames, int $width = 1280): array
    {
        $given = [];
        foreach ($fileNames as $fileName) {
            $clean = trim((string)preg_replace('/^File:/i', '', trim((string)$fileName)));
            if ($clean !== '' && !str_contains($clean, '|')) $given[(string)$fileName] = 'File:' . $clean;
        }
        $titles = array_values(array_unique($given));
        sort($titles);

        $found = [];
        foreach (array_chunk($titles, 40) as $chunk) {
            $url = 'https://commons.wikimedia.org/w/api.php?' . http_build_query([
                'action' => 'query',
                'prop' => 'imageinfo',
                'iiprop' => 'url|mime|extmetadata',
                'iiurlwidth' => $width,
                'titles' => implode('|', $chunk),
                'format' => 'json',
            ]);
            $json = OpenDataHttp::getJson($url, self::TTL, ['timeout' => 60]);
            $query = $json['query'] ?? null;
            if (!is_array($query)) continue;

            $pages = [];
            foreach (($query['pages'] ?? []) as $page) {
                $info = $page['imageinfo'][0] ?? null;
                if (!is_array($info)) continue;
                $mime = (string)($info['mime'] ?? '');
                if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) continue;
                $meta = $info['extmetadata'] ?? [];
                $artist = html_entity_decode(strip_tags((string)($meta['Artist']['value'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $pages[(string)$page['title']] = [
                    'download_url' => (string)($info['thumburl'] ?? $info['url'] ?? ''),
                    'page_url' => (string)($info['descriptionurl'] ?? ''),
                    'mime' => $mime,
                    'license' => trim(strip_tags((string)($meta['LicenseShortName']['value'] ?? ''))),
                    'artist' => mb_substr(trim((string)preg_replace('/\s+/u', ' ', $artist)), 0, 200),
                ];
            }
            foreach (self::resolvedTitles($query, $chunk) as $title => $final) {
                if (isset($pages[$final])) $found[$title] = $pages[$final];
            }
        }

        $out = [];
        foreach ($given as $fileName => $title) {
            if (isset($found[$title])) $out[$fileName] = $found[$title];
        }
        return $out;
    }

    /** "en:A Famosa" (the OSM wikipedia tag) -> ['en', 'A Famosa']. */
    public static function parseWikipediaTag(string $tag): ?array
    {
        if (!preg_match('/^([a-z]{2,3}):(.+)$/', trim($tag), $m)) return null;
        return [$m[1], trim($m[2])];
    }

    /**
     * The API renames titles ("a_famosa" becomes "A famosa") and follows redirects. Map each
     * title we asked for to the title of the page that came back.
     *
     * @return array<string,string>
     */
    private static function resolvedTitles(array $query, array $asked): array
    {
        $normalized = array_column($query['normalized'] ?? [], 'to', 'from');
        $redirects = array_column($query['redirects'] ?? [], 'to', 'from');
        $map = [];
        foreach ($asked as $title) {
            $final = $normalized[$title] ?? $title;
            for ($hop = 0; $hop < 3 && isset($redirects[$final]); $hop++) {
                $final = $redirects[$final];
            }
            $map[$title] = $final;
        }
        return $map;
    }

    /** First string value of a property, preferring the statement ranked "preferred". */
    private static function claimValue(array $claims, string $property): ?string
    {
        $fallback = null;
        foreach ($claims[$property] ?? [] as $statement) {
            $value = $statement['mainsnak']['datavalue']['value'] ?? null;
            if (!is_string($value) || $value === '') continue;
            if (($statement['rank'] ?? '') === 'preferred') return $value;
            $fallback ??= $value;
        }
        return $fallback;
    }

    /** @return string[] item ids ("Q33506") a property points to */
    private static function claimItems(array $claims, string $property): array
    {
        $ids = [];
        foreach ($claims[$property] ?? [] as $statement) {
            $id = $statement['mainsnak']['datavalue']['value']['id'] ?? null;
            if (is_string($id)) $ids[] = $id;
        }
        return $ids;
    }
}
