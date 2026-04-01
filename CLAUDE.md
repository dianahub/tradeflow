# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
composer setup        # First-time: install deps + migrate + seed + build
composer dev          # Concurrent: artisan serve + queue:listen + pail + npm run dev
composer test         # Clear config cache, then run PHPUnit
npm run build         # Build frontend assets only

php artisan migrate:fresh --seed   # Reset DB with seed data
php artisan queue:listen           # Background worker for embedding jobs
```

Run a single test file:
```bash
php artisan test tests/Feature/PositionControllerTest.php
./vendor/bin/phpunit tests/Unit/SomeTest.php
```

## Architecture

TradeFlow is a portfolio/trading analysis platform with AI-powered insights. The backend is a Laravel 13 JSON API consumed by a separate frontend (not in this repo). Auth uses Laravel Sanctum (stateless tokens).

### Request Flow

```
API Route (routes/api.php)
  → Controller (app/Http/Controllers/Api/)
      → TradingAnalysisService  (AI with caching + RAG)
          → AnalysisCacheService  (hash-based TTL cache in ai_analyses table)
          → AnthropicService      (Claude API, retries on 429/5xx)
          → EmbeddingService      (OpenAI embeddings for RAG similarity)
      → Model (Eloquent)
      → PriceController → CoinGecko / Alpha Vantage
```

Background queue worker (`GenerateAnalysisEmbedding` job) runs after each AI analysis to generate and store OpenAI embeddings asynchronously.

### AI Services (`app/Services/AI/`)

| Service | Responsibility |
|---------|---------------|
| `AnthropicService` | HTTP client for Claude API; `complete()` for text, `completeWithVision()` for images (claude-opus-4-6); exponential backoff |
| `TradingAnalysisService` | Orchestrates portfolio/position/sell-recommendations/journal analysis; handles caching and RAG context injection |
| `AnalysisCacheService` | Stores results in `ai_analyses` table keyed by `context_hash`; TTL: portfolio/position 60 min, sell_recommendations 30 min, journal_insights 360 min |
| `EmbeddingService` | Calls OpenAI `text-embedding-3-small` (1536-dim); cosine similarity to find top-3 past analyses for RAG |

Default Claude model: `claude-haiku-4-5-20251001`. Vision calls use `claude-opus-4-6`.

### Key Controllers (`app/Http/Controllers/Api/`)

- **AuthController** — Registration/login with free trial gate (5 logins before paywall via `login_count`/`is_paid` on User)
- **PositionController** — Upserts by `(user_id, symbol, asset_type)`; dispatches AI analysis
- **TradeController** — CRUD; P&L computed as model attributes (`pnl`, `pnl_percent`)
- **AnalyticsController** — Aggregated stats (win rate, P&L by symbol, AI journal insights)
- **PriceController** — CoinGecko (crypto, unlimited free) + Alpha Vantage (stocks/ETFs, 25 req/day)
- **FreeAnalysisController** — Public endpoint (`POST /api/analyze-free`), rate-limited 3/day per IP, prompt-injection sanitized
- **PortfolioImportController** — Accepts screenshot image, extracts positions using Claude vision

### Data Model

| Table | Purpose |
|-------|---------|
| `users` | Auth + `login_count`, `is_paid` for trial gating |
| `trades` | Trade journal: symbol, direction (long/short), entry/exit price, quantity, fees, status |
| `positions` | Current holdings; options have extra fields (strike, expiration, delta, IV) |
| `ai_analyses` | AI result cache + RAG store; `embedding` column holds JSON float array |
| `jobs` | Database queue for `GenerateAnalysisEmbedding` |

### External APIs

- **Anthropic Claude** — `ANTHROPIC_API_KEY`, default model via `ANTHROPIC_MODEL`
- **OpenAI** — `OPENAI_API_KEY` (optional; embedding jobs fall back gracefully if unset)
- **Alpha Vantage** — `ALPHA_VANTAGE_KEY` (25 free requests/day; controller adds 0.2s delay between calls)
- **CoinGecko** — No key required

### Environment

Key `.env` values beyond the standard Laravel set:
```
ANTHROPIC_API_KEY=
ANTHROPIC_MODEL=claude-haiku-4-5-20251001
OPENAI_API_KEY=          # Optional, enables RAG embeddings
ALPHA_VANTAGE_KEY=
QUEUE_CONNECTION=database
```

Dev uses MariaDB (DDEV); `.env.example` defaults to SQLite.
