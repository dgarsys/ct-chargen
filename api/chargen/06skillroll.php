<?php

// -----------------------------------------------------------------------
// Decode state
// -----------------------------------------------------------------------
$charState      = $_GET['charState']            ?? '';
$skillRollCount = (int)($_GET['skillRollCount'] ?? 0);
$termNumber     = (int)($_GET['termNumber']     ?? 1);
$charData       = $charState ? json_decode(base64_decode($charState), true) : [];
$serviceId      = (int)($charData['character']['service'] ?? 0);

// Max skills
$maxSkills         = maxCharSkills($charData['character']['stats']);
$currentSkillCount = isset($charData['character']['skills'])
    ? array_sum($charData['character']['skills']) : 0;
$atMaxSkills       = ($currentSkillCount >= $maxSkills);

// Available tables for this service
$stmtTables = $pdo->prepare(
    'SELECT DISTINCT roll_table FROM service_skills WHERE service = :svc ORDER BY roll_table'
);
$stmtTables->execute(['svc' => $serviceId]);
$availableTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

// Gate table 4 on EDU 8+
$eduVal = $charData['character']['stats']['edu']['num'] ?? 0;
if ($eduVal < 8) {
    $availableTables = array_filter($availableTables, fn($t) => $t != 4);
}

$tableNames = [
    1 => 'Personal Development',
    2 => 'Service Skills',
    3 => 'Advanced Education',
    4 => 'Further Advanced Education',
];

// Helper: recursively fetch all leaf skills under a cascade parent
function getLeafSkills(PDO $pdo, int $parentId): array {
    $stmt = $pdo->prepare('SELECT * FROM skills_list WHERE cas_parent = :parent');
    $stmt->execute(['parent' => $parentId]);
    $children = $stmt->fetchAll();
    $leaves   = [];
    foreach ($children as $child) {
        if ($child['has_cas_child']) {
            $leaves = array_merge($leaves, getLeafSkills($pdo, (int)$child['id']));
        } else {
            $leaves[] = $child;
        }
    }
    return $leaves;
}

// -----------------------------------------------------------------------
// Determine mode
// -----------------------------------------------------------------------
$mode = 'choose';
if (isset($_GET['cascadeSkill']))   $mode = 'apply_cascade';
elseif (isset($_GET['cascadeParent'])) $mode = 'cascade';
elseif (isset($_GET['selectedTable'])) $mode = 'roll';

$resultMessage     = '';
$cascadeParent     = 0;
$cascadeParentName = '';
$rollDetails       = [];


// -----------------------------------------------------------------------
// Mode: apply_cascade — apply chosen leaf skill, decrement count
// -----------------------------------------------------------------------
if ($mode === 'apply_cascade') {
    $cascadeSkillId = (int)$_GET['cascadeSkill'];

    $stmtSkill = $pdo->prepare('SELECT skill_name FROM skills_list WHERE id = :id');
    $stmtSkill->execute(['id' => $cascadeSkillId]);
    $skillName = $stmtSkill->fetchColumn() ?: 'Unknown';

    if (!isset($charData['character']['skills'])) $charData['character']['skills'] = [];
    $charData['character']['skills'][$cascadeSkillId] =
        ($charData['character']['skills'][$cascadeSkillId] ?? 0) + 1;
    $level = $charData['character']['skills'][$cascadeSkillId];

    $charData['character']['log'][] = [
        'step'       => 'skill_roll',
        'term'       => $termNumber,
        'table'      => $_GET['rolledTable'] ?? '?',
        'type'       => 'skill',
        'skill_id'   => $cascadeSkillId,
        'skill_name' => $skillName,
        'level'      => $level,
        'cascade'    => true,
    ];

    $resultMessage = "Gained <strong>$skillName-$level</strong>.";
    $skillRollCount--;
    $mode = 'choose';
}


