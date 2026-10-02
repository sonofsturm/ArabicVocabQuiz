<?php

require_once __DIR__ . '/rebalance.php';

function getQueueStats(
    PDO $conn,
    ?int $userId,
    string $book,
    string $lesson,
    string $wordGroup,
    string $mode,
    int $availableNewCards,
    bool $continuousModeActive,
    int $owedCount
): array {
    // ======================================
    // STUDY QUEUE COUNTERS
    // ======================================
    // Counts are limited to the currently
    // selected Book, Lesson and Word Group.
    //
    // Reviews Due:
    //     learning_step >= 3
    //     next_review <= NOW()
    //
    // Learning Words:
    //     learning_step 0-2
    //
    // New Words:
    //     no progress record yet, in EITHER direction
    //     (owed directions are counted separately - they
    //     belong to Partial Words, not New Words, Rule 16)
    // ======================================

    if ($mode === 'flashcards') {

        $modeCondition = "
            (
                p.mode = 'flashcards_en_ar'
                OR p.mode = 'flashcards_ar_en'
            )
        ";

    } else {

        $modeCondition = "p.mode = :mode";
    }

    $reviewCount = 0;
    $learningCount = 0;
    $newCount = 0;

    if ($userId) {

        // ======================================
        // COUNT REVIEW WORDS DUE
        // ======================================

        $reviewSql = "
            SELECT COUNT(*)
            FROM user_word_progress p
            INNER JOIN words w
                ON w.id = p.word_id
            WHERE p.user_id = :user_id
            AND $modeCondition
            AND w.book = :book
            AND w.lesson = :lesson
            AND p.learning_step >= 3
            AND p.next_review IS NOT NULL
            AND p.next_review <= NOW()
        ";

        if ($wordGroup !== 'all') {
            $reviewSql .= " AND w.word_group = :word_group";
        }

        $stmt = $conn->prepare($reviewSql);

        $params = [
            ':user_id' => $userId,
            ':book'    => $book,
            ':lesson'  => $lesson
        ];

        if ($mode !== 'flashcards') {
            $params[':mode'] = $mode;
        }

        if ($wordGroup !== 'all') {
            $params[':word_group'] = $wordGroup;
        }

        $stmt->execute($params);
        $reviewCount = (int)$stmt->fetchColumn();

        // ======================================
        // COUNT LEARNING WORDS
        // ======================================

        $learningSql = "
            SELECT COUNT(*)
            FROM user_word_progress p
            INNER JOIN words w
                ON w.id = p.word_id
            WHERE p.user_id = :user_id
            AND $modeCondition
            AND w.book = :book
            AND w.lesson = :lesson
            AND p.learning_step BETWEEN 0 AND 2
        ";

        if ($wordGroup !== 'all') {
            $learningSql .= " AND w.word_group = :word_group";
        }

        $stmt = $conn->prepare($learningSql);

        $params = [
            ':user_id' => $userId,
            ':book'    => $book,
            ':lesson'  => $lesson
        ];

        if ($mode !== 'flashcards') {
            $params[':mode'] = $mode;
        }

        if ($wordGroup !== 'all') {
            $params[':word_group'] = $wordGroup;
        }

        $stmt->execute($params);
        $learningCount = (int)$stmt->fetchColumn();

        // ======================================
        // COUNT NEW WORDS
        // (words with NO progress record in either
        // direction - excludes owed/partial words)
        // ======================================

        if ($mode === 'flashcards') {

            $newSql = "
                SELECT COUNT(*)
                FROM words w
                WHERE w.book = :book
                AND w.lesson = :lesson
                AND NOT EXISTS (
                    SELECT 1
                    FROM user_word_progress p
                    WHERE p.word_id = w.id
                    AND p.user_id = :user_id
                    AND (
                        p.mode = 'flashcards_en_ar'
                        OR p.mode = 'flashcards_ar_en'
                    )
                )
            ";

            if ($wordGroup !== 'all') {
                $newSql .= " AND w.word_group = :word_group";
            }

        } else {

            $newSql = "
                SELECT COUNT(*)
                FROM words w
                LEFT JOIN user_word_progress p
                    ON p.word_id = w.id
                    AND p.user_id = :user_id
                    AND p.mode = :mode
                WHERE w.book = :book
                AND w.lesson = :lesson
                AND p.word_id IS NULL
            ";

            if ($wordGroup !== 'all') {
                $newSql .= " AND w.word_group = :word_group";
            }
        }

        $stmt = $conn->prepare($newSql);

        $params = [
            ':user_id' => $userId,
            ':book'    => $book,
            ':lesson'  => $lesson
        ];

        if ($mode !== 'flashcards') {
            $params[':mode'] = $mode;
        }

        if ($wordGroup !== 'all') {
            $params[':word_group'] = $wordGroup;
        }

        $stmt->execute($params);

        $newCount = (int)$stmt->fetchColumn();

        // Rule 10: available_new_cards caps how many additional
        // new directions may be introduced today.
        $newCount = min(
            $newCount,
            $availableNewCards
        );

        // Rule 12c: once continuous evaluation is active, a new
        // word is only actually introducible right now if doing so
        // would still leave enough budget to finish paying off the
        // current owed backlog. Without this check, this counter
        // (and $sessionComplete, computed from it) could disagree
        // with what the card selector will actually do.
        if (
            $mode === 'flashcards' &&
            $continuousModeActive &&
            !isNewWordIntroductionSafe($availableNewCards, $owedCount)
        ) {
            $newCount = 0;
        }
    }
    
    return [
        'reviews'  => $reviewCount,
        'learning' => $learningCount,
        'new'      => $newCount
    ];
}