<?php

// Decode incoming character state
$charState  = $_GET['charState'] ?? '';
$charData   = $charState ? json_decode(base64_decode($charState), true) : [];
$charStats  = $charData['character']['stats'] ?? [];

// Helper: returns true if character's stat meets or exceeds threshold
function charMeetsBonus(array $charStats, string $statKey, int $threshold): bool {
    if ($statKey === 'none') return false;
    return (int)($charStats[$statKey]['num'] ?? 0) >= $threshold;
}

// Pull all careers from DB
$sqlCareers = 'SELECT * FROM priorService ORDER BY draft';
$stmt       = $pdo->prepare($sqlCareers);
$stmt->execute();
$careers    = $stmt->fetchAll();

// Pre-build a charState per career with service/rank/age/terms baked in
$careerStates = [];
foreach ($careers as $career) {
    $careerData = $charData;
    $careerData['character']['service'] = (int)$career['draft'];
    $careerData['character']['rank']    = 0;
    $careerData['character']['age']     = 18;
    $careerData['character']['terms']   = 0;
    $careerStates[$career['draft']] = base64_encode(json_encode($careerData));
}

?>

<h2>Choose a Career</h2>
<p>Select a career to attempt enlistment. The enlistment throw is the minimum roll on 2D6 required to join. Positive DMs apply if your stat meets or exceeds the listed value.</p>
<p>UPP: <strong><?= htmlspecialchars(generateUPP($charStats)) ?></strong></p>

<table>
    <thead>
        <tr>
            <th></th>
            <?php foreach ($careers as $career): ?>
                <th><?= htmlspecialchars($career['name']) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <tr>
            <td>Draft #</td>
            <?php foreach ($careers as $career): ?>
                <td><?= (int)$career['draft'] ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Enlistment</td>
            <?php foreach ($careers as $career): ?>
                <td><?= (int)$career['enlistment'] ?>+</td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Enlist DM +1</td>
            <?php foreach ($careers as $career): ?>
                <?php $met = charMeetsBonus($charStats, $career['ePlus1Stat'], (int)$career['ePus1Val']); ?>
                <td<?= $met ? ' class="bonus-met"' : '' ?>><?= $career['ePlus1Disp'] !== 'None' ? htmlspecialchars($career['ePlus1Disp']).' '.(int)$career['ePus1Val'].'+' : '—' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Enlist DM +2</td>
            <?php foreach ($careers as $career): ?>
                <?php $met = charMeetsBonus($charStats, $career['ePlus2Stat'], (int)$career['ePlus2Val']); ?>
                <td<?= $met ? ' class="bonus-met"' : '' ?>><?= $career['ePlus2Disp'] !== 'None' ? htmlspecialchars($career['ePlus2Disp']).' '.(int)$career['ePlus2Val'].'+' : '—' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Survival</td>
            <?php foreach ($careers as $career): ?>
                <td><?= (int)$career['survivalRoll'] ?>+</td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Survival DM +2</td>
            <?php foreach ($careers as $career): ?>
                <?php $met = charMeetsBonus($charStats, $career['survPlus2Stat'], (int)$career['survPlus2Val']); ?>
                <td<?= $met ? ' class="bonus-met"' : '' ?>><?= $career['survPlus2Dis'] !== 'None' ? htmlspecialchars($career['survPlus2Dis']).' '.(int)$career['survPlus2Val'].'+' : '—' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Commission</td>
            <?php foreach ($careers as $career): ?>
                <td><?= (int)$career['commishRoll'] > 0 ? (int)$career['commishRoll'].'+' : 'N/A' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Commission DM +1</td>
            <?php foreach ($careers as $career): ?>
                <?php $met = (int)$career['commishRoll'] > 0 && charMeetsBonus($charStats, $career['commish1stat'], (int)$career['commish1Val']); ?>
                <td<?= $met ? ' class="bonus-met"' : '' ?>><?= (int)$career['commishRoll'] > 0 && $career['commish1Disp'] !== 'None' ? htmlspecialchars($career['commish1Disp']).' '.(int)$career['commish1Val'].'+' : '—' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Promotion</td>
            <?php foreach ($careers as $career): ?>
                <td><?= (int)$career['promoRoll'] > 0 ? (int)$career['promoRoll'].'+' : 'N/A' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Promotion DM +1</td>
            <?php foreach ($careers as $career): ?>
                <?php $met = (int)$career['promoRoll'] > 0 && charMeetsBonus($charStats, $career['pro1Stat'], (int)$career['pro1Val']); ?>
                <td<?= $met ? ' class="bonus-met"' : '' ?>><?= (int)$career['promoRoll'] > 0 && $career['pro1Disp'] !== 'None' ? htmlspecialchars($career['pro1Disp']).' '.(int)$career['pro1Val'].'+' : '—' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td>Re-enlistment</td>
            <?php foreach ($careers as $career): ?>
                <td><?= (int)$career['reEnlist'] > 0 ? (int)$career['reEnlist'].'+' : 'N/A' ?></td>
            <?php endforeach; ?>
        </tr>
        <tr>
            <td></td>
            <?php foreach ($careers as $career): ?>
                <td>
                    <form hx-get="/api/chargen/firsttermstart" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true">
                        <input type="hidden" name="charState" value="<?= htmlspecialchars($careerStates[$career['draft']]) ?>" />
                        <button type="submit">Select</button>
                    </form>
                </td>
            <?php endforeach; ?>
        </tr>
    </tbody>
</table>

<div class="diagnosticsdiv">
    <strong>DIAGNOSTICS — Step 3: Choose Career</strong><br />
    <br />
    Incoming charState (raw): <?= htmlspecialchars($charState) ?><br />
    <br />
    Incoming stats:<br />
    <?php foreach ($statDefs as $key => $fullName): ?>
        <?= htmlspecialchars($fullName) ?> (<?= strtoupper($key) ?>):
        <?= (int)($charStats[$key]['num'] ?? '?') ?> / <?= htmlspecialchars($charStats[$key]['hex'] ?? '?') ?><br />
    <?php endforeach; ?>
</div>
