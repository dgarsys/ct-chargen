<?php

// Decode incoming character state
$charState = $_GET['charState'] ?? '';
$charData  = $charState ? json_decode(base64_decode($charState), true) : [];
$char      = $charData['character'] ?? [];
$charStats = $char['stats'] ?? [];
$serviceId = (int)($char['service'] ?? 0);

// Fetch attempted career data from DB
$stmt = $pdo->prepare('SELECT * FROM priorService WHERE draft = :id');
$stmt->execute(['id' => $serviceId]);
$career = $stmt->fetch();

// Roll 2d6 and apply enlistment DMs
$baseRoll  = roll2d6();
$modifiers = [];
$modTotal  = 0;

if ($career['ePlus1Stat'] !== 'none' && ($charStats[$career['ePlus1Stat']]['num'] ?? 0) >= (int)$career['ePus1Val']) {
    $modifiers[] = ['value' => 1, 'reason' => strtoupper($career['ePlus1Stat']).' '.(int)$career['ePus1Val'].'+'];
    $modTotal += 1;
}
if ($career['ePlus2Stat'] !== 'none' && ($charStats[$career['ePlus2Stat']]['num'] ?? 0) >= (int)$career['ePlus2Val']) {
    $modifiers[] = ['value' => 2, 'reason' => strtoupper($career['ePlus2Stat']).' '.(int)$career['ePlus2Val'].'+'];
    $modTotal += 2;
}

$totalRoll = $baseRoll + $modTotal;
$target    = (int)$career['enlistment'];
$success   = $totalRoll >= $target;

// On failure, roll 1d6 for draft — may land in same service
$draftedCareer  = null;
$draftRoll      = null;
if (!$success) {
    $draftRoll     = rolld6();
    $stmtDraft     = $pdo->prepare('SELECT * FROM priorService WHERE draft = :id');
    $stmtDraft->execute(['id' => $draftRoll]);
    $draftedCareer = $stmtDraft->fetch();

    // Update service in charData to drafted service
    $charData['character']['service'] = $draftRoll;
}

$activeCareer = $success ? $career : $draftedCareer;
$phrase       = $success
    ? ($joinPhrases[$serviceId] ?? 'entered service')
    : 'been drafted into the ' . ($draftedCareer['name'] ?? 'Unknown');

// Build log entry
$logEntry = [
    'step'          => 'enlistment',
    'attempted'     => $career['name'],
    'target'        => $target,
    'roll'          => $baseRoll,
    'mods'          => $modifiers,
    'total'         => $totalRoll,
    'result'        => $success ? 'enlisted' : 'drafted',
    'active_career' => $activeCareer['name'] ?? '',
];
if (!$success) {
    $logEntry['draft_roll'] = $draftRoll;
}

$charData['character']['log'][] = $logEntry;
$newCharState = base64_encode(json_encode($charData));

?>

<h2>Enlistment</h2>

<p>The target roll to enlist in <strong><?= htmlspecialchars($career['name']) ?></strong> is <strong><?= $target ?>+</strong>.</p>

<table>
    <tbody>
        <tr>
            <td>2D6 Roll</td>
            <td><?= $baseRoll ?></td>
        </tr>
        <?php foreach ($modifiers as $mod): ?>
        <tr>
            <td>DM +<?= $mod['value'] ?> (<?= htmlspecialchars($mod['reason']) ?>)</td>
            <td>+<?= $mod['value'] ?></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td><strong>Total</strong></td>
            <td><strong><?= $totalRoll ?></strong></td>
        </tr>
    </tbody>
</table>

<?php if ($success): ?>
    <p>Congratulations — you have <?= htmlspecialchars($phrase) ?>.</p>
<?php else: ?>
    <p>Enlistment failed — you rolled <?= $totalRoll ?>, needed <?= $target ?>.</p>
    <p>You rolled a <?= $draftRoll ?> on the draft table and have <?= htmlspecialchars($phrase) ?>.</p>
    <?php if ($draftRoll === $serviceId): ?>
        <p><em>Ironically, the draft assigned you to the very service you failed to enlist in.</em></p>
    <?php endif; ?>
<?php endif; ?>

<form hx-get="/api/chargen/term" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true">
    <input type="hidden" name="charState" value="<?= htmlspecialchars($newCharState) ?>" />
    <button type="submit">Continue to First Term</button>
</form>

<div class="diagnosticsdiv">
    <strong>DIAGNOSTICS — Step 4: Enlistment</strong><br />
    Attempted: <?= htmlspecialchars($career['name']) ?> (target <?= $target ?>+)<br />
    Roll: <?= $baseRoll ?> + mods <?= $modTotal ?> = <?= $totalRoll ?> — <?= $success ? 'ENLISTED' : 'FAILED' ?><br />
    <?php if (!$success): ?>
        Draft roll: <?= $draftRoll ?> → <?= htmlspecialchars($draftedCareer['name'] ?? '') ?><br />
    <?php endif; ?>
    Active service: <?= htmlspecialchars($activeCareer['name'] ?? '') ?><br />
    <br />
    charState (decoded):<br />
    <pre><?= htmlspecialchars(json_encode($charData, JSON_PRETTY_PRINT)) ?></pre>
</div>
