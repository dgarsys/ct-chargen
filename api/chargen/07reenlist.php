<?php

// -----------------------------------------------------------------------
// Decode incoming state
// -----------------------------------------------------------------------
$charState = $_GET['charState'] ?? '';
$charData  = $charState ? json_decode(base64_decode($charState), true) : [];

$serviceId    = (int)($charData['character']['service'] ?? 0);
$currentTerms = (int)($charData['character']['terms']   ?? 0);
$currentAge   = (int)($charData['character']['age']     ?? 0);
$charStats    = &$charData['character']['stats'];

// Fetch career row for reEnlist target
$stmt = $pdo->prepare('SELECT * FROM priorService WHERE draft = :id');
$stmt->execute(['id' => $serviceId]);
$career = $stmt->fetch();

// -----------------------------------------------------------------------
// Mode detection
// -----------------------------------------------------------------------
$intent = $_GET['intent'] ?? '';    // 'reenlist' | 'muster' | ''
$mode   = $intent !== '' ? 'roll' : 'page';

// -----------------------------------------------------------------------
// Page mode: apply aging, render intent buttons
// -----------------------------------------------------------------------
if ($mode === 'page') {

    // Read and clear any result message from previous action
    $resultMessage = $charData['_lastResult'] ?? '';
    unset($charData['_lastResult']);

    // -- Aging rolls --
    $agingRolls     = [];
    $anyStatReduced = false;

    if (isset($agingTable[$currentAge])) {
        foreach ($agingTable[$currentAge] as $entry) {
            $statKey = $entry['stat'];
            $target  = (int)$entry['target'];
            $roll    = roll2d6();
            $reduced = $roll['total'] < $target;

            $agingRollEntry = [
                'stat'   => $statKey,
                'die1'   => $roll['die1'],
                'die2'   => $roll['die2'],
                'total'  => $roll['total'],
                'target' => $target,
                'result' => $reduced ? 'reduced' : 'no_effect',
            ];

            if ($reduced) {
                $newVal = max(1, $charData['character']['stats'][$statKey]['num'] - 1);
                $charData['character']['stats'][$statKey]['num'] = $newVal;
                $charData['character']['stats'][$statKey]['hex'] = strtoupper(dechex($newVal));
                $agingRollEntry['new_val'] = $newVal;
                $anyStatReduced = true;
            }

            $agingRolls[] = $agingRollEntry;
        }

        $charData['character']['log'][] = [
            'step'  => 'aging',
            'age'   => $currentAge,
            'rolls' => $agingRolls,
        ];
    }

    $newCharState  = base64_encode(json_encode($charData));
    $atTermMax     = ($currentTerms >= 7);
    $uppAfterAging = generateUPP($charData['character']['stats']);

    ?>

    <div class="term-working">

        <h2>Term <?= $currentTerms ?> — Re-enlistment</h2>

        <?php // --- Aging results --- ?>
        <h3>Aging</h3>
        <?php if (empty($agingRolls)): ?>
            <p>No aging effects this term.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr><th>Roll</th><th>Stat</th><th>Target</th><th>Result</th></tr>
                </thead>
                <tbody>
                <?php foreach ($agingRolls as $ar): ?>
                    <tr>
                        <td><?= (int)$ar['total'] ?> (<?= (int)$ar['die1'] ?>, <?= (int)$ar['die2'] ?>)</td>
                        <td><?= htmlspecialchars(strtoupper($ar['stat'])) ?></td>
                        <td><?= (int)$ar['target'] ?>+</td>
                        <td><?= $ar['result'] === 'reduced'
                                ? '<strong>Reduced to ' . (int)$ar['new_val'] . '</strong>'
                                : 'No effect' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($anyStatReduced): ?>
                <p>Current UPP: <strong><?= htmlspecialchars($uppAfterAging) ?></strong></p>
            <?php endif; ?>
        <?php endif; ?>

        <?php // --- Intent buttons --- ?>
        <h3>What would you like to do?</h3>

        <?php if (!$atTermMax): ?>
        <form hx-get="/api/chargen/reenlist"
              hx-target="#roll-result"
              hx-swap="innerHTML">
            <input type="hidden" name="charState" value="<?= htmlspecialchars($newCharState) ?>" />
            <input type="hidden" name="intent"    value="reenlist" />
            <button type="submit">Try to Re-enlist</button>
        </form>
        <?php endif; ?>

        <form hx-get="/api/chargen/reenlist"
              hx-target="#roll-result"
              hx-swap="innerHTML">
            <input type="hidden" name="charState" value="<?= htmlspecialchars($newCharState) ?>" />
            <input type="hidden" name="intent"    value="muster" />
            <button type="submit">Muster Out</button>
        </form>

        <?php if ($atTermMax): ?>
            <p><em>Maximum terms reached — re-enlistment not available.</em></p>
        <?php endif; ?>

        <div id="roll-result">
            <?php if ($resultMessage): ?>
                <p><?= $resultMessage ?></p>
            <?php endif; ?>
        </div>

    </div>

    <?php // Charlog OOB ?>
    <div id="charlog" <?= isHtmxRequest() ? 'hx-swap-oob="true"' : '' ?>>
        <?php require $viewDir . 'charapp/charlog.php'; ?>
    </div>

    <?php // Diagnostics ?>
    <div class="diagnosticsdiv">
        <strong>DIAGNOSTICS — Step 7: Re-enlistment</strong><br />
        Mode: page &nbsp;
        Age: <?= $currentAge ?> &nbsp;
        Terms: <?= $currentTerms ?> &nbsp;
        At term max: <?= $atTermMax ? 'yes' : 'no' ?><br />
        Aging rolls fired: <?= count($agingRolls) ?><br />
        <br />
        Updated charState (decoded):<br />
        <pre><?= htmlspecialchars(json_encode($charData, JSON_PRETTY_PRINT)) ?></pre>
    </div>

    <?php
    return; // end page mode
}

