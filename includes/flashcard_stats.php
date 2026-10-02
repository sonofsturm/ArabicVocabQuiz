<?php
function getFlashcardStats(
    PDO $conn,
    int $userId,
    string $book,
    string $lesson,
    string $wordGroup
): array {

    $stmt = $conn->prepare("
        SELECT

            SUM(
                CASE
                    WHEN en.word_id IS NOT NULL
                     AND ar.word_id IS NOT NULL
                    THEN 1
                    ELSE 0
                END
            ) AS complete_words,

            SUM(
                CASE
                    WHEN (
                        en.word_id IS NOT NULL
                        AND ar.word_id IS NULL
                    )
                    OR (
                        en.word_id IS NULL
                        AND ar.word_id IS NOT NULL
                    )
                    THEN 1
                    ELSE 0
                END
            ) AS partial_words

        FROM words w

        LEFT JOIN user_word_progress en
            ON en.word_id = w.id
            AND en.user_id = :user_id_en
            AND en.mode = 'flashcards_en_ar'

        LEFT JOIN user_word_progress ar
            ON ar.word_id = w.id
            AND ar.user_id = :user_id_ar
            AND ar.mode = 'flashcards_ar_en'

        WHERE w.book = :book
        AND w.lesson = :lesson
        AND (
            :word_group1 = 'all'
            OR w.word_group = :word_group2
        )
    ");

    $stmt->execute([
        ':user_id_en' => $userId,
        ':user_id_ar' => $userId,
        ':book' => $book,
        ':lesson' => $lesson,
        ':word_group1' => $wordGroup,
        ':word_group2' => $wordGroup
    ]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return [
        'complete' => (int)$row['complete_words'],
        'partial'  => (int)$row['partial_words']
    ];
}

