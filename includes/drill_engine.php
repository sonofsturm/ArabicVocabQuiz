<?php

// ======================================
// DRILL ENGINE
// Shared, topic-agnostic mechanics for all
// "Concept + Drill" games (verb Forms,
// negation, and future topics). Topic files
// supply their own content/data; this file
// only handles scoring, answer-checking,
// and multiple-choice option building.
// ======================================

// ----------------------------------------
// SESSION-BASED SCORE TRACKING
// Session-only for now (no DB persistence).
// Each drill is tracked independently by a
// caller-supplied key, e.g. "verb_forms_identify"
// or "verb_forms_produce", so different drills
// (and future topics) never collide.
// ----------------------------------------

function initDrillScore(string $drillKey): void
{
    if (!isset($_SESSION['drills'])) {
        $_SESSION['drills'] = [];
    }

    if (!isset($_SESSION['drills'][$drillKey])) {
        $_SESSION['drills'][$drillKey] = [
            'correct' => 0,
            'total' => 0
        ];
    }
}

function recordDrillAnswer(string $drillKey, bool $wasCorrect): void
{
    initDrillScore($drillKey);

    $_SESSION['drills'][$drillKey]['total']++;

    if ($wasCorrect) {
        $_SESSION['drills'][$drillKey]['correct']++;
    }
}

function getDrillScore(string $drillKey): array
{
    initDrillScore($drillKey);

    return $_SESSION['drills'][$drillKey];
}

function resetDrillScore(string $drillKey): void
{
    $_SESSION['drills'][$drillKey] = [
        'correct' => 0,
        'total' => 0
    ];
}

// ----------------------------------------
// TYPE-IN ANSWER CHECKING
//
// Short vowel marks (fatha/damma/kasra/sukun)
// and tatweel are stripped before comparing -
// not required to be marked correct.
//
// The shadda (consonant-doubling mark) is kept
// and IS required when the correct answer has
// one. Doubling is how several Forms are
// structurally distinguished from Form I (e.g.
// Form II vs Form I reduce to the identical
// three letters without it) - dropping it would
// mean the drill could never test whether that
// specific pattern was actually learned.
// ----------------------------------------

function normalizeArabicAnswer(string $text): string
{
    $stripChars = [
        "\u{064B}", // fathatan
        "\u{064C}", // dammatan
        "\u{064D}", // kasratan
        "\u{064E}", // fatha
        "\u{064F}", // damma
        "\u{0650}", // kasra
        "\u{0652}", // sukun
        "\u{0640}", // tatweel
    ];

    $normalized = str_replace($stripChars, '', $text);

    return trim($normalized);
}

function checkTypedAnswer(string $userAnswer, string $correctAnswer): bool
{
    $normalizedUser = normalizeArabicAnswer($userAnswer);
    $normalizedCorrect = normalizeArabicAnswer($correctAnswer);

    return $normalizedUser === $normalizedCorrect;
}

// ----------------------------------------
// MULTIPLE CHOICE OPTION BUILDING
//
// $correctValue is always included. $distractorPool
// is the caller-supplied list of plausible wrong
// answers to draw from (e.g. this root's OTHER
// attested Forms) - the engine doesn't know or
// care what domain the values come from.
// ----------------------------------------

function buildMultipleChoiceOptions(
    string $correctValue,
    array $distractorPool,
    int $optionCount = 4
): array {
    // Remove the correct value from the pool if it
    // somehow appears there, to avoid a duplicate.
    $distractorPool = array_values(array_filter(
        $distractorPool,
        function ($value) use ($correctValue) {
            return $value !== $correctValue;
        }
    ));

    shuffle($distractorPool);

    $neededDistractors = max(0, $optionCount - 1);

    $chosenDistractors = array_slice(
        $distractorPool,
        0,
        $neededDistractors
    );

    $options = array_merge([$correctValue], $chosenDistractors);

    shuffle($options);

    return $options;
}