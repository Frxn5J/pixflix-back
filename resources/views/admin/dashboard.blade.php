@extends('admin.layout')

@section('title', 'Panel administrativo')

@section('content')
@php
    $tabs = [
        'overview' => 'Resumen',
        'users' => 'Usuarios',
        'subscriptions' => 'Suscripciones',
        'plans' => 'Planes',
        'channels' => 'Canales IPTV',
        'iptv-playlists' => 'Listas IPTV',
        'iptv-vod-playlists' => 'VOD IPTV',
        'iptv-proxies' => 'Proxies',
        'fallback' => 'Fuentes Stremio',
        'trials' => 'Crear prueba',
    ];
@endphp
<div class="page-heading">
    <div><p class="eyebrow">CONTROL DEL SERVICIO</p><h1>Panel administrativo</h1><p class="muted">Todas las configuraciones se guardan y ejecutan en Laravel.</p></div>
</div>
<nav class="tabs" aria-label="Secciones administrativas">
    @foreach ($tabs as $key => $label)
        <a class="tab @if ($section === $key) active @endif" href="{{ route('admin.dashboard', ['section' => $key]) }}">{{ $label }}</a>
    @endforeach
</nav>

@if (count($syncProgress ?? []))
    <section class="panel sync-monitor" aria-live="polite">
        <div class="panel-heading">
            <div>
                <p class="eyebrow">PROCESOS EN SEGUNDO PLANO</p>
                <h2>Sincronizaciones</h2>
                <p class="muted">El avance se actualiza automáticamente cada pocos segundos.</p>
            </div>
        </div>
        <div class="sync-list">
            @foreach ($syncProgress as $state)
                @php
                    $percentage = is_numeric($state['percentage'] ?? null) ? max(0, min(100, (int) $state['percentage'])) : 0;
                    $statusLabel = match ($state['status'] ?? null) {
                        'queued' => 'En cola',
                        'running' => 'En curso',
                        'completed' => 'Completada',
                        'failed' => 'Con error',
                        default => 'Estado desconocido',
                    };
                @endphp
                <article class="sync-item" data-sync-progress data-sync-id="{{ $state['id'] }}" data-sync-type="{{ $state['type'] }}" data-sync-initial-status="{{ $state['status'] }}" data-sync-status-url="{{ route('admin.sync-status', ['id' => $state['id']]) }}">
                    <div class="sync-item-heading">
                        <div><strong data-sync-label>{{ $state['label'] }}</strong><small data-sync-message>{{ $state['message'] }}</small></div>
                        <span class="status" data-sync-status>{{ $statusLabel }}</span>
                    </div>
                    <div class="progress-track" role="progressbar" aria-label="Avance de {{ $state['label'] }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $percentage }}"><span data-sync-bar style="width:{{ $percentage }}%"></span></div>
                    <div class="sync-meta"><span data-sync-percent>{{ $state['percentage'] === null ? 'Avance calculándose' : $percentage.'%' }}</span><span data-sync-count>{{ $state['current'] ?? 0 }}{{ $state['total'] !== null ? ' de '.$state['total'] : '' }} elementos</span><span data-sync-eta>{{ $state['eta_label'] ?? 'Tiempo restante calculándose' }}</span></div>
                    <p class="sync-error" data-sync-error hidden></p>
                    <p class="sync-result" data-sync-result hidden></p>
                </article>
            @endforeach
        </div>
    </section>
@endif

@if ($section === 'overview')
    <div class="metric-grid">
        @foreach ([['users','Usuarios'],['subscribers','Suscriptores'],['active_subscriptions','Suscripciones activas'],['trials','Pruebas activas'],['plans','Planes'],['channels','Canales'],['active_channels','Canales activos'],['staff','Personal']] as [$key,$label])
            <div class="metric"><span>{{ $label }}</span><strong>{{ $overview[$key] ?? 0 }}</strong></div>
        @endforeach
    </div>
    <section class="panel" style="margin-top:18px">
        <div class="panel-heading"><div><p class="eyebrow">FUENTE DE CONTENIDO</p><h2>Estado de Stremio</h2></div><span class="status @if (!($streamFallback['enabled'] ?? false)) off @endif">{{ ($streamFallback['enabled'] ?? false) ? 'Activo' : 'Inactivo' }}</span></div>
        <p class="muted">{{ ($streamFallback['primary'] ?? false) ? 'Stremio está configurado como fuente principal; la cache y la API legacy quedan fuera de la resolución.' : 'Stremio está disponible como fallback.' }}</p>
        <a class="button primary" href="{{ route('admin.dashboard', ['section' => 'fallback']) }}">Administrar fuentes</a>
    </section>
