<?php

declare(strict_types=1);

/**
 * Build a GraphQL query for a contribution graph
 *
 * @param string $user GitHub username to get graphs for
 * @param int $year Year to get graph for
 * @return string GraphQL query
 */
function buildContributionGraphQuery(string $user, int $year): string
{
    $start = "$year-01-01T00:00:00Z";
    $end = "$year-12-31T23:59:59Z";
    return "query {
        user(login: \"$user\") {
            contributionsCollection(from: \"$start\", to: \"$end\") {
                contributionYears
                contributionCalendar {
                    weeks {
                        contributionDays {
                            contributionCount
                            date
                        }
                    }
                }
                commitContributionsByRepository {
                    repository {
                        isFork
                        name
                    }
                    contributions {
                        occurredAt
                        commitCount
                    }
                }
                pullRequestContributionsByRepository {
                    repository {
                        isFork
                        name
                    }
                    contributions {
                        occurredAt
                    }
                }
            }
        }
    }";
}

/**
 * Execute multiple requests with cURL and handle GitHub API rate limits and errors
 *
 * @param string $user GitHub username to get graphs for
 * @param array<int> $years Years to get graphs for
 * @return array<int,stdClass> List of GraphQL response objects with years as keys
 */
function executeContributionGraphRequests(string $user, array $years): array
{
    $tokens = [];
    $requests = [];
    foreach ($years as $year) {
        $tokens[$year] = getGitHubToken();
        $query = buildContributionGraphQuery($user, $year);
        $requests[$year] = getGraphQLCurlHandle($query, $tokens[$year]);
    }
    $multi = curl_multi_init();
    foreach ($requests as $handle) {
        curl_multi_add_handle($multi, $handle);
    }
    $running = null;
    do {
        curl_multi_exec($multi, $running);
    } while ($running);
    $responses = [];
    foreach ($requests as $year => $handle) {
        $contents = curl_multi_getcontent($handle);
        $decoded = is_string($contents) ? json_decode($contents) : null;
        if (empty($decoded) || empty($decoded->data) || !empty($decoded->errors)) {
            error_log("Failed to decode response for $user's $year contributions.");
            continue;
        }
        $responses[$year] = $decoded;
    }
    foreach ($requests as $request) {
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
    return $responses;
}

/**
 * Get contribution data for a user
 *
 * @param array<int,stdClass> $contributionGraphs List of GraphQL response objects by year
 * @return array<string,int> Y-M-D contribution dates with contribution counts
 */
function getContributionDates(array $contributionGraphs): array
{
    $contributions = [];
    foreach ($contributionGraphs as $graph) {
        $weeks = $graph->data->user->contributionsCollection->contributionCalendar->weeks;
        $repositories = $graph->data->user->contributionsCollection->commitContributionsByRepository;
        foreach ($weeks as $week) {
            foreach ($week->contributionDays as $day) {
                $date = $day->date;
                $count = $day->contributionCount;
                $contributions[$date] = ($contributions[$date] ?? 0) + $count;
            }
        }
        foreach ($repositories as $repoContributions) {
            if ($repoContributions->repository->isFork) {
                foreach ($repoContributions->contributions as $contribution) {
                    $date = $contribution->occurredAt;
                    $count = $contribution->commitCount ?? 1;
                    $contributions[$date] = ($contributions[$date] ?? 0) + $count;
                }
            }
        }
    }
    ksort($contributions);
    return $contributions;
}

/**
 * Merge contribution data arrays
 *
 * @param array<string,int> $existingContributions
 * @param array<string,int> $newContributions
 * @return array<string,int>
 */
function mergeContributions(array $existingContributions, array $newContributions): array
{
    foreach ($newContributions as $date => $count) {
        $existingContributions[$date] = ($existingContributions[$date] ?? 0) + $count;
    }
    ksort($existingContributions);
    return $existingContributions;
}

/**
 * Calculate contribution streaks with a grace period
 *
 * @param array<string,int> $contributions Y-M-D contribution dates with contribution counts
 * @param int $graceDays Number of missed days allowed
 * @return array<string,mixed> Streak stats
 */
function getContributionStats(array $contributions, int $graceDays = 1): array
{
    if (empty($contributions)) {
        throw new AssertionError("No contributions found.", 204);
    }
    $today = array_key_last($contributions);
    $first = array_key_first($contributions);
    $stats = [
        "totalContributions" => 0,
        "longestStreak" => ["start" => $first, "end" => $first, "length" => 0],
        "currentStreak" => ["start" => $first, "end" => $first, "length" => 0],
    ];
    $previousDate = null;
    $missedDays = 0;
    foreach ($contributions as $date => $count) {
        $stats["totalContributions"] += $count;
        if ($count > 0) {
            $missedDays = 0;
            ++$stats["currentStreak"]["length"];
            $stats["currentStreak"]["end"] = $date;
            if ($stats["currentStreak"]["length"] > $stats["longestStreak"]["length"]) {
                $stats["longestStreak"] = $stats["currentStreak"];
            }
        } elseif ($previousDate && (strtotime($date) - strtotime($previousDate) > 86400)) {
            ++$missedDays;
            if ($missedDays > $graceDays) {
                $stats["currentStreak"] = ["start" => $today, "end" => $today, "length" => 0];
                $missedDays = 0;
            }
        }
        $previousDate = $date;
    }
    return $stats;
}

/**
 * Fetch contribution graphs for a user
 *
 * @param string $user GitHub username
 * @param int|null $startingYear Start year for contributions
 * @return array<int,stdClass>
 */
function getContributionGraphs(string $user, ?int $startingYear = null): array
{
    $currentYear = intval(date("Y"));
    $years = range($startingYear ?? $currentYear, $currentYear);
    return executeContributionGraphRequests($user, $years);
}
