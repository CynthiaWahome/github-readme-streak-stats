<?php

declare(strict_types=1);

/**
 * Get all GitHub tokens from environment variables
 *
 * @return array<string> GitHub tokens
 */
function getGitHubTokens(): array
{
    $tokens = [];
    // Check both $_ENV and $_SERVER for Vercel compatibility
    $token = $_ENV["TOKEN"] ?? $_SERVER["TOKEN"] ?? getenv("TOKEN") ?: "";
    if (!empty($token)) {
        $tokens = array_map('trim', explode(",", $token));
    }
    return $tokens;
}

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
                    contributions(first: 100) {
                        edges {
                            node {
                                occurredAt
                                commitCount
                            }
                        }
                    }
                }
                pullRequestContributionsByRepository {
                    repository {
                        isFork
                        name
                    }
                    contributions(first: 100) {
                        edges {
                            node {
                                occurredAt
                            }
                        }
                    }
                }
            }
        }
    }";
}

/**
 * Get a cURL handle for a GraphQL request
 *
 * @param string $query The GraphQL query
 * @param string $token The GitHub token
 * @return CurlHandle The cURL handle
 */
function getGraphQLCurlHandle(string $query, string $token): CurlHandle
{
    $url = "https://api.github.com/graphql";
    $headers = [
        "Authorization: bearer " . $token,
        "Content-Type: application/json",
        "Accept: application/json",
        "User-Agent: GitHub-Readme-Streak-Stats",
    ];
    $body = ["query" => $query];
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    return $ch;
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
    $tokens = getGitHubTokens();
    if (empty($tokens)) {
        throw new InvalidArgumentException("No GitHub tokens found.", 500);
    }
    $requests = [];
    foreach ($years as $year) {
        // get a token for the request
        $token = array_shift($tokens);
        // add the token back to the end of the array
        $tokens[] = $token;
        $query = buildContributionGraphQuery($user, $year);
        $requests[$year] = getGraphQLCurlHandle($query, $token);
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
    $maxRetries = count($tokens);
    foreach ($requests as $year => $handle) {
        $contents = curl_multi_getcontent($handle);
        $decoded = is_string($contents) ? json_decode($contents) : null;
        $retryCount = 0;
        
        // if the request failed, retry with the next token
        while ((empty($decoded) || !empty($decoded->errors) || curl_getinfo($handle, CURLINFO_HTTP_CODE) >= 400) && $retryCount < $maxRetries) {
            // log the error
            $error = !empty($decoded->errors) ? $decoded->errors[0]->message : "Unknown error.";
            $currentToken = $tokens[$retryCount % count($tokens)];
            error_log("Request failed for year $year (attempt " . ($retryCount + 1) . "/$maxRetries): $error");
            
            $retryCount++;
            if ($retryCount < $maxRetries) {
                // get the next token
                $nextToken = $tokens[$retryCount % count($tokens)];
                // rebuild the request with the new token
                $query = buildContributionGraphQuery($user, $year);
                curl_multi_remove_handle($multi, $requests[$year]);
                curl_close($requests[$year]);
                $requests[$year] = getGraphQLCurlHandle($query, $nextToken);
                // re-add the handle to the multi-handle
                curl_multi_add_handle($multi, $requests[$year]);
                // restart the multi-exec
                $running = null;
                do {
                    curl_multi_exec($multi, $running);
                } while ($running);
                // get the new response
                $contents = curl_multi_getcontent($requests[$year]);
                $decoded = is_string($contents) ? json_decode($contents) : null;
            }
        }
        
        if (empty($decoded) || empty($decoded->data) || !empty($decoded->errors)) {
            error_log("Failed to decode response for $user's $year contributions after $retryCount retries.");
            continue;
        }
        $responses[$year] = $decoded;
    }
    foreach ($requests as $request) {
        curl_multi_remove_handle($multi, $handle);
    }
    curl_multi_close($multi);
    
    // Check if we got any valid responses
    if (empty($responses)) {
        throw new InvalidArgumentException("Failed to fetch contribution data. Please check that the username is correct and not an organization, and that the GitHub API is accessible.", 500);
    }
    
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
    $today = date("Y-m-d");
    $tomorrow = date("Y-m-d", strtotime("tomorrow"));
    foreach ($contributionGraphs as $graph) {
        $weeks = $graph->data->user->contributionsCollection->contributionCalendar->weeks;
        foreach ($weeks as $week) {
            foreach ($week->contributionDays as $day) {
                $date = $day->date;
                $count = $day->contributionCount;
                // count contributions up until today
                // also count next day if user contributed already
                if ($date <= $today || ($date == $tomorrow && $count > 0)) {
                    $contributions[$date] = ($contributions[$date] ?? 0) + $count;
                }
            }
        }
        $repositories = $graph->data->user->contributionsCollection->commitContributionsByRepository;
        foreach ($repositories as $repoContributions) {
            if ($repoContributions->repository->isFork) {
                foreach ($repoContributions->contributions->edges as $edge) {
                    $contribution = $edge->node;
                    $date = substr($contribution->occurredAt, 0, 10);
                    $count = $contribution->commitCount ?? 1;
                    $contributions[$date] = ($contributions[$date] ?? 0) + $count;
                }
            }
        }
        $pullRequests = $graph->data->user->contributionsCollection->pullRequestContributionsByRepository;
        foreach ($pullRequests as $repoContributions) {
            if ($repoContributions->repository->isFork) {
                foreach ($repoContributions->contributions->edges as $edge) {
                    $contribution = $edge->node;
                    $date = substr($contribution->occurredAt, 0, 10);
                    $contributions[$date] = ($contributions[$date] ?? 0) + 1;
                }
            }
        }
    }
    ksort($contributions);
    return $contributions;
}

/**
 * Check if a day is an excluded day of the week
 *
 * @param string $date Date to check (Y-m-d)
 * @param array<string> $excludedDays List of days of the week to exclude
 * @return bool True if the day is excluded, false otherwise
 */
function isExcludedDay(string $date, array $excludedDays): bool
{
    if (empty($excludedDays)) {
        return false;
    }
    $day = date("D", strtotime($date)); // "D" = Mon, Tue, Wed, etc.
    return in_array($day, $excludedDays);
}

/**
 * Get a stats array with the contribution count, daily streak, and dates
 *
 * @param array<string,int> $contributions Y-M-D contribution dates with contribution counts
 * @param array<string> $excludedDays List of days of the week to exclude
 * @param int $graceDays Number of consecutive missed days allowed without breaking streak (default: 0)
 * @return array<string,mixed> Streak stats
 */
function getContributionStats(array $contributions, array $excludedDays = [], int $graceDays = 0): array
{
    // if no contributions, display error
    if (empty($contributions)) {
        throw new AssertionError("No contributions found.", 204);
    }
    $today = array_key_last($contributions);
    $first = array_key_first($contributions);
    $stats = [
        "mode" => "daily",
        "totalContributions" => 0,
        "firstContribution" => "",
        "longestStreak" => [
            "start" => $first,
            "end" => $first,
            "length" => 0,
        ],
        "currentStreak" => [
            "start" => $first,
            "end" => $first,
            "length" => 0,
        ],
        "excludedDays" => $excludedDays,
    ];

    $missedDays = 0;

    // calculate the stats from the contributions array
    foreach ($contributions as $date => $count) {
        // add contribution count to total
        $stats["totalContributions"] += $count;
        
        // check if still in streak (contribution made or excluded day)
        $isExcluded = $stats["currentStreak"]["length"] > 0 && isExcludedDay($date, $excludedDays);
        
        if ($count > 0 || $isExcluded) {
            // Reset missed days counter on contribution or excluded day
            $missedDays = 0;
            
            // increment streak
            ++$stats["currentStreak"]["length"];
            $stats["currentStreak"]["end"] = $date;
            
            // set start on first day of streak
            if ($stats["currentStreak"]["length"] == 1) {
                $stats["currentStreak"]["start"] = $date;
            }
            
            // set first contribution date the first time
            if (!$stats["firstContribution"]) {
                $stats["firstContribution"] = $date;
            }
            
            // update longestStreak
            if ($stats["currentStreak"]["length"] > $stats["longestStreak"]["length"]) {
                // copy current streak start, end, and length into longest streak
                $stats["longestStreak"]["start"] = $stats["currentStreak"]["start"];
                $stats["longestStreak"]["end"] = $stats["currentStreak"]["end"];
                $stats["longestStreak"]["length"] = $stats["currentStreak"]["length"];
            }
        }
        // reset streak but give exception for today and grace period
        elseif ($date != $today) {
            $missedDays++;
            
            // Only reset if we've exceeded grace days
            if ($missedDays > $graceDays) {
                // reset streak
                $stats["currentStreak"]["length"] = 0;
                $stats["currentStreak"]["start"] = $today;
                $stats["currentStreak"]["end"] = $today;
                $missedDays = 0;
            }
        }
    }
    return $stats;
}

/**
 * Calculate weekly contribution streaks
 *
 * @param array<string,int> $contributions Y-M-D contribution dates with contribution counts
 * @return array<string,mixed> Streak stats
 */
function getWeeklyContributionStats(array $contributions): array
{
    if (count($contributions) == 0) {
        return [
            "totalContributions" => 0,
            "firstContribution" => "",
            "longestStreak" => [
                "start" => "",
                "end" => "",
                "length" => 0,
            ],
            "currentStreak" => [
                "start" => "",
                "end" => "",
                "length" => 0,
            ],
        ];
    }

    $first = array_key_first($contributions);
    $totalContributions = array_sum($contributions);

    // group contributions by week
    $weeks = [];
    foreach ($contributions as $date => $count) {
        if ($count > 0) {
            $week = date("Y-W", strtotime($date));
            $weeks[$week] = true;
        }
    }

    $weekKeys = array_keys($weeks);
    sort($weekKeys);

    $longestStreak = [
        "start" => "",
        "end" => "",
        "length" => 0,
    ];
    $currentStreak = [
        "start" => "",
        "end" => "",
        "length" => 0,
    ];

    // calculate streaks
    $streak = 0;
    $lastWeek = "";
    foreach ($weekKeys as $week) {
        if ($streak == 0) {
            $streak = 1;
            $currentStreak["start"] = $week;
        } else {
            // check if the weeks are consecutive
            $lastWeekNum = intval(substr($lastWeek, -2));
            $currentWeekNum = intval(substr($week, -2));
            $lastYear = intval(substr($lastWeek, 0, 4));
            $currentYear = intval(substr($week, 0, 4));
            if ($currentYear == $lastYear && $currentWeekNum == $lastWeekNum + 1) {
                $streak++;
            } elseif ($currentYear == $lastYear + 1 && $currentWeekNum == 1 && ($lastWeekNum == 52 || $lastWeekNum == 53)) {
                $streak++;
            } else {
                $streak = 1;
                $currentStreak["start"] = $week;
            }
        }
        $currentStreak["end"] = $week;
        $lastWeek = $week;
        if ($streak > $longestStreak["length"]) {
            $longestStreak["length"] = $streak;
            $longestStreak["start"] = $currentStreak["start"];
            $longestStreak["end"] = $currentStreak["end"];
        }
    }

    // check if current streak is active
    $today = date("Y-m-d");
    $currentWeek = date("Y-W", strtotime($today));
    $lastContributionWeek = date("Y-W", strtotime(array_key_last($contributions)));
    if ($currentWeek != $lastContributionWeek) {
        $currentStreak["length"] = 0;
        $currentStreak["start"] = "";
        $currentStreak["end"] = "";
    } else {
        $currentStreak["length"] = $streak;
    }

    // convert week numbers to dates
    $longestStreak["start"] = date("Y-m-d", strtotime($longestStreak["start"]));
    $longestStreak["end"] = date("Y-m-d", strtotime($longestStreak["end"] . "-7"));
    $currentStreak["start"] = date("Y-m-d", strtotime($currentStreak["start"]));
    $currentStreak["end"] = date("Y-m-d", strtotime($currentStreak["end"] . "-7"));

    return [
        "totalContributions" => $totalContributions,
        "firstContribution" => $first,
        "longestStreak" => $longestStreak,
        "currentStreak" => $currentStreak,
        "mode" => "weekly",
    ];
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
    // get the list of years the user has contributed and the current year's contribution graph
    $currentYear = intval(date("Y"));
    $responses = executeContributionGraphRequests($user, [$currentYear]);

    // get user's created date (YYYY-MM-DDTHH:MM:SSZ format)
    $userCreatedDateTimeString = $responses[$currentYear]->data->user->contributionsCollection->contributionYears[0] . "-01-01T00:00:00Z" ?? null;

    // if there are no contribution years, an API error must have occurred
    if (empty($userCreatedDateTimeString)) {
        throw new AssertionError("Failed to retrieve contributions. This is likely a GitHub API issue.", 500);
    }

    // extract the year from the created datetime string
    $userCreatedYear = intval(explode("-", $userCreatedDateTimeString)[0]);

    // if override parameter is null then define starting year
    // as the user created year; else use the provided override year
    $minimumYear = $startingYear ?: $userCreatedYear;

    // make sure the minimum year is not before 2005 (the year Git was created)
    $minimumYear = max($minimumYear, 2005);

    // create an array of years from the user's created year to one year before the current year
    $yearsToRequest = range($minimumYear, $currentYear - 1);

    // get the contribution graphs for the previous years
    if (!empty($yearsToRequest)) {
        $responses += executeContributionGraphRequests($user, $yearsToRequest);
    }

    return $responses;
}

/**
 * Normalize days of the week
 *
 * @param array<string> $days Days of the week
 * @return array<string> Normalized days of the week
 */
function normalizeDays(array $days): array
{
    return array_map(function ($day) {
        return ucfirst(substr(trim($day), 0, 3));
    }, $days);
}
