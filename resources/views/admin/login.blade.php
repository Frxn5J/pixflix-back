<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Acceso administrativo · Pixflix</title>
    <style>
        :root { color-scheme:dark; --bg:#080d12; --panel:#111a22; --line:#263746; --text:#eef5f8; --muted:#9cafb9; --brand:#55e6a5; --danger:#ff9aa5; }
        * { box-sizing:border-box; } body { display:grid; place-items:center; min-height:100vh; margin:0; padding:22px; color:var(--text); background:radial-gradient(circle at 20% 10%,#123529 0,transparent 38rem),var(--bg); font:15px/1.5 Inter,ui-sans-serif,system-ui,sans-serif; }
        .login-shell { width:min(440px,100%); } .brand { display:flex; align-items:center; gap:11px; margin:0 auto 20px; width:max-content; color:var(--brand); font-weight:900; letter-spacing:.14em; } .mark { display:grid; place-items:center; width:38px; height:38px; border-radius:12px; color:#07120d; background:var(--brand); font-size:21px; } .brand small { display:block; margin-top:4px; color:var(--muted); font-size:10px; letter-spacing:.1em; }
        .card { padding:28px; border:1px solid var(--line); border-radius:18px; background:rgba(17,26,34,.94); box-shadow:0 25px 70px rgba(0,0,0,.3); } .eyebrow { margin:0 0 7px; color:var(--brand); font-size:11px; letter-spacing:.14em; font-weight:900; } h1 { margin:0 0 8px; font-size:30px; } .intro { margin:0 0 24px; color:var(--muted); }
        label { display:block; margin:15px 0 6px; color:var(--muted); font-size:13px; font-weight:700; } input { width:100%; padding:11px 12px; color:var(--text); background:#0b141b; border:1px solid var(--line); border-radius:9px; outline:none; } input:focus { border-color:var(--brand); box-shadow:0 0 0 3px rgba(85,230,165,.12); } .remember { display:flex; align-items:center; gap:8px; color:var(--muted); font-size:13px; } .remember input { width:auto; accent-color:var(--brand); }
        button { width:100%; margin-top:22px; padding:12px; color:#06140d; background:var(--brand); border:0; border-radius:9px; font-weight:900; cursor:pointer; } .error { margin:14px 0 0; color:var(--danger); } .note { margin:18px 0 0; color:var(--muted); font-size:12px; text-align:center; }
    </style>
</head>
<body>
<div class="login-shell">
    <div class="brand"><span class="mark">P</span><span>PIXFLIX <small>PANEL ADMINISTRATIVO</small></span></div>
    <section class="card" aria-labelledby="login-title">
        <p class="eyebrow">ACCESO RESTRINGIDO</p>
        <h1 id="login-title">Inicia sesion como admin.</h1>
        <p class="intro">Este acceso administra el backend, las fuentes de contenido y las cuentas del sistema.</p>
        <form method="POST" action="{{ route('admin.login.store') }}">
            @csrf
            <label for="login">Correo, telefono o usuario</label>
            <input id="login" name="login" value="{{ old('login') }}" autocomplete="username" required autofocus>
            @error('login')<p class="error" role="alert">{{ $message }}</p>@enderror
            <label for="password">Contrasena</label>
            <input id="password" name="password" type="password" autocomplete="current-password" required>
            <label class="remember"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Mantener la sesion iniciada</label>
            <button type="submit">Entrar al panel</button>
        </form>
        <p class="note">Solo personal autorizado (admin y agente) puede acceder. Los suscriptores no tienen acceso a este panel.</p>
    </section>
</div>
</body>
</html>
