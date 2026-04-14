<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="en" lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title><?= htmlspecialchars($pageTitle ?? 'Traveller Lab') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin="anonymous" />
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;700&family=Share+Tech+Mono&family=Open+Sans:wght@400;600&display=swap" />
    <link rel="stylesheet" href="/travellerlab.css" />
    <script src="/scripts/htmx.min.js"></script>
    <style>
        .skill-blocked {
            color: #999;
            text-decoration: line-through;
        }
    </style>
</head>
<body>
<header>
    <nav>
        <a href="/">Home</a> |
        <a href="/character">Character Generator</a>
    </nav>
</header>
<main>
