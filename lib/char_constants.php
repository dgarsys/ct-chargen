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
