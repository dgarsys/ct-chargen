<?php

// -----------------------------------------------------------------------
// Decode incoming state
// -----------------------------------------------------------------------
$charState = $_GET['charState'] ?? '';
$charData  = $charState ? json_decode(base64_decode($charState), true) : [];

$serviceId    = (int)($charData['character']['service'] ?? 0);
$currentRank  = (int)($charData['character']['rank']    ?? 0);
$currentTerms = (int)($charData['character']['terms']   ?? 0);
$charStats    = &$charData['character']['stats'];
$isFirstTerm  = ($currentTerms === 0);
$noCommission = [4, 6]; // Scouts, Other Careers

// Fetch career row
$stmt = $pdo->prepare('SELECT * FROM priorService WHERE draft = :id');
$stmt->execute(['id' => $serviceId]);
$career = $stmt->fetch();

$termNumber = $currentTerms + 1;


// -----------------------------------------------------------------------
// 1. Auto-skill rank tracker
// -----------------------------------------------------------------------
$newRanks = [];
if ($isFirstTerm) {
    $newRanks[] = $currentRank;
}


// -----------------------------------------------------------------------
// 2. Survival roll
// -----------------------------------------------------------------------
$survBase     = roll2d6();
$survMods     = [];
$survModTotal = 0;

if ($career['survPlus2Stat'] !== 'none'
    && ($charStats[$career['survPlus2Stat']]['num'] ?? 0) >= (int)$career['survPlus2Val']) {
    $survMods[]    = ['value' => 2, 'reason' => strtoupper($career['survPlus2Stat']).' '.(int)$career['survPlus2Val'].'+'];
    $survModTotal += 2;
}

$survTotal  = $survBase['total'] + $survModTotal;
$survTarget = (int)$career['survivalRoll'];
$survived   = ($survTotal >= $survTarget);

$charData['character']['log'][] = [
    'step'   => 'survival',
    'term'   => $termNumber,
    'target' => $survTarget,
    'die1'   => $survBase['die1'],
    'die2'   => $survBase['die2'],
    'roll'   => $survBase['total'],
    'mods'   => $survMods,
    'total'  => $survTotal,
    'result' => $survived ? 'survived' : 'killed',
];

if (!$survived) {
    $charData['character']['log'][] = [
        'step'   => 'character_died',
        'term'   => $termNumber,
    ];
}


// -----------------------------------------------------------------------
// 3. Commission check
//    Gate: survived + not scouts/other + currently rank 0
// -----------------------------------------------------------------------
$commAttempted   = false;
$commissioned    = false;
$commBase  = [];
$commTotal = $commTarget = 0;
$commMods = [];
$newCommRankName = '';

if ($survived && !in_array($serviceId, $noCommission) && $currentRank === 0) {
    $commAttempted = true;
    $commBase      = roll2d6();
    $commMods      = [];
    $commModTotal  = 0;

    if ($career['commish1stat'] !== 'none'
        && ($charStats[$career['commish1stat']]['num'] ?? 0) >= (int)$career['commish1Val']) {
        $commMods[]    = ['value' => 1, 'reason' => strtoupper($career['commish1stat']).' '.(int)$career['commish1Val'].'+'];
        $commModTotal += 1;
    }

    $commTotal  = $commBase['total'] + $commModTotal;
    $commTarget = (int)$career['commishRoll'];
    $commissioned = ($commTotal >= $commTarget);

    if ($commissioned) {
        $charData['character']['rank'] = 1;
        $newRanks[] = 1;

        $stmtRank = $pdo->prepare('SELECT rankName FROM ranktables WHERE service = :svc AND `rank` = :rank');
        $stmtRank->execute(['svc' => $serviceId, 'rank' => 1]);
        $newCommRankName = $stmtRank->fetchColumn() ?: 'Rank 1';
    }

    $logEntry = [
        'step'   => 'commission',
        'term'   => $termNumber,
        'target' => $commTarget,
        'die1'   => $commBase['die1'],
        'die2'   => $commBase['die2'],
        'roll'   => $commBase['total'],
        'mods'   => $commMods,
        'total'  => $commTotal,
        'result' => $commissioned ? 'commissioned' : 'not commissioned',
    ];
    if ($commissioned) $logEntry['new_rank'] = $newCommRankName;
    $charData['character']['log'][] = $logEntry;
}


