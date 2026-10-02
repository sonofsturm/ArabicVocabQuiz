<?php

// ======================================
// VERB FORMS - STARTER DATA
// Each root lists only its ATTESTED common
// Forms - gaps are intentional, not missing
// data (see conversation notes: not every
// root has a real word in every Form).
// Add more roots/Forms here later without
// touching any page or engine code.
// ======================================

function getVerbFormsData(): array
{
    return [
        [
            'root' => 'ك-ت-ب',
            'meaning' => 'write',
            'forms' => [
                'I'    => ['word' => 'كَتَبَ',   'meaning' => 'he wrote'],
                'II'   => ['word' => 'كَتَّبَ',  'meaning' => 'he had (someone) write / registered'],
                'III'  => ['word' => 'كَاتَبَ',  'meaning' => 'he corresponded with'],
                'IV'   => ['word' => 'أَكْتَبَ',  'meaning' => 'he dictated'],
                'VIII' => ['word' => 'اِكْتَتَبَ', 'meaning' => 'he subscribed/enrolled'],
                'X'    => ['word' => 'اِسْتَكْتَبَ', 'meaning' => 'he asked someone to write'],
            ]
        ],
        [
            'root' => 'ع-ل-م',
            'meaning' => 'know',
            'forms' => [
                'I'   => ['word' => 'عَلِمَ',  'meaning' => 'he knew'],
                'II'  => ['word' => 'عَلَّمَ', 'meaning' => 'he taught'],
                'IV'  => ['word' => 'أَعْلَمَ', 'meaning' => 'he informed'],
                'V'   => ['word' => 'تَعَلَّمَ', 'meaning' => 'he learned'],
            ]
        ],
        [
            'root' => 'خ-ر-ج',
            'meaning' => 'exit',
            'forms' => [
                'I'  => ['word' => 'خَرَجَ',  'meaning' => 'he went out'],
                'IV' => ['word' => 'أَخْرَجَ', 'meaning' => 'he took out / produced'],
                'V'  => ['word' => 'تَخَرَّجَ', 'meaning' => 'he graduated'],
                'X'  => ['word' => 'اِسْتَخْرَجَ', 'meaning' => 'he extracted'],
            ]
        ],
    ];
}

function getVerbFormPatterns(): array
{
    return [
        'I' => [
            'pattern' => 'فَعَلَ',
            'change' => [
                ['text' => 'the base pattern - no change applied'],
            ]
        ],
        'II' => [
            'pattern' => 'فَعَّلَ',
            'change' => [
                ['text' => 'the middle root letter is doubled (gemination)'],
            ]
        ],
        'III' => [
            'pattern' => 'فَاعَلَ',
            'change' => [
                ['text' => 'a long alif ('],
                ['arabic' => 'ا'],
                ['text' => ') is inserted after the FIRST root letter'],
            ]
        ],
        'IV' => [
            'pattern' => 'أَفْعَلَ',
            'change' => [
                ['text' => 'a hamza ('],
                ['arabic' => 'أ'],
                ['text' => ') is prefixed before the root'],
            ]
        ],
        'V' => [
            'pattern' => 'تَفَعَّلَ',
            'change' => [
                ['arabic' => 'ت'],
                ['text' => ' is prefixed AND the middle root letter is doubled (Form II + '],
                ['arabic' => 'ت'],
                ['text' => ')'],
            ]
        ],
        'VIII' => [
            'pattern' => 'اِفْتَعَلَ',
            'change' => [
                ['arabic' => 'ت'],
                ['text' => ' is inserted right after the FIRST root letter, with '],
                ['arabic' => 'اِ'],
                ['text' => ' prefixed before it'],
            ]
        ],
        'X' => [
            'pattern' => 'اِسْتَفْعَلَ',
            'change' => [
                ['arabic' => 'اِسْتَ'],
                ['text' => ' is prefixed before the root'],
            ]
        ],
    ];
}