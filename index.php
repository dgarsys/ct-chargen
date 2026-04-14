<?php

/*

-----------------------------------------------------------------------------------------------------------
        Index / Global Routing Page

            - Used to set up global functions that will still be in effect for subpages

            - Used to catch incoming URL and call the appropriate page, API, or Fragment

            - Each Call will include the needed header / footer bits if a full page

            - All other code includeing $_GET reads, etc., are handled in the main page code, 
                and if swapped in, within teh fragments


-----------------------------------------------------------------------------------------------------------


--------------------------------------------------------------------------------
        Global Functions

            - eecho : echo encapsulation for htmlspecialchars()
            - roll2d6 : return array ['die1', 'die2', 'total'] from two 1d6 rolls
            - rolld6 : return a random value of 1-6 as if rolled on one die
            - var_echo : a wrapper to dump strings out of a var dump so we can then wrap them in a htmlspecialchars
            - generateUPP : given a charstat subarray, generates a hex-coded Traveller UPP string

--------------------------------------------------------------------------------
*/

function isHtmxRequest() {
    return isset($_SERVER['HTTP_HX_REQUEST']) && $_SERVER['HTTP_HX_REQUEST'] === 'true';
}

function charFragmentHeader($pageTitle, $viewDir) {
    if (!isHtmxRequest()) {
        require $viewDir . 'parts/aheader.php';
        echo '<h1>Classic Traveller Character Generator</h1>';
        echo '<div id="char-container">';
        echo '<div id="charapp">';
    }
}

function charFragmentFooter($viewDir) {
    if (!isHtmxRequest()) {
        echo '</div>'; // close #charapp
        echo '<div id="charlog"></div>';
        echo '</div>'; // close #char-container
        require $viewDir . 'parts/afooter.php';
    }
}

function eecho($echoString) {
    echo( htmlspecialchars($echoString, ENT_QUOTES, 'UTF-8') );
}

function roll2d6(): array {
    $d1 = rand(1, 6);
    $d2 = rand(1, 6);
    return ['die1' => $d1, 'die2' => $d2, 'total' => $d1 + $d2];
}

function rolld6() {
        return rand(1,6);
}

function var_echo($echoVar) {
        ob_start();
        var_dump($echoVar);
        $vd_result = ob_get_clean();
        eecho($vd_result);
}


function maxCharSkills($charStats) {
        // passing in teh "stats" block of $charSheet
        $maxSkills = $charStats['int']['num'] + $charStats['edu']['num'];
        return $maxSkills;
}



/*
-------------------------------------------------------------

GenerateUPP :   given a charstat subarray, generates a  
                hex-coded Traveller UPP string

                Structure: 
                        array [
                                'str' => [
                                        numeric => str,
                                        hex => str,
                                ],
                                'dex' => [...],
                                'end' => [...],
                                'int' => [...],
                                'edu' => [...],
                                'soc' => [...],                       
                        ]

-------------------------------------------------------------
*/

function generateUPP ( $charStats) {
        //takes an array with hex values for the siz main stats and generates a UPP string
        $uppString = $charStats['str']['hex'].$charStats['dex']['hex'].$charStats['end']['hex'].$charStats['int']['hex'].$charStats['edu']['hex'].$charStats['soc']['hex'];
        return $uppString;
}



/*
--------------------------------------------------------------------------------
        Global Defaults for this page and all included pages via router below
--------------------------------------------------------------------------------
*/
$baseURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/';
$baseTitle = "Traveller Dev | ";        // Base Title for tabs

$request = $_SERVER['REQUEST_URI'];     // Getting the request URI and parameters
$viewDir = __DIR__ . '/views/';         // Base directory for views
$apiDir = __DIR__ . '/api/';            // Base directory for api and fragment calls
$baseDIR = __DIR__;                     // Base directory , period

$secureDir = __DIR__ . '/../secureconfig/';  // Base directory for things like DB credentials and connection includes




/*
--------------------------------------------------------------------------------
        Include DB Conenctions
--------------------------------------------------------------------------------
*/
require $secureDir."pdoset.php";
require $baseDIR.'/lib/char_constants.php';






/*
--------------------------------------------------------------------------------

    Routing Handler - full pages and /API switches


    NOTE : on pages must set page titles before calling header php
    NOTE : any full apge requires an included header , main, and footer

    First block is full pages

--------------------------------------------------------------------------------
*/

switch (true):
        case  ($request === '')  :
        case ( $request ==='/' ) :
                $pageTitle=$baseTitle."Home";
                require $viewDir . "parts/aheader.php";
                require $viewDir . 'home.php';
                require $viewDir . "parts/afooter.php";
                break;


        case  preg_match('/character/', $request)  :                    // character app page
                $pageTitle=$baseTitle."CT Character Generator";
                require $viewDir . "parts/aheader.php";
                require $viewDir . 'charapp/ctgen.php';
                require $viewDir . "parts/afooter.php";
                break;
/*
--------------------------------------------------------------------------------
    API BLOCK 
--------------------------------------------------------------------------------
*/
        case preg_match('/api\/roll/', $request)  :                     //roll.php test API call
                require $apiDir."roll/roll.php";
                break;
  
 /*
--------------------------------------------------------------------------------
    Inner HTML  BLOCK 
--------------------------------------------------------------------------------
*/
        case preg_match('/api\/chargen\/startchar/', $request) :        // CHARgen : call first starting page
                require $apiDir."chargen/01start.php";
                break;

       case preg_match('/api\/chargen\/rollchar/', $request) :          // CHARgen : call stage 2 to roll stats
                require $apiDir."chargen/02roll.php";
                break;

        case preg_match('/api\/chargen\/choosecareer/', $request) :     // CHARgen : call stage 3 to choose a career
                $pageTitle=$baseTitle."CT Character Generator";
                charFragmentHeader($pageTitle, $viewDir);
                require $apiDir."chargen/03choosecareer.php";
                charFragmentFooter($viewDir);
                break;

        case preg_match('/api\/chargen\/firsttermstart/', $request) :   // CHARgen : call stage 4 - enlistment or drafting
                $pageTitle=$baseTitle."CT Character Generator";
                charFragmentHeader($pageTitle, $viewDir);
                require $apiDir."chargen/04firsttermstart.php";
                charFragmentFooter($viewDir);
                break;

        case preg_match('/api\/chargen\/term/', $request) :             // CHARgen : Call term resolution, we will loop through this
                $pageTitle=$baseTitle."CT Character Generator";
                charFragmentHeader($pageTitle, $viewDir);
                require $apiDir."chargen/05termhandler.php";
                charFragmentFooter($viewDir);
                break;

        case preg_match('/api\/chargen\/skillroll/', $request) :        // CHARgen : Call skill roll
                $pageTitle=$baseTitle."CT Character Generator";
                charFragmentHeader($pageTitle, $viewDir);
                require $apiDir."chargen/06skillroll.php";
                charFragmentFooter($viewDir);
                break;

        case preg_match('/api\/chargen\/reenlist/', $request) :         // CHARgen : Call re-enlistment and aging
                $pageTitle=$baseTitle."CT Character Generator";
                charFragmentHeader($pageTitle, $viewDir);
                require $apiDir."chargen/07reenlist.php";
                charFragmentFooter($viewDir);
                break;






/*
--------------------------------------------------------------------------------
        Default 404
--------------------------------------------------------------------------------
*/
        default:
                http_response_code(404);
                $pageTitle=$baseTitle."404 - page not found";
                require $viewDir . "parts/aheader.php";
                require $viewDir . '404/404.php';
                require $viewDir . "parts/afooter.php";


        endswitch;


?>