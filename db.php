<?php

function connectToDB()
{
    try {
        if ($_SERVER['HTTP_HOST'] === 'localhost') {

            $dsn  = 'mysql:host=localhost;dbname=arabic_quiz;charset=utf8mb4';
            $user = 'root';
            $pass = '1550';

        } else {

            $dsn  = 'mysql:host=sql100.infinityfree.com;dbname=if0_41601904_arabic_quiz;charset=utf8mb4';
            $user = 'if0_41601904';
            $pass = '3rXxIcrpYNPa8Pv';
        }

        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        
     } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        die('Service temporarily unavailable. Please try again shortly.');
    }
}