// -----------------------------------------------------------------------
// Mode: roll — roll 1d6 on chosen table, process result
// -----------------------------------------------------------------------
if ($mode === 'roll') {
    $selectedTable = (int)$_GET['selectedTable'];
    $dieRoll       = rolld6();
    $tableName     = $tableNames[$selectedTable] ?? "Table $selectedTable";

    $stmtResult = $pdo->prepare(
        'SELECT * FROM service_skills
         WHERE service = :svc AND roll_table = :table AND roll_val = :val'
    );
    $stmtResult->execute(['svc' => $serviceId, 'table' => $selectedTable, 'val' => $dieRoll]);
    $result = $stmtResult->fetch();

    if (!$result) {
        $resultMessage = "No result found for table $selectedTable, roll $dieRoll.";
        $skillRollCount--;
        $mode = 'choose';

    } elseif ($result['skill_type'] === 'stat') {
        $statKey     = $result['stat_name'];
        $charData['character']['stats'][$statKey]['num']++;
        $charData['character']['stats'][$statKey]['hex'] = strtoupper(
            dechex($charData['character']['stats'][$statKey]['num'])
        );
        $newVal      = $charData['character']['stats'][$statKey]['num'];
        $statDisplay = $statDefs[$statKey] ?? strtoupper($statKey);

        $charData['character']['log'][] = [
            'step'    => 'skill_roll',
            'term'    => $termNumber,
            'table'   => $tableName,
            'roll'    => $dieRoll,
            'type'    => 'stat',
            'stat'    => $statKey,
            'new_val' => $newVal,
        ];

        $resultMessage = "Rolled <strong>$dieRoll</strong> on $tableName: "
            . "<strong>$statDisplay</strong> increased to <strong>$newVal</strong>.";
        $skillRollCount--;
        $mode = 'choose';

    } elseif ($result['skill_type'] === 'skill') {
        $skillId = (int)$result['skill_table_id'];

        $stmtSkill = $pdo->prepare('SELECT * FROM skills_list WHERE id = :id');
        $stmtSkill->execute(['id' => $skillId]);
        $skillRow = $stmtSkill->fetch();

        if ($skillRow['has_cas_child']) {
            // Cascade — hold count until player chooses sub-skill
            $mode              = 'cascade';
            $cascadeParent     = $skillId;
            $cascadeParentName = $skillRow['skill_name'];
            $rollDetails       = ['table' => $selectedTable, 'tableName' => $tableName, 'roll' => $dieRoll];
        } else {
            if (!isset($charData['character']['skills'])) $charData['character']['skills'] = [];
            $charData['character']['skills'][$skillId] =
                ($charData['character']['skills'][$skillId] ?? 0) + 1;
            $level     = $charData['character']['skills'][$skillId];
            $skillName = $skillRow['skill_name'];

            $charData['character']['log'][] = [
                'step'       => 'skill_roll',
                'term'       => $termNumber,
                'table'      => $tableName,
                'roll'       => $dieRoll,
                'type'       => 'skill',
                'skill_id'   => $skillId,
                'skill_name' => $skillName,
                'level'      => $level,
            ];

            $resultMessage = "Rolled <strong>$dieRoll</strong> on $tableName: "
                . "gained <strong>$skillName-$level</strong>.";
            $skillRollCount--;
            $mode = 'choose';
        }
    }
}

// Re-encode updated state
$newCharState      = base64_encode(json_encode($charData));
$currentSkillCount = isset($charData['character']['skills'])
    ? array_sum($charData['character']['skills']) : 0;

?>

