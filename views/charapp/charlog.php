<?php
/*
--------------------------------------------------------------------------------
    charlog.php — Character sheet / log panel
    Expects $charData and $pdo to be in scope
--------------------------------------------------------------------------------
*/

$logChar    = $charData['character'] ?? [];
$logStats   = $logChar['stats'] ?? [];
$logService = (int)($logChar['service'] ?? 0);
$logRank    = (int)($logChar['rank'] ?? 0);
$logAge     = (int)($logChar['age'] ?? 18);
$logTerms   = (int)($logChar['terms'] ?? 0);
$logEntries = $logChar['log'] ?? [];

// UPP
$logUPP = !empty($logStats) ? generateUPP($logStats) : '------';

// Service name
$logServiceName = '—';
if ($logService > 0) {
    $stmtSvc = $pdo->prepare('SELECT name FROM priorService WHERE draft = :id');
    $stmtSvc->execute(['id' => $logService]);
    $svcRow = $stmtSvc->fetch();
    $logServiceName = $svcRow['name'] ?? '—';
}

// Rank display
$noCommissionServices = [4, 6]; // Scouts, Other Careers
if ($logService === 0) {
    $rankDisplay = '—';
} elseif (in_array($logService, $noCommissionServices)) {
    $rankDisplay = 'N/A';
} elseif ($logRank === 0) {
    $rankDisplay = 'Enlisted';
} else {
    $stmtRank = $pdo->prepare('SELECT rankName FROM ranktables WHERE service = :svc AND `rank` = :rank');
    $stmtRank->execute(['svc' => $logService, 'rank' => $logRank]);
    $rankRow = $stmtRank->fetch();
    $rankDisplay = $rankRow['rankName'] ?? 'Rank ' . $logRank;
}

// Skills — batch-fetch names from DB by ID
$logSkills     = $logChar['skills'] ?? [];
$logSkillNames = [];

if (!empty($logSkills)) {
    $ids         = array_keys($logSkills);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtSkills  = $pdo->prepare(
        "SELECT id, skill_name FROM skills_list WHERE id IN ($placeholders)"
    );
    $stmtSkills->execute($ids);
    foreach ($stmtSkills->fetchAll() as $row) {
        $logSkillNames[(int)$row['id']] = $row['skill_name'];
    }
}
?>

<h3>Character Status</h3>

<strong>UPP:</strong> <?= htmlspecialchars($logUPP) ?><br />
<strong>Service:</strong> <?= htmlspecialchars($logServiceName) ?><br />
<strong>Age:</strong> <?= $logAge ?><br />
<strong>Terms:</strong> <?= $logTerms ?><br />
<strong>Rank:</strong> <?= htmlspecialchars($rankDisplay) ?><br />

<strong>Skills:</strong><br />
<?php if (empty($logSkills)): ?>
    <em>None yet</em>
<?php else: ?>
    <?php foreach ($logSkills as $skillId => $level): ?>
        <?= htmlspecialchars($logSkillNames[(int)$skillId] ?? 'Skill '.$skillId) ?>-<?= (int)$level ?><br />
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($logEntries)): ?>
<div class="log-entries">
    <strong>Event Log</strong><br />
    <?php foreach ($logEntries as $entry): ?>
        <?php if ($entry['step'] === 'enlistment'): ?>
            <?php if ($entry['result'] === 'enlisted'): ?>
                Attempted enlistment in <?= htmlspecialchars($entry['attempted']) ?>
                (target <?= $entry['target'] ?>+, rolled <?= $entry['total'] ?>):
                <strong>Enlisted.</strong><br />
            <?php else: ?>
                Attempted enlistment in <?= htmlspecialchars($entry['attempted']) ?>
                (target <?= $entry['target'] ?>+, rolled <?= $entry['total'] ?>):
                Failed. Draft roll <?= $entry['draft_roll'] ?? '?' ?> →
                <strong>Drafted into <?= htmlspecialchars($entry['active_career']) ?>.</strong><br />
            <?php endif; ?>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php endif; ?>
