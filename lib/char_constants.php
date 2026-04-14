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
    34 => [
        ['stat' => 'str', 'target' => 8],
        ['stat' => 'dex', 'target' => 7],
        ['stat' => 'end', 'target' => 8],
    ],
    38 => [
        ['stat' => 'str', 'target' => 7],
        ['stat' => 'dex', 'target' => 7],
        ['stat' => 'end', 'target' => 7],
        ['stat' => 'int', 'target' => 8],
    ],
];