<?php // ---- Main working area ---- ?>
<div class="term-working">

    <h2>Term <?= $termNumber ?> — Skill Rolls</h2>
    <p>Rolls remaining: <strong><?= $skillRollCount ?></strong> &nbsp;|&nbsp;
       Skills: <strong><?= $currentSkillCount ?> / <?= $maxSkills ?></strong></p>

    <?php if ($resultMessage): ?>
        <p><?= $resultMessage ?></p>
    <?php endif; ?>

    <?php // --- Cascade choice --- ?>
    <?php if ($mode === 'cascade'): ?>
        <?php $leaves = getLeafSkills($pdo, $cascadeParent); ?>
        <h3>Choose a <?= htmlspecialchars($cascadeParentName) ?> specialisation</h3>
        <p>You rolled <strong><?= $rollDetails['roll'] ?></strong> on
           <?= htmlspecialchars($rollDetails['tableName']) ?> and gained
           <?= htmlspecialchars($cascadeParentName) ?>. Choose a specialisation:</p>
        <div>
        <?php foreach ($leaves as $leaf): ?>
            <form hx-get="/api/chargen/skillroll" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true" style="display:inline-block; margin: 0.25rem 0.25rem 0 0;">
                <input type="hidden" name="charState"      value="<?= htmlspecialchars($newCharState) ?>" />
                <input type="hidden" name="skillRollCount" value="<?= $skillRollCount ?>" />
                <input type="hidden" name="termNumber"     value="<?= $termNumber ?>" />
                <input type="hidden" name="cascadeSkill"   value="<?= (int)$leaf['id'] ?>" />
                <input type="hidden" name="rolledTable"    value="<?= htmlspecialchars($rollDetails['tableName']) ?>" />
                <button type="submit"><?= htmlspecialchars($leaf['skill_name']) ?></button>
            </form>
        <?php endforeach; ?>
        </div>

    <?php // --- Choose table --- ?>
    <?php elseif ($skillRollCount > 0): ?>

        <?php if ($atMaxSkills): ?>
            <p><em>You are at your skill maximum of <?= $maxSkills ?> (INT + EDU).
               Only Personal Development is available — rolling a stat increase to INT or EDU
               will raise your maximum and unlock additional skill rolls.</em></p>
        <?php elseif ($eduVal < 8): ?>
            <p><em>Further Advanced Education requires EDU 8+. Your current EDU is <?= $eduVal ?>.</em></p>
        <?php endif; ?>

        <table>
            <tbody>
            <?php foreach ($availableTables as $tableId): ?>
                <?php
                    $tableId = (int)$tableId;
                    if ($atMaxSkills && $tableId !== 1) continue;
                    $tName = $tableNames[$tableId] ?? "Table $tableId";
                ?>
                <tr>
                    <td><?= htmlspecialchars($tName) ?></td>
                    <td>
                        <form hx-get="/api/chargen/skillroll" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true">
                            <input type="hidden" name="charState"      value="<?= htmlspecialchars($newCharState) ?>" />
                            <input type="hidden" name="skillRollCount" value="<?= $skillRollCount ?>" />
                            <input type="hidden" name="termNumber"     value="<?= $termNumber ?>" />
                            <input type="hidden" name="selectedTable"  value="<?= $tableId ?>" />
                            <button type="submit">Roll</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p><small>Personal Development may include skills as well as stat increases.</small></p>

    <?php // --- All rolls done --- ?>
    <?php else: ?>
        <p>All skill rolls for this term are complete.</p>
        <form hx-get="/api/chargen/term" hx-target="#charapp" hx-swap="innerHTML" hx-push-url="true">
            <input type="hidden" name="charState" value="<?= htmlspecialchars($newCharState) ?>" />
            <button type="submit">Continue</button>
        </form>
    <?php endif; ?>

</div>

<?php // ---- Charlog OOB ---- ?>
<div id="charlog" <?= isHtmxRequest() ? 'hx-swap-oob="true"' : '' ?>>
    <?php require $viewDir . 'charapp/charlog.php'; ?>
</div>

<?php // ---- Diagnostics ---- ?>
<div class="diagnosticsdiv">
    <strong>DIAGNOSTICS — Step 6: Skill Rolls</strong><br />
    Mode: <?= $mode ?> &nbsp;
    Rolls remaining: <?= $skillRollCount ?> &nbsp;
    Term: <?= $termNumber ?><br />
    Max skills: <?= $maxSkills ?> &nbsp;
    Current: <?= $currentSkillCount ?> &nbsp;
    At max: <?= $atMaxSkills ? 'yes' : 'no' ?><br />
    <br />
    charState (decoded):<br />
    <pre><?= htmlspecialchars(json_encode($charData, JSON_PRETTY_PRINT)) ?></pre>
</div>
