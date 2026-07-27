<?php

declare(strict_types=1);

// load functions
require_once __DIR__ . "/../vendor/autoload.php";
require_once "stats.php";
require_once "card.php";

// load .env file if it exists (for local development)
if (file_exists(dirname(__DIR__) . "/.env")) {
    $dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 1));
    $dotenv->safeLoad();
}

// if environment variables are not loaded, display error
$token = $_ENV["TOKEN"] ?? $_SERVER["TOKEN"] ?? getenv("TOKEN") ?: "";
if (empty($token)) {
    $message = "Missing token in config. Check Contributing.md for details.";
    renderOutput($message, 500);
}


// set cache to refresh once per three horus
$cacheMinutes = 3 * 60 * 60;
header("Expires: " . gmdate("D, d M Y H:i:s", time() + $cacheMinutes) . " GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: public, max-age=$cacheMinutes");

// redirect to demo site if user is not given
if (!isset($_REQUEST["user"])) {
    header("Location: demo/");
    exit();
}

try {
    // get streak stats for user given in query string
    $user = preg_replace("/[^a-zA-Z0-9\-]/", "", $_REQUEST["user"]);
    $startingYear = isset($_REQUEST["starting_year"]) ? intval($_REQUEST["starting_year"]) : null;
    $contributionGraphs = getContributionGraphs($user, $startingYear);
    $contributions = getContributionDates($contributionGraphs);

    // TEMPORARY DIAGNOSTIC ENDPOINT — remove once the Jan 2026 streak
    // truncation mystery is resolved. Gated behind DEBUG_CONTRIBUTIONS so
    // it's never active unless explicitly enabled in Vercel's env vars.
    if (getenv("DEBUG_CONTRIBUTIONS") && isset($_GET["debug"])) {
        header("Content-Type: application/json");
        echo json_encode([
            "years_fetched" => array_keys($contributionGraphs),
            "total_merged_days" => count($contributions),
            "merged_first_date" => array_key_first($contributions),
            "merged_last_date" => array_key_last($contributions),
            "zero_count_days" => count(array_filter($contributions, fn($c) => $c === 0)),
            "all_zero_dates" => array_keys(array_filter($contributions, fn($c) => $c === 0)),
        ], JSON_PRETTY_PRINT);
        exit();
    }

    // Get grace period from request (default: 7, max: 7). Defaulting to the
    // max keeps the public-facing URL free of a &grace= param — the value
    // still lives here in code, not visibly tuned in a shared/public link.
    $graceDays = isset($_REQUEST["grace"]) ? max(0, min(7, intval($_REQUEST["grace"]))) : 7;

    if (isset($_GET["mode"]) && $_GET["mode"] === "weekly") {
        $stats = getWeeklyContributionStats($contributions);
    } else {
        // split and normalize excluded days
        $excludeDays = normalizeDays(explode(",", $_GET["exclude_days"] ?? ""));
        $stats = getContributionStats($contributions, $excludeDays, $graceDays);
    }
    renderOutput($stats);
} catch (InvalidArgumentException | AssertionError $error) {
    error_log("Error {$error->getCode()}: {$error->getMessage()}");
    if ($error->getCode() >= 500) {
        error_log($error->getTraceAsString());
    }
    renderOutput($error->getMessage(), $error->getCode());
}
