<?php

// Rule 12: the first 10 newly introduced flashcard directions carry
// no capacity evaluation - this simply reports whether that 10th
// direction has been reached yet for this book/lesson/word_group.
// Unlike the old periodic model, this count is never reset - once
// true, it stays true, because Rule 12c makes evaluation continuous
// from that point forward rather than re-arming every 10 directions.
function hasContinuousRebalanceStarted($introducedDirections)
{
    return $introducedDirections >= 10;
}

// Rule 12c's guarantee mechanism. A new word's first direction may
// only be introduced if, after spending that one unit of today's
// budget, there is still enough left to also finish paying off
// every direction currently owed (Rule 17) PLUS this new word's own
// second direction. Written out: introducing a new word consumes 1
// unit now; it must leave availableNewCards - 1 >= owedCount + 1,
// which rearranges to the check below.
//
// This keeps the invariant (availableNewCards - owedCount) >= 0
// true at every point, which is what guarantees owedCount reaches 0
// no later than availableNewCards reaches 0 - see the conversation
// record for the full proof.
function isNewWordIntroductionSafe($availableNewCards, $owedCount)
{
    return $availableNewCards >= ($owedCount + 2);
}