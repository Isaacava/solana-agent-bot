# SKILLS.md — Solana Agent Bot

> This file is intended for AI agents and automated tools to understand the capabilities, structure, and interfaces of this project.

## Agent Identity

- **Name:** SolanaBot
- **Type:** Autonomous Agentic Wallet + AI Chat Assistant
- **Platform:** Telegram
- **Language:** PHP 7.4+ (pure, no Composer)
- **Network:** Solana (devnet default, mainnet-ready)

---

## Core Capabilities

### 1. Wallet Management
- **Create wallet**: Generates Ed25519 keypair using PHP sodium extension
- **Store wallet**: AES-256-GCM encrypted private key saved to SQLite
- **Restore wallet**: Decrypts and reconstructs keypair on demand
- **Export wallet**: Returns Phantom-compatible byte array

### 2. Transaction Execution (Autonomous)
- Builds Solana legacy transactions in pure PHP
- Signs with Ed25519 via `sodium_crypto_sign_detached`
- Broadcasts via Solana JSON-RPC `sendTransaction`
- Confirms via `getSignatureStatuses` polling

### 3. Scheduled Actions
- **Price alerts**: Cron polls CoinGecko, fires Telegram message when threshold crossed
- **Scheduled sends**: Stores task in SQLite, cron executes at specified datetime

### 4. Data Fetching
| Skill | Source | Auth Required |
|-------|--------|---------------|
| SOL price (USD, NGN) | CoinGecko API v3 | No |
| NFT floor/volume | Magic Eden API v2 | No |
| Solana news | RSS (Cointelegraph, CoinDesk) | No |
| .sol domains | Bonfida SNS Proxy | No |
| On-chain data | Solana JSON-RPC | No |

### 5. Natural Language Understanding
- Uses Groq / Gemini / Cohere (free tier) with fallback chain
- Detects intents and extracts parameters from freeform text
- Responds in user's language (English, Yoruba, Igbo, Hausa, Pidgin)

---

## Entry Points

| File | Purpose |
|------|---------|
| `webhook.php` | Telegram webhook receiver (POST) |
| `cron.php` | Scheduled task runner (CLI or HTTP GET) |
| `index.php` | Admin dashboard (web) |
| `setup.php` | First-time setup wizard |

---

## Intent System

The AI agent detects the following intents from natural language:

```
create_wallet     → {}
check_balance     → {}
send_sol          → { to: string, amount: float }
schedule_send     → { to: string, amount: float, time: string }
price_alert       → { target: float, direction: "above"|"below" }
check_price       → {}
check_nft         → { collection: string }
check_domain      → { domain: string }
get_news          → {}
request_airdrop   → { amount: float }
get_history       → {}
export_wallet     → {}
general_chat      → {}
```

---

## Data Storage

- **Engine:** SQLite 3 (via PHP PDO)
- **File:** `data/agent.db`
- **Tables:** `users`, `wallets`, `transactions`, `price_alerts`, `scheduled_tasks`, `chat_history`, `bot_logs`, `settings`

---

## Security Model

- Private keys never leave the server in plaintext
- AES-256-GCM encryption with derived key (SHA-256 of user-supplied secret)
- Admin panel protected by bcrypt password
- Webhook validated by `X-Telegram-Bot-Api-Secret-Token` header
- No external dependency on key management services

---

## Dependencies (Zero External)

All functionality is implemented using:
- PHP built-in extensions: `sodium`, `openssl`, `curl`, `pdo_sqlite`, `simplexml`, `gmp`
- No Composer, no npm, no external packages

---

## Solana RPC Methods Used

```
getBalance
getLatestBlockhash
sendTransaction
getSignatureStatuses
getSignaturesForAddress
getTokenAccountsByOwner
getAccountInfo
requestAirdrop
getVersion
getSlot
getHealth
```

---

## Supported Languages (Nigerian)

| Code | Language |
|------|----------|
| `english` | English |
| `yoruba` | Yoruba |
| `igbo` | Igbo |
| `hausa` | Hausa |
| `pidgin` | Nigerian Pidgin English |

---

## Cron Schedule

```
* * * * * php /path/to/cron.php
```

Runs every minute to:
1. Check all untriggered price alerts against live SOL price
2. Execute any scheduled tasks whose `execute_at` has passed

---

## Extending the Agent

To add a new feature:
1. Add a new class in `src/Features/`
2. Register the intent in `src/AI/AIManager.php` system prompt
3. Handle the intent in `src/Bot/Handler.php` → `executeAction()`
4. Add the slash command in `Handler::handleCommand()`
