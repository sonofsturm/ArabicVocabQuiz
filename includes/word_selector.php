<?php

function shouldLoadNewWord()
{
    return (
        !isset($_SESSION['current_word']) ||
        isset($_POST['next']) ||
        isset($_POST['correct']) ||
        isset($_POST['incorrect'])
    );
}

function initializeRecentWordIds()
{
    if (
        !isset($_SESSION['recent_word_ids']) ||
        !is_array($_SESSION['recent_word_ids'])
    ) {
        $_SESSION['recent_word_ids'] = [];
    }
}

function getRecentIdsForSpacing()
{
    initializeRecentWordIds();

    return array_slice(
        $_SESSION['recent_word_ids'],
        -6
    );
}

function getOwedCard(
    $conn,
    $userId,
    $book,
    $lesson,
    $wordGroup,
    $recentIds
) {
    $sql = "
        SELECT
            w.english,
            w.arabic_diacritics,
            w.arabic_shadda,
            w.book,
            w.lesson,
            w.id,

            CASE
                WHEN ar.word_id IS NOT NULL
                     AND en.word_id IS NULL
                THEN 'flashcards_en_ar'

                WHEN en.word_id IS NOT NULL
                     AND ar.word_id IS NULL
                THEN 'flashcards_ar_en'
            END AS missing_mode

        FROM words w

        LEFT JOIN user_word_progress ar
            ON ar.word_id = w.id
            AND ar.user_id = :user_id_ar
            AND ar.mode = 'flashcards_ar_en'

        LEFT JOIN user_word_progress en
            ON en.word_id = w.id
            AND en.user_id = :user_id_en
            AND en.mode = 'flashcards_en_ar'

        WHERE w.book = :book
        AND w.lesson = :lesson
        AND (
            :word_group1 = 'all'
            OR w.word_group = :word_group2
        )
        AND (
            (ar.word_id IS NOT NULL AND en.word_id IS NULL)
            OR
            (en.word_id IS NOT NULL AND ar.word_id IS NULL)
        )
    ";

    $params = [
        ':user_id_ar' => $userId,
        ':user_id_en' => $userId,
        ':book' => $book,
        ':lesson' => $lesson,
        ':word_group1' => $wordGroup,
        ':word_group2' => $wordGroup
    ];

    if (!empty($recentIds)) {

        $placeholders = [];

        foreach ($recentIds as $i => $rid) {

            $ph = ':recent_' . $i;

            $placeholders[] = $ph;
            $params[$ph] = $rid;
        }

        $sql .=
            " AND w.id NOT IN (" .
            implode(',', $placeholders) .
            ")";
    }

    $sql .= " ORDER BY RAND() LIMIT 1";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetch(PDO::FETCH_ASSOC);
}

// Rule 12/12c: owed and new-word eligibility are now decided by a
// single random draw between whichever categories are currently
// eligible, rather than a deterministic priority order (Rule 18
// forbids "clear all owed, then new"; Rule 35a forbids a backlog
// completely blocking other categories from appearing).
function shouldShowOwedCard(
    $owedCard,
    $mode,
    $userId,
    $availableNewCards,
    $continuousModeActive,
    $newWordIntroductionSafe
) {
    if (
        $mode !== 'flashcards' ||
        !$userId ||
        empty($owedCard) ||
        $availableNewCards <= 0
    ) {
        return false;
    }

    // Rule 12: before the 10th introduced direction, there is no
    // capacity evaluation yet - owed and new are both freely
    // eligible, chosen at random (Rule 23e).
    if (!$continuousModeActive) {
        return (rand(0, 1) === 1);
    }

    // Rule 12c: if introducing a new word right now would leave the
    // owed backlog unable to be finished within remaining budget,
    // a new word is not a genuine choice - owed is the only
    // eligible option.
    if (!$newWordIntroductionSafe) {
        return true;
    }

    // Both owed and new are genuinely safe and eligible - a fair
    // coin decides, so neither is forced first, last, or in a fixed
    // order (Rule 18, 23c, 35a).
    return (rand(0, 1) === 1);
}

function assignOwedCard($owedCard)
{
    $_SESSION['current_word'] = $owedCard;

    $_SESSION['current_word']['learning_step'] = -1;

    $trackingMode =
        $owedCard['missing_mode'];

    $_SESSION['direction'] =
        $trackingMode === 'flashcards_ar_en'
            ? 'arabic_to_english'
            : 'english_to_arabic';

    return $trackingMode;
}

