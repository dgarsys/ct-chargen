<?php

// -----------------------------------------------------------------------
// 1. Decode state
// -----------------------------------------------------------------------
$charState      = $_GET['charState']            ?? '';
$skillRollCount = (int)($_GET['skillRollCount'] ?? 0);
$termNumber     = (int)($_GET['termNumber']     ?? 1);
$charData       = $charState ? json_decode(base64_decode($charState), true) : [];
$serviceId      = (int)($charData['character']['service'] ?? 0);

// -----------------------------------------------------------------------
// 2. Derived values — re-evaluated every request
// -----------------------------------------------------------------------
$stats             = $charData['character']['stats'] ?? [];
$maxSkills         = maxCharSkills($stats);
$currentSkillCount = isset($charData['character']['skills'])
    ? array_sum($charData['character']['skills']) : 0;
$atMaxSkills       = ($currentSkillCount >= $maxSkills);
$eduVal            = $stats['edu']['num'] ?? 0;

// -----------------------------------------------------------------------
// 3. Available tables — gated by EDU and at-max
// -----------------------------------------------------------------------
$stmtTables = $pdo->prepare(
    'SELECT DISTINCT roll_table FROM service_skills WHERE service = :svc ORDER BY roll_table'
);
$stmtTables->execute(['svc' => $serviceId]);
$availableTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);

if ($eduVal < 8) {
    $availableTables = array_values(array_filter($availableTables, fn($t) => $t != 4));
}
if ($atMaxSkills) {
    $availableTables = [1];
}

$tableNames = [
    1 => 'Personal Development',
    2 => 'Service Skills',
    3 => 'Advanced Education',
    4 => 'Further Advanced Education',
];

// -----------------------------------------------------------------------
// 4. Data fetch — single query, $tableData[roll_table][roll_val] = full row
// -----------------------------------------------------------------------
function fetchTableData(PDO $pdo, int $serviceId, array $availableTables): array {
    if (empty($availableTables)) return [];
    $placeholders = implode(',', array_fill(0, count($availableTables), '?'));
    $stmt = $pdo->prepare(
        "SELECT ss.roll_table, ss.roll_val, ss.skill_type,
                ss.stat_name, ss.stat_mod, ss.skill_table_id,
                sl.skill_name
         FROM service_skills ss
         LEFT JOIN skills_list sl ON ss.skill_table_id = sl.id
         WHERE ss.service = ? AND ss.roll_table IN ($placeholders)
         ORDER BY ss.roll_table, ss.roll_val"
    );
    $stmt->execute(array_merge([$serviceId], $availableTables));
    $data = [];
    foreach ($stmt->fetchAll() as $row) {
        $data[(int)$row['roll_table']][(int)$row['roll_val']] = $row;
    }
    return $data;
}

$tableData = fetchTableData($pdo, $serviceId, $availableTables);

// -----------------------------------------------------------------------
// 5. Helpers
// -----------------------------------------------------------------------
function buildDisplayString(array $row): string {
    if ($row['skill_type'] === 'stat') {
        $mod  = (int)$row['stat_mod'];
        $sign = $mod >= 0 ? '+' : '';
        return $sign . $mod . ' ' . strtoupper($row['stat_name']);
    }
    return $row['skill_name'] ?? '?';
}

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
// 6. Mode detection
// -----------------------------------------------------------------------
$mode = 'page';
if (isset($_GET['cascadeSkill']))      $mode = 'apply_cascade';
elseif (isset($_GET['selectedTable'])) $mode = 'roll';

// Result message — stored in charData['_lastResult'], cleared each request
$resultMessage = $charData['_lastResult'] ?? '';
unset($charData['_lastResult']);

// Cascade fragment vars (only set in cascade sub-case of roll mode)
$cascadeParentId   = 0;
$cascadeParentName = '';
$cascadeRoll       = 0;
$cascadeTableName  = '';
$cascadeLeaves     = [];

// -----------------------------------------------------------------------
// 7. Mode: apply_cascade
// -----------------------------------------------------------------------
if ($mode === 'apply_cascade') {
    $cascadeSkillId  = (int)$_GET['cascadeSkill'];
    $rolledTableName = $_GET['rolledTable']  ?? '?';
    $rolledRoll      = (int)($_GET['rolledRoll']   ?? 0);
    $rolledParentId  = (int)($_GET['rolledParent'] ?? 0);

    $stmtLeaf = $pdo->prepare('SELECT skill_name FROM skills_list WHERE id = :id');
    $stmtLeaf->execute(['id' => $cascadeSkillId]);
    $leafName = $stmtLeaf->fetchColumn() ?: 'Unknown';

    $stmtParent = $pdo->prepare('SELECT skill_name FROM skills_list WHERE id = :id');
    $stmtParent->execute(['id' => $rolledParentId]);
    $parentName = $stmtParent->fetchColumn() ?: 'Unknown';

    if (!isset($charData['character']['skills'])) $charData['character']['skills'] = [];
    $charData['character']['skills'][$cascadeSkillId] =
        ($charData['character']['skills'][$cascadeSkillId] ?? 0) + 1;
    $level = $charData['character']['skills'][$cascadeSkillId];

    $charData['character']['log'][] = [
        'step'           => 'skill_roll',
        'term'           => $termNumber,
        'table'          => $rolledTableName,
        'roll'           => $rolledRoll,
        'type'           => 'skill',
        'skill_id'       => $rolledParentId,
        'skill_name'     => $parentName,
        'cascade_choice' => $cascadeSkillId,
        'cascade_name'   => $leafName,
        'level'          => $level,
        'cascade'        => true,
    ];

    $skillRollCount--;
    $charData['_lastResult'] = "Rolled $rolledRoll on $rolledTableName: $parentName &rarr; $leafName-$level.";
    $resultMessage           = $charData['_lastResult'];

    header('HX-Retarget: #charapp');
    header('HX-Reswap: innerHTML');
    $mode = 'page';
}

