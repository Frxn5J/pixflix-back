# Pixflix API

Backend Laravel 10 de Pixflix. Expone la API autenticada, el catálogo, la
reproducción y la guía de canales en vivo.

## Requisitos

- Linux (Ubuntu/Debian recomendado)
- PHP 8.1 o superior con `mbstring`, `xml`, `curl`, `zip`, `intl`, `bcmath`
  y el driver PDO de la base de datos
- Composer 2
- MySQL 8/MariaDB 10.6+ o SQLite
- Nginx y PHP-FPM para producción

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
la base de datos y el dominio del frontend. Como mínimo, revisa:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.ejemplo.com
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_DATABASE=pixflix
DB_USERNAME=pixflix
DB_PASSWORD=CAMBIAR_ESTA_CLAVE
CORS_ALLOWED_ORIGINS=https://app.ejemplo.com
SANCTUM_STATEFUL_DOMAINS=app.ejemplo.com
```

El archivo `deploy/nginx-pixflix-api.conf.example` contiene un virtual host
listo para copiar a Nginx. Cambia el dominio y el socket de PHP-FPM antes de
habilitarlo. La raíz pública debe ser únicamente
`/var/www/pixflix-backend/public`.

## Tareas programadas

La sincronización de catálogo, canales IPTV y expiración de pruebas se ejecuta
con el scheduler de Laravel. Instala `deploy/pixflix-schedule.cron.example` en
el crontab del usuario de la aplicación:

```cron
* * * * * cd /var/www/pixflix-backend && php artisan schedule:run >> /dev/null 2>&1
```

La playlist se configura con `PIXFLIX_IPTV_M3U_URL`; por defecto usa la lista
M3U configurada por el proyecto. El contenido de origen no se muestra al
usuario en la interfaz.

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