function getNextStudyCard(
    $conn,
    $userId,
    $trackingMode,
    $book,
    $lesson,
    $wordGroup,
    $availableNewCards,
    $continuousModeActive,
    $newWordIntroductionSafe,
    $recentIds = []
) {
    $mustExcludeNewWords =
        ($availableNewCards <= 0) ||
        ($continuousModeActive && !$newWordIntroductionSafe);

    if ($wordGroup === 'all') {

        $baseSql = "
            SELECT
                w.english,
                w.arabic_diacritics,
                w.arabic_shadda,
                w.book,
                w.lesson,
                w.id,

                COALESCE(p.learning_step, -1) AS learning_step,

                CASE
                    WHEN p.learning_step IS NULL THEN 1
                    ELSE 0
                END AS is_new

            FROM words w

            LEFT JOIN user_word_progress p
                ON p.word_id = w.id
                AND p.user_id = :user_id
                AND p.mode = :mode

            WHERE w.book = :book
                AND w.lesson = :lesson

                AND (
                    p.word_id IS NULL
                    OR p.learning_step BETWEEN 0 AND 2
                    OR (
                        p.learning_step >= 3
                        AND p.next_review IS NOT NULL
                        AND p.next_review <= NOW()
                    )
                )
        ";

        $orderSql = "
            ORDER BY

                CASE

                    WHEN p.learning_step >= 3
                         AND p.next_review IS NOT NULL
                         AND p.next_review <= NOW()
                        THEN 1

                    WHEN p.learning_step IS NULL
                        THEN 1

                    WHEN p.learning_step BETWEEN 0 AND 2
                        THEN 1

                    ELSE 2

                END,

                RAND()

            LIMIT 1
        ";

        if ($mustExcludeNewWords) {
            $orderSql = "AND p.learning_step IS NOT NULL " . $orderSql;
        }

        $params = [
            ':user_id' => $userId,
            ':mode'    => $trackingMode,
            ':book'    => $book,
            ':lesson'  => $lesson
        ];

        $result = false;

        if (!empty($recentIds)) {

            $placeholders = [];
            $excludeParams = [];

            foreach ($recentIds as $i => $rid) {

                $ph = ':recent_' . $i;

                $placeholders[] = $ph;
                $excludeParams[$ph] = $rid;
            }

            $sqlWithExclusion =
                $baseSql
                . " AND w.id NOT IN (" . implode(',', $placeholders) . ")"
                . $orderSql;

            $stmt = $conn->prepare($sqlWithExclusion);
            $stmt->execute($params + $excludeParams);

            $result = $stmt->fetch();
        }

        // Fallback: if the recency-spacing exclusion leaves nothing
        // eligible, fall back to the full pool. Spacing is a
        // preference (Rule 23c), not a guarantee, and must yield
        // when it is the only thing blocking a card that Rule
        // 17/35a require to remain eligible for participation.
        if ($result === false) {

            $stmt = $conn->prepare($baseSql . $orderSql);
            $stmt->execute($params);

            $result = $stmt->fetch();
        }

        return $result;
    }

    $baseSql = "
        SELECT
            w.english,
            w.arabic_diacritics,
            w.arabic_shadda,
            w.book,
            w.lesson,
            w.id,

            COALESCE(p.learning_step, -1) AS learning_step,

            CASE
                WHEN p.learning_step IS NULL THEN 1
                ELSE 0
            END AS is_new

        FROM words w

        LEFT JOIN user_word_progress p
            ON p.word_id = w.id
            AND p.user_id = :user_id
            AND p.mode = :mode

        WHERE w.book = :book
            AND w.lesson = :lesson
            AND w.word_group = :word_group

            AND (
                p.word_id IS NULL
                OR p.learning_step BETWEEN 0 AND 2
                OR (
                    p.learning_step >= 3
                    AND p.next_review IS NOT NULL
                    AND p.next_review <= NOW()
                )
            )
    ";

    $orderSql = "
        ORDER BY

            CASE

                WHEN p.learning_step >= 3
                     AND p.next_review IS NOT NULL
                     AND p.next_review <= NOW()
                    THEN 1

                WHEN p.learning_step IS NULL
                    THEN 1

                WHEN p.learning_step BETWEEN 0 AND 2
                    THEN 1

                ELSE 2

            END,

        RAND()

        LIMIT 1
    ";

    if ($mustExcludeNewWords) {
        $orderSql = "AND p.learning_step IS NOT NULL " . $orderSql;
    }

    $params = [
        ':user_id'    => $userId,
        ':mode'       => $trackingMode,
        ':book'       => $book,
        ':lesson'     => $lesson,
        ':word_group' => $wordGroup
    ];

    $result = false;

    if (!empty($recentIds)) {

        $placeholders = [];
        $excludeParams = [];

        foreach ($recentIds as $i => $rid) {

            $ph = ':recent_' . $i;

            $placeholders[] = $ph;
            $excludeParams[$ph] = $rid;
        }

        $sqlWithExclusion =
            $baseSql
            . " AND w.id NOT IN (" . implode(',', $placeholders) . ")"
            . $orderSql;

        $stmt = $conn->prepare($sqlWithExclusion);
        $stmt->execute($params + $excludeParams);

        $result = $stmt->fetch();
    }

    if ($result === false) {

        $stmt = $conn->prepare($baseSql . $orderSql);
        $stmt->execute($params);

        $result = $stmt->fetch();
    }

    return $result;
}