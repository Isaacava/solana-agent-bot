# SKILL.md — 9ja Solana Agent Bot

> This file is intended for AI agents and automated tools to understand the capabilities, structure, and interfaces of this project.

## Agent Identity

- **Name:** 9ja Solana Agent
- **Type:** Autonomous Agentic DeFi Wallet + AI Chat Assistant
- **Platform:** Telegram
- **Language:** PHP 8.1+ (pure, no Composer)
- **Network:** Solana (devnet default, mainnet-ready)
- **Voice:** Groq Whisper (English, Pidgin, Yoruba, Igbo, Hausa)

---

## Core Capabilities

### 1. Wallet Management
- **Create wallet**: Generates Ed25519 keypair using PHP sodium extension — triggered by command, text, or voice note
- **Store wallet**: AES-256-GCM encrypted private key saved to SQLite
- **Restore wallet**: Decrypts and reconstructs keypair on demand
- **Export wallet**: Returns Phantom-compatible byte array
- **Multiple wallets**: Up to 5 wallets per user, one active at a time

### 2. Transaction Execution (Autonomous)
- Builds Solana legacy transactions in pure PHP
- Signs with Ed25519 via `sodium_crypto_sign_detached`
- Broadcasts via Solana JSON-RPC `sendTransaction`
- Confirms via `getSignatureStatuses` polling
- Logs every transaction to SQLite with metadata

### 3. DeFi — SOL ↔ USDC Swaps
- **Instant swaps** at live CoinGecko price
- **Devnet**: Real on-chain SPL token transfers via bot liquidity wallet
- **Mainnet**: Jupiter Aggregator v6 for best routing
- ATA addresses always queried from chain via `getTokenAccountsByMint` — never derived in PHP
- Every swap logged to `transactions` table with `meta` JSON (from/to symbols, amounts, rate)

### 4. Conditional Goals (Price-Triggered)
- **Conditional send**: Send SOL to an address when price crosses a threshold
- **Conditional swap**: Swap SOL↔USDC when price crosses a threshold
- Cron checks price every minute against all active goals
- On trigger: checks balance first — cancels gracefully with explanation if wallet is empty

### 5. Auto Trading Strategy (Fully Autonomous)
- Agent suggests buy/sell/stop-loss parameters based on live price
- User confirms once — agent executes all phases autonomously
- **Phase waiting_buy**: watches for price ≤ buy_price → executes USDC→SOL swap
- **Phase holding**: watches for price ≥ sell_price OR price ≤ stop_loss → executes SOL→USDC swap
- Reports P&L percentage on exit
- Cancels with explanation if USDC balance is insufficient at buy trigger

### 6. Scheduled Actions
- **Price alerts**: Cron polls CoinGecko, fires Telegram message when threshold crossed
- **Scheduled sends**: Stores task in SQLite, cron executes at specified datetime
- **Proactive warnings**: Alerts user 10 minutes before a scheduled send if balance is low

### 7. Balance Guard (Fund Awareness)
Tracks committed funds across all active tasks before any new action:
- Pending scheduled sends (SOL reserved)
- Conditional send goals (SOL reserved)
- Conditional swap goals (SOL or USDC depending on direction)
- Strategies in `waiting_buy` phase (USDC reserved)
- Strategies in `holding` phase (SOL reserved)

Reports free balance vs total before executing sends or swaps. Cancels tasks gracefully with user-friendly explanation if wallet is empty at execution time. Warns proactively once per hour per task when underfunded.

### 8. Voice Notes
- All features work via Telegram voice note
- Transcribed using Groq Whisper
- Supports: English, Nigerian Pidgin, Yoruba, Igbo, Hausa

### 9. Data Fetching
| Skill | Source | Auth Required |
|-------|--------|---------------|
| SOL price (USD, NGN, 24h change) | CoinGecko API v3 | No |
| NFT floor/volume | Magic Eden API v2 | No |
| Solana news | RSS (Cointelegraph) | No |
| .sol domains | Bonfida SNS Proxy | No |
| On-chain data | Solana JSON-RPC | No |
| Swap routing (mainnet) | Jupiter Aggregator v6 | No |

### 10. Natural Language Understanding
- Uses Groq / Gemini / Cohere (free tier) with fallback chain
- Detects intents and extracts parameters from freeform text and voice
- Responds in user's language (English, Yoruba, Igbo, Hausa, Pidgin)
- Chat history (last 20 messages) passed as context on every turn
- Anti-poisoning: completed actions stored as `✓ action done` in history to prevent re-firing

---

## Entry Points

| File | Purpose |
|------|---------|
| `webhook.php` | Telegram webhook receiver (POST) |
| `cron.php` | Scheduled task runner (CLI or HTTP GET) |
| `management.php` | Agent Management Panel (web, 9 pages) |
| `setup.php` | First-time setup wizard |
| `setup-defi.php` | DeFi liquidity wallet initialisation |

---

## Intent System

The AI agent detects the following intents from natural language:

```
create_wallet         → {}
check_balance         → {}
check_token_balance   → {}
send_sol              → { to: string, amount: float }
schedule_send         → { to: string, amount: float, time: string }
price_alert           → { target: float, direction: "above"|"below" }
set_conditional       → { condition: string, price: float, action: string, to: string, amount: float }
swap_tokens           → { from: string, to: string, amount: float }
conditional_swap      → { condition: string, price: float, from: string, to: string, amount: float, amount_type: "token"|"usd" }
suggest_strategy      → { amount_sol: float }
activate_strategy     → { buy_price: float, sell_price: float, stop_loss: float, amount_sol: float }
list_strategies       → {}
cancel_strategy       → { id: int }
get_tasks             → {}
get_history           → {}
check_price           → {}
check_nft             → { collection: string }
check_domain          → { domain: string }
get_news              → {}
request_airdrop       → { amount: float }
faucet                → {}
export_wallet         → {}
general_chat          → {}
```

### Key Disambiguation Rules
| User says | Correct intent |
|---|---|
| "swap now" / "swap immediately" | `swap_tokens` |
| "swap when price hits $X" | `conditional_swap` |
| "send when price hits $X" | `set_conditional` |
| "show my tasks" / "what's running" / "my goals" | `get_tasks` |
| "my transactions" / "what did I send" | `get_history` |
| "grow my SOL" / "auto trade" | `suggest_strategy` |
| "yes" / "oya run am" / "do it" / "activate" after suggestion | `activate_strategy` |

---

## Data Storage

- **Engine:** SQLite 3 (via PHP PDO)
- **File:** `data/agent.db` — auto-created by `setup.php`
- **Tables:**

| Table | Purpose |
|---|---|
| `users` | Telegram user accounts |
| `wallets` | AES-256-GCM encrypted keypairs |
| `transactions` | All sends and swaps with `meta` JSON |
| `price_alerts` | Notify-only price triggers |
| `scheduled_tasks` | Time-based SOL sends |
| `conditional_tasks` | Price-triggered send and swap goals |
| `trading_strategies` | Auto strategy state (phase, prices, tx sigs) |
| `chat_history` | Last 20 messages per user |
| `bot_logs` | Structured system logs |
| `settings` | Liquidity wallet keys, pending params, warn timestamps |

---

## Cron Engine

```
* * * * * php /path/to/cron.php
```

Runs every minute in this order:
1. Fetch live SOL price from CoinGecko
2. Check all untriggered price alerts — fire if threshold crossed
3. Check all conditional tasks — execute if price condition met
4. Check all active trading strategies — advance phase if price condition met
5. Execute any scheduled sends whose `execute_at` has passed
6. Proactive balance check — warn users about underfunded tasks due in next 10 minutes

---

## Trading Strategy State Machine

```
Status: active | completed | stopped | cancelled
Phase:  waiting_buy → holding → completed | stopped | cancelled

waiting_buy + price ≤ buy_price   → USDC→SOL swap → holding
holding     + price ≥ sell_price  → SOL→USDC swap → completed
holding     + price ≤ stop_loss   → SOL→USDC swap → stopped
any         + USDC/SOL empty      → cancelled + notify user
any         + user cancels        → cancelled
```

---

## Security Model

- Private keys never leave the server in plaintext
- AES-256-GCM encryption with 32-char user-configured secret
- Agent Management Panel protected by bcrypt password + session auth
- Webhook validated by `X-Telegram-Bot-Api-Secret-Token` header
- `.htaccess` blocks direct web access to `data/`, `config/`, `src/`, `*.db`, `*.log`
- Security headers set on every response (X-Frame-Options, X-Content-Type-Options, X-XSS-Protection)
- No external dependency on key management services

---

## Dependencies (Zero External)

All functionality implemented using PHP built-in extensions only:
- `sodium` — Ed25519 key generation and signing
- `openssl` — AES-256-GCM encryption
- `curl` — HTTP requests (RPC, APIs, Telegram)
- `pdo_sqlite` — database
- `gmp` — Base58 encoding/decoding
- No Composer, no npm, no external packages

---

## Solana RPC Methods Used

```
getBalance
getBalanceSol
getLatestBlockhash
sendTransaction
getSignatureStatuses
getSignaturesForAddress
getTokenAccountsByMint
getTokenAccountsByOwner
getAccountInfo
requestAirdrop
getVersion
getSlot
getHealth
```

---

## Supported Languages

| Code | Language |
|------|----------|
| `english` | English |
| `yoruba` | Yoruba |
| `igbo` | Igbo |
| `hausa` | Hausa |
| `pidgin` | Nigerian Pidgin English |

---

## Extending the Agent

To add a new feature:
1. Add a new class in `src/Features/`
2. Register the intent in `src/AI/AIManager.php` system prompt
3. Handle the intent in `src/Bot/Handler.php` → `executeAction()`
4. Add the slash command in `Handler::handleCommand()` (optional — natural language works without it)
5. If it involves funds, wire in `BalanceGuard::getCommitted()` before execution
6. If it needs cron execution, hook into `Scheduler::run()`
