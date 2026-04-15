<?php

/*
--------------------------------------------------------------------------------
    Character Generation Constants

    $statDefs : ordered array of all six core stats
        key   => three-letter abbreviation (matches $charStats array keys)
        value => full stat name
--------------------------------------------------------------------------------
*/

/*
--------------------------------------------------------------------------------
    Service join phrases — keyed by draft/service ID
    Used to generate natural-language enlistment result text
--------------------------------------------------------------------------------
*/
$joinPhrases = [
    1 => 'enlisted in the Navy',
    2 => 'enlisted in the Marines',
    3 => 'enlisted in the Army',
    4 => 'been accepted by the Scout Service',
    5 => 'signed on with Merchant Services',
    6 => 'entered Other Careers',
];

$statDefs = [
    'str' => 'Strength',
    'dex' => 'Dexterity',
    'end' => 'Endurance',
    'int' => 'Intelligence',
    'edu' => 'Education',
    'soc' => 'Social Standing',
];

/*
--------------------------------------------------------------------------------
    Aging table — keyed by exact character age at which rolls are triggered.
    Each entry is an array of per-stat rolls: ['stat' => key, 'target' => int].
    Fail (roll total < target) means -1 to that stat (minimum 1).
    Populate with full CT rulebook values when available.
--------------------------------------------------------------------------------
*/
$agingTable = [
    // stub — user to populate with full CT rulebook values
    // "target" is the value to be rolled on 2d6 or higher to avoid aging effects
    // "penalty" is the amount the stat is dropped if the target is not met. 

    // Aging Crisis: If, as a result of aging, a characteristic is
    // reduced to zero, the character is considered to have had an
    // aging crisis and become quite ill. A basic saving throw of 8 +
    // applies to avoid death  The characteristic which was reduced to 
    // zero automatically becomes 1. 

    34 => [
        ['stat' => 'str', 'target' => 8, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 7, 'penalty' => -1],
        ['stat' => 'end', 'target' => 8, 'penalty' => -1],
    ],
    38 => [
        ['stat' => 'str', 'target' => 8, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 7, 'penalty' => -1],
        ['stat' => 'end', 'target' => 8, 'penalty' => -1],
    ],
    42 => [
        ['stat' => 'str', 'target' => 8, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 7, 'penalty' => -1],
        ['stat' => 'end', 'target' => 8, 'penalty' => -1],
    ],
    46=> [
        ['stat' => 'str', 'target' => 8, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 7, 'penalty' => -1],
        ['stat' => 'end', 'target' => 8, 'penalty' => -1],
    ],
    50 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 8, 'penalty' => -1],
        ['stat' => 'end', 'target' => 9, 'penalty' => -1],
    ],
    54 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 8, 'penalty' => -1],
        ['stat' => 'end', 'target' => 9, 'penalty' => -1],
    ],
    58 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 8, 'penalty' => -1],
        ['stat' => 'end', 'target' => 9, 'penalty' => -1],
    ],
    62 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -1],
        ['stat' => 'dex', 'target' => 8, 'penalty' => -1],
        ['stat' => 'end', 'target' => 9, 'penalty' => -1],
    ],
    66 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -2],
        ['stat' => 'dex', 'target' => 9, 'penalty' => -2],
        ['stat' => 'end', 'target' => 9, 'penalty' => -2],
        ['stat' => 'int', 'target' => 9, 'penalty' => -1],
    ],
    70 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -2],
        ['stat' => 'dex', 'target' => 9, 'penalty' => -2],
        ['stat' => 'end', 'target' => 9, 'penalty' => -2],
        ['stat' => 'int', 'target' => 9, 'penalty' => -1],
    ],
    74 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -2],
        ['stat' => 'dex', 'target' => 9, 'penalty' => -2],
        ['stat' => 'end', 'target' => 9, 'penalty' => -2],
        ['stat' => 'int', 'target' => 9, 'penalty' => -1],
    ],
    78 => [
        ['stat' => 'str', 'target' => 9, 'penalty' => -2],
        ['stat' => 'dex', 'target' => 9, 'penalty' => -2],
        ['stat' => 'end', 'target' => 9, 'penalty' => -2],
        ['stat' => 'int', 'target' => 9, 'penalty' => -1],
    ],
];
