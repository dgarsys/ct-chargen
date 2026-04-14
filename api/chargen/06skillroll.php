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
<?php // ====================================================================
// CASCADE FRAGMENT — early output when a cascade skill is rolled.
// Targets #roll-result (primary) + OOB swap on #skill-tables (disabled buttons).
// ===========================================================================
if ($mode === 'cascade_fragment'):
    $charSkills = $charData['character']['skills'] ?? [];
?>

<?php // Primary content → #roll-result ?>
<p>Rolled <strong><?= $cascadeRoll ?></strong> on
   <?= htmlspecialchars($cascadeTableName) ?>:
   <strong><?= htmlspecialchars($cascadeParentName) ?></strong> — choose a specialisation:</p>

<form hx-get="/api/chargen/skillroll"
      hx-target="#charapp"
      hx-swap="innerHTML"
      hx-push-url="true">
    <input type="hidden" name="charState"      value="<?= htmlspecialchars($newCharState) ?>" />
    <input type="hidden" name="skillRollCount" value="<?= $skillRollCount ?>" />
    <input type="hidden" name="termNumber"     value="<?= $termNumber ?>" />
    <input type="hidden" name="rolledTable"    value="<?= htmlspecialchars($cascadeTableName) ?>" />
    <input type="hidden" name="rolledRoll"     value="<?= $cascadeRoll ?>" />
    <input type="hidden" name="rolledParent"   value="<?= $cascadeParentId ?>" />

    <select name="cascadeSkill"
            id="cascade-select"
            onchange="document.getElementById('cascade-continue').disabled = (this.value === '');">
        <option value="">-- Choose specialisation --</option>
        <?php foreach ($cascadeLeaves as $leaf):
            $leafId    = (int)$leaf['id'];
            $leafLevel = $charSkills[$leafId] ?? 0;
            $label     = htmlspecialchars($leaf['skill_name'])
                       . ($leafLevel > 0 ? " (level $leafLevel)" : '');
        ?>
            <option value="<?= $leafId ?>"><?= $label ?></option>
        <?php endforeach; ?>
    </select>

    <button id="cascade-continue" type="submit" disabled>Continue</button>
</form>

<?php // OOB swap → #skill-tables with Roll buttons disabled ?>
<div id="skill-tables" hx-swap-oob="true">
<table>
    <thead>
        <tr>
            <th>Roll</th>
            <?php foreach ($availableTables as $tId): ?>
                <th><?= htmlspecialchars($tableNames[(int)$tId] ?? "Table $tId") ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
        <?php for ($roll = 1; $roll <= 6; $roll++): ?>
        <tr>
            <td><?= $roll ?></td>
            <?php foreach ($availableTables as $tId):
                $tId  = (int)$tId;
                $row  = $tableData[$tId][$roll] ?? null;
                $disp = $row ? htmlspecialchars(buildDisplayString($row)) : '—';
                $isBlockedSkill = $atMaxSkills && $row && $row['skill_type'] === 'skill';
            ?>
                <td<?= $isBlockedSkill ? ' class="skill-blocked"' : '' ?>>
                    <?= $disp ?><?= $isBlockedSkill ? ' *' : '' ?>
                </td>
            <?php endforeach; ?>
        </tr>
        <?php endfor; ?>
    </tbody>
    <tfoot>
        <tr>
            <td></td>
            <?php foreach ($availableTables as $tId): ?>
                <td><button type="button" disabled>Roll</button></td>
            <?php endforeach; ?>
        </tr>
    </tfoot>
</table>
<?php if ($atMaxSkills): ?>
    <p><small>* At skill maximum — these results will not apply.</small></p>
<?php endif; ?>
</div>

<?php // Charlog OOB ?>
<div id="charlog" hx-swap-oob="true">
    <?php require $viewDir . 'charapp/charlog.php'; ?>
</div>

<?php
    exit;
endif;
// End cascade fragment — full page render follows in Task 3
?>
<?php // TODO Task 3: full page render goes here ?>
