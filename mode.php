<?php
session_start();

$isLoggedIn = isset($_SESSION['user_id']);

// Save selected book

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['lesson'])) {
        $_SESSION['lesson'] = $_POST['lesson'];
    }
    if (isset($_POST['mode'])) {
        $_SESSION['mode'] = $_POST['mode'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Select Mode</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include 'header.php'; ?>

<div class="quiz-container">
<h1>Select Quiz Mode</h1>

<form action="quiz.php" method="POST">

    <label>
        <input type="radio" name="mode" value="shadda" required>
        Spelling with Shadda
    </label><br>

    <label>
        <input type="radio" name="mode" value="diacritics">
        Spelling with Diacritics
    </label><br>

    <label>
        <input type="radio" name="mode" value="flashcards">
        Flash Cards
    </label><br><br>

    <button type="submit">Start Quiz</button>
</form>
</div>
</body>
</html>