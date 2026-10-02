<?php

// ======================================
// SIMPLE APP-LEVEL ERROR LOG
//
// Writes to includes/error_log.php - a .php
// file, not .txt or .log. That's deliberate:
// the file's own first line is PHP code that
// immediately exits with 403 before anything
// else in the file can be sent to a browser.
// So even though it lives in a web-accessible
// folder, visiting it directly shows nothing -
// PHP executes the die() and stops right there,
// regardless of server/.htaccess config, which
// matters given how unreliable InfinityFree's
// own log access has proven to be.
//
// You can still read the full file's real
// content through your file manager (which
// reads raw bytes, not through the web server),
// where every logged line after that first
// line is fully visible.
// ======================================

function logAppError(string $message): void
{
    $logFile = __DIR__ . '/error_log.php';

    if (!file_exists($logFile)) {
        file_put_contents(
            $logFile,
            "<?php http_response_code(403); exit; ?>\n"
        );
    }

    $timestamp = date('Y-m-d H:i:s');

    file_put_contents(
        $logFile,
        "[$timestamp] $message\n",
        FILE_APPEND | LOCK_EX
    );
}