// -----------------------------------------------------------------------
// 4. Promotion check
//    Gate: survived + not scouts/other + rank >= 1 + rank < maxrank
// -----------------------------------------------------------------------
$promoAttempted   = false;
$promoted         = false;
$promoBase  = [];
$promoTotal = $promoTarget = 0;
$promoMods = [];
$newPromoRankName = '';
$rankAfterComm    = (int)($charData['character']['rank'] ?? 0);

if ($survived && !in_array($serviceId, $noCommission)
    && $rankAfterComm >= 1 && $rankAfterComm < (int)$career['maxrank']) {
    $promoAttempted = true;
    $promoBase      = roll2d6();
    $promoMods      = [];
    $promoModTotal  = 0;

    if ($career['pro1Stat'] !== 'none'
        && ($charStats[$career['pro1Stat']]['num'] ?? 0) >= (int)$career['pro1Val']) {
        $promoMods[]    = ['value' => 1, 'reason' => strtoupper($career['pro1Stat']).' '.(int)$career['pro1Val'].'+'];
        $promoModTotal += 1;
    }

    $promoTotal  = $promoBase['total'] + $promoModTotal;
    $promoTarget = (int)$career['promoRoll'];
    $promoted    = ($promoTotal >= $promoTarget);

    if ($promoted) {
        $charData['character']['rank']++;
        $newRanks[] = $charData['character']['rank'];

        $stmtRank = $pdo->prepare('SELECT rankName FROM ranktables WHERE service = :svc AND `rank` = :rank');
        $stmtRank->execute(['svc' => $serviceId, 'rank' => $charData['character']['rank']]);
        $newPromoRankName = $stmtRank->fetchColumn() ?: 'Rank '.$charData['character']['rank'];
    }

    $logEntry = [
        'step'   => 'promotion',
        'term'   => $termNumber,
        'target' => $promoTarget,
        'die1'   => $promoBase['die1'],
        'die2'   => $promoBase['die2'],
        'roll'   => $promoBase['total'],
        'mods'   => $promoMods,
        'total'  => $promoTotal,
        'result' => $promoted ? 'promoted' : 'not promoted',
    ];
    if ($promoted) $logEntry['new_rank'] = $newPromoRankName;
    $charData['character']['log'][] = $logEntry;
}


// -----------------------------------------------------------------------
// 5. Skill roll count
//    First term always 2. Subsequent terms use DB skillsPerTerm (scouts = 2).
// -----------------------------------------------------------------------
$skillRollCount  = $isFirstTerm ? 2 : (int)($career['skillsPerTerm'] ?? 1);
$skillRollCount += $commissioned ? 1 : 0;
$skillRollCount += $promoted     ? 1 : 0;


// -----------------------------------------------------------------------
// 6. Apply automatic service skills from $newRanks tracker
// -----------------------------------------------------------------------
$autoSkillsGained = [];

foreach ($newRanks as $rankVal) {
    $stmtAuto = $pdo->prepare(
        'SELECT * FROM auto_svc_skills WHERE service = :svc AND `rank` = :rank'
    );
    $stmtAuto->execute(['svc' => $serviceId, 'rank' => $rankVal]);
    $autoRows = $stmtAuto->fetchAll();

    foreach ($autoRows as $row) {
        if ($row['skill_type'] === 'stat') {
            $statKey = $row['stat_name'];
            $charData['character']['stats'][$statKey]['num']++;
            $charData['character']['stats'][$statKey]['hex'] = strtoupper(
                dechex($charData['character']['stats'][$statKey]['num'])
            );
            $autoSkillsGained[] = [
                'type'    => 'stat',
                'stat'    => $statKey,
                'new_val' => $charData['character']['stats'][$statKey]['num'],
            ];
        } elseif ($row['skill_type'] === 'skill') {
            $skillId = (int)$row['skill_table_id'];
            if (!isset($charData['character']['skills'])) {
                $charData['character']['skills'] = [];
            }
            $charData['character']['skills'][$skillId] =
                ($charData['character']['skills'][$skillId] ?? 0) + 1;

            $stmtSkill = $pdo->prepare('SELECT skill_name FROM skills_list WHERE id = :id');
            $stmtSkill->execute(['id' => $skillId]);
            $skillName = $stmtSkill->fetchColumn() ?: 'Unknown';

            $autoSkillsGained[] = [
                'type'  => 'skill',
                'id'    => $skillId,
                'name'  => $skillName,
                'level' => $charData['character']['skills'][$skillId],
            ];
        }
    }
}


