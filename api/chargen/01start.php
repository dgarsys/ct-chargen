<?php
// STUB: Step 1 - Start character generation
// Returns initial character creation form fragment
?>
<p><strong>Step 1: Roll Stats</strong></p>
<p> Stats are rolled using 2D6 for each relevant value. Strength, Dexterity, Endurance, Intelligence, Education, and Social Standing. These influence which careers you can easily apply for, and your odds of promotion and survival. Also, the maximum number of skills a character can have is equal to the sum of their Intelligence and Education stats.</p>
<button hx-get="/api/chargen/rollchar"
        hx-target="#charapp"
        hx-swap="innerHTML">
    Roll Stats
</button>
