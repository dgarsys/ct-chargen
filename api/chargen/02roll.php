<?php

// Roll 2d6 for each stat and build the stats array
$stats       = [];
$rollDetails = [];
foreach (array_keys($statDefs) as $stat) {
    $roll = roll2d6();
    $stats[$stat] = [
        'num' => $roll['total'],
        'hex' => strtoupper(dechex($roll['total'])),
    ];
    $rollDetails[$stat] = $roll;
}

$upp = generateUPP($stats);

$charData  = [ 'character' => [ 'stats' => $stats ] ];
$charState = base64_encode(json_encode($charData));

?>
<table>
    <tbody>
    <?php foreach ($statDefs as $key => $fullName): ?>
        <tr>
            <td><?= htmlspecialchars($fullName) ?> (<?= htmlspecialchars(strtoupper($key)) ?>)</td>
            <td class="stat-value">
                <?= $stats[$key]['num'] ?> (<?= htmlspecialchars($stats[$key]['hex']) ?>)
                &nbsp;<small><?= (int)$rollDetails[$key]['die1'] ?>, <?= (int)$rollDetails[$key]['die2'] ?></small>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p><strong>UPP:</strong> <?= htmlspecialchars($upp) ?></p>

<form hx-get="/api/chargen/rollchar" hx-target="#charapp" hx-swap="innerHTML">
    <button type="submit">Reroll</button>
</form>

<form hx-get="/api/chargen/choosecareer" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true">
    <input type="hidden" name="charState" value="<?= htmlspecialchars($charState) ?>" />
    <button type="submit">Choose Career</button>
</form>

<div class="diagnosticsdiv">
    <strong>DIAGNOSTICS — Step 2: Stat Roll</strong><br />
    UPP: <?= htmlspecialchars($upp) ?><br />
    <br />
    <?php foreach ($statDefs as $key => $fullName): ?>
        <?= htmlspecialchars($fullName) ?> (<?= strtoupper($key) ?>):
        <?= $stats[$key]['num'] ?> / <?= htmlspecialchars($stats[$key]['hex']) ?><br />
    <?php endforeach; ?>
    <br />
    charState (raw): <?= htmlspecialchars($charState) ?><br />
    charState (decoded):<br />
    <pre><?= htmlspecialchars(json_encode($charData, JSON_PRETTY_PRINT)) ?></pre>
</div>
