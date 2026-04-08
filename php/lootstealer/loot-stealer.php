<?php
/*
    @Application: Loot Stealer
    @Description: This bot controls everything raid-related and lets people know what's going.
    @Version: 1.4.0
*/

//die();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: 0");

// Toggle debug mode, because production is overrated
$debug = True;  // Yes, true. Don't ask why.

if ($debug == false)
{
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// Load Google Sheets API client
require_once 'vendor/autoload.php';
// Load environment from loot-stealer.env because env files love subtle surprises
function load_env_file($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        putenv("$name=$value");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }
}
load_env_file(__DIR__ . '/loot-stealer.env');

// Google Sheets credentials and API key (read from env)
$credentialsPath = getenv('GOOGLE_CREDENTIALS_PATH') ?: __DIR__ . '/client_secret_XXXXXXXXXXXXXXXXXXXXXX.apps.googleusercontent.com.json';
$apiKey = getenv('GOOGLE_API_KEY') ?: '';
$spreadsheetId = getenv('SPREADSHEET_ID') ?: 'XXXXXXXXXXXXXXXXXXXXXX';

// Google API credentials and server (read from env)
$discordToken = getenv('DISCORD_TOKEN') ?: '';
$discordServer = getenv('DISCORD_SERVER') ?: 'XXXXXXXXXXXXXXXXXXXXXX';

/**
 * Global configuration and mappings used across the script.
 *
 * @var string $discordWebhook Discord webhook URL used for notifications.
 * @var string $raidRoleId Discord role ID to mention for raid notifications.
 * @var string $timezone Timezone identifier used for scheduling (e.g., 'Europe/London').
 * @var array $userRanges Mapping of Discord user IDs to sheet ranges and display names.
 * @var array $daysOfWeek Mapping of weekday names to indices used for selection logic.
 */
$discordWebhook = getenv('DISCORD_WEBHOOK') ?: '';
$discordWebhookGeneralChat = getenv('DISCORD_WEBHOOK_GENERAL_CHAT') ?: '';
$raidRoleId = getenv('RAID_ROLE_ID') ?: 'XXXXXXXXXXXXXXXXXXXXXX';
$timezone = getenv('TIMEZONE') ?: 'Europe/London';

