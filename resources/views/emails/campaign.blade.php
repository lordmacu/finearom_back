<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Email</title>
</head>
<body>
{!! $body !!}

@if(!empty($logId))
    <img
        src="{{ url('/api/email-campaigns/track-open/' . $logId) }}"
        width="1"
        height="1"
        style="display:none;"
        alt=""
    />
@endif
</body>
</html>

