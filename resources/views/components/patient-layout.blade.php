@props(['title' => null])
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' · ' : '' }}{{ \App\Models\Setting::get('clinic.name') }}</title>
    <style>
        :root { --brand: #0e7490; --brand-dark: #155e75; --ink: #0f172a; --muted: #475569; --line: #e2e8f0; --ok: #15803d; --warn: #b45309; --bad: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 18px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); background: #f8fafc; }
        main { max-width: 480px; margin: 0 auto; padding: 24px 16px 48px; }
        header { font-weight: 700; color: var(--brand-dark); margin-bottom: 16px; }
        .card { background: #fff; border: 1px solid var(--line); border-radius: 16px; padding: 20px; }
        h1 { font-size: 1.4rem; margin: 0 0 12px; }
        dl { margin: 0; display: grid; gap: 8px; }
        dt { font-size: .85rem; color: var(--muted); }
        dd { margin: 0 0 4px; font-weight: 600; }
        .badge { display: inline-block; font-size: .85rem; font-weight: 600; padding: 2px 10px; border-radius: 999px; background: #fef3c7; color: var(--warn); }
        .badge.ok { background: #dcfce7; color: var(--ok); }
        .badge.bad { background: #fee2e2; color: var(--bad); }
        .actions { display: grid; gap: 12px; margin-top: 20px; }
        button, .button { display: block; width: 100%; padding: 16px; font-size: 1.05rem; font-weight: 700; border-radius: 12px; border: 0; cursor: pointer; text-align: center; text-decoration: none; }
        .primary { background: var(--brand); color: #fff; }
        .secondary { background: #fff; color: var(--ink); border: 2px solid var(--line); }
        .danger { background: #fff; color: var(--bad); border: 2px solid #fecaca; }
        .flash { padding: 14px 16px; border-radius: 12px; margin-bottom: 16px; font-weight: 600; }
        .flash.ok { background: #dcfce7; color: var(--ok); }
        .flash.bad { background: #fee2e2; color: var(--bad); }
        .note { color: var(--muted); font-size: .95rem; margin-top: 16px; }
        details summary { list-style: none; }
        textarea { width: 100%; font: inherit; padding: 12px; border: 2px solid var(--line); border-radius: 12px; margin: 12px 0; }
    </style>
</head>
<body>
<main>
    <header>{{ \App\Models\Setting::get('clinic.name') }}</header>
    @if (session('status'))
        <div class="flash ok" role="status">{{ session('status') }}</div>
    @endif
    @if (session('error'))
        <div class="flash bad" role="alert">{{ session('error') }}</div>
    @endif
    {{ $slot }}
</main>
</body>
</html>
