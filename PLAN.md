# Plan de desarrollo del backend Pixflix

El plan coordinado de implementación está en [`../PLAN.md`](../PLAN.md).

## Base compatible

- Laravel 10.50.3.
- PHP 8.1.10.
- Laravel Sanctum 3.3.
- PHPUnit 10.5.
- SQLite para desarrollo local; MySQL/PostgreSQL para staging y producción.
- Redis, Horizon y Scheduler se incorporarán junto con las fases de sincronización.

## Orden de implementación

1. API base `/api/v1`, errores, CORS, request id y rate limits.
2. Migraciones, modelos, seeders y relaciones del dominio.
3. Sanctum, login, logout, roles y suscripciones.
4. Catálogo cacheado, sincronización y streams normalizados.
5. Perfiles, progreso y favoritos.
6. IPTV, EPG y fallback de streams.
7. Trials de una hora y API keys.
8. Administración, auditoría, métricas y operación.

## Regla de compatibilidad

No usar APIs ni dependencias que requieran PHP 8.2 o superior. Composer está configurado con:

```json
"platform": {
    "php": "8.1.10"
}
```

Laravel 10 está fuera de soporte activo; la actualización a Laravel 11/12 queda condicionada a actualizar PHP.

## Estado de C1

- Implementados usuarios con email/telefono/usuario, roles, planes, suscripciones y seeds locales.
- Implementados login, logout, `me`, cambio de contraseña y middleware de rol/suscripcion activa.

## Estado de C2

- Implementados perfiles con límite por plan, nombres únicos y aislamiento por suscripción.
- Implementados alta, edición, eliminación, PIN hasheado y respuesta sanitizada sin `pin_hash`.
- Tests de autorización, eliminación, límite, aislamiento y hash de PIN añadidos.

## Estado de C4

- Implementados streams normalizados por titulo (/titles/{slug}/streams), episodio (/episodes/{id}/streams) y resolve (/catalog/resolve) con contrato sanitizado sin URLs crudas.
- Implementados watch_progress y playback_logs, endpoint PUT /progress y GET /progress/continue-watching aislados por perfil y suscripcion.
- Implementadas fixtures, ocultamiento de proveedor y validacion de supresion de completados en continuar viendo.

## Estado de C5

- Implementados cliente aislado con fallback, reintentos, backoff y circuit breaker por base.
- Implementados snapshots running/partial/success con checkpoint reanudable, jobs por pagina/titulo/episodio y lock de sincronizacion.
- Implementados comando `pixflix:sync-catalog`, scheduler configurable por settings y servicio cache-first del snapshot exitoso.

## Estado del fallback de streams

- Implementada resolución ordenada cache → API principal → addons Stremio por prioridad.
- Implementada configuración administrativa persistida en `settings`, con múltiples addons, timeout, prioridad, activación y filtro de idioma.
- Implementada normalización al contrato `Stream`, descarte de torrents sin seeders/leechers y pruebas secuenciales sin borrar la base de datos.
- Implementada verificación rápida de manifest Stremio al agregar addons, sin cachear ni persistir el diagnóstico.
- Implementada verificación profunda manual por addon: paginación de catálogos, conteo por tipo/idioma y revisión temporal de streams y pares torrent, con límites de páginas/elementos y sin cache ni persistencia.

## Estado de C3

- Implementados snapshots versionados, títulos, temporadas y episodios cacheados con seed local idempotente.
- Implementados Resources sanitizados, listado paginado, filtros, destacados, géneros y detalle por slug.
- Pendiente ejecutar migraciones y tests en un entorno con PHP y las extensiones requeridas.