@elseif ($section === 'users')
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow">CUENTAS</p><h2>Usuarios</h2><p class="muted">Edita identidad, rol y contraseña desde el backend.</p></div><span class="status">{{ count($users) }} mostrados</span></div>
        <div class="table-wrap"><table><thead><tr><th>Usuario</th><th>Contacto</th><th>Rol</th><th>Suscripción</th><th>Guardar</th></tr></thead><tbody>
        @forelse ($users as $user)
            <tr><td><strong>{{ $user['name'] ?: 'Sin nombre' }}</strong><small>{{ $user['username'] ?: 'sin usuario' }} · #{{ $user['id'] }}</small></td><td><small>{{ $user['email'] ?: 'sin correo' }}<br>{{ $user['phone'] ?: 'sin teléfono' }}</small></td><td><form class="inline-form" method="POST" action="{{ route('admin.users.update', $user['id']) }}">@csrf @method('PUT')<input type="hidden" name="name" value="{{ $user['name'] }}"><input type="hidden" name="email" value="{{ $user['email'] }}"><input type="hidden" name="phone" value="{{ $user['phone'] }}"><input type="hidden" name="username" value="{{ $user['username'] }}"><select name="role"><option value="subscriber" @selected($user['role'] === 'subscriber')>Suscriptor</option><option value="agent" @selected($user['role'] === 'agent')>Agente</option><option value="admin" @selected($user['role'] === 'admin')>Admin</option></select></td><td><small>{{ data_get($user, 'subscription.plan.name', 'Sin plan') }}<br>{{ data_get($user, 'subscription.status', 'Sin suscripción') }}</small></td><td><button class="button small primary" type="submit">Guardar rol</button></form></td></tr>
        @empty <tr><td colspan="5" class="empty">No hay usuarios.</td></tr>@endforelse
        </tbody></table></div>
    </section>
@elseif ($section === 'subscriptions')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">ACCESO</p><h2>Suscripciones</h2><p class="muted">Activa, suspende o modifica la vigencia de cada cuenta.</p></div></div>
    <div class="table-wrap"><table><thead><tr><th>Cuenta</th><th>Plan</th><th>Estado</th><th>Vigencia</th><th>Guardar</th></tr></thead><tbody>
    @forelse ($subscriptions as $subscription)
        <tr><td><strong>{{ data_get($subscription, 'user.name', 'Sin nombre') }}</strong><small>{{ data_get($subscription, 'user.username', data_get($subscription, 'user.email', '')) }}</small></td><td><select form="subscription-{{ $subscription['id'] }}" name="plan_id"><option value="">Sin plan</option>@foreach ($plans as $plan)<option value="{{ $plan['id'] }}" @selected((string) ($subscription['plan']['id'] ?? '') === (string) $plan['id'])>{{ $plan['name'] }}</option>@endforeach</select></td><td><form id="subscription-{{ $subscription['id'] }}" class="inline-form" method="POST" action="{{ route('admin.subscriptions.update', $subscription['id']) }}">@csrf @method('PUT')<select name="status"><option value="pending" @selected($subscription['status'] === 'pending')>Pendiente</option><option value="active" @selected($subscription['status'] === 'active')>Activa</option><option value="expiring" @selected($subscription['status'] === 'expiring')>Por vencer</option><option value="expired" @selected($subscription['status'] === 'expired')>Vencida</option><option value="suspended" @selected($subscription['status'] === 'suspended')>Suspendida</option><option value="cancelled" @selected($subscription['status'] === 'cancelled')>Cancelada</option><option value="trial" @selected($subscription['status'] === 'trial')>Prueba</option></select><input type="hidden" name="plan_id" value="{{ $subscription['plan']['id'] ?? '' }}"></td><td><small>Inicio: {{ $subscription['starts_at'] ?: '—' }}<br>Fin: {{ $subscription['ends_at'] ?: '—' }}<br>Grupo: {{ $subscription['group_number'] }}</small></td><td><button class="button small primary" type="submit">Guardar estado</button></form></td></tr>
    @empty <tr><td colspan="5" class="empty">No hay suscripciones.</td></tr>@endforelse
    </tbody></table></div></section>
