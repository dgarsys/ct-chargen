<h1>Classic Traveller Character Generator</h1>
<div id="char-container">
    <div id="charapp">
        <p>This webapp will generate a character using the LBB rules as presented in the Traveller Book. This is slightly different than the earlier LBB edition of the rules, but does not include the later add-on books Mercenary, High Guard, Scout, or Merchant Prince</p>
        <button hx-get="/api/chargen/startchar"
                hx-target="#charapp"
                hx-swap="innerHTML">
            Begin Character Generation
        </button>
    </div>
    <div id="charlog"></div>
</div>
