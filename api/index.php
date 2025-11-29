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
    error_log("TOKEN not found. _ENV: " . (isset($_ENV["TOKEN"]) ? "yes" : "no") . ", _SERVER: " . (isset($_SERVER["TOKEN"]) ? "yes" : "no") . ", getenv: " . (getenv("TOKEN") ? "yes" : "no"));
    $message = "Missing token in config. Token count: " . count(explode(",", $token)) . " Check Contributing.md for details.";
    renderOutput($message, 500);
}
error_log("TOKEN loaded successfully. Token count: " . count(array_filter(array_map('trim', explode(",", $token)))));


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
    
    // Get grace period from request (default: 3, max: 7)
    $graceDays = isset($_REQUEST["grace"]) ? max(0, min(7, intval($_REQUEST["grace"]))) : 3;

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