// -----------------------------------------------------------------------
// 7. Advance age and terms if survived
// -----------------------------------------------------------------------
if ($survived) {
    $charData['character']['age']   += 4;
    $charData['character']['terms'] += 1;
}


// -----------------------------------------------------------------------
// 8. Max skills check
// -----------------------------------------------------------------------
$maxSkills         = maxCharSkills($charData['character']['stats']);
$currentSkillCount = isset($charData['character']['skills'])
    ? array_sum($charData['character']['skills']) : 0;
$overMaxSkills     = ($currentSkillCount >= $maxSkills);


// -----------------------------------------------------------------------
// Final rank display
// -----------------------------------------------------------------------
$finalRank = (int)($charData['character']['rank'] ?? 0);
if (in_array($serviceId, $noCommission)) {
    $finalRankDisplay = 'N/A';
} elseif ($finalRank === 0) {
    $finalRankDisplay = 'Enlisted';
} else {
    $stmtRank = $pdo->prepare('SELECT rankName FROM ranktables WHERE service = :svc AND `rank` = :rank');
    $stmtRank->execute(['svc' => $serviceId, 'rank' => $finalRank]);
    $finalRankDisplay = $stmtRank->fetchColumn() ?: 'Rank '.$finalRank;
}

$newCharState = base64_encode(json_encode($charData));

?>

