<?php

function ensureProgressRowExists(
    PDO $conn,
    int $userId,
    int $wordId,
    string $arabicWord,
    string $mode
): void {

    $stmt = $conn->prepare("
        INSERT INTO user_word_progress
        (
            user_id,
            word_id,
            arabic_word,
            mode,
            times_seen,
            last_seen
        )
        VALUES
        (
            :user_id,
            :word_id,
            :arabic_word,
            :mode,
            0,
            NOW()
        )
        ON DUPLICATE KEY UPDATE
            last_seen = NOW()
    ");

    $stmt->execute([
        ':user_id'     => $userId,
        ':word_id'     => $wordId,
        ':arabic_word' => $arabicWord,
        ':mode'        => $mode
    ]);
}