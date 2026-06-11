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
- Anti-troll foundations
- Simulation laboratory for large-scale testing

---

# Tech Stack

- Laravel 12
- PostgreSQL
- Docker / Docker Compose
- Soketi (Pusher-compatible WebSockets)
- REST API
- PHPUnit
- Nginx

Architecture highlights:

- Event-log inspired vote storage
- Materialized state for fast reads
- Domain-oriented matchmaking layer
- Simulation-based tuning workflow
- Event-driven ranking projections (RabbitMQ + Redis)

### Event-Driven Projections

Zcout uses asynchronous projection pipelines for ranking generation.

Player rating updates emit domain events which are published to RabbitMQ.

Dedicated projection consumers process these events and maintain Redis sorted sets used for fast ranking lookups.

Flow:

```text
Vote / Scout Report
        ↓
Domain Event
        ↓
RabbitMQ
        ↓
Projection Consumers
        ↓
Redis Sorted Sets
        ↓
Fast Rank Queries
```

Current projections:

- Overall player rankings
- Attribute-specific rankings (Pace, Dribbling, Passing, etc.)

Redis projections can be rebuilt at any time from PostgreSQL source-of-truth data using dedicated rebuild commands.

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
├── Enums/
├── Events/
├── Exceptions/
├── Http/
├── Matchmaking/
├── Models/
├── Providers/
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

## Start containers

```bash
docker compose up -d --build
```

## Run migrations

```bash
docker compose exec laravel.test php artisan migrate --seed
```

## Run tests

```bash
docker compose exec laravel.test php artisan test
```

---

# API Overview

Example endpoints:

```text
GET    /api/duels/next
POST   /api/votes
POST   /api/duels/{id}/skip

GET    /api/rankings
GET    /api/players/{slug}

POST   /api/scout-reports
```

---

# Current MVP Scope

Implemented / in progress:

- Duel flow
- Crowd reveal system
- Matchmaking v1
- Rankings
- Player profiles
- Scout reports
- Simulation lab
- Confidence system
- Anti-troll foundations

Planned expansions:

- Player comparison
- Advanced freshness resurfacing
- Richer calibration buckets
- Trust & integrity expansion
- Advanced live widgets
- Historical rating timelines

---
