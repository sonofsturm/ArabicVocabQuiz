<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once 'db.php';

$conn = connectToDB();

$stmt = $conn->prepare("
    SELECT
        first_name,
        last_name,
        email,
        created_at
    FROM users
    WHERE id = :user_id
");

$stmt->execute([
    ':user_id' => $_SESSION['user_id']
]);

$user = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT
        COUNT(*) AS total_words,
        SUM(times_correct) AS total_correct,
        SUM(times_incorrect) AS total_incorrect
    FROM user_word_progress
    WHERE user_id = :user_id
");

$stmt->execute([
    ':user_id' => $_SESSION['user_id']
]);

$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$stmt = $conn->prepare("
    SELECT DISTINCT study_date
    FROM user_daily_stats
    WHERE user_id = :user_id
    ORDER BY study_date DESC
");

$stmt->execute([
    ':user_id' => $_SESSION['user_id']
]);

$studyDates = $stmt->fetchAll(PDO::FETCH_COLUMN);

$streak = 0;

if (!empty($studyDates)) {

    $expectedDate = new DateTime();

    foreach ($studyDates as $studyDate) {

        if ($studyDate === $expectedDate->format('Y-m-d')) {

            $streak++;

            $expectedDate->modify('-1 day');

        } else {

            break;
        }
    }
}

if (!$user) {
    die('User not found.');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Account</title>
    <link rel="stylesheet" href="style.css?v=5">
</head>
<body>

<?php include 'header.php'; ?>

<div class="quiz-container">

    <h1>My Account</h1>

    <div class="study-info">

        <p>
            <strong>Name:</strong>
            <?php echo htmlspecialchars(
                $user['first_name'] . ' ' . $user['last_name']
            ); ?>

        </p>

        <p>
            <strong>Email:</strong>
            <?php echo htmlspecialchars($user['email']); ?>
        </p>

        <p>
            <strong>Member Since:</strong>
            <?php echo htmlspecialchars($user['created_at']); ?>
        </p>

    </div>
    
    <h2>Quick Stats</h2>

<div class="study-info">

    <p>
        <strong>Cards Studied:</strong>
        <?php echo (int) $stats['total_words']; ?>
    </p>

    <p>
        <strong>Correct Answers:</strong>
        <?php echo (int) ($stats['total_correct'] ?? 0); ?>
    </p>

    <p>
        <strong>Incorrect Answers:</strong>
        <?php echo (int) ($stats['total_incorrect'] ?? 0); ?>
    </p>

</div>
<h2>Daily Streak</h2>
<div class="study-info">
 
<p>
🔥 <strong>Current Streak:</strong>
<?php echo $streak; ?>
 day<?php echo ($streak == 1 ? '' : 's'); ?>
</p>
</div>

<br><br>

    <br><br>

    <a href="change_password.php">
        <button type="button">
            Change Password
        </button>
    </a>
<br><br>

<a href="index.php">
← Back to Home
</a>
</div>

</body>
</html>