@elseif ($section === 'plans')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">PRODUCTOS</p><h2>Planes</h2><p class="muted">Precios, límites de perfiles/dispositivos y calidad máxima.</p></div></div>
    @foreach ($plans as $plan)
        <form class="collection-row" method="POST" action="{{ route('admin.plans.update', $plan['id']) }}"><input type="hidden" name="_token" value="{{ csrf_token() }}"><input type="hidden" name="_method" value="PUT"><div class="field"><label>Nombre</label><input name="name" value="{{ $plan['name'] }}" required></div><div class="field"><label>Descripción</label><input name="description" value="{{ $plan['description'] }}"></div><div class="field"><label>Precio</label><input name="price" type="number" min="0" step="0.01" value="{{ $plan['price'] }}" required></div><div class="field"><label>Calidad</label><input name="max_quality" value="{{ $plan['max_quality'] }}" required></div><div class="actions"><input type="hidden" name="max_profiles" value="{{ $plan['max_profiles'] }}"><input type="hidden" name="max_devices" value="{{ $plan['max_devices'] }}"><input type="hidden" name="is_active" value="0"><label class="checkbox"><input type="checkbox" name="is_active" value="1" @checked($plan['is_active'])> Activo</label><button class="button small primary" type="submit">Guardar</button></div></form>
    @endforeach
    @if (count($plans) === 0)<p class="empty">No hay planes configurados.</p>@endif
    </section>
    <section class="panel"><h3>Crear plan</h3><form method="POST" action="{{ route('admin.plans.store') }}">@csrf<div class="form-grid"><div class="field"><label>Nombre</label><input name="name" required></div><div class="field"><label>Precio</label><input name="price" type="number" min="0" step="0.01" required></div><div class="field"><label>Perfiles</label><input name="max_profiles" type="number" min="1" max="20" value="1" required></div><div class="field"><label>Dispositivos</label><input name="max_devices" type="number" min="1" max="20" value="1" required></div><div class="field"><label>Calidad</label><input name="max_quality" value="HD" required></div><div class="field"><label>Descripción</label><input name="description"></div><label class="checkbox"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" checked> Activo</label></div><div class="actions"><button class="button primary" type="submit">Crear plan</button></div></form></section>
@elseif ($section === 'channels')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">TELEVISION</p><h2>Canales IPTV</h2><p class="muted">Controla qué canales sincronizados quedan disponibles.</p></div><span class="status">{{ count($channels) }} canales</span></div><div class="table-wrap"><table><thead><tr><th>Canal</th><th>Categoría</th><th>Fuente</th><th>Estado</th></tr></thead><tbody>
    @forelse ($channels as $channel)<tr><td><strong>{{ $channel['name'] }}</strong><small>{{ $channel['country'] ?: '—' }} · {{ $channel['language'] ?: '—' }}</small></td><td>{{ $channel['category'] ?: '—' }}</td><td><small>{{ $channel['external_id'] ?: '—' }}<br>{{ $channel['has_stream'] ? 'stream disponible' : 'sin stream' }}</small></td><td><form class="inline-form" method="POST" action="{{ route('admin.channels.update', $channel['id']) }}">@csrf @method('PUT')<input type="hidden" name="is_active" value="0"><label class="checkbox"><input type="checkbox" name="is_active" value="1" @checked($channel['is_active'])> Activo</label><button class="button small" type="submit">Guardar</button></form></td></tr>@empty<tr><td colspan="4" class="empty">No hay canales. Sincroniza una lista IPTV.</td></tr>@endforelse
    </tbody></table></div></section>
