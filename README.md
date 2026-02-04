# Zcout – Laravel Backend (API)

Backend API dla projektu **Zcout** – crowdsourcowej bazy danych piłkarzy
opartej o pojedynki atrybutów (Football Manager–style).

## Stack
- Laravel
- PostgreSQL
- Docker (Laravel Sail)

## Wymagania
- Docker + Docker Compose
- Git
- Bash (Git Bash / WSL na Windowsie)

## Instalacja (lokalnie)

```bash
git clone https://github.com/TWOJ_LOGIN/zcout-backend.git
cd zcout-backend
composer install
cp .env.example .env
php artisan key:generate
Uruchomienie (Docker / Sail)
bash vendor/bin/sail up -d
Migracje:

bash vendor/bin/sail artisan migrate
Aplikacja:

http://localhost:8080

API (MVP)
Healthcheck
GET /api/health
Utworzenie piłkarza
POST /api/players
Body (JSON):

{
  "name": "Dennis Bergkamp",
  "country": "NL",
  "club": "Arsenal",
  "position": "AM"
}
Pobranie piłkarza
GET /api/players/{id}
Uwagi
slug generowany automatycznie z name

API responses kontrolowane przez Laravel Resources

Projekt rozwijany iteracyjnie (MVP-first)
