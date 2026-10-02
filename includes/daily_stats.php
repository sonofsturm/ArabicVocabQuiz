<?php
function loadDailyStats(
    PDO $conn,
    int $userId,
    string $book,
    string $lesson,
    string $wordGroup
): array {
    $stmtDaily = $conn->prepare("
        SELECT
            available_new_cards,
            last_replenished,
            introduced_directions_since_eval
        FROM user_daily_stats
        WHERE user_id = :user_id
        AND book = :book
        AND lesson = :lesson
        AND word_group = :word_group
    ");
    $stmtDaily->execute([
        ':user_id'    => $userId,
        ':book'       => $book,
        ':lesson'     => $lesson,
        ':word_group' => $wordGroup
    ]);
    $dailyRow = $stmtDaily->fetch(PDO::FETCH_ASSOC);
    if (!$dailyRow) {
        $stmtInit = $conn->prepare("
            INSERT INTO user_daily_stats
            (
                user_id,
                book,
                lesson,
                word_group,
                study_date,
                available_new_cards,
                last_replenished,
                introduced_directions_since_eval
            )
            VALUES
            (
                :user_id,
                :book,
                :lesson,
                :word_group,
                CURDATE(),
                20,
                CURDATE(),
                0
            )
        ");
        $stmtInit->execute([
            ':user_id'    => $userId,
            ':book'       => $book,
            ':lesson'     => $lesson,
            ':word_group' => $wordGroup
        ]);
        
        $dbToday = $conn
            ->query("SELECT CURDATE()")
            ->fetchColumn();
        
        return [
            'availableNewCards' => 20,
            'introducedDirections' => 0,
            'lastReplenished' => $dbToday
        ];
    }
    return [
        'availableNewCards' =>
            (int)$dailyRow['available_new_cards'],
        'introducedDirections' =>
            (int)$dailyRow['introduced_directions_since_eval'],
        'lastReplenished' =>
            $dailyRow['last_replenished']
    ];
}
function replenishDailyAllowance(
    PDO $conn,
    int $userId,
    string $book,
    string $lesson,
    string $wordGroup,
    int $availableNewCards,
    string $lastReplenished,
    string $today
): int {
    if ($lastReplenished === $today) {
        return $availableNewCards;
    }
    $availableNewCards = min(
        40,
        $availableNewCards + 20
    );
    $stmtUpdate = $conn->prepare("
        UPDATE user_daily_stats
        SET
            available_new_cards = :available,
            last_replenished = CURDATE()
        WHERE user_id = :user_id
        AND book = :book
        AND lesson = :lesson
        AND word_group = :word_group
    ");
    $stmtUpdate->execute([
        ':available'  => $availableNewCards,
        ':user_id'    => $userId,
        ':book'       => $book,
        ':lesson'     => $lesson,
        ':word_group' => $wordGroup
    ]);
    return $availableNewCards;
}