<?php // ---- Main working area ---- ?>
<div class="term-working">

    <h2>Term <?= $termNumber ?> — <?= htmlspecialchars($career['name']) ?></h2>

    <?php // --- Survival --- ?>
    <h3>Survival</h3>
    <table>
        <tbody>
            <tr><td>Target</td><td><?= $survTarget ?>+</td></tr>
            <tr><td>2D6 Roll</td><td><?= (int)$survBase['total'] ?> (<?= (int)$survBase['die1'] ?>, <?= (int)$survBase['die2'] ?>)</td></tr>
            <?php foreach ($survMods as $mod): ?>
                <tr><td>DM +<?= $mod['value'] ?> (<?= htmlspecialchars($mod['reason']) ?>)</td><td>+<?= $mod['value'] ?></td></tr>
            <?php endforeach; ?>
            <tr><td><strong>Total</strong></td><td><strong><?= $survTotal ?></strong></td></tr>
        </tbody>
    </table>

    <?php if (!$survived): ?>
        <p><strong>Your character did not survive term <?= $termNumber ?>.</strong></p>
        <p>Character generation ends. Roll a new character?</p>
        <form hx-get="/api/chargen/rollchar" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true">
            <button type="submit">Start Over</button>
        </form>
    <?php else: ?>
        <p>You survived term <?= $termNumber ?>.</p>

        <?php // --- Commission --- ?>
        <?php if ($commAttempted): ?>
            <h3>Commission</h3>
            <table>
                <tbody>
                    <tr><td>Target</td><td><?= $commTarget ?>+</td></tr>
                    <tr><td>2D6 Roll</td><td><?= (int)$commBase['total'] ?> (<?= (int)$commBase['die1'] ?>, <?= (int)$commBase['die2'] ?>)</td></tr>
                    <?php foreach ($commMods as $mod): ?>
                        <tr><td>DM +<?= $mod['value'] ?> (<?= htmlspecialchars($mod['reason']) ?>)</td><td>+<?= $mod['value'] ?></td></tr>
                    <?php endforeach; ?>
                    <tr><td><strong>Total</strong></td><td><strong><?= $commTotal ?></strong></td></tr>
                </tbody>
            </table>
            <?php if ($commissioned): ?>
                <p>Commissioned — you are now a <strong><?= htmlspecialchars($newCommRankName) ?></strong>.</p>
            <?php else: ?>
                <p>Commission attempt failed.</p>
            <?php endif; ?>
        <?php endif; ?>

        <?php // --- Promotion --- ?>
        <?php if ($promoAttempted): ?>
            <h3>Promotion</h3>
            <table>
                <tbody>
                    <tr><td>Target</td><td><?= $promoTarget ?>+</td></tr>
                    <tr><td>2D6 Roll</td><td><?= (int)$promoBase['total'] ?> (<?= (int)$promoBase['die1'] ?>, <?= (int)$promoBase['die2'] ?>)</td></tr>
                    <?php foreach ($promoMods as $mod): ?>
                        <tr><td>DM +<?= $mod['value'] ?> (<?= htmlspecialchars($mod['reason']) ?>)</td><td>+<?= $mod['value'] ?></td></tr>
                    <?php endforeach; ?>
                    <tr><td><strong>Total</strong></td><td><strong><?= $promoTotal ?></strong></td></tr>
                </tbody>
            </table>
            <?php if ($promoted): ?>
                <p>Promoted — you are now a <strong><?= htmlspecialchars($newPromoRankName) ?></strong>.</p>
            <?php else: ?>
                <p>Promotion attempt failed.</p>
            <?php endif; ?>
        <?php endif; ?>

        <p>Current rank: <strong><?= htmlspecialchars($finalRankDisplay) ?></strong></p>

        <?php // --- Automatic service skills --- ?>
        <?php if (!empty($autoSkillsGained)): ?>
            <h3>Automatic Service Skills</h3>
            <ul>
            <?php foreach ($autoSkillsGained as $auto): ?>
                <?php if ($auto['type'] === 'stat'): ?>
                    <li><?= htmlspecialchars($statDefs[$auto['stat']] ?? strtoupper($auto['stat'])) ?> increased to <?= $auto['new_val'] ?></li>
                <?php else: ?>
                    <li><?= htmlspecialchars($auto['name']) ?>-<?= $auto['level'] ?></li>
                <?php endif; ?>
            <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php // --- Skill rolls --- ?>
        <h3>Skill Rolls This Term</h3>
        <p>You have <strong><?= $skillRollCount ?></strong> skill roll<?= $skillRollCount > 1 ? 's' : '' ?> this term.</p>
        <p>Maximum skills (INT + EDU): <strong><?= $maxSkills ?></strong>. Current skill levels: <strong><?= $currentSkillCount ?></strong>.</p>
        <?php if ($overMaxSkills): ?>
            <p><em>You are at or over your skill maximum. No additional skills may be learned until INT or EDU increases.</em></p>
        <?php endif; ?>

        <p>Age is now <strong><?= (int)$charData['character']['age'] ?></strong>. Terms completed: <strong><?= (int)$charData['character']['terms'] ?></strong>.</p>

        <form hx-get="/api/chargen/skillroll" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true">
            <input type="hidden" name="charState"      value="<?= htmlspecialchars($newCharState) ?>" />
            <input type="hidden" name="skillRollCount" value="<?= $skillRollCount ?>" />
            <input type="hidden" name="termNumber"     value="<?= $termNumber ?>" />
            <button type="submit">Continue to Skill Rolls</button>
        </form>

    <?php endif; // survived ?>

</div>

<?php // ---- Charlog OOB ---- ?>
<div id="charlog" <?= isHtmxRequest() ? 'hx-swap-oob="true"' : '' ?>>
    <?php require $viewDir . 'charapp/charlog.php'; ?>
</div>

<?php // ---- Diagnostics ---- ?>
<div class="diagnosticsdiv">
    <strong>DIAGNOSTICS — Step 5: Term Handler</strong><br />
    Term: <?= $termNumber ?> &nbsp;
    Service: <?= $serviceId ?> &nbsp;
    First term: <?= $isFirstTerm ? 'yes' : 'no' ?><br />
    New ranks this term: [<?= implode(', ', $newRanks) ?>]<br />
    Skill rolls: <?= $skillRollCount ?> &nbsp;
    Max skills: <?= $maxSkills ?> &nbsp;
    Current: <?= $currentSkillCount ?><br />
    Age after term: <?= (int)$charData['character']['age'] ?> &nbsp;
    Terms completed: <?= (int)$charData['character']['terms'] ?><br />
    <br />
    Updated charState (decoded):<br />
    <pre><?= htmlspecialchars(json_encode($charData, JSON_PRETTY_PRINT)) ?></pre>
</div>