@elseif ($section === 'iptv-playlists')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">FUENTES EN VIVO</p><h2>Listas IPTV</h2><p class="muted">Las listas y su sincronización ahora se administran en Laravel.</p></div></div>
    @if (count($iptvPlaylists))<form method="POST" action="{{ route('admin.iptv-playlists.update') }}">@csrf @method('PUT')@foreach ($iptvPlaylists as $index => $playlist)<div class="source-row"><input type="hidden" name="playlists[{{ $index }}][id]" value="{{ $playlist['id'] }}"><div class="field"><label>Nombre</label><input name="playlists[{{ $index }}][name]" value="{{ $playlist['name'] }}" required></div><div class="field wide"><label>URL M3U/M3U8</label><input type="url" name="playlists[{{ $index }}][url]" value="{{ $playlist['url'] }}" required></div><div class="field"><label>País</label><input name="playlists[{{ $index }}][country]" value="{{ $playlist['country'] ?? '' }}"></div><div class="field"><label>Idioma</label><input name="playlists[{{ $index }}][language]" value="{{ $playlist['language'] ?? '' }}"></div><div class="field"><label>Prioridad</label><input name="playlists[{{ $index }}][priority]" type="number" min="1" value="{{ $playlist['priority'] }}" required></div><label class="checkbox"><input type="hidden" name="playlists[{{ $index }}][use_proxy]" value="0"><input type="checkbox" name="playlists[{{ $index }}][use_proxy]" value="1" @checked($playlist['use_proxy'])> Proxy</label><label class="checkbox"><input type="hidden" name="playlists[{{ $index }}][enabled]" value="0"><input type="checkbox" name="playlists[{{ $index }}][enabled]" value="1" @checked($playlist['enabled'])> Activa</label><button class="button small danger" type="submit" formaction="{{ route('admin.iptv-playlists.remove', $playlist['id']) }}" formmethod="POST" name="_method" value="DELETE" onclick="return confirm('¿Eliminar esta lista?')">Eliminar</button></div>@endforeach<div class="actions"><button class="button primary" type="submit">Guardar listas</button><button class="button" type="submit" formaction="{{ route('admin.iptv-playlists.sync') }}" formmethod="POST" name="_method" value="POST">Sincronizar ahora</button><button class="button" type="submit" formaction="{{ route('admin.iptv-resources.refresh') }}" formmethod="POST" name="_method" value="POST">Actualizar recursos</button></div></form>@else<p class="empty">No hay listas IPTV configuradas.</p>@endif
    </section>
    <section class="panel"><h3>Agregar lista IPTV</h3><form method="POST" action="{{ route('admin.iptv-playlists.add') }}">@csrf<div class="form-grid"><div class="field"><label>Nombre</label><input name="name" placeholder="TV en vivo" required></div><div class="field"><label>URL M3U/M3U8</label><input name="url" type="url" placeholder="https://servidor/lista.m3u" required></div><div class="field"><label>País</label><input name="country" placeholder="MX"></div><div class="field"><label>Idioma</label><input name="language" placeholder="spa"></div><div class="field"><label>Prioridad</label><input name="priority" type="number" min="1" value="1" required></div><label class="checkbox"><input type="hidden" name="use_proxy" value="0"><input type="checkbox" name="use_proxy" value="1" checked> Usar proxy</label></div><div class="actions"><button class="button primary" type="submit">Agregar lista</button></div></form></section>
