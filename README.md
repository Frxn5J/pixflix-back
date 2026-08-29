# Pixflix API

Backend Laravel 10 de Pixflix. Expone la API autenticada, el catálogo, la
reproducción y la guía de canales en vivo.

## Requisitos

- Linux (Ubuntu/Debian recomendado)
- PHP 8.1 o superior con `mbstring`, `xml`, `curl`, `zip`, `intl`, `bcmath`
  y el driver PDO de la base de datos
- Composer 2
- PostgreSQL 14+ (PostgreSQL 17 recomendado en el compose de producción)
- DragonflyDB 1.30+ para cache, sesiones, locks y colas (compatible con el
  protocolo RESP que usa Laravel)
- Nginx y PHP-FPM para una instalación tradicional, o Docker para Coolify

## Instalación local

```bash
cp .env.example .env
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8001
```

La API quedará disponible en `http://127.0.0.1:8001/api/v1`.

## Instalación en servidor

```bash
cd /var/www
git clone <URL_DEL_REPOSITORIO_BACKEND> pixflix-backend
cd pixflix-backend
cp .env.example .env
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan key:generate --force
php artisan migrate --force
php artisan storage:link
php artisan optimize
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rw storage bootstrap/cache
```

Configura `.env` antes de ejecutar `migrate` con el dominio público de la API,
la base de datos, Dragonfly y el dominio del frontend. Como mínimo, revisa:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.ejemplo.com
DB_CONNECTION=pgsql
DB_HOST=postgres.example.internal
DB_PORT=5432
DB_DATABASE=pixflix
DB_USERNAME=pixflix
DB_PASSWORD=CAMBIAR_ESTA_CLAVE
DRAGONFLY_HOST=dragonfly.example.internal
DRAGONFLY_PORT=6379
DRAGONFLY_PASSWORD=CAMBIAR_ESTA_CLAVE
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
PIXFLIX_SYNC_ASYNC=true
PIXFLIX_STREAM_DELIVERY=xaccel
CORS_ALLOWED_ORIGINS=https://app.ejemplo.com
SANCTUM_STATEFUL_DOMAINS=app.ejemplo.com
```

El archivo `deploy/nginx-pixflix-api.conf.example` contiene un virtual host
listo para copiar a Nginx. Cambia el dominio y el socket de PHP-FPM antes de
habilitarlo. La raíz pública debe ser únicamente
`/var/www/pixflix-backend/public`.

## Despliegue en Coolify

El backend incluye `Dockerfile` y `docker-compose.coolify.yml`. La opción
recomendada es crear en Coolify una aplicación desde el repositorio usando el
Dockerfile y crear PostgreSQL y Dragonfly como recursos persistentes. Si se
prefiere una sola Service Stack, se puede importar directamente el compose;
incluye `app`, `queue`, `scheduler`, `postgres` y `dragonfly`.

En Coolify configura el dominio de la aplicación apuntando al puerto `8080` y
define como comando de pre-deploy:

```bash
php artisan migrate --force
```

Variables obligatorias:

```dotenv
APP_KEY=base64:CLAVE_GENERADA_CON_php_artisan_key:generate
APP_URL=https://api.ejemplo.com
CORS_ALLOWED_ORIGINS=https://app.ejemplo.com
SANCTUM_STATEFUL_DOMAINS=app.ejemplo.com
POSTGRES_PASSWORD=CLAVE_LARGA_POSTGRES
DRAGONFLY_PASSWORD=CLAVE_LARGA_DRAGONFLY
```

Para una aplicación creada fuera del compose, usa los nombres internos de los
recursos de Coolify en `DB_HOST` y `DRAGONFLY_HOST`. Si Coolify entrega una
URL completa de PostgreSQL, también se acepta en `DB_URL` o `DATABASE_URL`.
No publiques los puertos 5432 ni 6379 a Internet.

El compose separa responsabilidades para permitir escalar `app` y `queue`
de forma independiente. Mantén una sola réplica de `scheduler`. El sistema
de archivos local no debe usarse para datos que deban sobrevivir a una nueva
réplica; configura `FILESYSTEM_DISK=s3` y las variables `AWS_*` si la
aplicación empieza a guardar archivos.

Health checks:

- `/up`: liveness, no depende de la base de datos.
- `/api/v1/health`: contrato público actual de salud.
- `/api/v1/health/ready`: readiness; verifica PostgreSQL y Dragonfly y devuelve
  `503` si alguna dependencia no está disponible.

Elige `DRAGONFLY_MAXMEMORY` según la memoria asignada al recurso y conserva el
volumen `dragonfly_data`. Dragonfly es el almacén rápido de estado; los datos
de negocio viven en PostgreSQL y deben respaldarse con la política de backups
de Coolify.

## Tareas programadas

La sincronización de catálogo, canales IPTV y expiración de pruebas se ejecuta
con el scheduler de Laravel. En Coolify, el servicio `scheduler` ya ejecuta
`php artisan schedule:work`. En una instalación tradicional, instala
`deploy/pixflix-schedule.cron.example` en el crontab del usuario de la
aplicación:

```cron
* * * * * cd /var/www/pixflix-backend && php artisan schedule:run >> /dev/null 2>&1
```

La playlist se configura con `PIXFLIX_IPTV_M3U_URL`; por defecto usa la lista
M3U configurada por el proyecto. El contenido de origen no se muestra al
usuario en la interfaz.

El panel administrativo permite añadir listas M3U separadas para canales en
vivo y para VOD. En las listas VOD, el modo automático agrupa nombres con
`S01E02` o `1x02` como series; las demás entradas se importan como películas.
La reproducción VOD reutiliza el pool de proxies IPTV, incluso para variantes
y segmentos de manifiestos HLS.

Las películas se enriquecen automáticamente con TMDB al sincronizar. Configura
`PIXFLIX_TMDB_API_KEY` o `PIXFLIX_TMDB_ACCESS_TOKEN` para obtener sinopsis,
duración, director, reparto, géneros, valoración, póster y enlace de TMDB.

## Actualización

```bash
git pull --ff-only
composer install --no-dev --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
sudo systemctl reload php8.2-fpm
```

## Verificación

```bash
php artisan test
php artisan route:list
curl https://api.ejemplo.com/api/v1/health
```
