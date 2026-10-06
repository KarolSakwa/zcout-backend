# Zcout Backend

Backend service for Zcout - a football crowd-scouting platform built around fast player duels, live rankings and community-driven attribute ratings.

Instead of filling long rating forms, users compare two players in a specific attribute (e.g. Pace, Dribbling, Passing).

The system transforms these micro-decisions into evolving player profiles, rankings and scouting insights using custom Elo-like rating and confidence mechanisms.

---

# Core Features

- Duel-based voting system
- Attribute rating engine
- Confidence system
- Anonymous + authenticated voting
- Matchmaking system with exposure balancing
- Scout reports (direct attribute ratings)
- Live rankings and player profiles
- Simulation laboratory for large-scale testing
- Scouting progression (My Scouting unlock)

---

# Tech Stack

- Laravel 12
- PostgreSQL
- Docker / Docker Compose
- Redis (ranking projections)
- RabbitMQ (overall ranking projection events)
- Soketi (Pusher-compatible WebSockets)
- REST API
- PHPUnit

Architecture highlights:

- Event-log inspired vote storage
- Materialized state for fast reads
- Domain-oriented matchmaking layer
- Simulation-based tuning workflow
- Ranking projections via Redis (+ RabbitMQ events; see docs for sync vs async paths)

### Ranking projections (Redis + RabbitMQ)

PostgreSQL is the source of truth. Redis holds ranking ZSETs for fast reads (rebuildable from PG).

**As-built write path (important):**

- **Attribute** rating updates upsert Redis **synchronously** in the domain event listener, then publish to RabbitMQ (attribute consumer is largely redundant for correctness).
- **Overall** updates publish to RabbitMQ; Redis `ranking:overall` is updated by the overall projection consumer **or** by a full rebuild (no sync Redis write in the overall listener).

See `docs/rankings-events-realtime.md` for the authoritative description. Do not assume “consumers only” for attribute rankings.

Current projections:

- Overall player rankings
- Attribute-specific rankings

Technical docs: [`docs/README.md`](docs/README.md) · As-built status: see workspace `zcout-private-docs/STATUS.md`.

---

# Infrastructure & Deployment

Production infrastructure is separated from application repositories.

Zcout uses:

- dedicated infrastructure repository,
- Docker-based runtime environment,
- GitHub Actions CI pipelines,
- GitHub Container Registry (GHCR),
- artifact-based deployments,
- isolated Docker networking,
- automated healthchecks and backup workflows.

Frontend and backend images are built in GitHub Actions and pushed to GHCR.

The production VPS acts as a runtime-only environment:

- no frontend builds on production,
- no Composer installs on production,
- no npm installs on production.

Deployment flow:

```text
git push
    ↓
GitHub Actions
    ↓
Docker image build
    ↓
GHCR push
    ↓
VPS image pull
    ↓
Docker Compose deployment
```

Backend image CI: [`.github/workflows/backend-image.yml`](.github/workflows/backend-image.yml).

---

# Product Philosophy

Zcout is designed around a simple gameplay loop:

```text
duel → decision → crowd reveal → next duel
```

The goal is to make data collection feel like interaction and discovery instead of form filling.

The platform focuses on:

- low-friction entry,
- fast feedback,
- evolving rankings,
- living player profiles,
- community-driven scouting perception.

---

# Matchmaking System

The matchmaking layer is designed to balance:

- player exposure,
- confidence growth,
- freshness of ratings,
- duel quality,
- anti-repetition behavior.

Current matchmaking architecture includes:

- production vs calibration flows,
- tier-based player exposure,
- gap filtering,
- positional matchmaking,
- separate GK / non-GK routing,
- confidence-aware candidate weighting.

---

# Rating & Confidence System

Player attributes evolve dynamically based on crowd decisions.

The backend uses:

- custom Elo-like probabilistic rating updates,
- confidence-aware scaling,
- event-based vote storage,
- materialized aggregate state.

Confidence represents how strongly an attribute profile is supported by accumulated evidence — not whether it is objectively correct.

---

# Simulation Laboratory

Zcout includes a dedicated simulation environment used to test:

- matchmaking behavior,
- rating drift,
- confidence growth,
- anonymous vs authenticated voting,
- synthetic user profiles,
- large-scale duel flows.

The simulation system supports:

- truth snapshots,
- repeatable experiment runs,
- baseline imports,
- anchor runs,
- synthetic expert/casual/noisy/biased users.

This allows tuning the system without relying on real production traffic.

---

# External Integrations

Zcout integrates with external football data providers for player metadata, club information and bootstrapping workflows.

Current integrations include:

- Fantasy Premier League API
- Football-Data.org
- Sportmonks

These integrations are used for:

- player metadata synchronization,
- club and competition data,
- initial seeding/bootstrap workflows,
- normalization pipelines.

Core player ratings, rankings and attribute evolution are generated internally by the Zcout system itself.

---

# Project Structure

```text
app/
├── Actions/
├── Console/
├── Data/              # DTOs / value objects (votes, scouting)
├── Enums/
├── Events/
├── Exceptions/
├── Http/
├── Listeners/         # domain events → Redis / RabbitMQ
├── Matchmaking/
├── Models/
├── PremierLeague/     # external PL sync helpers (not core rating math)
├── Providers/
├── Requests/
├── Services/
├── Simulation/
└── Support/
```

Main architectural flow:

```text
HTTP Controllers
        ↓
Application Actions
        ↓
Matchmaking / Domain Logic
        ↓
Persistence Layer
```

---

# Running Locally

## Requirements

- Docker
- Docker Compose

## First-time setup

```bash
cp .env.example .env   # if missing; DB_* already targets Sail/pgsql
docker compose up -d --build
docker compose exec laravel.test php artisan key:generate   # if APP_KEY is empty
docker compose exec laravel.test php artisan migrate --seed
```

App URL: **http://localhost:8080** (`laravel.test` maps host port 8080).

`compose.yaml` also runs **pgsql**, **redis**, **rabbitmq** (5672; management UI 15672), **soketi** (6001), and **scheduler** (`php artisan schedule:work`).

For rankings, WebSockets, RabbitMQ topology, and projection consumers in dev, see [`docs/local-dev-and-ops.md`](docs/local-dev-and-ops.md).

## Run tests

```bash
docker compose exec laravel.test php artisan test
```

---

# API Overview

Example endpoints (see `docs/api-surface.md` for the full map):

```text
GET    /api/health
GET    /api/duels/next
POST   /api/votes
POST   /api/duels/skip          # body: duel_id (not /duels/{id}/skip)
POST   /api/votes/direct        # sanctum — direct attribute rating

GET    /api/rankings/{attributeKey}
GET    /api/players/{player}

POST   /api/scout-reports       # sanctum
GET    /api/search?q=

GET    /api/scouting/progress
GET    /api/my-scouting
POST   /api/auth/claim-anon     # sanctum
```

Live feed, database clubs, and homepage widgets: [`docs/api-surface.md`](docs/api-surface.md).

---

# Documentation

- Technical docs: [`docs/README.md`](docs/README.md)
- AI / agent entrypoint: [`AI_CONTEXT.md`](AI_CONTEXT.md)
- Workspace as-built status: [`zcout-private-docs/STATUS.md`](../zcout-private-docs/STATUS.md) (sibling repo in the Zcout workspace; optional for backend-only clones)

---

# Current MVP Scope

Implemented (high level):

- Duel flow, reveal, skip, anonymous voting + claim
- Matchmaking v1, rating + confidence, overall
- Rankings (Redis projections), player profiles, scout reports
- Simulation lab, scouting progression (My Scouting dashboard + progress APIs; **Your Impact** content still **PLANNED** — stage-2 progress only)
- Live feed endpoints + Soketi events

Schema stubs only / not runtime-scored:

- Trust Score, Integrity, anti-troll weight factors (see STATUS — **PLANNED/DEFERRED**)

Planned / deferred examples:

- Elasticsearch (current search is SQL)
- Freshness resurfacing, attribute-aware matchmaking
- Trust & integrity engines, Your Impact content
- Player comparison, historical timelines

---
