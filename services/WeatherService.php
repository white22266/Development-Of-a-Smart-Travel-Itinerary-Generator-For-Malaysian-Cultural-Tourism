<?php
// services/WeatherService.php
// Daily weather forecasts from Open-Meteo: free, no API key, open-source, with data from
// national weather services (CC BY 4.0; show "Weather data by Open-Meteo.com" where a forecast
// is shown). A forecast reaches 16 days ahead; a later date has none yet.

require_once __DIR__ . '/OpenDataHttp.php';

class WeatherService
{
    public const CREDIT = 'Weather data by Open-Meteo.com';

    /** WMO weather codes, as Open-Meteo returns them. */
    private const CODES = [
        0 => 'Clear sky', 1 => 'Mainly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
        45 => 'Fog', 48 => 'Fog',
        51 => 'Light drizzle', 53 => 'Drizzle', 55 => 'Heavy drizzle', 56 => 'Freezing drizzle', 57 => 'Freezing drizzle',
        61 => 'Light rain', 63 => 'Rain', 65 => 'Heavy rain', 66 => 'Freezing rain', 67 => 'Freezing rain',
        71 => 'Light snow', 73 => 'Snow', 75 => 'Heavy snow', 77 => 'Snow grains',
        80 => 'Light showers', 81 => 'Showers', 82 => 'Heavy showers', 85 => 'Snow showers', 86 => 'Snow showers',
        95 => 'Thunderstorm', 96 => 'Thunderstorm with hail', 99 => 'Thunderstorm with hail',
    ];

    /**
     * The forecast for today and the 15 days after it at a place.
     *
     * @return array<string,array{date:string,summary:string,code:int,temp_min:int,temp_max:int,rain_chance:?int,rain_mm:float,wet:bool}>
     *         keyed by date (Y-m-d); empty when the service cannot be reached
     */
    public static function daily(float $lat, float $lng): array
    {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude' => round($lat, 2),    // about a kilometre: nearby stops share one cached forecast
            'longitude' => round($lng, 2),
            'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum',
            'timezone' => 'Asia/Kuala_Lumpur',
            'forecast_days' => 16,
        ]);
        $json = OpenDataHttp::getJson($url, 10800, [   // 3 hours
            'timeout' => 8,
            'validate' => static fn(array $j): bool => isset($j['daily']['time']),
        ]);
        $daily = $json['daily'] ?? null;
        if (!is_array($daily)) return [];

        $days = [];
        foreach ($daily['time'] as $i => $date) {
            $code = (int)($daily['weather_code'][$i] ?? -1);
            $rainChance = isset($daily['precipitation_probability_max'][$i]) ? (int)$daily['precipitation_probability_max'][$i] : null;
            $days[(string)$date] = [
                'date' => (string)$date,
                'summary' => self::CODES[$code] ?? 'Unknown',
                'code' => $code,
                'temp_min' => (int)round((float)($daily['temperature_2m_min'][$i] ?? 0)),
                'temp_max' => (int)round((float)($daily['temperature_2m_max'][$i] ?? 0)),
                'rain_chance' => $rainChance,
                'rain_mm' => round((float)($daily['precipitation_sum'][$i] ?? 0), 1),
                // rain, showers or thunderstorm expected, or more likely than not
                'wet' => ($code >= 51 && $code <= 67) || ($code >= 80 && $code <= 82) || $code >= 95 || ($rainChance !== null && $rainChance >= 60),
            ];
        }
        return $days;
    }

    /**
     * The daily chance of rain (0 to 100) at a place for those of the given dates (Y-m-d) that
     * lie inside the forecast horizon and have a figure. A date without one is left out; a
     * failed request gives an empty list and never throws, so planning goes on without it.
     *
     * @param string[] $dates
     * @return array<string,int> date => chance of rain in percent
     */
    public static function rainChances(float $lat, float $lng, array $dates): array
    {
        try {
            $daily = self::daily($lat, $lng);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($dates as $date) {
            $chance = $daily[(string)$date]['rain_chance'] ?? null;
            if ($chance !== null) $out[(string)$date] = max(0, min(100, (int)$chance));
        }
        return $out;
    }

    /**
     * The chance of rain for each hour of the given dates (Y-m-d) at a place, from Open-Meteo's
     * hourly precipitation_probability in local (Asia/Kuala_Lumpur) time. A date outside the
     * forecast horizon, or one without a whole day of figures, is left out. A failed request
     * gives an empty list and never throws. With ROUTING_ESTIMATES_ONLY (offline) no request
     * is made at all.
     *
     * @param string[] $dates
     * @return array<string,int[]> date => 24 whole numbers (index 0 = 00:00 to 00:59), each 0 to 100
     */
    public static function rainHours(float $lat, float $lng, array $dates): array
    {
        if (self::offline() || !$dates) return [];
        try {
            $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
                'latitude' => round($lat, 2),    // same rounding as daily(): nearby stops share the cache
                'longitude' => round($lng, 2),
                'hourly' => 'precipitation_probability',
                'timezone' => 'Asia/Kuala_Lumpur',
                'forecast_days' => 16,
            ]);
            $json = OpenDataHttp::getJson($url, 10800, [   // 3 hours, as daily()
                'timeout' => 8,
                'validate' => static fn(array $j): bool => isset($j['hourly']['time'], $j['hourly']['precipitation_probability']),
            ]);
            $times = $json['hourly']['time'] ?? null;
            $values = $json['hourly']['precipitation_probability'] ?? null;
            if (!is_array($times) || !is_array($values)) return [];

            $byDate = [];
            foreach ($times as $i => $stamp) {
                if (!is_string($stamp) || !preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):/', $stamp, $m)) continue;
                if (!isset($values[$i]) || !is_numeric($values[$i])) continue;   // a missing hour spoils the day
                $byDate[$m[1]][(int)$m[2]] = max(0, min(100, (int)round((float)$values[$i])));
            }
            $out = [];
            foreach ($dates as $date) {
                $hours = $byDate[(string)$date] ?? null;
                if ($hours === null || count($hours) !== 24) continue;
                ksort($hours);
                $out[(string)$date] = array_values($hours);
            }
            return $out;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Whether ROUTING_ESTIMATES_ONLY is set: the planner check runs offline and no service may then make a request. */
    private static function offline(): bool
    {
        return in_array(strtolower(trim((string)getenv('ROUTING_ESTIMATES_ONLY'))), ['1', 'true', 'yes'], true);
    }

    /** The forecast for one date (Y-m-d) at a place, or null when there is none (yet). */
    public static function forDate(float $lat, float $lng, string $date): ?array
    {
        return self::daily($lat, $lng)[$date] ?? null;
    }

    /** One forecast day in a sentence: "Thunderstorm, 24 to 31 C, 80% chance of rain." */
    public static function describe(array $day): string
    {
        $text = $day['summary'] . ', ' . $day['temp_min'] . ' to ' . $day['temp_max'] . ' C';
        if ($day['rain_chance'] !== null) $text .= ', ' . $day['rain_chance'] . '% chance of rain';
        return $text . '.';
    }

    /** What the forecast means for a day of sightseeing. */
    public static function advice(array $day): string
    {
        if ($day['wet']) return 'Bring an umbrella and put indoor places such as museums first.';
        if ($day['temp_max'] >= 33) return 'It will be hot: keep outdoor stops for the morning or the evening.';
        return 'Good conditions for sightseeing.';
    }
}
