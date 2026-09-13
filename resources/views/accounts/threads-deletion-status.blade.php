<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Threads data deletion — Shoutrrr</title>
</head>
<body>
    <main>
        @if ($outcome === 'completed')
            <h1>Threads data deletion completed</h1>
            <p>The requested Threads connection and its associated platform data were removed from Shoutrrr when this confirmation was issued.</p>
        @else
            <h1>Older Threads deletion request processed</h1>
            <p>A newer Threads authorization was preserved. This older request does not remove connections authorized after it was issued.</p>
        @endif
        <p>Your Shoutrrr workspace, original authored posts and other connected accounts were preserved. Distinct Threads text variations were saved as unscheduled drafts without a destination. Original attachments remain on their source posts; these recovery drafts do not recreate per-account media layouts.</p>
        <p>This does not delete posts on Threads.</p>
        <p>Confirmation code: <code>{{ $confirmationCode }}</code></p>
        <p>This confirmation link is available for 30 days.</p>
    </main>
</body>
</html>
