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
