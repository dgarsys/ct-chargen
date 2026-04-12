

<div class="">
 <h1>Old Home for validation </h1>
 <h2> Path is :  <?php eecho($request)  ?> !</h2>
 <p>This is a paragraph. Hi this is the home page</p>
 <p>This is another paragraph.</p>
  <p> Go to H3 <a href="home"> home3</a></p>


<div id="dieroller">
    <?php $dieInit=true; ?>
    <div id="dieroller_header">
        <h1>Roll a Die?</h1>
    </div>
    <div id="dieroller_button">
        <button type="button"  
            hx-trigger="click" 
            hx-get="/api/roll/" 
            hx-swap="innerHTML"
            hx-target="#dieroller_result_print" 
            hx-replace-url="/home/dieroll"
        >
        Roll it!!</button>
    </div>
    <div id="dieroller_result">
        <p id="dieroller_result_print"><?php if ($dieInit) eecho('Nothing rolled yet.'); ?></p>
    </div>     

</div>  

<?php   

/*

<input type="text" name="q"
    hx-get="/trigger_delay"
    hx-trigger="click"
    hx-target="#search-results"
    placeholder="Search...">
<div id="search-results"></div>
*/






/*
    apache_get_modules();

if (in_array('mod_rewrite', apache_get_modules())) {
    echo "mod_rewrite is enabled";
} else {
    echo "mod_rewrite is not enabled";
}
*/

// phpinfo();


?>


</div>
