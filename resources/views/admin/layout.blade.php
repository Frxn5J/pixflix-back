<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Administracion') · Pixflix</title>
    <style>
        :root { color-scheme: dark; --bg:#080d12; --panel:#111a22; --panel-2:#17232e; --line:#263746; --text:#eef5f8; --muted:#9cafb9; --brand:#55e6a5; --danger:#ff7d8c; --warning:#ffd37a; }
        * { box-sizing:border-box; }
        body { margin:0; min-width:320px; background:radial-gradient(circle at 0 0,#123529 0,transparent 36rem),var(--bg); color:var(--text); font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        a { color:inherit; text-decoration:none; }
        button,input,select,textarea { font:inherit; }
        button { cursor:pointer; }
        .admin-shell { min-height:100vh; }
        .admin-header { position:sticky; top:0; z-index:10; display:flex; align-items:center; justify-content:space-between; gap:20px; padding:16px clamp(18px,4vw,56px); background:rgba(8,13,18,.9); border-bottom:1px solid var(--line); backdrop-filter:blur(18px); }
        .brand { display:flex; align-items:center; gap:11px; font-weight:900; letter-spacing:.14em; }
        .brand-mark { display:grid; place-items:center; width:36px; height:36px; border-radius:11px; color:#07120d; background:var(--brand); font-size:20px; }
        .brand-copy { display:flex; flex-direction:column; line-height:1.1; }
        .brand-copy small { margin-top:4px; color:var(--muted); font-size:10px; letter-spacing:.12em; font-weight:700; }
        .admin-user { display:flex; align-items:center; gap:12px; color:var(--muted); }
        .logout-button,.button { border:1px solid var(--line); border-radius:9px; padding:9px 13px; color:var(--text); background:var(--panel-2); }
        .button:hover,.logout-button:hover { border-color:var(--brand); }
        .button.primary { color:#06140d; border-color:var(--brand); background:var(--brand); font-weight:800; }
        .button.danger { color:#ffdce0; border-color:#763a45; background:#351820; }
        .button.small { padding:6px 9px; font-size:13px; }
        .admin-main { width:min(1500px,100%); margin:0 auto; padding:34px clamp(18px,4vw,56px) 70px; }
        .page-heading { display:flex; justify-content:space-between; align-items:end; gap:20px; margin-bottom:24px; }
        .eyebrow { margin:0 0 6px; color:var(--brand); font-size:11px; letter-spacing:.14em; font-weight:900; }
        h1,h2,h3,p { margin-top:0; }
        h1 { margin-bottom:6px; font-size:clamp(28px,4vw,44px); line-height:1.08; }
        h2 { margin-bottom:5px; font-size:24px; }
        h3 { margin-bottom:10px; font-size:17px; }
        .muted,.hint { color:var(--muted); }
        .hint { font-size:13px; }
        .tabs { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:24px; }
        .tab { padding:9px 13px; border:1px solid var(--line); border-radius:999px; color:var(--muted); background:rgba(17,26,34,.75); }
        .tab:hover,.tab.active { color:#06140d; border-color:var(--brand); background:var(--brand); font-weight:800; }
        .flash { margin:0 0 18px; padding:12px 14px; border:1px solid #2a6f57; border-radius:10px; color:#caffdf; background:#102a20; }
        .flash.error { border-color:#763a45; color:#ffdce0; background:#351820; }
        .metric-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; }
        .metric,.panel { border:1px solid var(--line); border-radius:14px; background:linear-gradient(145deg,rgba(23,35,46,.96),rgba(13,21,28,.96)); box-shadow:0 18px 45px rgba(0,0,0,.14); }
        .metric { padding:18px; }
        .metric strong { display:block; margin-top:3px; font-size:30px; }
        .metric span { color:var(--muted); font-size:13px; }
        .panel { padding:20px; margin-bottom:18px; overflow:hidden; }
        .panel-heading { display:flex; align-items:start; justify-content:space-between; gap:18px; margin-bottom:17px; }
        .panel-heading p:last-child { margin-bottom:0; }
        .grid-2 { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:18px; }
        .grid-3 { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; }
        .form-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; align-items:end; }
        .field { display:flex; flex-direction:column; gap:6px; min-width:0; }
        .field label,.field > span { color:var(--muted); font-size:12px; font-weight:700; }
        input,select,textarea { width:100%; border:1px solid var(--line); border-radius:8px; padding:9px 10px; color:var(--text); background:#0b141b; outline:none; }
        input:focus,select:focus,textarea:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(85,230,165,.12); }
        textarea { min-height:84px; resize:vertical; }
        .actions { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-top:16px; }
        .checkbox { display:flex; align-items:center; gap:7px; min-height:40px; color:var(--text); }
        .checkbox input { width:auto; accent-color:var(--brand); }
        .table-wrap { overflow-x:auto; }
        table { width:100%; border-collapse:collapse; min-width:760px; }
        th,td { padding:11px 10px; text-align:left; vertical-align:top; border-bottom:1px solid var(--line); }
        th { color:var(--muted); font-size:11px; letter-spacing:.08em; text-transform:uppercase; }
        td strong { display:block; }
        td small { color:var(--muted); }
        .inline-form { display:flex; flex-wrap:wrap; gap:7px; align-items:center; }
        .inline-form input,.inline-form select { min-width:110px; width:auto; }
        .collection-row { display:grid; grid-template-columns:1.1fr 1.5fr 90px 90px auto; gap:10px; padding:14px 0; border-top:1px solid var(--line); align-items:end; }
        .collection-row:first-child { border-top:0; padding-top:0; }
        .collection-row .wide { min-width:0; }
        .collection-row .checkbox { align-items:center; }
        .source-row { display:grid; grid-template-columns:1.1fr 1.8fr 90px 90px auto; gap:10px; padding:14px 0; border-top:1px solid var(--line); align-items:end; }
        .source-row:first-child { border-top:0; padding-top:0; }
        .source-row .wide { min-width:0; }
        code { color:#c7f8dd; }
         .status { display:inline-block; padding:3px 8px; border-radius:999px; color:#caffdf; background:#163c2b; font-size:12px; }
         .status.off { color:#ffdce0; background:#49232b; }
         .sync-monitor { margin-top:18px; }
         .sync-list { display:grid; gap:12px; }
         .sync-item { padding:14px; border:1px solid var(--line); border-radius:11px; background:rgba(8,13,18,.38); }
         .sync-item-heading,.sync-meta { display:flex; align-items:center; justify-content:space-between; gap:12px; }
         .sync-item-heading small { display:block; margin-top:3px; color:var(--muted); }
         .progress-track { height:9px; margin:13px 0 8px; overflow:hidden; border-radius:999px; background:#0a1218; }
         .progress-track span { display:block; height:100%; border-radius:inherit; background:linear-gradient(90deg,#55e6a5,#7be8ff); transition:width .35s ease; }
         .progress-track span.indeterminate { width:38% !important; animation:sync-progress 1.2s ease-in-out infinite alternate; }
         .sync-meta { color:var(--muted); font-size:12px; }
         .sync-error { margin:10px 0 0; color:#ffdce0; }
         .sync-result { margin:10px 0 0; color:#caffdf; font-size:13px; }
         @keyframes sync-progress { from { transform:translateX(-30%); } to { transform:translateX(190%); } }
         .result { margin-top:14px; padding:13px; border:1px solid #69562a; border-radius:10px; color:#ffe8a8; background:#2c2514; }
        .result pre { margin:9px 0 0; max-height:220px; overflow:auto; white-space:pre-wrap; font-size:12px; }
        .empty { padding:20px 0; color:var(--muted); }
        @media (max-width:1000px) { .metric-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } .form-grid,.grid-3 { grid-template-columns:repeat(2,minmax(0,1fr)); } .collection-row,.source-row { grid-template-columns:repeat(2,minmax(0,1fr)); } }
        @media (max-width:650px) { .admin-header { align-items:flex-start; flex-direction:column; } .admin-user { width:100%; justify-content:space-between; } .page-heading { display:block; } .metric-grid,.grid-2,.form-grid,.grid-3 { grid-template-columns:1fr; } .panel { padding:15px; } .collection-row,.source-row { grid-template-columns:1fr; } h1 { font-size:32px; } }
    </style>
</head>
<body>
<div class="admin-shell">
    <header class="admin-header">
        <a class="brand" href="{{ route('admin.dashboard') }}">
            <span class="brand-mark">P</span>
            <span class="brand-copy">PIXFLIX <small>ADMINISTRACION EN LARAVEL</small></span>
        </a>
        <div class="admin-user">
            <span>{{ $admin->name ?: $admin->username ?: $admin->email }}</span>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button class="logout-button" type="submit">Cerrar sesion</button>
            </form>
        </div>
    </header>
    <main class="admin-main">
        @if (session('success'))
            <div class="flash" role="status">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="flash error" role="alert">
                <strong>No se pudo completar la operacion.</strong>
                <ul>
                    @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            </div>
        @endif
        @yield('content')
    </main>
</div>
@yield('scripts')
</body>
</html>