@elseif ($section === 'iptv-vod-playlists')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">VIDEO BAJO DEMANDA</p><h2>Listas IPTV para VOD</h2><p class="muted">Configura listas M3U para películas y series.</p></div></div>
    @if (count($iptvVodPlaylists))<form method="POST" action="{{ route('admin.iptv-vod-playlists.update') }}">@csrf @method('PUT')@foreach ($iptvVodPlaylists as $index => $playlist)<div class="source-row"><input type="hidden" name="playlists[{{ $index }}][id]" value="{{ $playlist['id'] }}"><div class="field"><label>Nombre</label><input name="playlists[{{ $index }}][name]" value="{{ $playlist['name'] }}" required></div><div class="field wide"><label>URL M3U/M3U8</label><input type="url" name="playlists[{{ $index }}][url]" value="{{ $playlist['url'] }}" required></div><div class="field"><label>Idioma</label><input name="playlists[{{ $index }}][language]" value="{{ $playlist['language'] ?? '' }}"></div><div class="field"><label>Contenido</label><select name="playlists[{{ $index }}][content_type]"><option value="auto" @selected(($playlist['content_type'] ?? 'auto') === 'auto')>Detectar</option><option value="movie" @selected(($playlist['content_type'] ?? '') === 'movie')>Películas</option></select></div><div class="field"><label>Prioridad</label><input name="playlists[{{ $index }}][priority]" type="number" min="1" value="{{ $playlist['priority'] }}" required></div><label class="checkbox"><input type="hidden" name="playlists[{{ $index }}][use_proxy]" value="0"><input type="checkbox" name="playlists[{{ $index }}][use_proxy]" value="1" @checked($playlist['use_proxy'] ?? true)> Requiere proxy</label><label class="checkbox"><input type="hidden" name="playlists[{{ $index }}][enabled]" value="0"><input type="checkbox" name="playlists[{{ $index }}][enabled]" value="1" @checked($playlist['enabled'] ?? true)> Activa</label><button class="button small danger" type="submit" formaction="{{ route('admin.iptv-vod-playlists.remove', $playlist['id']) }}" formmethod="POST" name="_method" value="DELETE" onclick="return confirm('¿Eliminar esta lista?')">Eliminar</button></div>@endforeach<div class="actions"><button class="button primary" type="submit">Guardar VOD</button><button class="button" type="submit" formaction="{{ route('admin.iptv-vod-playlists.sync') }}" formmethod="POST" name="_method" value="POST">Sincronizar VOD</button></div></form>@else<p class="empty">No hay listas VOD configuradas.</p>@endif</section>
    <section class="panel"><h3>Agregar lista VOD</h3><form method="POST" action="{{ route('admin.iptv-vod-playlists.add') }}">@csrf<div class="form-grid"><div class="field"><label>Nombre</label><input name="name" placeholder="Películas y series" required></div><div class="field"><label>URL M3U/M3U8</label><input name="url" type="url" required></div><div class="field"><label>Idioma</label><input name="language" placeholder="spa"></div><div class="field"><label>Contenido</label><select name="content_type"><option value="auto">Detectar</option><option value="movie">Películas</option></select></div><div class="field"><label>Prioridad</label><input name="priority" type="number" min="1" value="1" required></div><label class="checkbox"><input type="checkbox" name="use_proxy" value="1" checked> Requiere proxy</label></div><div class="actions"><button class="button primary" type="submit">Agregar lista VOD</button></div></form></section>
@elseif ($section === 'iptv-proxies')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">RESILIENCIA IPTV</p><h2>Proxies IPTV</h2><p class="muted">El reproductor del frontend usa estas URLs por prioridad y prueba la siguiente si falla.</p></div></div>
    @if (count($iptvProxies))<form method="POST" action="{{ route('admin.iptv-proxies.update') }}">@csrf @method('PUT')@foreach ($iptvProxies as $index => $proxy)<div class="collection-row"><input type="hidden" name="proxies[{{ $index }}][id]" value="{{ $proxy['id'] }}"><div class="field"><label>Nombre</label><input name="proxies[{{ $index }}][name]" value="{{ $proxy['name'] }}" required></div><div class="field"><label>URL base</label><input type="url" name="proxies[{{ $index }}][base_url]" value="{{ $proxy['base_url'] }}" required></div><div class="field"><label>Prioridad</label><input name="proxies[{{ $index }}][priority]" type="number" min="1" value="{{ $proxy['priority'] }}" required></div><label class="checkbox"><input type="hidden" name="proxies[{{ $index }}][enabled]" value="0"><input type="checkbox" name="proxies[{{ $index }}][enabled]" value="1" @checked($proxy['enabled'])> Activo</label><button class="button small danger" type="submit" formaction="{{ route('admin.iptv-proxies.remove', $proxy['id']) }}" formmethod="POST" name="_method" value="DELETE" onclick="return confirm('¿Eliminar este proxy?')">Eliminar</button></div>@endforeach<div class="actions"><button class="button primary" type="submit">Guardar proxies</button></div></form>@else<p class="empty">No hay proxies. Los streams se solicitarán directamente.</p>@endif</section>
    <section class="panel"><h3>Agregar proxy</h3><form method="POST" action="{{ route('admin.iptv-proxies.add') }}">@csrf<div class="form-grid"><div class="field"><label>Nombre</label><input name="name" placeholder="Proxy principal" required></div><div class="field"><label>URL base</label><input name="base_url" type="url" placeholder="https://proxy/?token=..." required></div><div class="field"><label>Prioridad</label><input name="priority" type="number" min="1" value="1" required></div></div><div class="actions"><button class="button primary" type="submit">Agregar proxy</button></div></form></section>