// -----------------------------------------------------------------------
// Roll mode: execute re-enlistment roll, determine outcome
// -----------------------------------------------------------------------

$roll           = roll2d6();
$reEnlistTarget = (int)($career['reEnlist'] ?? 6);

if ($roll['total'] === 12) {
    $outcome    = 'forced_reenlist';
    $resultText = "Rolled 12 (6, 6) — forced re-enlistment. Reporting for another term.";
    $nextRoute  = '/api/chargen/term';
} elseif ($intent === 'muster') {
    $outcome    = 'mustered_out';
    $resultText = "Rolled {$roll['total']} ({$roll['die1']}, {$roll['die2']}) — mustered out.";
    $nextRoute  = '/api/chargen/musterout';
} elseif ($roll['total'] >= $reEnlistTarget) {
    $outcome    = 'reenlisted';
    $resultText = "Rolled {$roll['total']} ({$roll['die1']}, {$roll['die2']}) — re-enlisted. (Target: {$reEnlistTarget}+)";
    $nextRoute  = '/api/chargen/term';
} else {
    $outcome    = 'failed_reenlist';
    $resultText = "Rolled {$roll['total']} ({$roll['die1']}, {$roll['die2']}) — failed re-enlistment (target: {$reEnlistTarget}+). Mustered out.";
    $nextRoute  = '/api/chargen/musterout';
}

$charData['character']['log'][] = [
    'step'   => 'reenlistment',
    'term'   => $currentTerms,
    'intent' => $intent,
    'die1'   => $roll['die1'],
    'die2'   => $roll['die2'],
    'total'  => $roll['total'],
    'target' => $reEnlistTarget,
    'result' => $outcome,
];

$charData['_lastResult'] = $resultText;
$newCharState = base64_encode(json_encode($charData));

header('HX-Retarget: #charapp');
header('HX-Reswap: innerHTML');

$atTermMax     = ($currentTerms >= 7);
$uppAfterAging = generateUPP($charData['character']['stats']);

?>

<div class="term-working">

    <h2>Term <?= $currentTerms ?> — Re-enlistment</h2>

    <h3>Aging</h3>
    <p><em>Aging was applied on page load.</em></p>

    <h3>What would you like to do?</h3>

    <?php if (!$atTermMax): ?>
    <form hx-get="/api/chargen/reenlist"
          hx-target="#roll-result"
          hx-swap="innerHTML">
        <input type="hidden" name="charState" value="<?= htmlspecialchars($newCharState) ?>" />
        <input type="hidden" name="intent"    value="reenlist" />
        <button type="submit" disabled>Try to Re-enlist</button>
    </form>
    <?php endif; ?>

    <form hx-get="/api/chargen/reenlist"
          hx-target="#roll-result"
          hx-swap="innerHTML">
        <input type="hidden" name="charState" value="<?= htmlspecialchars($newCharState) ?>" />
        <input type="hidden" name="intent"    value="muster" />
        <button type="submit" disabled>Muster Out</button>
    </form>

    <div id="roll-result">
        <p><?= htmlspecialchars($resultText) ?></p>
        <form hx-get="<?= htmlspecialchars($nextRoute) ?>"
              hx-target="#charapp"
              hx-swap="innerHTML"
              hx-push-url="true">
            <input type="hidden" name="charState" value="<?= htmlspecialchars($newCharState) ?>" />
            <button type="submit">Continue</button>
        </form>
    </div>

</div>

<?php // Charlog OOB ?>
<div id="charlog" <?= isHtmxRequest() ? 'hx-swap-oob="true"' : '' ?>>
    <?php require $viewDir . 'charapp/charlog.php'; ?>
</div>

<?php // Diagnostics ?>
<div class="diagnosticsdiv">
    <strong>DIAGNOSTICS — Step 7: Re-enlistment</strong><br />
    Mode: roll &nbsp;
    Intent: <?= htmlspecialchars($intent) ?> &nbsp;
    Roll: <?= (int)$roll['total'] ?> (<?= (int)$roll['die1'] ?>, <?= (int)$roll['die2'] ?>) &nbsp;
    Target: <?= $reEnlistTarget ?>+<br />
    Outcome: <?= htmlspecialchars($outcome) ?><br />
    Next route: <?= htmlspecialchars($nextRoute) ?><br />
    <br />
    Updated charState (decoded):<br />
    <pre><?= htmlspecialchars(json_encode($charData, JSON_PRETTY_PRINT)) ?></pre>
</div>