// -----------------------------------------------------------------------
// 8. Mode: roll
// -----------------------------------------------------------------------
if ($mode === 'roll') {
    $selectedTable = (int)$_GET['selectedTable'];
    $dieRoll       = rolld6();
    $tableName     = $tableNames[$selectedTable] ?? "Table $selectedTable";
    $result        = $tableData[$selectedTable][$dieRoll] ?? null;

    if (!$result) {
        $skillRollCount--;
        $charData['_lastResult'] = "No result found for table $selectedTable, roll $dieRoll.";
        $resultMessage           = $charData['_lastResult'];
        header('HX-Retarget: #charapp');
        header('HX-Reswap: innerHTML');
        $mode = 'page';

    } elseif ($result['skill_type'] === 'stat') {
        $statKey     = $result['stat_name'];
        $statMod     = (int)$result['stat_mod'];
        $charData['character']['stats'][$statKey]['num'] += $statMod;
        $charData['character']['stats'][$statKey]['hex'] = strtoupper(
            dechex($charData['character']['stats'][$statKey]['num'])
        );
        $newVal      = $charData['character']['stats'][$statKey]['num'];
        $statDisplay = $statDefs[$statKey] ?? strtoupper($statKey);
        $sign        = $statMod >= 0 ? '+' : '';

        $charData['character']['log'][] = [
            'step'    => 'skill_roll',
            'term'    => $termNumber,
            'table'   => $tableName,
            'roll'    => $dieRoll,
            'type'    => 'stat',
            'stat'    => $statKey,
            'new_val' => $newVal,
        ];

        $skillRollCount--;
        $charData['_lastResult'] = "Rolled $dieRoll on $tableName: {$sign}{$statMod} $statDisplay (now $newVal).";
        $resultMessage           = $charData['_lastResult'];
        header('HX-Retarget: #charapp');
        header('HX-Reswap: innerHTML');
        $mode = 'page';

    } elseif ($result['skill_type'] === 'skill') {
        $skillId   = (int)$result['skill_table_id'];
        $skillName = $result['skill_name'] ?? 'Unknown';

        $stmtCas = $pdo->prepare('SELECT has_cas_child FROM skills_list WHERE id = :id');
        $stmtCas->execute(['id' => $skillId]);
        $hasCascade = (bool)$stmtCas->fetchColumn();

        if ($atMaxSkills) {
            $charData['character']['log'][] = [
                'step'       => 'skill_roll',
                'term'       => $termNumber,
                'table'      => $tableName,
                'roll'       => $dieRoll,
                'type'       => 'skill',
                'skill_id'   => $skillId,
                'skill_name' => $skillName,
                'applied'    => false,
                'reason'     => 'at_max_skills',
            ];

            $skillRollCount--;
            $charData['_lastResult'] = "Rolled $dieRoll on $tableName: $skillName not gained (at skill maximum).";
            $resultMessage           = $charData['_lastResult'];
            header('HX-Retarget: #charapp');
            header('HX-Reswap: innerHTML');
            $mode = 'page';

        } elseif ($hasCascade) {
            $cascadeParentId   = $skillId;
            $cascadeParentName = $skillName;
            $cascadeRoll       = $dieRoll;
            $cascadeTableName  = $tableName;
            $cascadeLeaves     = getLeafSkills($pdo, $skillId);
            $mode              = 'cascade_fragment';

        } else {
            if (!isset($charData['character']['skills'])) $charData['character']['skills'] = [];
            $charData['character']['skills'][$skillId] =
                ($charData['character']['skills'][$skillId] ?? 0) + 1;
            $level = $charData['character']['skills'][$skillId];

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

            $skillRollCount--;
            $charData['_lastResult'] = "Rolled $dieRoll on $tableName: gained $skillName-$level.";
            $resultMessage           = $charData['_lastResult'];
            header('HX-Retarget: #charapp');
            header('HX-Reswap: innerHTML');
            $mode = 'page';
        }
    }
}

// -----------------------------------------------------------------------
// 9. Re-encode state + re-evaluate for render
//    (skip for cascade_fragment — state unchanged, derived values unchanged)
// -----------------------------------------------------------------------
$newCharState = base64_encode(json_encode($charData));

if ($mode !== 'cascade_fragment') {
    $stats             = $charData['character']['stats'] ?? [];
    $maxSkills         = maxCharSkills($stats);
    $currentSkillCount = isset($charData['character']['skills'])
        ? array_sum($charData['character']['skills']) : 0;
    $atMaxSkills       = ($currentSkillCount >= $maxSkills);
    $eduVal            = $stats['edu']['num'] ?? 0;

    // Re-gate available tables (stat bump may have changed INT/EDU)
    $stmtTables->execute(['svc' => $serviceId]);
    $availableTables = $stmtTables->fetchAll(PDO::FETCH_COLUMN);
    if ($eduVal < 8) {
        $availableTables = array_values(array_filter($availableTables, fn($t) => $t != 4));
    }
    if ($atMaxSkills) {
        $availableTables = [1];
    }

    // Re-fetch display data for the (possibly updated) available tables
    $tableData = fetchTableData($pdo, $serviceId, $availableTables);
}

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
