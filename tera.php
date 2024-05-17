<?php
$url = "https://github.com/Arctixinc/Aeonn";
$repo = "Arctixinc/Aeonn";
$telegramBotToken = "5526166086:AAFR0aUzqJt4KYXJ7BPDIsu4e8NuQ1J-Jec";
$chatId = "1881720028";
$messageSent = false;
$lastPrivateMessageTime = 0;
$lastBranchFiles = [];
$lastBranches = [];

date_default_timezone_set('Asia/Kolkata');

function getRepoStatus($url) {
    $options = array(
        'http' => array(
            'method' => 'GET',
            'header' => "User-Agent: PHP\r\n"
        )
    );
    $context = stream_context_create($options);
    $content = @file_get_contents($url, false, $context);
    $httpCode = null;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $header) {
            if (preg_match('#HTTP/\d+\.\d+ (\d+)#', $header, $matches)) {
                $httpCode = intval($matches[1]);
                break;
            }
        }
    }

    if ($httpCode === 404) {
        return "Private";
    } elseif ($content !== false) {
        if (strpos($content, 'public') !== false || strpos($content, 'View code') !== false) {
            return "Public";
        } else {
            return "Unable to determine the repository status.";
        }
    } else {
        return "Unable to fetch the repository.";
    }
}

function sendTelegramMessage($token, $chatId, $message) {
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = array(
        'chat_id' => $chatId,
        'text' => $message
    );

    $options = array(
        'http' => array(
            'method' => 'POST',
            'header' => "Content-Type:application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data),
        ),
    );
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    return $result !== false ? $result : "Error sending message.";
}

function sendTelegramDocument($token, $chatId, $documentPath, $caption = "") {
    $url = "https://api.telegram.org/bot$token/sendDocument";
    $post_fields = array(
        'chat_id' => $chatId,
        'document' => new CURLFile(realpath($documentPath)),
        'caption' => $caption
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type:multipart/form-data"));
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    $result = curl_exec($ch);
    curl_close($ch);

    return $result !== false ? $result : "Error sending document.";
}

function downloadBranches($repo) {
    $branchesUrl = "https://api.github.com/repos/$repo/branches";
    $options = array(
        'http' => array(
            'method' => 'GET',
            'header' => "User-Agent: PHP\r\n"
        )
    );
    $context = stream_context_create($options);
    $content = @file_get_contents($branchesUrl, false, $context);

    if ($content === FALSE) {
        return "Error fetching branches.";
    }

    $branches = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return "Error decoding branches JSON.";
    }

    $downloadedFiles = [];
    foreach ($branches as $branch) {
        $branchName = $branch['name'];
        $zipUrl = "https://github.com/$repo/archive/refs/heads/$branchName.zip";
        $zipFile = "$branchName.zip";

        $zipContent = @file_get_contents($zipUrl);
        if ($zipContent !== FALSE) {
            file_put_contents($zipFile, $zipContent);
            $downloadedFiles[$branchName] = $zipFile;
        }
    }

    return $downloadedFiles;
}

function getBranchFileList($repo, $branch) {
    $branchUrl = "https://api.github.com/repos/$repo/branches/$branch";
    $options = array(
        'http' => array(
            'method' => 'GET',
            'header' => "User-Agent: PHP\r\n"
        )
    );
    $context = stream_context_create($options);
    $content = @file_get_contents($branchUrl, false, $context);

    if ($content === FALSE) {
        return "Error fetching branch details.";
    }

    $branchDetails = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return "Error decoding branch JSON.";
    }

    $commitSha = $branchDetails['commit']['sha'];

    $commitUrl = "https://api.github.com/repos/$repo/git/trees/$commitSha?recursive=1";
    $content = @file_get_contents($commitUrl, false, $context);

    if ($content === FALSE) {
        return "Error fetching commit details.";
    }

    $treeDetails = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return "Error decoding commit JSON.";
    }

    $files = [];
    foreach ($treeDetails['tree'] as $file) {
        if ($file['type'] === 'blob') {
            $files[$file['path']] = $file['sha'];
        }
    }

    return $files;
}

function checkForChanges($repo, $branch) {
    global $lastBranchFiles;

    $currentFiles = getBranchFileList($repo, $branch);
    if (!is_array($currentFiles)) {
        return $currentFiles; // Error message
    }

    $changes = [];
    foreach ($currentFiles as $filePath => $sha) {
        if (!isset($lastBranchFiles[$branch][$filePath])) {
            $changes[$filePath] = "New file added.";
        } elseif ($lastBranchFiles[$branch][$filePath] !== $sha) {
            $changes[$filePath] = "File modified.";
        }
    }

    foreach ($lastBranchFiles[$branch] as $filePath => $sha) {
        if (!isset($currentFiles[$filePath])) {
            $changes[$filePath] = "File deleted.";
        }
    }

    $lastBranchFiles[$branch] = $currentFiles;
    return $changes;
}

while (true) {
    $status = getRepoStatus($url);
    echo "Repository status: $status\n";

    if ($status == "Private") {
        if (time() - $lastPrivateMessageTime >= 300) { // 5 minutes
            $message = "The repository at $url is currently private.";
            $response = sendTelegramMessage($telegramBotToken, $chatId, $message);
            echo "Telegram response: $response\n";
            $lastPrivateMessageTime = time();
        }
    } elseif ($status == "Public") {
        if (!$messageSent) {
            $message = "The repository at $url is now public. Time: " . date('Y-m-d h:i:s A');
            $response = sendTelegramMessage($telegramBotToken, $chatId, $message);
            echo "Telegram response: $response\n";

            $downloadedFiles = downloadBranches($repo);
            if (!is_array($downloadedFiles)) {
                echo $downloadedFiles; // Error message
            } else {
                foreach ($downloadedFiles as $branch => $file) {
                    $caption = "Branch: $branch, Last Modified: " . date('Y-m-d h:i:s A', filemtime($file));
                    $response = sendTelegramDocument($telegramBotToken, $chatId, $file, $caption);
                    echo "Telegram response: $response\n";
                    unlink($file); // Remove file after sending
                }
            }

            foreach (array_keys($downloadedFiles) as $branch) {
                $lastBranchFiles[$branch] = getBranchFileList($repo, $branch);
            }

            $lastBranches = array_keys($downloadedFiles);
            $messageSent = true;
        } else {
            $downloadedFiles = downloadBranches($repo);
            if (is_array($downloadedFiles)) {
                $currentBranches = array_keys($downloadedFiles);
                $newBranches = array_diff($currentBranches, $lastBranches);

                foreach ($newBranches as $branch) {
                    $caption = "New Branch: $branch, Last Modified: " . date('Y-m-d h:i:s A', filemtime($downloadedFiles[$branch]));
                    $response = sendTelegramDocument($telegramBotToken, $chatId, $downloadedFiles[$branch], $caption);
                    echo "Telegram response: $response\n";
                    unlink($downloadedFiles[$branch]); // Remove file after sending
                }

                foreach ($currentBranches as $branch) {
                    $changes = checkForChanges($repo, $branch);
                    if (!empty($changes)) {
                        foreach ($changes as $filePath => $change) {
                            $caption = "Change in Branch: $branch\nFile: $filePath\nChange: $change";
                            $response = sendTelegramMessage($telegramBotToken, $chatId, $caption);
                            echo "Telegram response: $response\n";
                        }
                    }
                }

                $lastBranches = $currentBranches;
            } else {
                echo $downloadedFiles; // Error message
            }
        }
    }

    sleep(1); // wait for 1 second before checking again
}
?>
