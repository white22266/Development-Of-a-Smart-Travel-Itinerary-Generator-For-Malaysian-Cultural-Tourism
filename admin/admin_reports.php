<?php
// admin/admin_reports.php
// Separate, complete admin reports with PDF/CSV export and AI analysis.
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . "/../config/db_connect.php";
require_once __DIR__ . "/../config/api_keys.php";
require_once __DIR__ . "/../services/AiAdminReportAnalysisService.php";

if (!isset($_SESSION["logged_in"]) || $_SESSION["logged_in"] !== true || ($_SESSION["role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php?role=admin");
    exit;
}

$adminName = $_SESSION["admin_name"] ?? "Administrator";

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, "UTF-8"); }
function rm($n): string { return "RM " . number_format((float)$n, 2); }

function valid_date($s): string
{
    $s = trim((string)$s);
    if ($s === "" || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return "";
    $dt = DateTime::createFromFormat("Y-m-d", $s);
    return ($dt && $dt->format("Y-m-d") === $s) ? $s : "";
}

function report_period_label(string $from, string $to): string
{
    if ($from !== "" && $to !== "") return $from . " to " . $to;
    if ($from !== "") return "From " . $from;
    if ($to !== "") return "Until " . $to;
    return "All time";
}

function table_exists(mysqli $conn, string $table): bool
{
    $table = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function column_exists(mysqli $conn, string $table, string $column): bool
{
    $table = $conn->real_escape_string($table);
    $column = $conn->real_escape_string($column);
    $res = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function rows_query(mysqli $conn, string $sql): array
{
    $rows = [];
    $res = $conn->query($sql);
    if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
    return $rows;
}

function scalar_query(mysqli $conn, string $sql, $default = 0)
{
    $res = $conn->query($sql);
    if (!$res) return $default;
    $row = $res->fetch_assoc();
    if (!$row) return $default;
    $val = reset($row);
    return $val === null ? $default : $val;
}

function date_filter_sql(mysqli $conn, string $alias, string $from, string $to): string
{
    $parts = [];
    if ($from !== "") $parts[] = "$alias.created_at >= '" . $conn->real_escape_string($from . " 00:00:00") . "'";
    if ($to !== "") $parts[] = "$alias.created_at <= '" . $conn->real_escape_string($to . " 23:59:59") . "'";
    return $parts ? " AND " . implode(" AND ", $parts) : "";
}

function csv_frequency(array $rows, string $column, string $labelKey): array
{
    $counts = [];
    foreach ($rows as $row) {
        $raw = (string)($row[$column] ?? "");
        foreach (explode(",", $raw) as $part) {
            $value = trim($part);
            if ($value === "") continue;
            $key = strtolower($value);
            if (!isset($counts[$key])) $counts[$key] = [$labelKey => $value, "Total" => 0, "total" => 0];
            $counts[$key]["Total"]++;
            $counts[$key]["total"]++;
        }
    }
    usort($counts, fn($a, $b) => ($b["total"] <=> $a["total"]) ?: strcmp((string)reset($a), (string)reset($b)));
    return array_values($counts);
}

function preference_interest_frequency(mysqli $conn, string $tpDate): array
{
    if (table_exists($conn, "traveller_preference_interests") && table_exists($conn, "travel_interests")) {
        return rows_query($conn, "
            SELECT ti.interest_label AS Interest, COUNT(*) AS Total, COUNT(*) AS total
            FROM traveller_preference_interests tpi
            JOIN travel_interests ti ON ti.interest_id = tpi.interest_id
            JOIN traveller_preferences tp ON tp.preference_id = tpi.preference_id
            WHERE 1=1$tpDate
            GROUP BY ti.interest_id, ti.interest_label
            ORDER BY total DESC, Interest ASC
        ");
    }
    return [];
}

function preference_state_frequency(mysqli $conn, string $tpDate): array
{
    if (table_exists($conn, "traveller_preference_states") && table_exists($conn, "malaysia_states")) {
        return rows_query($conn, "
            SELECT ms.state_name AS State, COUNT(tp.preference_id) AS Total, COUNT(tp.preference_id) AS total
            FROM malaysia_states ms
            LEFT JOIN traveller_preference_states tps ON tps.state_id = ms.state_id
            LEFT JOIN traveller_preferences tp ON tp.preference_id = tps.preference_id$tpDate
            GROUP BY ms.state_id, ms.state_name
            ORDER BY total DESC, State ASC
        ");
    }
    return [];
}

function top_rows(array $rows, int $limit = 10): array { return array_slice($rows, 0, $limit); }
function bottom_rows(array $rows, int $limit = 10, bool $includeZero = false): array
{
    $filtered = $includeZero ? array_values($rows) : array_values(array_filter($rows, fn($r) => (int)($r["total"] ?? 0) > 0));
    usort($filtered, fn($a, $b) => ((int)$a["total"] <=> (int)$b["total"]));
    return array_slice($filtered, 0, $limit);
}

function money_rows(array $rows, array $moneyKeys): array
{
    foreach ($rows as &$row) {
        foreach ($moneyKeys as $key) {
            if (array_key_exists($key, $row)) $row[$key] = rm($row[$key]);
        }
    }
    unset($row);
    return $rows;
}

function report_options(): array
{
    return [
        "overview" => "System Overview",
        "user_preferences" => "User Preference Analysis",
        "destination_demand" => "Destination Demand Report",
        "attraction_price" => "Attraction and Price Report",
        "cost_budget" => "Cost and Budget Report",
        "ai_usage" => "AI Usage Report",
    ];
}

function section(string $title, array $headers, array $rows, string $note = ""): array
{
    return ["title" => $title, "headers" => $headers, "rows" => $rows, "note" => $note];
}

function build_report(mysqli $conn, string $type, string $from, string $to, string $period): array
{
    $hasPreferences = table_exists($conn, "traveller_preferences");
    $hasAiLogs = table_exists($conn, "ai_chat_logs");
    $hasSuggestions = table_exists($conn, "cultural_place_suggestions");
    $hasReviews = table_exists($conn, "ratings_reviews");
    $hasTravellerActive = column_exists($conn, "travellers", "is_active");
    $hasTravellerCreated = column_exists($conn, "travellers", "created_at");
    $hasDistrict = column_exists($conn, "cultural_places", "district");
    $hasSelectedHotel = column_exists($conn, "itineraries", "selected_hotel_id");

    $itDate = date_filter_sql($conn, "i", $from, $to);
    $tpDate = $hasPreferences && column_exists($conn, "traveller_preferences", "created_at") ? date_filter_sql($conn, "tp", $from, $to) : "";
    $cpDistrict = $hasDistrict ? "cp.district" : "''";
    $priceExpr = "COALESCE(NULLIF(cp.entrance_fee, 0), cp.estimated_cost, 0)";
    $sections = [];
    $kpis = [];
    $raw = ["period" => $period, "report_type" => $type];

    $commonSummary = [
        "Travellers" => (int)scalar_query($conn, "SELECT COUNT(*) FROM travellers"),
        "Preferences" => $hasPreferences ? (int)scalar_query($conn, "SELECT COUNT(*) FROM traveller_preferences tp WHERE 1=1$tpDate") : 0,
        "Generated Trips" => (int)scalar_query($conn, "SELECT COUNT(*) FROM itineraries i WHERE 1=1$itDate"),
        "Active Places" => (int)scalar_query($conn, "SELECT COUNT(*) FROM cultural_places WHERE is_active = 1"),
    ];

    if ($type === "overview") {
        $title = "System Overview Report";
        $description = "Complete system-wide report covering users, preferences, trips, cultural data, reviews, content suggestions, sharing, and AI usage.";
        $kpis = [
            ["label" => "Registered Travellers", "value" => $commonSummary["Travellers"], "note" => $hasTravellerActive ? scalar_query($conn, "SELECT COUNT(*) FROM travellers WHERE is_active=1") . " active" : "Active column unavailable"],
            ["label" => "Generated Trips", "value" => $commonSummary["Generated Trips"], "note" => "Filtered by selected period"],
            ["label" => "Cultural Places", "value" => $commonSummary["Active Places"], "note" => "Active records"],
            ["label" => "AI Questions", "value" => $hasAiLogs ? (int)scalar_query($conn, "SELECT COUNT(*) FROM ai_chat_logs a WHERE 1=1" . date_filter_sql($conn, "a", $from, $to)) : 0, "note" => "AI assistant logs"],
        ];

        $sections[] = section("System Summary", ["Metric", "Value"], [
            ["Metric" => "Total travellers", "Value" => $commonSummary["Travellers"]],
            ["Metric" => "Preference records", "Value" => $commonSummary["Preferences"]],
            ["Metric" => "Generated itineraries", "Value" => $commonSummary["Generated Trips"]],
            ["Metric" => "Active cultural places", "Value" => $commonSummary["Active Places"]],
            ["Metric" => "Shared itinerary links", "Value" => table_exists($conn, "shared_itineraries") ? scalar_query($conn, "SELECT COUNT(*) FROM shared_itineraries WHERE is_active=1") : 0],
        ]);
        $sections[] = section("Monthly Trip Generation", ["Month", "Trips", "Total Cost"], money_rows(rows_query($conn, "
            SELECT DATE_FORMAT(i.created_at, '%Y-%m') AS Month, COUNT(*) AS Trips, COALESCE(SUM(i.total_estimated_cost),0) AS `Total Cost`
            FROM itineraries i WHERE 1=1$itDate GROUP BY DATE_FORMAT(i.created_at, '%Y-%m') ORDER BY Month DESC LIMIT 12
        "), ["Total Cost"]));
        if ($hasSuggestions) {
            $sections[] = section("Content Suggestion Status", ["Status", "Total"], rows_query($conn, "
                SELECT status AS Status, COUNT(*) AS Total FROM cultural_place_suggestions s WHERE 1=1" . date_filter_sql($conn, "s", $from, $to) . " GROUP BY status ORDER BY Total DESC
            "));
        }
        if ($hasReviews) {
            $sections[] = section("Top Reviewed Places", ["Place", "State", "Reviews", "Average Rating"], rows_query($conn, "
                SELECT cp.name AS Place, cp.state AS State, COUNT(rr.review_id) AS Reviews, ROUND(AVG(rr.rating),2) AS `Average Rating`
                FROM ratings_reviews rr JOIN cultural_places cp ON cp.place_id=rr.place_id
                WHERE 1=1" . date_filter_sql($conn, "rr", $from, $to) . "
                GROUP BY cp.place_id, cp.name, cp.state ORDER BY Reviews DESC, `Average Rating` DESC LIMIT 10
            "));
        }
    } elseif ($type === "user_preferences") {
        $title = "User Preference Analysis Report";
        $description = "Analyzes what users prefer: highest and lowest interests, states, districts, transport modes, budgets, and trip duration.";
        $prefRows = $hasPreferences ? rows_query($conn, "SELECT preferred_districts FROM traveller_preferences tp WHERE 1=1$tpDate") : [];
        $interestAll = $hasPreferences ? preference_interest_frequency($conn, $tpDate) : [];
        $stateAll = $hasPreferences ? preference_state_frequency($conn, $tpDate) : [];
        $districtAll = csv_frequency($prefRows, "preferred_districts", "District");
        $transportRows = $hasPreferences ? rows_query($conn, "
            SELECT COALESCE(NULLIF(transport_type,''),'not specified') AS Transport, COUNT(*) AS Total, ROUND(AVG(budget),2) AS `Average Budget`
            FROM traveller_preferences tp WHERE 1=1$tpDate GROUP BY COALESCE(NULLIF(transport_type,''),'not specified') ORDER BY Total DESC
        ") : [];
        $budgetRows = $hasPreferences ? money_rows(rows_query($conn, "
            SELECT 
              CASE
                WHEN budget < 300 THEN 'Budget below RM300'
                WHEN budget BETWEEN 300 AND 799 THEN 'Budget RM300-RM799'
                WHEN budget BETWEEN 800 AND 1499 THEN 'Budget RM800-RM1499'
                ELSE 'Budget RM1500+'
              END AS `Budget Range`,
              COUNT(*) AS Total,
              ROUND(AVG(budget),2) AS `Average Budget`,
              MIN(budget) AS `Lowest Budget`,
              MAX(budget) AS `Highest Budget`
            FROM traveller_preferences tp WHERE 1=1$tpDate
            GROUP BY `Budget Range` ORDER BY Total DESC
        "), ["Average Budget", "Lowest Budget", "Highest Budget"]) : [];

        $kpis = [
            ["label" => "Preference Records", "value" => $commonSummary["Preferences"], "note" => "Selected period"],
            ["label" => "Top Interest", "value" => $interestAll[0]["Interest"] ?? "-", "note" => (($interestAll[0]["total"] ?? 0) . " selection(s)")],
            ["label" => "Top Desired State", "value" => $stateAll[0]["State"] ?? "-", "note" => (($stateAll[0]["total"] ?? 0) . " selection(s)")],
            ["label" => "Average Budget", "value" => rm(scalar_query($conn, "SELECT AVG(budget) FROM traveller_preferences tp WHERE 1=1$tpDate", 0)), "note" => "Across preference records"],
        ];
        $sections[] = section("Highest User Interests", ["Interest", "Total"], top_rows($interestAll), "Most selected interests.");
        $sections[] = section("Lowest User Interests", ["Interest", "Total"], bottom_rows($interestAll), "Least selected interests that may need content improvement.");
        $sections[] = section("Highest Desired States", ["State", "Total"], top_rows($stateAll), "Regions tourists most want to visit.");
        $sections[] = section("Lowest Desired States", ["State", "Total"], bottom_rows($stateAll, 10, true), "Regions with lower demand, including states with zero user selections.");
        $sections[] = section("Highest Desired Districts", ["District", "Total"], top_rows($districtAll), "District-level demand from user preferences.");
        $sections[] = section("Lowest Desired Districts", ["District", "Total"], bottom_rows($districtAll), "Districts with lower preference demand.");
        $sections[] = section("Transport Preference Analysis", ["Transport", "Total", "Average Budget"], $transportRows);
        $sections[] = section("Budget Range Analysis", ["Budget Range", "Total", "Average Budget", "Lowest Budget", "Highest Budget"], $budgetRows);
        $sections[] = section("Trip Duration Preference", ["Trip Days", "Total", "Average Budget"], money_rows(rows_query($conn, "
            SELECT trip_days AS `Trip Days`, COUNT(*) AS Total, ROUND(AVG(budget),2) AS `Average Budget`
            FROM traveller_preferences tp WHERE 1=1$tpDate GROUP BY trip_days ORDER BY `Trip Days` ASC
        "), ["Average Budget"]));
    } elseif ($type === "destination_demand") {
        $title = "Destination Demand Report";
        $description = "Compares desired destinations from user preferences with actual generated itinerary destinations.";
        $prefRows = $hasPreferences ? rows_query($conn, "SELECT preferred_districts FROM traveller_preferences tp WHERE 1=1$tpDate") : [];
        $stateAll = $hasPreferences ? preference_state_frequency($conn, $tpDate) : [];
        $districtAll = csv_frequency($prefRows, "preferred_districts", "District");
        $actualStates = rows_query($conn, "
            SELECT cp.state AS State, COUNT(*) AS `Itinerary Items`, COUNT(DISTINCT i.itinerary_id) AS Itineraries
            FROM itinerary_items ii JOIN itineraries i ON i.itinerary_id=ii.itinerary_id JOIN cultural_places cp ON cp.place_id=ii.place_id
            WHERE cp.state IS NOT NULL AND cp.state<>''$itDate
            GROUP BY cp.state ORDER BY `Itinerary Items` DESC
        ");
        $actualDistricts = rows_query($conn, "
            SELECT {$cpDistrict} AS District, cp.state AS State, COUNT(*) AS `Itinerary Items`
            FROM itinerary_items ii JOIN itineraries i ON i.itinerary_id=ii.itinerary_id JOIN cultural_places cp ON cp.place_id=ii.place_id
            WHERE {$cpDistrict} IS NOT NULL AND {$cpDistrict}<>''$itDate
            GROUP BY {$cpDistrict}, cp.state ORDER BY `Itinerary Items` DESC
        ");
        $kpis = [
            ["label" => "Most Desired State", "value" => $stateAll[0]["State"] ?? "-", "note" => (($stateAll[0]["total"] ?? 0) . " preference selection(s)")],
            ["label" => "Least Desired State", "value" => bottom_rows($stateAll, 1, true)[0]["State"] ?? "-", "note" => ((bottom_rows($stateAll, 1, true)[0]["total"] ?? 0) . " selection(s)")],
            ["label" => "Most Generated State", "value" => $actualStates[0]["State"] ?? "-", "note" => (($actualStates[0]["Itinerary Items"] ?? 0) . " item(s)")],
            ["label" => "Destination Records", "value" => count($actualStates), "note" => "States appearing in generated routes"],
        ];
        $sections[] = section("Highest Desired States from Users", ["State", "Total"], top_rows($stateAll));
        $sections[] = section("Lowest Desired States from Users", ["State", "Total"], bottom_rows($stateAll, 10, true));
        $sections[] = section("Highest Desired Districts from Users", ["District", "Total"], top_rows($districtAll));
        $sections[] = section("Lowest Desired Districts from Users", ["District", "Total"], bottom_rows($districtAll));
        $sections[] = section("Actual Generated Destination States", ["State", "Itinerary Items", "Itineraries"], $actualStates);
        $sections[] = section("Actual Generated Destination Districts", ["District", "State", "Itinerary Items"], $actualDistricts);
    } elseif ($type === "attraction_price") {
        $title = "Attraction and Price Report";
        $description = "Analyzes attraction records, category coverage, usage frequency, free/paid places, highest and lowest entrance prices.";
        $categoryPrice = money_rows(rows_query($conn, "
            SELECT cp.category AS Category, COUNT(*) AS Places, SUM(CASE WHEN cp.is_active=1 THEN 1 ELSE 0 END) AS Active,
                   ROUND(AVG($priceExpr),2) AS `Average Price`, MIN($priceExpr) AS `Lowest Price`, MAX($priceExpr) AS `Highest Price`
            FROM cultural_places cp GROUP BY cp.category ORDER BY Places DESC
        "), ["Average Price", "Lowest Price", "Highest Price"]);
        $popularPlaces = money_rows(rows_query($conn, "
            SELECT cp.name AS Place, cp.state AS State, {$cpDistrict} AS District, cp.category AS Category, COUNT(*) AS Used, $priceExpr AS Price
            FROM itinerary_items ii JOIN itineraries i ON i.itinerary_id=ii.itinerary_id JOIN cultural_places cp ON cp.place_id=ii.place_id
            WHERE ii.place_id IS NOT NULL$itDate
            GROUP BY cp.place_id, cp.name, cp.state, {$cpDistrict}, cp.category, Price
            ORDER BY Used DESC LIMIT 20
        "), ["Price"]);
        $expensive = money_rows(rows_query($conn, "
            SELECT cp.name AS Place, cp.state AS State, {$cpDistrict} AS District, cp.category AS Category, $priceExpr AS Price
            FROM cultural_places cp WHERE cp.is_active=1 ORDER BY Price DESC, cp.name ASC LIMIT 20
        "), ["Price"]);
        $cheapest = money_rows(rows_query($conn, "
            SELECT cp.name AS Place, cp.state AS State, {$cpDistrict} AS District, cp.category AS Category, $priceExpr AS Price
            FROM cultural_places cp WHERE cp.is_active=1 ORDER BY Price ASC, cp.name ASC LIMIT 20
        "), ["Price"]);
        $kpis = [
            ["label" => "Active Attractions", "value" => $commonSummary["Active Places"], "note" => "Cultural place records"],
            ["label" => "Free Places", "value" => scalar_query($conn, "SELECT COUNT(*) FROM cultural_places cp WHERE cp.is_active=1 AND $priceExpr <= 0"), "note" => "Price RM0"],
            ["label" => "Highest Price", "value" => rm(scalar_query($conn, "SELECT MAX($priceExpr) FROM cultural_places cp WHERE cp.is_active=1", 0)), "note" => "Current database"],
            ["label" => "Average Price", "value" => rm(scalar_query($conn, "SELECT AVG($priceExpr) FROM cultural_places cp WHERE cp.is_active=1", 0)), "note" => "Across active places"],
        ];
        $sections[] = section("Category and Price Summary", ["Category", "Places", "Active", "Average Price", "Lowest Price", "Highest Price"], $categoryPrice);
        $sections[] = section("Most Used Attractions in Itineraries", ["Place", "State", "District", "Category", "Used", "Price"], $popularPlaces);
        $sections[] = section("Highest Price Attractions", ["Place", "State", "District", "Category", "Price"], $expensive);
        $sections[] = section("Lowest Price Attractions", ["Place", "State", "District", "Category", "Price"], $cheapest);
        $sections[] = section("Data Completeness Check", ["Issue", "Total"], [
            ["Issue" => "Places without coordinates", "Total" => scalar_query($conn, "SELECT COUNT(*) FROM cultural_places WHERE latitude IS NULL OR longitude IS NULL OR (latitude=0 AND longitude=0)")],
            ["Issue" => "Places without image", "Total" => scalar_query($conn, "SELECT COUNT(*) FROM cultural_places WHERE COALESCE(image_url,'')=''")],
            ["Issue" => "Places without opening hours", "Total" => scalar_query($conn, "SELECT COUNT(*) FROM cultural_places WHERE COALESCE(opening_hours,'')=''")],
            ["Issue" => "Places without visit duration", "Total" => scalar_query($conn, "SELECT COUNT(*) FROM cultural_places WHERE visit_duration_min IS NULL OR visit_duration_min<=0")],
        ]);
    } elseif ($type === "cost_budget") {
        $title = "Cost and Budget Report";
        $description = "Analyzes generated itinerary prices, user budgets, highest and lowest trip costs, hotel cost, and budget fit.";
        $budgetFit = money_rows(rows_query($conn, "
            SELECT
              CASE
                WHEN tp.budget IS NULL OR tp.budget <= 0 THEN 'No budget'
                WHEN i.total_estimated_cost <= tp.budget THEN 'Within budget'
                ELSE 'Over budget'
              END AS Status,
              COUNT(*) AS Trips,
              ROUND(AVG(i.total_estimated_cost),2) AS `Average Trip Cost`,
              ROUND(AVG(tp.budget),2) AS `Average User Budget`
            FROM itineraries i LEFT JOIN traveller_preferences tp ON tp.preference_id=i.preference_id
            WHERE 1=1$itDate GROUP BY Status ORDER BY Trips DESC
        "), ["Average Trip Cost", "Average User Budget"]);
        $highestTrips = money_rows(rows_query($conn, "
            SELECT i.title AS Itinerary, t.full_name AS Traveller, i.total_days AS Days, i.total_estimated_cost AS Cost, tp.budget AS Budget, i.created_at AS Created
            FROM itineraries i LEFT JOIN travellers t ON t.traveller_id=i.traveller_id LEFT JOIN traveller_preferences tp ON tp.preference_id=i.preference_id
            WHERE 1=1$itDate ORDER BY i.total_estimated_cost DESC LIMIT 15
        "), ["Cost", "Budget"]);
        $lowestTrips = money_rows(rows_query($conn, "
            SELECT i.title AS Itinerary, t.full_name AS Traveller, i.total_days AS Days, i.total_estimated_cost AS Cost, tp.budget AS Budget, i.created_at AS Created
            FROM itineraries i LEFT JOIN travellers t ON t.traveller_id=i.traveller_id LEFT JOIN traveller_preferences tp ON tp.preference_id=i.preference_id
            WHERE 1=1$itDate ORDER BY i.total_estimated_cost ASC LIMIT 15
        "), ["Cost", "Budget"]);
        $hotelRows = $hasSelectedHotel ? money_rows(rows_query($conn, "
            SELECT COALESCE(i.selected_hotel_name,'No hotel selected') AS Hotel, COUNT(*) AS Trips, SUM(i.selected_hotel_total_cost) AS `Total Hotel Cost`, AVG(i.selected_hotel_total_cost) AS `Average Hotel Cost`
            FROM itineraries i WHERE 1=1$itDate GROUP BY COALESCE(i.selected_hotel_name,'No hotel selected') ORDER BY Trips DESC
        "), ["Total Hotel Cost", "Average Hotel Cost"]) : [];
        $kpis = [
            ["label" => "Average Trip Cost", "value" => rm(scalar_query($conn, "SELECT AVG(i.total_estimated_cost) FROM itineraries i WHERE 1=1$itDate", 0)), "note" => "Generated itinerary total"],
            ["label" => "Lowest Trip Cost", "value" => rm(scalar_query($conn, "SELECT MIN(i.total_estimated_cost) FROM itineraries i WHERE 1=1$itDate", 0)), "note" => "Selected period"],
            ["label" => "Highest Trip Cost", "value" => rm(scalar_query($conn, "SELECT MAX(i.total_estimated_cost) FROM itineraries i WHERE 1=1$itDate", 0)), "note" => "Selected period"],
            ["label" => "Over Budget Trips", "value" => scalar_query($conn, "SELECT COUNT(*) FROM itineraries i JOIN traveller_preferences tp ON tp.preference_id=i.preference_id WHERE tp.budget > 0 AND i.total_estimated_cost > tp.budget$itDate"), "note" => "Budget comparison"],
        ];
        $sections[] = section("Budget Fit Summary", ["Status", "Trips", "Average Trip Cost", "Average User Budget"], $budgetFit);
        $sections[] = section("Highest Cost Itineraries", ["Itinerary", "Traveller", "Days", "Cost", "Budget", "Created"], $highestTrips);
        $sections[] = section("Lowest Cost Itineraries", ["Itinerary", "Traveller", "Days", "Cost", "Budget", "Created"], $lowestTrips);
        if ($hotelRows) $sections[] = section("Hotel Cost Summary", ["Hotel", "Trips", "Total Hotel Cost", "Average Hotel Cost"], $hotelRows);
        $sections[] = section("Monthly Cost Trend", ["Month", "Trips", "Total Cost", "Average Cost"], money_rows(rows_query($conn, "
            SELECT DATE_FORMAT(i.created_at,'%Y-%m') AS Month, COUNT(*) AS Trips, SUM(i.total_estimated_cost) AS `Total Cost`, AVG(i.total_estimated_cost) AS `Average Cost`
            FROM itineraries i WHERE 1=1$itDate GROUP BY DATE_FORMAT(i.created_at,'%Y-%m') ORDER BY Month DESC
        "), ["Total Cost", "Average Cost"]));
    } else {
        $type = "ai_usage";
        $title = "AI Usage Report";
        $description = "Analyzes AI assistant usage by question intent, linked itinerary questions, and most active users.";
        $aiDate = $hasAiLogs ? date_filter_sql($conn, "a", $from, $to) : "";
        $userRows = $hasAiLogs ? rows_query($conn, "
            SELECT COALESCE(t.full_name,'Unknown') AS Traveller, COUNT(*) AS Questions, MAX(a.created_at) AS `Last Question`
            FROM ai_chat_logs a LEFT JOIN travellers t ON t.traveller_id=a.traveller_id
            WHERE 1=1$aiDate GROUP BY COALESCE(t.full_name,'Unknown') ORDER BY Questions DESC LIMIT 20
        ") : [];
        $intentRows = $hasAiLogs ? rows_query($conn, "
            SELECT
              CASE
                WHEN LOWER(user_message) LIKE '%route%' OR user_message LIKE '%路线%' THEN 'Route writing'
                WHEN LOWER(user_message) LIKE '%cost%' OR LOWER(user_message) LIKE '%budget%' OR user_message LIKE '%费用%' THEN 'Cost and budget'
                WHEN LOWER(user_message) LIKE '%culture%' OR user_message LIKE '%文化%' THEN 'Cultural explanation'
                WHEN LOWER(user_message) LIKE '%improve%' OR LOWER(user_message) LIKE '%suggest%' THEN 'Improvement suggestion'
                ELSE 'General question'
              END AS Intent,
              COUNT(*) AS Total
            FROM ai_chat_logs a WHERE 1=1$aiDate GROUP BY Intent ORDER BY Total DESC
        ") : [];
        $kpis = [
            ["label" => "AI Questions", "value" => $hasAiLogs ? scalar_query($conn, "SELECT COUNT(*) FROM ai_chat_logs a WHERE 1=1$aiDate") : 0, "note" => "Selected period"],
            ["label" => "Assistant Responses", "value" => $hasAiLogs ? scalar_query($conn, "SELECT COUNT(*) FROM ai_chat_logs a WHERE 1=1$aiDate") : 0, "note" => "AI replies recorded"],
            ["label" => "Itinerary Questions", "value" => $hasAiLogs ? scalar_query($conn, "SELECT COUNT(*) FROM ai_chat_logs a INNER JOIN itineraries i ON i.itinerary_id=a.itinerary_id WHERE i.title IS NOT NULL AND i.title <> ''$aiDate") : 0, "note" => "Questions linked to real itineraries"],
            ["label" => "Active AI Users", "value" => $hasAiLogs ? scalar_query($conn, "SELECT COUNT(DISTINCT traveller_id) FROM ai_chat_logs a WHERE 1=1$aiDate") : 0, "note" => "Travellers using AI"],
        ];
        $sections[] = section("AI Question Intent Summary", ["Intent", "Total"], $intentRows);
        $sections[] = section("Most Active AI Users", ["Traveller", "Questions", "Last Question"], $userRows);
    }

    $raw["kpis"] = $kpis;
    $raw["sections"] = $sections;
    return ["type" => $type, "title" => $title, "description" => $description, "kpis" => $kpis, "sections" => $sections, "raw" => $raw];
}

function build_ai_analysis(array $report): array
{
    $model = defined("OLLAMA_MODEL") ? OLLAMA_MODEL : "qwen2.5:3b";
    $baseUrl = defined("OLLAMA_BASE_URL") ? OLLAMA_BASE_URL : "http://127.0.0.1:11434";
    $svc = new AiAdminReportAnalysisService($model, $baseUrl);
    $ai = $svc->analyze($report["raw"]);
    $ai["analysis"] = clean_ai_report_text((string)($ai["analysis"] ?? ""));
    return $ai;
}

function clean_ai_report_text(string $text): string
{
    $text = str_replace(["**", "***", "__"], "", $text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $clean = [];
    foreach ($lines as $line) {
        $line = preg_replace('/^\s{0,3}#{1,6}\s*/', '', (string)$line);
        $line = preg_replace('/^\s*[-*]\s+/', '- ', (string)$line);
        $clean[] = rtrim((string)$line);
    }
    return trim(implode("\n", $clean));
}

function log_generated_ai_report(mysqli $conn, int $adminId, array $report, array $ai, string $period): void
{
    if (!table_exists($conn, "audit_logs")) return;

    $details = json_encode([
        "report_type" => $report["type"] ?? "",
        "report_title" => $report["title"] ?? "",
        "period" => $period,
        "summary" => function_exists("mb_substr")
            ? mb_substr((string)($ai["analysis"] ?? ""), 0, 600)
            : substr((string)($ai["analysis"] ?? ""), 0, 600),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $action = "generate_ai_report";
    $entityType = "admin_report";
    $stmt = $conn->prepare("
        INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, NULL, ?)
    ");
    if ($stmt) {
        $stmt->bind_param("isss", $adminId, $action, $entityType, $details);
        $stmt->execute();
        $stmt->close();
    }
}

function render_table(array $section): void
{
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($section["headers"] as $header) echo '<th>' . h($header) . '</th>';
    echo '</tr></thead><tbody>';
    if (empty($section["rows"])) {
        echo '<tr><td colspan="' . count($section["headers"]) . '">No data available for this report.</td></tr>';
    } else {
        foreach ($section["rows"] as $row) {
            echo '<tr>';
            foreach ($section["headers"] as $header) echo '<td>' . h($row[$header] ?? '') . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></div>';
}

function chart_number($value): ?float
{
    if (is_int($value) || is_float($value)) return (float)$value;
    $clean = preg_replace('/[^0-9.\-]/', '', (string)$value);
    if ($clean === '' || $clean === '-' || $clean === '.') return null;
    return is_numeric($clean) ? (float)$clean : null;
}

function chart_for_section(array $section): ?array
{
    $rows = array_values(array_filter($section["rows"] ?? [], fn($row) => is_array($row)));
    $headers = $section["headers"] ?? [];
    if (count($rows) < 1 || count($headers) < 2) return null;

    $labelHeader = (string)$headers[0];
    $valueHeader = "";
    foreach (array_slice($headers, 1) as $header) {
        foreach ($rows as $row) {
            if (chart_number($row[$header] ?? null) !== null) {
                $valueHeader = (string)$header;
                break 2;
            }
        }
    }
    if ($valueHeader === "") return null;

    $labels = [];
    $values = [];
    foreach (array_slice($rows, 0, 8) as $row) {
        $value = chart_number($row[$valueHeader] ?? null);
        if ($value === null) continue;
        $label = trim((string)($row[$labelHeader] ?? ""));
        $labels[] = $label !== "" ? $label : "Unknown";
        $values[] = $value;
    }

    if (empty($labels)) return null;

    return [
        "title" => (string)$section["title"],
        "label" => $valueHeader,
        "labels" => $labels,
        "values" => $values,
        "type" => count($labels) <= 5 ? "doughnut" : "bar",
    ];
}

function build_report_charts(array $report): array
{
    $charts = [];
    foreach ($report["sections"] as $section) {
        $chart = chart_for_section($section);
        if ($chart === null) continue;
        $charts[] = $chart;
        if (count($charts) >= 4) break;
    }
    return $charts;
}

function build_pdf_html(array $report, ?array $ai, string $period, string $adminName): string
{
    ob_start();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, Arial, sans-serif; color:#0f172a; font-size:12px; }
        h1 { font-size:22px; margin:0 0 4px; }
        h2 { font-size:15px; margin:18px 0 8px; }
        .muted { color:#64748b; }
        .kpis { width:100%; border-collapse:collapse; margin:14px 0; }
        .kpis td { border:1px solid #e2e8f0; padding:10px; width:25%; }
        .label { color:#64748b; font-size:10px; text-transform:uppercase; }
        .value { font-size:17px; font-weight:bold; margin-top:4px; }
        table { width:100%; border-collapse:collapse; margin-bottom:12px; }
        th, td { border:1px solid #e2e8f0; padding:6px 7px; text-align:left; }
        th { background:#f1f5f9; font-size:10px; text-transform:uppercase; }
        .analysis { white-space:pre-wrap; border:1px solid #e2e8f0; background:#f8fafc; padding:10px; line-height:1.45; }
    </style>
</head>
<body>
    <h1><?php echo h($report["title"]); ?></h1>
    <div class="muted">Period: <?php echo h($period); ?> | Generated by: <?php echo h($adminName); ?> | Generated at: <?php echo date("Y-m-d H:i"); ?></div>
    <p><?php echo h($report["description"]); ?></p>
    <table class="kpis"><tr>
        <?php foreach ($report["kpis"] as $kpi): ?>
        <td><div class="label"><?php echo h($kpi["label"]); ?></div><div class="value"><?php echo h($kpi["value"]); ?></div><div class="muted"><?php echo h($kpi["note"] ?? ""); ?></div></td>
        <?php endforeach; ?>
    </tr></table>
    <?php if ($ai !== null): ?>
        <h2>AI Analysis</h2>
        <div class="analysis"><?php echo h($ai["analysis"] ?? ""); ?></div>
    <?php endif; ?>
    <?php foreach ($report["sections"] as $section): ?>
        <h2><?php echo h($section["title"]); ?></h2>
        <?php if (!empty($section["note"])): ?><div class="muted"><?php echo h($section["note"]); ?></div><?php endif; ?>
        <table><thead><tr>
            <?php foreach ($section["headers"] as $header): ?><th><?php echo h($header); ?></th><?php endforeach; ?>
        </tr></thead><tbody>
            <?php if (empty($section["rows"])): ?>
                <tr><td colspan="<?php echo count($section["headers"]); ?>">No data available for this report.</td></tr>
            <?php else: foreach ($section["rows"] as $row): ?>
                <tr><?php foreach ($section["headers"] as $header): ?><td><?php echo h($row[$header] ?? ""); ?></td><?php endforeach; ?></tr>
            <?php endforeach; endif; ?>
        </tbody></table>
    <?php endforeach; ?>
</body>
</html>
<?php
    return ob_get_clean();
}

$reportTypes = report_options();
$request = ($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" ? $_POST : $_GET;
$type = strtolower(trim((string)($request["type"] ?? "user_preferences")));
if (!isset($reportTypes[$type])) $type = "user_preferences";
$from = valid_date($request["from"] ?? "");
$to = valid_date($request["to"] ?? "");
if ($from !== "" && $to !== "" && $from > $to) [$from, $to] = [$to, $from];
$period = report_period_label($from, $to);
$export = strtolower(trim((string)($_GET["export"] ?? "")));

$report = build_report($conn, $type, $from, $to, $period);
$ai = null;
$aiGenerated = false;
if (($_SERVER["REQUEST_METHOD"] ?? "GET") === "POST" && ($_POST["action"] ?? "") === "generate_ai_report") {
    $ai = build_ai_analysis($report);
    $aiGenerated = true;
    log_generated_ai_report($conn, (int)($_SESSION["admin_id"] ?? 0), $report, $ai, $period);
}
$charts = build_report_charts($report);

if ($export === "pdf") {
    $autoload = __DIR__ . "/../vendor/autoload.php";
    if (!file_exists($autoload)) die("Dompdf is not installed. Run composer install first.");
    require_once $autoload;
    $dompdf = new \Dompdf\Dompdf(["isRemoteEnabled" => true]);
    $dompdf->loadHtml(build_pdf_html($report, null, $period, $adminName));
    $dompdf->setPaper("A4", "portrait");
    $dompdf->render();
    $dompdf->stream($report["type"] . "_report_" . date("Ymd_His") . ".pdf", ["Attachment" => true]);
    exit;
}

if ($export === "csv") {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=" . $report["type"] . "_report_" . date("Ymd_His") . ".csv");
    $out = fopen("php://output", "w");
    fputcsv($out, [$report["title"]]);
    fputcsv($out, ["Period", $period]);
    fputcsv($out, ["Generated By", $adminName]);
    fputcsv($out, []);
    fputcsv($out, ["KPI", "Value", "Note"]);
    foreach ($report["kpis"] as $kpi) fputcsv($out, [$kpi["label"], $kpi["value"], $kpi["note"] ?? ""]);
    foreach ($report["sections"] as $section) {
        fputcsv($out, []);
        fputcsv($out, [$section["title"]]);
        if (!empty($section["note"])) fputcsv($out, [$section["note"]]);
        fputcsv($out, $section["headers"]);
        foreach ($section["rows"] as $row) {
            $line = [];
            foreach ($section["headers"] as $header) $line[] = $row[$header] ?? "";
            fputcsv($out, $line);
        }
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Reports | Admin</title>
    <link rel="stylesheet" href="../assets/dashboard_style.css">
    <style>
        .report-filter { display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
        .report-filter label { display:block; font-size:12px; font-weight:800; color:#475569; margin-bottom:5px; }
        .report-filter input, .report-filter select { padding:9px 11px; border:1px solid rgba(15,23,42,.14); border-radius:10px; background:#fff; }
        .report-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-top:12px; }
        .report-tabs a { padding:8px 11px; border:1px solid rgba(15,23,42,.12); border-radius:999px; font-size:12px; font-weight:800; color:#334155; text-decoration:none; }
        .report-tabs a.active { background:#0f172a; color:#fff; border-color:#0f172a; }
        .metric-note { font-size:12px; color:#64748b; margin-top:6px; }
        .ai-analysis-box { white-space:pre-wrap; line-height:1.55; font-size:13px; color:#334155; background:#f8fafc; border:1px solid rgba(15,23,42,.08); border-radius:10px; padding:14px; }
        .ai-generate-panel { display:flex; align-items:center; justify-content:space-between; gap:14px; flex-wrap:wrap; }
        .ai-generate-panel form { margin:0; }
        .chart-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:14px; align-items:start; }
        .chart-panel { border:1px solid rgba(15,23,42,.08); border-radius:10px; padding:16px 20px; background:#fff; min-height:0; overflow:visible; display:flex; flex-direction:column; }
        .chart-title { font-size:13px; font-weight:900; color:#0f172a; margin-bottom:10px; }
        .chart-box { position:relative; height:200px; max-height:200px; flex:0 0 200px; }
        .chart-box-doughnut { width:min(100%, 320px); margin:0 auto; }
        .chart-box-bar { height:200px; max-height:200px; }
        .chart-box canvas { max-width:100%; max-height:100%; }
        .chart-data-list { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); column-gap:18px; row-gap:7px; margin-top:14px; overflow:visible; padding-right:0; }
        .chart-data-row { display:grid; grid-template-columns:12px minmax(0, 1fr) auto auto; gap:8px; align-items:center; font-size:12px; color:#334155; }
        .chart-data-row span:nth-child(2) { min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .chart-dot { width:10px; height:10px; border-radius:50%; }
        .chart-percent { min-width:52px; text-align:right; font-weight:900; color:#0f172a; }
        .chart-value { min-width:58px; text-align:right; color:#64748b; }
        .chart-empty { display:none; color:#64748b; font-size:13px; padding:12px; background:#f8fafc; border-radius:8px; }
        @media (max-width: 900px) { .chart-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="app">
    <aside class="sidebar">
        <div class="brand">
            <div class="brand-badge">ST</div>
            <div class="brand-title">
                <strong>Smart Travel Itinerary Generator</strong>
                <span>Reports</span>
            </div>
        </div>
        <nav class="nav" aria-label="Sidebar Navigation">
            <a href="../admin/admin_dashboard.php"><span class="dot"></span> Dashboard</a>
            <a href="../admin/admin_cultural_kb.php"><span class="dot"></span> State Cultural Knowledge Base</a>
            <a href="../admin/admin_pending.php"><span class="dot"></span> Content Validation</a>
            <a href="../admin/user_manage/index.php"><span class="dot"></span> User Management</a>
            <a class="active" href="../admin/admin_reports.php"><span class="dot"></span> Reports</a>
            <a href="../auth/logout.php"><span class="dot"></span> Logout</a>
        </nav>
        <div class="sidebar-footer">
            <div class="small">Logged in as:</div>
            <div style="margin-top:6px; font-weight:800;"><?php echo h($adminName); ?></div>
            <div class="chip">Role: Admin</div>
        </div>
    </aside>

    <main class="content">
        <div class="topbar">
            <div class="page-title">
                <h1><?php echo h($report["title"]); ?></h1>
                <p><?php echo h($report["description"]); ?></p>
            </div>
            <div class="actions">
                <a class="btn btn-ghost" href="../admin/admin_dashboard.php">Back</a>
                <a class="btn btn-primary" href="?type=<?php echo h($type); ?>&from=<?php echo h($from); ?>&to=<?php echo h($to); ?>&export=pdf">Export PDF</a>
                <a class="btn btn-ghost" href="?type=<?php echo h($type); ?>&from=<?php echo h($from); ?>&to=<?php echo h($to); ?>&export=csv">Export CSV</a>
            </div>
        </div>

        <section class="grid">
            <div class="card col-12">
                <h3>Report Generator</h3>
                <p class="meta">Current period: <?php echo h($period); ?>. This page shows database report data first. AI analysis is generated only after admin confirmation.</p>
                <form method="get" class="report-filter">
                    <div>
                        <label for="type">Report Type</label>
                        <select id="type" name="type">
                            <?php foreach ($reportTypes as $key => $label): ?>
                                <option value="<?php echo h($key); ?>" <?php echo $key === $type ? "selected" : ""; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div><label for="from">From</label><input id="from" type="date" name="from" value="<?php echo h($from); ?>"></div>
                    <div><label for="to">To</label><input id="to" type="date" name="to" value="<?php echo h($to); ?>"></div>
                    <button class="btn btn-primary" type="submit">Load Data Report</button>
                    <a class="btn btn-ghost" href="admin_reports.php?type=<?php echo h($type); ?>">Reset Dates</a>
                </form>
                <div class="report-tabs">
                    <?php foreach ($reportTypes as $key => $label): ?>
                        <a class="<?php echo $key === $type ? "active" : ""; ?>" href="?type=<?php echo h($key); ?>&from=<?php echo h($from); ?>&to=<?php echo h($to); ?>"><?php echo h($label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php foreach ($report["kpis"] as $kpi): ?>
            <div class="card col-3">
                <h3><?php echo h($kpi["label"]); ?></h3>
                <div class="kpi"><div class="value" style="font-size:24px;"><?php echo h($kpi["value"]); ?></div><div class="tag">Report KPI</div></div>
                <div class="metric-note"><?php echo h($kpi["note"] ?? ""); ?></div>
            </div>
            <?php endforeach; ?>

            <div class="card col-12">
                <div class="ai-generate-panel">
                    <div>
                        <h3>AI Generated Report</h3>
                        <p class="meta">Click the button only when you want AI to summarize and analyze the current database report.</p>
                    </div>
                    <form method="post">
                        <input type="hidden" name="action" value="generate_ai_report">
                        <input type="hidden" name="type" value="<?php echo h($type); ?>">
                        <input type="hidden" name="from" value="<?php echo h($from); ?>">
                        <input type="hidden" name="to" value="<?php echo h($to); ?>">
                        <button class="btn btn-primary" type="submit">Generate AI Report</button>
                    </form>
                </div>
                <?php if ($aiGenerated && $ai !== null): ?>
                    <p class="meta">Generated from this report's database result set.</p>
                    <div class="ai-analysis-box"><?php echo h($ai["analysis"] ?? ""); ?></div>
                <?php else: ?>
                    <div class="ai-analysis-box">AI report has not been generated yet. The database tables and charts below are available without AI processing.</div>
                <?php endif; ?>
            </div>

            <?php if (!empty($charts)): ?>
            <div class="card col-12">
                <h3>Report Charts</h3>
                <p class="meta">Visual summary generated from the same database tables below.</p>
                <div class="chart-empty" id="chartFallback">Charts could not load. The complete report data is still available in the tables below.</div>
                <div class="chart-grid">
                    <?php foreach ($charts as $index => $chart): ?>
                    <div class="chart-panel">
                        <div class="chart-title"><?php echo h($chart["title"]); ?></div>
                        <div class="chart-box chart-box-<?php echo h($chart["type"] === "doughnut" ? "doughnut" : "bar"); ?>"><canvas id="reportChart<?php echo (int)$index; ?>"></canvas></div>
                        <div class="chart-data-list" id="reportChartData<?php echo (int)$index; ?>"></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php foreach ($report["sections"] as $section): ?>
            <div class="card col-12">
                <h3><?php echo h($section["title"]); ?></h3>
                <?php if (!empty($section["note"])): ?><p class="meta"><?php echo h($section["note"]); ?></p><?php endif; ?>
                <?php render_table($section); ?>
            </div>
            <?php endforeach; ?>
        </section>
    </main>
</div>
<?php if (!empty($charts)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const reportCharts = <?php echo json_encode($charts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
const chartColors = ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#64748b', '#db2777'];
const chartValueLabels = {
    id: 'chartValueLabels',
    afterDatasetsDraw(chart) {
        const {ctx} = chart;
        const chartType = chart.config.type;
        const dataset = chart.data.datasets[0];
        const values = dataset.data.map(Number);
        const total = values.reduce((sum, value) => sum + value, 0);
        if (!total) return;
        if (chart.width < 260 || values.length > 8) return;

        ctx.save();
        ctx.font = '700 11px system-ui, -apple-system, Segoe UI, sans-serif';
        ctx.fillStyle = '#0f172a';
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';

        const drawnBoxes = [];
        const canPlaceLabel = (x, y, text) => {
            const padding = 5;
            const width = ctx.measureText(text).width + padding * 2;
            const height = 16;
            const box = {left: x - width / 2, right: x + width / 2, top: y - height / 2, bottom: y + height / 2};
            const area = chart.chartArea;
            if (box.left < area.left || box.right > area.right || box.top < area.top || box.bottom > area.bottom) return false;
            for (const placed of drawnBoxes) {
                const overlaps = !(box.right < placed.left || box.left > placed.right || box.bottom < placed.top || box.top > placed.bottom);
                if (overlaps) return false;
            }
            drawnBoxes.push(box);
            return true;
        };

        chart.getDatasetMeta(0).data.forEach((element, index) => {
            const value = values[index] || 0;
            const percent = ((value / total) * 100).toFixed(1) + '%';
            if (value <= 0) return;

            let label = chartType === 'doughnut' ? percent : `${value} (${percent})`;
            const pos = element.tooltipPosition();
            let x = pos.x;
            let y = pos.y;

            if (chartType === 'bar') {
                const barWidth = Number(element.width || 0);
                if (barWidth > 0 && barWidth < 54) return;
                y = pos.y - 12;
            } else if (chartType === 'doughnut') {
                const slicePercent = value / total;
                const circumference = Number(element.circumference || 0);
                if (slicePercent < 0.12 || circumference < 0.55) return;
            }

            if (canPlaceLabel(x, y, label)) ctx.fillText(label, x, y);
        });
        ctx.restore();
    }
};

function renderChartDataList(chart, index) {
    const list = document.getElementById('reportChartData' + index);
    if (!list) return;
    const values = chart.values.map(Number);
    const total = values.reduce((sum, value) => sum + value, 0);
    const escapeHtml = value => String(value).replace(/[&<>"']/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[char]));
    list.innerHTML = chart.labels.map((label, rowIndex) => {
        const value = values[rowIndex] || 0;
        const percent = total ? ((value / total) * 100).toFixed(1) + '%' : '0.0%';
        const color = chartColors[rowIndex % chartColors.length];
        return `
            <div class="chart-data-row">
                <span class="chart-dot" style="background:${color}"></span>
                <span>${escapeHtml(label)}</span>
                <span class="chart-value">${value}</span>
                <span class="chart-percent">${percent}</span>
            </div>
        `;
    }).join('');
}

function renderReportCharts() {
    if (!window.Chart) {
        const fallback = document.getElementById('chartFallback');
        if (fallback) fallback.style.display = 'block';
        return;
    }

    reportCharts.forEach((chart, index) => {
        const canvas = document.getElementById('reportChart' + index);
        if (!canvas) return;
        renderChartDataList(chart, index);
        new Chart(canvas, {
            type: chart.type === 'doughnut' ? 'doughnut' : 'bar',
            data: {
                labels: chart.labels,
                datasets: [{
                    label: chart.label,
                    data: chart.values,
                    backgroundColor: chartColors,
                    borderColor: '#ffffff',
                    borderWidth: chart.type === 'doughnut' ? 2 : 0,
                    borderRadius: chart.type === 'bar' ? 6 : 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const values = ctx.dataset.data.map(Number);
                                const total = values.reduce((sum, value) => sum + value, 0);
                                const value = Number(ctx.raw || 0);
                                const percent = total ? ((value / total) * 100).toFixed(1) : '0.0';
                                return `${ctx.label}: ${ctx.formattedValue} (${percent}%)`;
                            }
                        }
                    }
                },
                scales: chart.type === 'bar' ? {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: {
                        ticks: {
                            maxRotation: chart.labels.length > 6 ? 35 : 0,
                            minRotation: 0,
                            autoSkip: false,
                            font: { size: chart.labels.length > 7 ? 10 : 11 }
                        }
                    }
                } : {},
                cutout: chart.type === 'doughnut' ? '54%' : undefined
            },
            plugins: [chartValueLabels]
        });
    });
}

renderReportCharts();
</script>
<?php endif; ?>
</body>
</html>