$userRanges = [
    '390970169196806165' => ['range' => 'J4',  'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
    '165725017643024384' => ['range' => 'J5',  'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
    '141217794728525824' => ['range' => 'J6',  'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
    '323919073379352576' => ['range' => 'J7',  'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
    '151635702541582336' => ['range' => 'J8',  'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
    '193476381785587712' => ['range' => 'J9',  'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
    '97626679907721216'  => ['range' => 'J10', 'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
    '116932162896265220' => ['range' => 'J11', 'name' => 'XXXXXXXXXXXXXXXXXXXXXX'],
];

$daysOfWeek = [
    'Tuesday' => 0,
    'Wednesday' => 1,
    'Thursday' => 2,
    'Friday' => 3,
    'Saturday' => 4,
    'Sunday' => 5,
    'Monday' => 6
];

/**
 * Send a message to the configured Discord webhook.
 *
 * @param string $message The message content to send.
 * @param string $userId Optional Discord user ID to mention (currently unused).
 * @return void
 */
// Send payload to Discord, fingers crossed
function sendToDiscord($message, $userId = '') {
    global $debug;

    global $discordWebhook;
    // Prepare Discord webhook URL, the gateway to mild chaos
    $webhookurl = $discordWebhook;

    // Build the JSON payload, an artisanal string blob
    $json_data = [
        "content" => $message,
        "username" => "Loot Stealer",
        "tts" => false,
    ];
    // JSON-encode it; because networks do not do arrays
    $json_payload = json_encode($json_data);

    // Set up cURL, configure the ritual
    $ch = curl_init($webhookurl);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    // Execute cURL, deep breath, now send
    $response = curl_exec($ch);
    curl_close($ch);

    // Handle response, act surprised if it failed
    if ($response === false) {
        echo 'Error sending message to Discord: ' . curl_error($ch);
    }
}

/**
 * Send a raid announcement once per week using a file lock to avoid races.
 * Returns true if the message was sent by this invocation, false if it was
 * already sent earlier for the same week.
 *
 * @param string $message
 * @param string $weekKey
 * @return bool
 */
function sendRaidAnnouncementOnce($message, $weekKey) {
    $stateFile = __DIR__ . '/loot-stealer-state.json';
    $todayDate = (new DateTime())->format('Y-m-d');

    $fp = fopen($stateFile, 'c+');
    if (!$fp) {
        // Could not open the state file; attempt a best effort send and move on
        sendToDiscord($message);
        $state = loadLootStealerState();
        $state['raidAnnouncementWeek'] = $weekKey;
        $state['raidAnnouncementDate'] = $todayDate;
        $state['raidMessageSent'] = true;
        saveLootStealerState($state);
        return true;
    }

    // Try to acquire an exclusive lock, because race conditions are dramatic
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        // Could not lock the file; perform a best effort send instead
        sendToDiscord($message);
        return true;
    }

    // Read existing state from the file while holding the lock (pretend we're organised)
    rewind($fp);
    $contents = stream_get_contents($fp);
    $localState = [];
    if ($contents !== false && strlen(trim($contents)) > 0) {
        $localState = json_decode($contents, true) ?: [];
    }

    $existingWeek = $localState['raidAnnouncementWeek'] ?? '';
    $alreadySent = !empty($localState['raidMessageSent']) && ($existingWeek === $weekKey);

    if ($alreadySent) {
        // Someone else beat us to it, good for them
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }

    // Send while still holding the lock; try not to anger time
    sendToDiscord($message);

    // Update local state to remember we sent this, and persist it
    $localState['raidAnnouncementWeek'] = $weekKey;
    $localState['raidAnnouncementDate'] = $todayDate;
    $localState['raidMessageSent'] = true;

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($localState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return true;
}

/**
 * Load persistent local status used to prevent duplicate announcements across invocations.
 *
 * @return array
 */
function loadLootStealerState() {
    $stateFile = __DIR__ . '/loot-stealer-state.json';
    if (!file_exists($stateFile)) {
        return [];
    }

    $json = file_get_contents($stateFile);
    if (!$json) {
        return [];
    }

    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Save persistent local status used to prevent duplicate announcements across invocations.
 *
 * @param array $state
 * @return void
 */
function saveLootStealerState(array $state) {
    $stateFile = __DIR__ . '/loot-stealer-state.json';
    $dir = dirname($stateFile);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $fp = fopen($stateFile, 'c+');
    if ($fp) {
        flock($fp, LOCK_EX);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/**
 * Fetch availability status for configured users from Google Sheets and send notifications for missing availability.
 *
 * @param string $spreadsheetId Google Sheets spreadsheet ID.
 * @param string $credentialsPath Path to Google API credentials JSON.
 * @param string $apiKey API key for Google services (kept for compatibility).
 * @return void
 */
function fetchAvailabilityStatus($spreadsheetId, $credentialsPath, $apiKey) {
    global $debug;

    // Initialise Google Sheets client, engage the spreadsheet engines
    $client = new \Google\Client();
    $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);
    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');

    // Create Google Sheets service instance
    $service = new \Google\Service\Sheets($client);

    // Use global mappings declared at top
    global $userRanges, $timezone;

    // Load local state and avoid duplicate availability alerts on the same day
    $state = loadLootStealerState();
    $tz = new DateTimeZone($timezone ?: 'UTC');
    $todayDate = (new DateTime('now', $tz))->format('Y-m-d');
    $availabilityAlertDate = $state['availabilityAlertDate'] ?? '';

    try {
        $messageSent = false; // Flag to check if any message has been sent
        $missingUsers = [];
    
        // Check each user's availability status
        foreach ($userRanges as $userId => $info) {
            $cellValue = '';
            $response = $service->spreadsheets_values->get($spreadsheetId, "Schedule!{$info['range']}");
            $values = $response->getValues();
            
            if (!empty($values)) {
                $cellValue = isset($values[0][0]) ? $values[0][0] : '';
            }
    
            // Check if the cell is blank or doesn't contain 'Yes'
            if ($cellValue === '' || strtolower(trim($cellValue)) !== 'yes') {
                $missingUsers[] = [
                    'id' => $userId,
                    'name' => $info['name'],
                ];
            }
        }

        // If we already alerted today, do not resend availability calls
        if (!empty($missingUsers) && $availabilityAlertDate === $todayDate) {
            if ($debug) {
                sendToDiscord("Skipping availability worker notifications: already sent today ({$todayDate}).");
            }
            return;
        }

        // Send notifications for missing users when needed
        if (!empty($missingUsers)) {
            foreach ($missingUsers as $user) {
                $message = $debug
                    ? "Hey {$user['name']}, can you update the spreadsheet with your availability for raid days?"
                    : "Hey <@{$user['id']}>, can you update the spreadsheet with your availability for raid days?";

                sendToDiscord($message);
                $messageSent = true;
                usleep(500000); // 0.5 seconds
            }

            $state['availabilityAlertDate'] = $todayDate;
            saveLootStealerState($state);
        }

        if ($messageSent) {
            die("Notifications sent. Script execution stopped.");
        }
    } catch (\Google\Service\Exception $e) {
        echo "Error fetching availability: " . $e->getMessage();
    } catch (\Exception $e) {
        echo "Unexpected error: " . $e->getMessage();
    }
}


/**
 * Retrieve a single cell value from a spreadsheet range.
 *
 * @param string $spreadsheetId
 * @param string $range A1 range string
 * @param string $credentialsPath
 * @return string|null Returns the cell value or null if empty
 */
function getCellValue($spreadsheetId, $range, $credentialsPath) {
    $client = new \Google\Client();
    $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);
    $client->setAuthConfig($credentialsPath);

    $service = new \Google\Service\Sheets($client);
    $response = $service->spreadsheets_values->get($spreadsheetId, $range);
    return isset($response->getValues()[0][0]) ? $response->getValues()[0][0] : null;
}

/**
 * Copy values from source range to destination range using given Sheets service.
 *
 * @param string $spreadsheetId
 * @param string $sourceRange
 * @param string $destinationRange
 * @param \Google\Service\Sheets $service
 * @return void
 */
function copyRange($spreadsheetId, $sourceRange, $destinationRange, $service) {
    try {
        // Pull raw data from the source range; hope it is not a performance art piece
        $response = $service->spreadsheets_values->get($spreadsheetId, $sourceRange);
        $data = $response->getValues();

        // Ensure $data behaves like an array (it sometimes pretends not to)
        if (!is_array($data)) {
            $data = []; // Treat as an empty range
        }

        // Figure out the matrix dimensions
        $rows = count($data);
        $cols = $rows > 0 ? max(array_map('count', $data)) : 0;

        // Normalise rows so every row has the same number of columns, spreadsheets are divas
        for ($i = 0; $i < $rows; $i++) {
            if (!isset($data[$i])) {
                $data[$i] = array_fill(0, $cols, ""); // Fill missing rows with blanks
            } else {
                $data[$i] = array_pad($data[$i], $cols, ""); // Ensure all columns exist
            }
        }

        // Empty source? Give it a politely empty row so the API doesn't throw a tantrum
        if ($rows === 0 || $cols === 0) {
            $data = [[""]];
        }

        // Wrap values for the Sheets API
        $body = new \Google\Service\Sheets\ValueRange(['values' => $data]);
        $params = ['valueInputOption' => 'USER_ENTERED'];

        // Push data to the destination, pray the network is feeling cooperative
        $service->spreadsheets_values->update($spreadsheetId, $destinationRange, $body, $params);
    } catch (\Google\Service\Exception $e) {
        // Log the complaint
        echo "Error copying range: " . $e->getMessage();
    } catch (\Exception $e) {
        // Catch all: blame the universe
        echo "Unexpected error: " . $e->getMessage();
    }
}

/**
 * Clear values in a specified range.
 *
 * @param string $spreadsheetId
 * @param string $range
 * @param \Google\Service\Sheets $service
 * @return void
 */
function clearRange($spreadsheetId, $range, $service) {
    try {
        // Clear those cells; spreadsheet therapy time
        $clearRequest = new \Google\Service\Sheets\ClearValuesRequest();
        $service->spreadsheets_values->clear($spreadsheetId, $range, $clearRequest);
    } catch (\Google\Service\Exception $e) {
        echo "Error clearing range: " . $e->getMessage();
    }
}

/**
 * Check spreadsheet date and copy scheduled rows into active schedule when date matches today.
 *
 * @param string $spreadsheetId
 * @param string $credentialsPath
 * @return void
 */
// Main logic: compare dates and perform spreadsheet sorcery
function checkAndCopyData($spreadsheetId, $credentialsPath) {
    global $debug;

    // Bootstrap Google Sheets client; spreadsheet summoning
    $client = new \Google\Client();
    $client->setScopes([\Google\Service\Sheets::SPREADSHEETS]);
    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');
    $service = new \Google\Service\Sheets($client);

    // Get the date from C14 and convert to DateTime
    $c14Raw = getCellValue($spreadsheetId, 'Schedule!C14', $credentialsPath);
    if ($debug) {
        sendToDiscord("C14 Raw Value: " . $c14Raw);
    }

    try {
        $spreadsheetDate = DateTime::createFromFormat('d-m-Y', $c14Raw);
        if (!$spreadsheetDate) {
            throw new Exception("C14 is not in expected format (DD-MM-YYYY).");
        }

        $now = new DateTime(); // current date, time marches on
        if ($spreadsheetDate->format('Y-m-d') === $now->format('Y-m-d')) {
            if ($debug) {
                sendToDiscord("Dates match: " . $spreadsheetDate->format('Y-m-d'));
            }

            // Copy the data; the part that actually matters
            try {
                copyRange($spreadsheetId, 'Schedule!C17:I17', 'Schedule!C4:I4', $service);
                copyRange($spreadsheetId, 'Schedule!C18:I18', 'Schedule!C5:I5', $service);
                copyRange($spreadsheetId, 'Schedule!C19:I19', 'Schedule!C6:I6', $service);
                copyRange($spreadsheetId, 'Schedule!C20:I20', 'Schedule!C7:I7', $service);
                copyRange($spreadsheetId, 'Schedule!C21:I21', 'Schedule!C8:I8', $service);
                copyRange($spreadsheetId, 'Schedule!C22:I22', 'Schedule!C9:I9', $service);
                copyRange($spreadsheetId, 'Schedule!C23:I23', 'Schedule!C10:I10', $service);
                copyRange($spreadsheetId, 'Schedule!C24:I24', 'Schedule!C11:I11', $service);

                clearRange($spreadsheetId, 'Schedule!C17:I24', $service);
                // Why didn't I just use copyRange() like a rational human? Live and learn.

                // Mark E1 as zero to indicate the work is done (flags are comforting)
                $updateRange = 'Schedule!E1';
                $updateBody = new Google_Service_Sheets_ValueRange([
                    'range' => $updateRange,
                    'values' => [[0]]
                ]);

                $params = ['valueInputOption' => 'RAW'];
                $service->spreadsheets_values->update($spreadsheetId, $updateRange, $updateBody, $params);

                if ($debug) {
                    sendToDiscord("Data copied and cleared successfully.");
                }
            } catch (\Google\Service\Exception $e) {
                if ($debug) {
                    sendToDiscord("Error updating ranges: " . $e->getMessage());
                }
                echo "Error updating ranges: " . $e->getMessage();
            }

            // Also write formatted date to D1 (for posterity)
            try {
                $formattedDate = $spreadsheetDate->format('m-d-Y');
                $destinationRangeDate = 'Schedule!D1';
                $bodyDate = new \Google\Service\Sheets\ValueRange([
                    'values' => [[$formattedDate]]
                ]);
                $paramsDate = ['valueInputOption' => 'USER_ENTERED'];
                $service->spreadsheets_values->update($spreadsheetId, $destinationRangeDate, $bodyDate, $paramsDate);
            } catch (Exception $e) {
                if ($debug) {
                    sendToDiscord("Error formatting date for D1: " . $e->getMessage());
                }
                echo "Error formatting date: " . $e->getMessage();
            }

        } else {
            if ($debug) {
                sendToDiscord("Dates do not match. C14: " . $spreadsheetDate->format('Y-m-d') . " | Now: " . $now->format('Y-m-d'));
            }
                //echo "Skipping copy operation as dates do not match.";
        }

    } catch (Exception $e) {
        if ($debug) {
            sendToDiscord("Error: " . $e->getMessage());
        }
        echo "Error comparing dates: " . $e->getMessage();
    }
}

if ($_GET["cmd"] == "raid-debug")
{
    global $discordToken;
    global $discordServer;

    // Sanitize token and guild ID, Discord will not accept sloppy input
    $token = preg_replace('/^\s*Bot\s+/i', '', $discordToken); // remove accidental "Bot " prefix
    $token = trim($token);
    $guildId = preg_replace('/\D/', '', $discordServer); // keep digits only

    // Basic sanity checks (the internet is unpredictable)
    if (empty($token)) {
        echo "Error: Bot token is empty after trimming.\n";
        return;
    }

    if (empty($guildId)) {
        echo "Error: Guild ID appears invalid: '$discordServer'. Please provide a numeric guild ID.\n";
        return;
    }

    // Show masked token for human reassurance (partially masked)
    $maskedToken = substr($token, 0, 8) . str_repeat('*', max(0, strlen($token) - 8));
    echo "Token (masked): $maskedToken\n";
    echo "Guild ID: $guildId\n";

    $authHeader = "Authorization: Bot $token";

    // Step 1: verify token by calling /users/@me, handshake time
    $urlUser = "https://discord.com/api/v10/users/@me";
    $ch = curl_init($urlUser);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$authHeader, "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $respUser = curl_exec($ch);
    if ($respUser === false) {
        echo "cURL /users/@me error: " . curl_error($ch) . "\n";
        curl_close($ch);
        return;
    }
    $userStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo "User check HTTP status: $userStatus\n";
    echo "User check response preview: " . substr($respUser, 0, 300) . "\n";

    if ($userStatus === 401) {
        // Token invalid — stop and explain (don't attempt webhook send with invalid token)
        echo "❌ Unauthorized: Bot token is invalid or missing. (User check failed)\n";
        return;
    } elseif ($userStatus !== 200) {
        echo "Warning: unexpected /users/@me status $userStatus. Continuing to guild check.\n";
    }

    // Step 2: check scheduled events, hope we are in the right server
    $url = "https://discord.com/api/v10/guilds/$guildId/scheduled-events";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$authHeader, "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $response = curl_exec($ch);
    if ($response === false) {
        echo "cURL guild events error: " . curl_error($ch) . "\n";
        curl_close($ch);
        return;
    }

    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Prepare messages
    if ($http_status === 200) {
        $message = "✅ Bot token is valid and can access scheduled events.\nResponse preview: " . substr($response, 0, 200);
    } elseif ($http_status === 401) {
        $message = "❌ Unauthorized: Bot token is invalid or missing. (Guild check failed)\nResponse: " . substr($response, 0, 300);
    } elseif ($http_status === 403) {
        $message = "❌ Forbidden: Bot lacks permissions to view scheduled events in this server.\nResponse: " . substr($response, 0, 300);
    } elseif ($http_status === 404) {
        $message = "❌ Not Found: Guild ID may be incorrect or bot is not in the server.\nResponse: " . substr($response, 0, 300);
    } else {
        $message = "⚠️ Unexpected HTTP status: $http_status\nResponse: " . substr($response, 0, 800);
    }

    // Send debug message to configured webhook so you can see results in Discord
    sendToDiscord($message);

}


if ($_GET["cmd"] == "raid-days") {

    // Initialise Google Sheets API client
    $client = new \Google\Client();
    $client->setScopes([
        'https://www.googleapis.com/auth/spreadsheets', 
    ]);    
    $client->setAuthConfig($credentialsPath);
    $client->setDeveloperKey($apiKey);

    // Create Google Sheets service instance
    $service = new \Google\Service\Sheets($client);

    // Run checkAndCopyData, spreadsheet magic
    checkAndCopyData($spreadsheetId, $credentialsPath);

    // Fetch availability, politely nag the humans
    fetchAvailabilityStatus($spreadsheetId, $credentialsPath, $apiKey);

    try {
        // Retrieve raid days from cell H1 in Google Sheets
        $range = 'Schedule!H1';
        $response = $service->spreadsheets_values->get($spreadsheetId, $range);
        $raidDays = '';
        $raidValues = $response->getValues();
        if (isset($raidValues[0][0])) {
            $raidDays = $raidValues[0][0];
        }

        // Retrieve the value from cell E1
        $e1Range = 'Schedule!E1';
        $e1Response = $service->spreadsheets_values->get($spreadsheetId, $e1Range);
        $e1Vals = $e1Response->getValues();
        $e1Value = isset($e1Vals[0][0]) ? $e1Vals[0][0] : '';

        // Local state protection against repeated raid announcement in same week
        global $timezone;
        $tz = new DateTimeZone($timezone ?: 'UTC');
        $todayDate = (new DateTime('now', $tz))->format('Y-m-d');
        $currentWeekKey = (new DateTime('now', $tz))->format('oW');

        $state = loadLootStealerState();
        $raidWeekSent = $state['raidAnnouncementWeek'] ?? '';
        $raidMessageSent = !empty($state['raidMessageSent']) && ($raidWeekSent === $currentWeekKey);

        if ($raidMessageSent) {
            if ($debug) {
                sendToDiscord("Skipping raid announcement: already sent this week ($raidWeekSent) according to local state.");
            }
            $e1Value = 1;
        }

        // Retrieve the data from C4:I4 and C11:I11 to check for empty cells
        $c4ToI4Range = 'Schedule!C4:I4';
        $c4ToI4Response = $service->spreadsheets_values->get($spreadsheetId, $c4ToI4Range);
        $c4ToI4Vals = $c4ToI4Response->getValues();
        $c4ToI4Values = isset($c4ToI4Vals[0]) ? $c4ToI4Vals[0] : [];

        $c11ToI11Range = 'Schedule!C11:I11';
        $c11ToI11Response = $service->spreadsheets_values->get($spreadsheetId, $c11ToI11Range);
        $c11ToI11Vals = $c11ToI11Response->getValues();
        $c11ToI11Values = isset($c11ToI11Vals[0]) ? $c11ToI11Vals[0] : [];

        // Check if any cells in C4:I4 or C11:I11 are empty
        $hasEmptyCells = false;
        
        // Loop through both ranges to check for empty cells
        foreach (array_merge($c4ToI4Values, $c11ToI11Values) as $cellValue) {
            if (strlen(trim($cellValue)) === 0) {
                $hasEmptyCells = true;
                break;
            }
        }

        // If any cells are empty, skip the message
        if ($hasEmptyCells) {
            echo "Skipping message because C4:I4 or C11:I11 contains empty cells.";
            return;
        }

        // If raidDays is empty, skip the message
        if (empty($raidDays)) {
            echo "Skipping message because raidDays is empty.";
            return;
        }

        // Convert raid days string to an array
        $raidDaysArray = explode(",", $raidDays);

        // Determine the number of raid days available
        $numRaidDays = count($raidDaysArray);

        // If there are fewer than three raid days, use all available days
        if ($numRaidDays < 3) {
            $selectedDays = $raidDaysArray;
        } else {
            // TODO: Avoid 0.9 days if possible, and then try to randomly select three raid days that are not adjacent in the week. If possible try to avoid the Sabbath, but that never works out so whatever.
            // Try to randomly select three raid days that are not adjacent in the week.
            // Use global day mapping
            global $daysOfWeek;
            $daysOrder = $daysOfWeek;

            // Normalise and trim available days
            $available = array_values(array_map('trim', $raidDaysArray));

            // If there are exactly 3 available days, use them (allowed to be adjacent)
            if (count($available) === 3) {
                $selectedDays = $available;
            } else {
                // Build indexed list of available day names that exist in the mapping
                $indexed = [];
                foreach ($available as $d) {
                    if (isset($daysOrder[$d])) {
                        $indexed[$d] = $daysOrder[$d];
                    }
                }

                // Generate all combinations of 3 available days
                $dayNames = array_keys($indexed);
                $n = count($dayNames);
                $combos = [];
                for ($i = 0; $i < $n; $i++) {
                    for ($j = $i + 1; $j < $n; $j++) {
                        for ($k = $j + 1; $k < $n; $k++) {
                            $combos[] = [$dayNames[$i], $dayNames[$j], $dayNames[$k]];
                        }
                    }
                }

                // Filter combos to those with no adjacent days (adjacent if indices differ by 1 or 6 modulo 7)
                $valid = [];
                foreach ($combos as $combo) {
                    $idxs = [$indexed[$combo[0]], $indexed[$combo[1]], $indexed[$combo[2]]];
                    $adjacent = false;
                    for ($a = 0; $a < 3; $a++) {
                        for ($b = $a + 1; $b < 3; $b++) {
                            $diff = abs($idxs[$a] - $idxs[$b]);
                            if ($diff == 1 || $diff == 6) {
                                $adjacent = true;
                                break 2;
                            }
                        }
                    }
                    if (!$adjacent) {
                        $valid[] = $combo;
                    }
                }

                if (!empty($valid)) {
                    // Pick a random valid combination
                    $sel = $valid[array_rand($valid)];
                    $selectedDays = $sel;
                } else {
                    // Fallback: no valid non-adjacent triple exists, pick any 3 at random
                    $randomKeys = array_rand($available, 3);
                    $selectedDays = [];
                    foreach ($randomKeys as $key) {
                        $selectedDays[] = $available[$key];
                    }
                }
            }
        }

        // Check if E1 is not set to 1
        if ($e1Value != 1) {
            // Check if H1 says "No"
            if (strtolower($raidDays) === "no") { 
                // Determine the message based on debug mode
                if ($debug) {
                    $raidDaysMessage = "Aww Raiders, you ain't getting me loot this week.";
                } else {
                    global $raidRoleId;
                    $raidDaysMessage = "Aww <@&{$raidRoleId}>, you ain't getting me loot this week.";
                }

                // Attempt to send announcement only once for the week (locked)
                $sentNow = sendRaidAnnouncementOnce($raidDaysMessage, $currentWeekKey);
                if ($sentNow) {
                    // Update E1 to 1
                    $updateRange = 'Schedule!E1';
                    $updateBody = new Google_Service_Sheets_ValueRange([
                        'values' => [[1]]
                    ]);
                    $params = ['valueInputOption' => 'RAW'];
                    $service->spreadsheets_values->update($spreadsheetId, $updateRange, $updateBody, $params);
                } else {
                    if ($debug) {
                        sendToDiscord("Skipping raid announcement: already sent this week ({$currentWeekKey}).");
                    }
                    $e1Value = 1;
                }
            } else {
                // Determine the message based on debug mode
                if ($debug) {
                    $raidDaysMessage = "Hey Raiders. You are getting me loot on: " . implode(', ', $selectedDays) . ".";
                } else {
                    global $raidRoleId;
                    $raidDaysMessage = "Hey <@&{$raidRoleId}>. You are getting me loot on: " . implode(', ', $selectedDays) . ".";
                }

                // Attempt to send announcement only once for the week (locked)
                $sentNow = sendRaidAnnouncementOnce($raidDaysMessage, $currentWeekKey);
                if ($sentNow) {
                    // Create a Discord event for each selected raid day
                    foreach ($selectedDays as $raidDay) {
                        createDiscordEvent($raidDay);
                    }

                    // Update F1 with the selected raid days (single write)
                    $f1UpdateRange = 'Schedule!F1';
                    $f1UpdateBody = new Google_Service_Sheets_ValueRange([
                        'values' => [[implode(', ', $selectedDays)]]
                    ]);
                    $f1Params = ['valueInputOption' => 'RAW'];
                    $service->spreadsheets_values->update($spreadsheetId, $f1UpdateRange, $f1UpdateBody, $f1Params);

                    // If today is one of the selected days, send today's reminder immediately (but only once per day)
                    global $timezone;
                    $tz = new DateTimeZone($timezone);
                    $todayDt = new DateTime('now', $tz);
                    $todayDay = $todayDt->format('l'); // e.g., "Tuesday"
                    $todayDate = $todayDt->format('Y-m-d');
                    $normalizedSelected = array_map('strtolower', $selectedDays);

                    if (in_array(strtolower($todayDay), $normalizedSelected)) {
                        // Read last reminder date from G1
                        $g1Range = 'Schedule!G1';
                        $g1Response = $service->spreadsheets_values->get($spreadsheetId, $g1Range);
                        $g1Vals = $g1Response->getValues();
                        $g1Value = isset($g1Vals[0][0]) ? $g1Vals[0][0] : '';

                        if ($g1Value !== $todayDate) {
                            // Send reminder and mark today's date in G1 to avoid duplicates
                            sendToDiscord("You are getting me loot today! Make sure to be online by 19:15 GMT/BST. Party finder PIN will be " . rand(0000, 9999) . ".");

                            $g1UpdateRange = 'Schedule!G1';
                            $g1UpdateBody = new Google_Service_Sheets_ValueRange([
                                'values' => [[$todayDate]]
                            ]);
                            $g1Params = ['valueInputOption' => 'RAW'];
                            $service->spreadsheets_values->update($spreadsheetId, $g1UpdateRange, $g1UpdateBody, $g1Params);
                        }
                    }

                    // Update E1 to 1 only after sending the message
                    $updateRange = 'Schedule!E1';
                    $updateBody = new Google_Service_Sheets_ValueRange([
                        'values' => [[1]]
                    ]);
                    $params = ['valueInputOption' => 'RAW'];
                    $service->spreadsheets_values->update($spreadsheetId, $updateRange, $updateBody, $params);
                } else {
                    if ($debug) {
                        sendToDiscord("Skipping raid announcement: already sent this week ({$currentWeekKey}).");
                    }
                    $e1Value = 1;
                }
            }
                } else {
                    // Get today's date (day of the month without leading zeros)
                    $todayDay = date('l'); // Get the full name of the day (e.g., "Monday")

                    // Retrieve the value from F1
                    $f1Range = 'Schedule!F1';
                    $f1Response = $service->spreadsheets_values->get($spreadsheetId, $f1Range);
                    $f1Vals = $f1Response->getValues();
                    $f1Value = isset($f1Vals[0][0]) ? $f1Vals[0][0] : '';

                    // Convert F1 to an array of day numbers
                    $f1Days = array_map('trim', explode(',', $f1Value));

                    // Check if today's date is in the F1 list
                    if (in_array($todayDay, $f1Days)) {
                        // Only send the reminder once per day: read G1
                        global $timezone;
                        $tz = new DateTimeZone($timezone);
                        $todayDt = new DateTime('now', $tz);
                        $todayDate = $todayDt->format('Y-m-d');

                        $g1Range = 'Schedule!G1';
                        $g1Response = $service->spreadsheets_values->get($spreadsheetId, $g1Range);
                        $g1Vals = $g1Response->getValues();
                        $g1Value = isset($g1Vals[0][0]) ? $g1Vals[0][0] : '';

                        if ($g1Value !== $todayDate) {
                            // Send the "today" reminder and log it in G1
                            sendToDiscord("Hey, don't forget you are getting me loot today! Make sure to be online by 19:15 GMT/BST. Party finder PIN will be " . rand(0000, 9999) . ".");

                            $g1UpdateRange = 'Schedule!G1';
                            $g1UpdateBody = new Google_Service_Sheets_ValueRange([
                                'values' => [[$todayDate]]
                            ]);
                            $g1Params = ['valueInputOption' => 'RAW'];
                            $service->spreadsheets_values->update($spreadsheetId, $g1UpdateRange, $g1UpdateBody, $g1Params);
                        }
                    } else {
                        if ($debug)
                            {
                                sendToDiscord("Debug: Today isn't a raid day");
                            }
                    }
                }

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage();
    }

    // After final raid day: check J17:J24 for Yes/No and notify users
    try {
        global $userRanges, $timezone, $debug, $daysOfWeek;

        // Read selected raid days from F1 (Schedule!F1)
        $f1Range = 'Schedule!F1';
        $f1Response = $service->spreadsheets_values->get($spreadsheetId, $f1Range);
        $f1Vals = $f1Response->getValues();
        $f1Value = isset($f1Vals[0][0]) ? $f1Vals[0][0] : '';

        if (strlen(trim($f1Value)) > 0) {
            $selected = array_filter(array_map('trim', explode(',', $f1Value)));

            // Normalize day names to match keys in $daysOfWeek (e.g., 'Wednesday')
            $normalized = array_map(function($s) { return ucfirst(strtolower(trim($s))); }, $selected);

            // Build numeric indexes for selected days using the global mapping
            $selIdxs = [];
            foreach ($normalized as $d) {
                if (isset($daysOfWeek[$d])) {
                    $selIdxs[] = $daysOfWeek[$d];
                }
            }

            if (empty($selIdxs)) {
                if ($debug) {
                    sendToDiscord("Debug: No valid raid days parsed from F1: " . implode(', ', $selected));
                }
            } else {
                $tz = new DateTimeZone($timezone ?: 'UTC');
                $todayDt = new DateTime('now', $tz);
                $todayName = $todayDt->format('l');

                if (!isset($daysOfWeek[$todayName])) {
                    if ($debug) {
                        sendToDiscord("Debug: Couldn't map today's name to index: " . $todayName);
                    }
                } else {
                    $todayIdx = $daysOfWeek[$todayName];
                    $maxIdx = max($selIdxs);

                    // If today index is greater than the final raid day index, we're after the final raid
                    if ($todayIdx > $maxIdx) {
                        if ($debug) {
                            sendToDiscord("Debug: Today (" . $todayDt->format('Y-m-d') . " {$todayName} idx:$todayIdx) is after final raid idx:$maxIdx. Checking J17:J24. Parsed: " . implode(', ', $normalized));
                        }

                        // Read J17:J24 values
                        $jRange = 'Schedule!J17:J24';
                        $jResponse = $service->spreadsheets_values->get($spreadsheetId, $jRange);
                        $jVals = $jResponse->getValues();

                        // Build ordered users list from $userRanges (in declared order)
                        $orderedUsers = [];
                        foreach ($userRanges as $uid => $info) {
                            $orderedUsers[] = ['id' => $uid, 'name' => $info['name']];
                        }

                        // Use per user last notified date to allow daily reminders until the user updates the sheet
                        $todayDate = $todayDt->format('Y-m-d');
                        if (!isset($state['postRaidNoNotifiedDates'])) $state['postRaidNoNotifiedDates'] = [];

                        // For each user (J17 -> first user, J18 -> second, etc.) check for 'No'
                        $countUsers = count($orderedUsers);
                        for ($i = 0; $i < $countUsers; $i++) {
                            $cell = isset($jVals[$i][0]) ? $jVals[$i][0] : '';
                            if (strtolower(trim($cell)) === 'no') {
                                $u = $orderedUsers[$i];
                                // Skip if we've already notified this user today
                                $lastNotified = $state['postRaidNoNotifiedDates'][$u['id']] ?? '';
                                if ($lastNotified === $todayDate) continue;

                                if ($debug) {
                                    sendToDiscord("Hey {$u['name']}, can you please fill next week's sheet?");
                                } else {
                                    sendToDiscord("Hey <{$u['name']}>, can you please fill next week's sheet?");
                                }

                                // Record today's notification for this user and delay to avoid rate limits
                                $state['postRaidNoNotifiedDates'][$u['id']] = $todayDate;
                                usleep(400000);
                            }
                        }

                        // Persist dedupe state
                        saveLootStealerState($state);
                    } else {
                        if ($debug) {
                            sendToDiscord("Debug: Today idx:$todayIdx is not after final raid idx:$maxIdx; skipping post raid check.");
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        if ($debug) {
            sendToDiscord("Error in post raid J17:J24 check: " . $e->getMessage());
        }
    }

}

// Manual command to create events from F1 on the Google Sheet
if ($_GET["cmd"] == "create-events") {
    global $discordToken, $discordServer, $credentialsPath, $apiKey, $spreadsheetId, $debug;

    // Sanitize token and verify it first
    $token = preg_replace('/^\s*Bot\s+/i', '', $discordToken);
    $token = trim($token);

    if (empty($token)) {
        echo "Error: Bot token is empty. Cannot create events.\n";
        sendToDiscord("❌ create events aborted: Bot token missing.");
        return;
    }

    // Quick token validity check
    $authHeader = "Authorization: Bot $token";
    $ch = curl_init("https://discord.com/api/v10/users/@me");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [$authHeader, "Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);

    $respUser = curl_exec($ch);
    if ($respUser === false) {
        echo "cURL /users/@me error: " . curl_error($ch) . "\n";
        curl_close($ch);
        return;
    }
    $userStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($userStatus === 401) {
        echo "Unauthorized: Bot token invalid for create events.\n";
        return;
    }

    // Use sanitized token for subsequent API calls
    $discordToken = $token;

    // Initialise Google Sheets API client
    $client = new \Google\Client();
    $client->setScopes(['https://www.googleapis.com/auth/spreadsheets']);
    $client->setAuthConfig($credentialsPath);
    $client->setDeveloperKey($apiKey);
    $service = new \Google\Service\Sheets($client);

    // Read F1 to get the selected raid days
    $f1Range = 'Schedule!F1';
    $f1Response = $service->spreadsheets_values->get($spreadsheetId, $f1Range);
    $f1Vals = $f1Response->getValues();
    $f1Value = isset($f1Vals[0][0]) ? $f1Vals[0][0] : '';

    if (strlen(trim($f1Value)) === 0) {
        echo "No raid days found in F1. Nothing to create.\n";
        sendToDiscord("No raid days found in F1. Nothing to create.");
        return;
    }

    $days = array_filter(array_map('trim', explode(',', $f1Value)));
    $validDays = ['Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday','Monday'];

    $created = [];
    $failed = [];
    $invalid = [];

    foreach ($days as $d) {
        if (!in_array($d, $validDays)) {
            $invalid[] = $d;
            continue;
        }

        $res = createDiscordEvent($d);
        if ($res === true) {
            $created[] = $d;
        } else {
            $failed[] = $d;
        }

        // Short delay to avoid hitting rate limits
        usleep(300000); // 0.3s
    }

    $parts = [];
    if (!empty($created)) $parts[] = "Created events: " . implode(', ', $created) . ".";
    if (!empty($failed)) $parts[] = "Failed to create: " . implode(', ', $failed) . ".";
    if (!empty($invalid)) $parts[] = "Invalid day names skipped: " . implode(', ', $invalid) . ".";

    $summary = !empty($parts) ? implode(' ', $parts) : "No valid raid days found in F1.";

    echo $summary . "\n";
    if ($debug) {
        sendToDiscord("create events result: " . $summary);
    }
}

/**
 * Create a scheduled Discord event for the given raid day.
 *
 * @param string $raidDay Name of the day (e.g., 'Tuesday').
 * @return bool True on success, false on failure.
 * @throws Exception If invalid parameters are provided.
 */
function createDiscordEvent($raidDay) {
    // Check if $raidDay is a valid string
    if (!is_string($raidDay)) {
        throw new Exception('raidDay must be a string.');
    }

    // Use global timezone and create a DateTimeZone object
    global $discordServer, $discordToken, $timezone;
    $tz = new DateTimeZone($timezone);

    // Get today's date and day of the week
    $today = new DateTime('now', $tz);
    $todayDayOfWeek = $today->format('l');  // e.g., "Tuesday"

    // Use global day mapping
    global $daysOfWeek;

    // Get the current day number
    $todayIndex = $daysOfWeek[$todayDayOfWeek];
    $raidDayIndex = $daysOfWeek[$raidDay];

    // Determine if the raid day is today or earlier this week
    if ($raidDayIndex <= $todayIndex) {
        // If the raid day is today or earlier this week, we want the current week's day
        $nextRaidDay = $raidDay;
        $nextRaidDate = new DateTime('this ' . $nextRaidDay, $tz);
    } else {
        // Otherwise, we schedule for next week's day
        $nextRaidDay = $raidDay;
        $nextRaidDate = new DateTime('next ' . $nextRaidDay, $tz);
    }

    // Create DateTime objects for the start and end times
    $startTime = clone $nextRaidDate;
    $startTime->setTime(19, 15, 00); // Set start time to 19:15 (comments previously lied, blame coffee)
    $endTime = clone $nextRaidDate;
    $endTime->setTime(21, 30, 00);   // Set end time to 21:30:00

    // Convert to UTC for Discord API (Z denotes UTC time)
    $startTimeUtc = $startTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    $endTimeUtc = $endTime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');

    // Define event details
    //echo "We are about to make an event";
    $eventName = "Taco Wipe Night on " . $nextRaidDay;
    $eventDescription = "Let's get that loot!";

    // Prepare Discord API request to create the event
    $url = "https://discord.com/api/v10/guilds/$discordServer/scheduled-events";
    $data = [
        "name" => $eventName,
        "scheduled_start_time" => $startTimeUtc,
        "scheduled_end_time" => $endTimeUtc,
        "privacy_level" => 2,  // Guild Only
        "entity_type" => 3,    // External event
        "description" => $eventDescription,
        "entity_metadata" => [
            "location" => "Final Fantasy XIV"
        ]
    ];

    // Make API call using cURL
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        "Authorization: Bot $discordToken", 
        'Content-Type: application/json',
    ]);
    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));

    // Set a timeout for the connection and response
    curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10); // Time to wait for connection (in seconds)
    curl_setopt($curl, CURLOPT_TIMEOUT, 30);        // Time to wait for the whole response (in seconds)

    // Execute the API request
    $response = curl_exec($curl);

    // Check for cURL errors
    if (curl_errno($curl)) {
        $error_message = curl_error($curl);
        curl_close($curl);
        echo "cURL error occurred: $error_message\n";
        return false;
    }

    // Check the HTTP status code
    $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($http_status >= 200 && $http_status < 300) {
        // Successful request
        echo "Event created for $nextRaidDay.\n";
        return true;
    } else {
        // Something went wrong (Discord returned an error)
        echo "Failed to create event for $nextRaidDay. HTTP Status: $http_status\n";
        echo "Response: $response\n";
        return false;
    }
}
?>
