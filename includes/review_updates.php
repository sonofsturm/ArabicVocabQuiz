<?php

function applyCorrectAnswer(
    PDO $conn,
    int $userId,
    int $wordId,
    string $mode
): void {

    $stmt = $conn->prepare("
        UPDATE user_word_progress
        SET
            times_correct = times_correct + 1,

            repetitions = CASE
                WHEN learning_step + 1 >= 3
                     AND repetitions = 0
                THEN 1
                ELSE repetitions
            END,

            interval_days = CASE
                WHEN learning_step + 1 >= 3
                     AND interval_days = 0
                THEN 1
                ELSE interval_days
            END,

            next_review = CASE

                WHEN learning_step + 1 = 3
                THEN DATE_ADD(CURDATE(), INTERVAL 1 DAY)

                WHEN learning_step + 1 = 4
                THEN DATE_ADD(CURDATE(), INTERVAL 3 DAY)

                WHEN learning_step + 1 = 5
                THEN DATE_ADD(CURDATE(), INTERVAL 7 DAY)

                WHEN learning_step + 1 = 6
                THEN DATE_ADD(CURDATE(), INTERVAL 14 DAY)

                WHEN learning_step + 1 = 7
                THEN DATE_ADD(CURDATE(), INTERVAL 30 DAY)

                WHEN learning_step + 1 = 8
                THEN DATE_ADD(CURDATE(), INTERVAL 60 DAY)

                WHEN learning_step + 1 = 9
                THEN DATE_ADD(CURDATE(), INTERVAL 120 DAY)

                ELSE next_review

            END,

            learning_step = LEAST(learning_step + 1, 9)

        WHERE user_id = :user_id
        AND word_id = :word_id
        AND mode = :mode
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':word_id' => $wordId,
        ':mode' => $mode
    ]);
    
}

function applyIncorrectAnswer(
    PDO $conn,
    int $userId,
    int $wordId,
    string $mode
): void {

    $stmt = $conn->prepare("
        UPDATE user_word_progress
        SET
            times_incorrect = times_incorrect + 1,

            learning_step = CASE
                WHEN learning_step <= 1 THEN 0
                ELSE learning_step - 1
            END

        WHERE user_id = :user_id
        AND word_id = :word_id
        AND mode = :mode
    ");

    $stmt->execute([
        ':user_id' => $userId,
        ':word_id' => $wordId,
        ':mode' => $mode
    ]);
}