@elseif ($section === 'fallback')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">FUENTE DE PLAYBACK</p><h2>Fuentes de streams</h2><p class="muted">Configura un addon Stremio como fuente principal y deja fuera la cache/API legacy.</p></div><span class="status @if (!($streamFallback['enabled'] ?? false)) off @endif">{{ ($streamFallback['enabled'] ?? false) ? 'Activo' : 'Inactivo' }}</span></div>
    <form method="POST" action="{{ route('admin.stream-fallback.update') }}">@csrf @method('PUT')<div class="grid-2"><label class="checkbox"><input type="hidden" name="primary" value="0"><input type="checkbox" name="primary" value="1" @checked($streamFallback['primary'] ?? false)> Usar Stremio como fuente principal</label><label class="checkbox"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked($streamFallback['enabled'] ?? false)> Activar fuente Stremio</label></div><div class="form-grid" style="margin-top:15px"><div class="field"><label>Idiomas permitidos, separados por coma</label><input name="languages_csv" value="{{ implode(', ', $streamFallback['languages'] ?? []) }}" placeholder="Latino, Español, English"></div><div class="field"><label>Timeout general (segundos)</label><input name="timeout_seconds" type="number" min="1" max="60" value="{{ $streamFallback['timeout_seconds'] ?? 10 }}" required></div><div class="field"><label>Cache de resoluciones (segundos)</label><input name="cache_ttl_seconds" type="number" min="60" max="604800" value="{{ $streamFallback['cache_ttl_seconds'] ?? 1800 }}" required></div></div><h3 style="margin-top:24px">Addons instalados</h3>@forelse ($streamFallback['addons'] ?? [] as $index => $addon)<div class="source-row"><input type="hidden" name="addons[{{ $index }}][id]" value="{{ $addon['id'] }}"><div class="field"><label>Nombre</label><input name="addons[{{ $index }}][name]" value="{{ $addon['name'] }}" required></div><div class="field wide"><label>URL base o manifest.json</label><input type="url" name="addons[{{ $index }}][base_url]" value="{{ $addon['base_url'] }}" required></div><div class="field"><label>Prioridad</label><input name="addons[{{ $index }}][priority]" type="number" min="1" value="{{ $addon['priority'] }}" required></div><div class="field"><label>Timeout</label><input name="addons[{{ $index }}][timeout_seconds]" type="number" min="1" max="60" value="{{ $addon['timeout_seconds'] ?? ($streamFallback['timeout_seconds'] ?? 10) }}"></div><label class="checkbox"><input type="hidden" name="addons[{{ $index }}][enabled]" value="0"><input type="checkbox" name="addons[{{ $index }}][enabled]" value="1" @checked($addon['enabled'])> Activo</label><button class="button small danger" type="submit" formaction="{{ route('admin.stream-fallback.addon.remove', $addon['id']) }}" formmethod="POST" name="_method" value="DELETE" onclick="return confirm('¿Eliminar este addon?')">Eliminar</button></div>@empty<p class="empty">No hay addons instalados.</p>@endforelse<div class="actions"><button class="button primary" type="submit">Guardar configuración</button><button class="button" type="submit" formaction="{{ route('admin.stream-fallback.sync-catalog') }}" formmethod="POST" name="_method" value="POST">Importar catálogo</button></div></form></section>
    <section class="panel">
        <div class="panel-heading"><div><p class="eyebrow">INVENTARIO DE CONTENIDO</p><h3>Contenido por addon de Stremio</h3><p class="muted">Conteo de películas y series detectado en la última importación del catálogo.</p></div>@if ($streamFallback['catalog_last_sync'] ?? null)<span class="status">Actualizado {{ \Carbon\CarbonImmutable::parse($streamFallback['catalog_last_sync'])->format('d/m/Y H:i') }}</span>@endif</div>
        @if (count($streamFallback['addon_counts'] ?? []))
            <div class="table-wrap"><table><thead><tr><th>Addon</th><th>Películas</th><th>Series</th><th>Total</th><th>Catálogos</th></tr></thead><tbody>
            @foreach ($streamFallback['addon_counts'] as $addonCount)
                <tr><td><strong>{{ $addonCount['name'] }}</strong><small>{{ ($addonCount['enabled'] ?? true) ? 'Activo' : 'Inactivo' }}</small></td><td>{{ $addonCount['movies'] ?? 0 }}</td><td>{{ $addonCount['series'] ?? 0 }}</td><td><strong>{{ $addonCount['titles'] ?? 0 }}</strong></td><td>{{ $addonCount['catalogs'] ?? 0 }}</td></tr>
            @endforeach
            </tbody></table></div>
        @else
            <p class="empty">Todavía no hay conteos. Usa «Importar catálogo» para consultar cada addon.</p>
        @endif
    </section>
    @if (session('verification'))<div class="result"><strong>Resultado de verificación del addon</strong><pre>{{ json_encode(session('verification'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>@endif
    @if (session('content_verification'))<div class="result"><strong>Reporte de contenido</strong><pre>{{ json_encode(session('content_verification'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>@endif
    <section class="panel"><h3>Verificar e instalar addon</h3><p class="hint">Laravel validará el manifest y que publique recursos de catálogo y streams antes de guardarlo.</p><form method="POST" action="{{ route('admin.stream-fallback.addon') }}">@csrf<div class="form-grid"><div class="field"><label>Nombre</label><input name="name" value="{{ old('name') }}" required></div><div class="field"><label>URL base o manifest.json</label><input name="base_url" type="url" value="{{ old('base_url') }}" placeholder="https://addon.example/manifest.json" required></div><div class="field"><label>Prioridad</label><input name="priority" type="number" min="1" value="{{ old('priority', 1) }}" required></div><div class="field"><label>Timeout</label><input name="timeout_seconds" type="number" min="1" max="60" value="{{ old('timeout_seconds', $streamFallback['timeout_seconds'] ?? 10) }}" required></div></div><div class="actions"><button class="button primary" type="submit">Verificar e instalar addon</button></div></form></section>
    <section class="panel"><h3>Verificar contenido de un addon</h3><form method="POST" action="{{ route('admin.stream-fallback.verify-content') }}">@csrf<div class="form-grid"><div class="field"><label>URL del addon</label><input name="base_url" type="url" required></div><div class="field"><label>Idiomas a revisar</label><input name="languages_csv" placeholder="latino, español"></div><div class="field"><label>Timeout</label><input name="timeout_seconds" type="number" min="1" max="60" value="{{ $streamFallback['timeout_seconds'] ?? 10 }}"></div></div><div class="actions"><button class="button" type="submit">Verificar contenido</button></div></form></section>
@elseif ($section === 'trials')
    <section class="panel"><div class="panel-heading"><div><p class="eyebrow">ALTAS RÁPIDAS</p><h2>Crear cuenta de prueba</h2><p class="muted">Genera una cuenta de una hora y muestra sus credenciales una sola vez.</p></div></div><form method="POST" action="{{ route('admin.trials.store') }}">@csrf<div class="form-grid"><div class="field"><label>Nombre (opcional)</label><input name="name" placeholder="Cuenta de prueba"></div><div class="field"><label>Referencia (opcional)</label><input name="label" placeholder="WhatsApp, campaña, etc."></div></div><div class="actions"><button class="button primary" type="submit">Crear cuenta de prueba</button></div></form></section>
    @if (session('trial_credentials'))@php($trial = session('trial_credentials'))<section class="panel"><h3>Credenciales generadas</h3><div class="grid-3"><div class="metric"><span>Usuario</span><strong>{{ $trial['username'] }}</strong></div><div class="metric"><span>Contraseña</span><strong>{{ $trial['password'] }}</strong></div><div class="metric"><span>Expira</span><strong>{{ $trial['expires_at'] }}</strong></div></div><p class="hint" style="margin-top:14px">Guarda estas credenciales antes de salir; no se volverán a mostrar automáticamente.</p></section>@endif
@endif
@endsection

@section('scripts')
<script>
(() => {
    const statusNames = { queued: 'En cola', running: 'En curso', completed: 'Completada', failed: 'Con error' };
    const activeStatuses = new Set(['queued', 'running']);
    const formatResult = (result) => {
        if (!result || typeof result !== 'object') return '';
        const parts = [];
        [['titles', 'títulos'], ['movies', 'películas'], ['series', 'series'], ['episodes', 'episodios'], ['channels', 'canales'], ['streams', 'streams']].forEach(([key, label]) => {
            if (result[key] !== undefined && result[key] !== null) parts.push(`${result[key]} ${label}`);
        });
        return parts.length ? `Resultado: ${parts.join(' · ')}` : '';
    };
    const render = (card, state) => {
        const percentage = Number.isFinite(Number(state.percentage)) ? Math.max(0, Math.min(100, Number(state.percentage))) : 0;
        const indeterminate = state.percentage === null || state.percentage === undefined;
        const bar = card.querySelector('[data-sync-bar]');
        const progress = card.querySelector('[role="progressbar"]');
        const status = card.querySelector('[data-sync-status]');
        const percent = card.querySelector('[data-sync-percent]');
        const count = card.querySelector('[data-sync-count]');
        const eta = card.querySelector('[data-sync-eta]');
        const message = card.querySelector('[data-sync-message]');
        const error = card.querySelector('[data-sync-error]');
        const result = card.querySelector('[data-sync-result]');
        bar.style.width = `${percentage}%`;
        bar.classList.toggle('indeterminate', indeterminate && activeStatuses.has(state.status));
        progress.setAttribute('aria-valuenow', String(percentage));
        status.textContent = statusNames[state.status] || state.status || 'Desconocido';
        percent.textContent = indeterminate ? 'Avance calculándose' : `${percentage}%`;
        count.textContent = `${state.current || 0}${state.total !== null && state.total !== undefined ? ` de ${state.total}` : ''} elementos`;
        eta.textContent = state.status === 'completed' ? 'Listo' : (state.eta_label || 'Tiempo restante calculándose');
        message.textContent = state.message || '';
        error.hidden = !state.error;
        error.textContent = state.error || '';
        const resultText = formatResult(state.result);
        result.hidden = !resultText;
        result.textContent = resultText;
    };
    const poll = async (card) => {
        try {
            const response = await fetch(card.dataset.syncStatusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const payload = await response.json();
            if (payload.data) render(card, payload.data);
            if (payload.data && activeStatuses.has(payload.data.status)) window.setTimeout(() => poll(card), 2500);
            if (payload.data && payload.data.status === 'completed' && card.dataset.syncType === 'stremio' && activeStatuses.has(card.dataset.syncInitialStatus) && !card.dataset.reloaded) {
                card.dataset.reloaded = '1';
                window.setTimeout(() => window.location.reload(), 500);
            }
        } catch (_) {
            window.setTimeout(() => poll(card), 5000);
        }
    };
    document.querySelectorAll('[data-sync-progress]').forEach((card) => poll(card));
})();
</script>
@endsection
