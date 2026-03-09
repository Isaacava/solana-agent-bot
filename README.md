# 🌟 9ja Solana Agent Bot

> An autonomous AI-powered Solana DeFi agent hosted on Telegram — built in **pure PHP 8.1+**, zero Composer dependencies.

Submission for **Superteam Nigeria — DeFi Developer Challenge: Agentic Wallets for AI Agents**

--- 

## 🎥 Demo

**Twitter Thread + Bot Demo:**
> 🔗 [https://x.com/mark67857/status/2030526725000470570?s=20](https://twitter.com/mark67857) — follow for live demos and updates

**Agent website + Bot Demo:**
> 🔗 [https://solanaagent.earnton.online](https://solanaagent.earnton.online) — Open link for website demos and Telegram bot demo


---

## ✨ Features

### 🤖 Agentic Wallet (Fully Autonomous)
- Generate Solana keypairs (Ed25519 via PHP sodium)
- Sign and broadcast transactions without human input
- AES-256-GCM encrypted private key storage
- Balance-aware — knows what funds are committed vs free before any action
- Cancels tasks gracefully with friendly explanations when wallet is empty

### 🧠 Multi-AI Engine (All Free Tiers)
| Provider | Model | Speed |
|----------|-------|-------|
| **Groq** | llama-3.3-70b-versatile | ⚡ Fastest |
| **Google Gemini** | gemini-2.0-flash-lite | ✅ Reliable |
| **Cohere** | command-r | ✅ Good fallback |

AI providers auto-fallback if one fails. Voice notes transcribed via **Groq Whisper**.

### 🇳🇬 Nigerian Language Support
Chat naturally in **English, Yoruba, Igbo, Hausa, or Pidgin** — typed or voice note — the agent understands and responds in your language.

### ⛓️ Solana Features
| Feature | How |
|---------|-----|
| Real-time SOL price (USD & NGN) | CoinGecko free API |
| Send SOL instantly | Solana RPC + pure PHP tx builder |
| Schedule SOL sends | Cron-based task engine with natural language times |
| SOL ↔ USDC swaps (devnet) | Bot liquidity wallet — real on-chain SPL transfers |
| SOL ↔ USDC swaps (mainnet) | Jupiter Aggregator v6 |
| Conditional goals (price-triggered sends & swaps) | Cron monitors price every minute |
| Auto trading strategies (buy/hold/sell/stop-loss) | 5-type market-aware autonomous strategy engine |
| Dollar-cost averaging (DCA) | Recurring USDC→SOL buys on a schedule |
| Portfolio snapshot & P&L | SOL + USDC value tracked in USD and NGN |
| Trailing stop-loss | Dynamic stop that moves up with price automatically |
| Price cascade sells | Sell chunks of SOL at multiple price targets |
| Check wallet balance | Solana JSON-RPC |
| USDC token balance | SPL `getTokenAccountsByMint` |
| NFT collection floor prices | Magic Eden free API |
| Latest Solana ecosystem news | RSS feeds |
| .sol domain name lookup & register | Bonfida SNS Proxy |
| Devnet SOL airdrop | Solana devnet RPC |
| Devnet USDC faucet | USDC-Dev mint |

### 🔄 DeFi — SOL ↔ USDC Swaps
Instant swaps at the live CoinGecko price. On devnet the bot runs its own liquidity wallet with real on-chain SPL token transfers — you can verify every transaction on Solana Explorer. On mainnet it routes through Jupiter for best price.

### 🎯 Conditional Goals (Price-Triggered Execution)
Set a condition and forget. The agent watches price every minute and executes automatically:

```
"Send 1 SOL to [address] when SOL hits $120"
"Swap 100 USDC to SOL when SOL drops below $75"
"Sell 0.5 SOL when price reaches $150"
"Buy $20 worth of SOL if price dips to $70"
```

If the condition fires but your wallet is empty, the agent cancels the goal and explains clearly — no silent failures, no error codes.

### 🤖 Auto Trading Strategies — 5 Types, Market-Aware
The agent detects the current market condition and recommends the best strategy type automatically. Each type has its own entry, target, and stop-loss tuned to that market:

| Type | Best When | Entry | Target | Stop | R:R |
|------|-----------|-------|--------|------|-----|
| 🛡️ Conservative | Sideways (−1% to +2%) | −2.5% dip | +7% | −2% | 1:3.5 |
| 🔥 Aggressive | Dipping (−3% to −7%) | −4% dip | +15% | −3% | 1:5 |
| ⚡ Scalp | Mild pump (+2% to +7%) | −0.8% dip | +2.5% | −0.8% | 1:3.1 |
| 🚀 Momentum | Strong pump (>+7%) | −0.5% dip | +10% | −2.5% | 1:4 |
| 💎 Deep Value | Crash (<−7%) | −2% dip | +20% | −5% | 1:4 |

```
You: "Grow my SOL" / "Set up auto trading for me"

Agent: 🔥 Aggressive Strategy — market is dipping (-5.2%)
       Buy at $141.30 (−4% from now)
       Sell at $162.50 (+15% from entry)
       Stop loss: $137.06 (−3% from entry)
       Risk/reward: 1:5  — shall I activate?

You: "Oya run am" / "yes" / "show me another one"

→ Agent cycles through all 5 types if you want options
→ Buys when price dips to target (USDC→SOL swap)
→ Holds and watches for sell target or stop loss
→ Exits position automatically and reports P&L in USD & NGN
```

### 🔁 DCA — Dollar-Cost Averaging
Tell the agent to buy SOL automatically on a recurring schedule using USDC:

```
"DCA $10 into SOL daily"
"Buy SOL every week with $50 USDC"
"Invest $20 every 3 days into SOL"
"Buy SOL every 6 hours with $5"
```

The agent creates a DCA task, runs it on schedule via cron, reports every execution with amount spent in USD and NGN, and tracks your total runs.

### 📊 Portfolio Tracking
```
You: "Show my portfolio" / "What's my total value?"

Agent: 📊 Portfolio Snapshot

       SOL:       2.450 SOL  → $362.10 / ₦586,602
       USDC:    100.00 USDC  → $100.00 / ₦162,000
       ─────────────────────────────────
       Total:                  $462.10 / ₦748,602

       📈 vs last snapshot: +$23.40 / +₦37,908 (+5.3%)
```

On devnet shows SOL + USDC-dev. On mainnet shows SOL, USDC, BONK, JTO, WIF, RAY.

### 📈 Trailing Stop
Set a trailing stop-loss that automatically moves up as price rises — locks in gains without you watching:

```
"Set trailing stop 3% on strategy #2"
→ Stop starts at $145.00
→ Price rises to $160 → stop moves up to $155.20
→ Price rises to $175 → stop moves up to $169.75
→ Price drops to $169.75 → agent sells automatically ✅
```

### 💧 Price Cascade
Schedule automatic partial sells at multiple price targets — take profits in stages:

```
"Sell 0.2 SOL at $160, 0.2 SOL at $175, 0.3 SOL at $200"
→ Agent watches price and executes each sell as the target is hit
→ Reports each execution with NGN received
```

### 🧠 Market Intelligence
| Command / Phrase | What you get |
|---------|-------------|
| `"Fear and greed index"` | Alternative.me index with progress bar and market advice |
| `"Whale activity"` | Recent large SOL transactions (mainnet, via Helius) |
| `"Network status"` | Solana TPS, slot height, validator count from RPC |
| `"Staking APY"` | Current Solana staking yield from Solana Compass |

### 👁️ Wallet Monitor & Alerts
| Command / Phrase | What happens |
|---------|-------------|
| `"Watch my wallet"` | Alerts you when your SOL balance drops unexpectedly |
| `"Stop watching wallet"` | Disables the wallet watcher |
| `"Send me price every hour"` | Recurring price report at your chosen interval |
| `"Cancel price reports"` | Stops the recurring reports |

Every night the agent also sends a **daily P&L digest** — a summary of all strategies completed in the last 24 hours with profit/loss in USD and NGN.

### ⏰ Natural Language Time Scheduling
Schedule sends using plain English — no timestamps required:

```
"Send 0.5 SOL to [address] tomorrow morning"     → 7:00 AM next day
"Send 1 SOL to [address] tonight"                → 9:00 PM today
"Send 0.2 SOL to [address] next Monday evening"  → Monday 6:00 PM
"Send 0.1 SOL to [address] Friday at 3pm"        → Friday 3:00 PM
"Send 0.3 SOL to [address] in 2 hours"           → 2 hours from now
```

Time slots: **morning** = 07:00, **afternoon** = 14:00, **evening** = 18:00, **night** = 21:00. All times respect the `Africa/Lagos` timezone (WAT, UTC+1).

### 🇳🇬 NGN on Everything
Every price, balance, P&L, and trade notification shows both USD and Naira side by side. The rate is fetched live from CoinGecko (5-minute cache) with ExchangeRate-API as fallback:

```
💰 SOL now: $147.50 / ₦238,950
💸 Bought at: $142.00 / ₦230,040
📈 P&L: +3.9% (+$5.50 / +₦8,910)
```

### 🛡️ Balance Guard (Fund Awareness)
Before any send or swap, the agent calculates your **free balance** — not just your total, but what's actually uncommitted:

| Committed to | Tracked |
|---|---|
| Pending scheduled sends | SOL reserved |
| Conditional send goals | SOL reserved |
| Conditional swap goals (SOL→USDC) | SOL reserved |
| Conditional swap goals (USDC→SOL) | USDC reserved |
| Strategy waiting to buy | USDC reserved |
| Strategy holding SOL | SOL reserved |
| Active DCA tasks | USDC reserved per cycle |

If you're short, the agent tells you exactly what your money is doing:

```
⚠️ Your SOL is busy!
💰 Total: 2.5 SOL
🔒 Committed: 1.5 SOL
  • Strategy #1: holding 1 SOL (selling at $92)
  • Goal: send 0.5 SOL → DtMMAwsc when SOL above $88
✅ Free: 0.99 SOL — you need 2 SOL
```

It also warns you proactively — 10 minutes before a scheduled send is due, if your balance is low, it messages you to top up in time.

### ⏰ Automation (Agentic)
- **"Alert me when SOL goes above $200"** — fires Telegram notification automatically
- **"Send 0.5 SOL to [address] tomorrow morning"** — executes autonomously at 7 AM
- **"Send 1 SOL to [address] when SOL hits $120"** — price-triggered execution
- **"Grow my SOL"** — autonomous buy/sell strategy runs for days
- **"DCA $10 daily into SOL"** — recurring buys on schedule
- **"Watch my wallet"** — proactive balance drop alerts

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.1 or higher |
| `sodium` extension | Built-in PHP 7.2+ |
| `pdo_sqlite` | Standard PHP |
| `openssl` | Standard PHP |
| `curl` | Standard PHP |
| `gmp` | Usually bundled |
| HTTPS web server | Required for Telegram webhook |

---

## 🚀 Installation

### Step 1 — Upload Files
Upload the entire project folder to your web hosting.

### Step 2 — Set Directory Permissions
```bash
chmod 755 data/
chmod -R 755 data/logs/
```

### Step 3 — Run Setup Wizard
Navigate to:
```
https://yourdomain.com/setup.php
```
Fill in:
- Telegram Bot Token (from [@BotFather](https://t.me/BotFather))
- AI API keys (Groq + Gemini recommended — both free)
- Admin username & password
- Encryption key (32 chars — **never change after wallets are created**)

This creates `agent.db` with all tables automatically.

### Step 4 — Set Up DeFi Liquidity Wallet (Devnet)
```
https://yourdomain.com/setup-defi.php
```
Or via CLI:
```bash
php setup-defi.php
```
This creates and funds the bot's liquidity wallet that powers SOL ↔ USDC swaps on devnet.

### Step 5 — Set Telegram Webhook
In Agent Management Panel → Settings → click **Set Webhook**

Or via curl:
```bash
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://yourdomain.com/webhook.php"}'
```

### Step 6 — Configure Cron
```bash
crontab -e

# Add this line (runs every minute)
* * * * * php /full/path/to/cron.php >> /full/path/to/data/logs/cron.log 2>&1
```

**No shell access?** Use [cron-job.org](https://cron-job.org) (free) to call:
```
GET https://yourdomain.com/cron.php?secret=YOUR_CRON_SECRET
```

### Step 7 — Test the Bot
Open Telegram, find your bot, send `/start` 🎉

---

## 📁 Project Structure

```
solana-agent/
│
├── webhook.php            ← 📡  Telegram webhook handler
├── admin.php              ← 🖥️  Agent Management Panel (11 pages)
├── setup.php              ← ⚙️  First-time setup wizard
├── setup-defi.php         ← 💧  DeFi liquidity wallet setup
├── cron.php               ← ⏰  Scheduler runner (runs every minute)
├── register-webhook.php   ← 🔗  Webhook registration helper
│
├── config/
│   ├── config.php         ← 🔧  Your configuration (fill in secrets)
│   └── config.example.php ← 📋  Template with all fields documented
│
├── src/
│   ├── autoload.php       ← 📦  PSR-4 autoloader (no Composer)
│   │
│   ├── Solana/
│   │   ├── Base58.php        ← 🔤  Base58 encode/decode (pure PHP + GMP)
│   │   ├── Keypair.php       ← 🔑  Ed25519 key generation (sodium)
│   │   ├── RPC.php           ← 🌐  Solana JSON-RPC 2.0 client
│   │   ├── PDA.php           ← 📐  Program Derived Address utilities
│   │   ├── Transaction.php   ← 📝  Tx builder & signer (pure PHP)
│   │   └── WalletManager.php ← 💼  High-level wallet operations
│   │
│   ├── AI/
│   │   ├── AIManager.php  ← 🧠  System prompt, intent detection, chat history
│   │   ├── Groq.php       ← ⚡  Groq LLaMA + Whisper voice transcription
│   │   ├── Gemini.php     ← 🔵  Google Gemini 2.0 Flash
│   │   ├── Cohere.php     ← 🟡  Cohere command-r
│   │   └── VoiceHandler.php ← 🎙️  Voice note → text (Groq Whisper)
│   │
│   ├── Bot/
│   │   ├── Telegram.php   ← 📱  Telegram Bot API wrapper
│   │   └── Handler.php    ← 🎮  All commands + intent routing + execution
│   │
│   ├── Features/
│   │   ├── Scheduler.php     ← ⏰  Price alerts, scheduled sends, conditional goals, natural language time
│   │   ├── Strategy.php      ← 📈  5-type market-aware strategy + trailing stop + price cascade
│   │   ├── Portfolio.php     ← 📊  Portfolio snapshot, P&L tracking (USD & NGN)
│   │   ├── DCA.php           ← 🔁  Dollar-cost averaging recurring buy tasks
│   │   ├── MarketIntel.php   ← 🧠  Fear & Greed, whale alerts, network status, staking APY
│   │   ├── WalletMonitor.php ← 👁️  Balance watcher, recurring price reports, daily P&L digest
│   │   ├── NGN.php           ← 🇳🇬  Central NGN rate + formatting (USD & NGN on everything)
│   │   ├── BalanceGuard.php  ← 🛡️  Committed-balance tracking + proactive warnings
│   │   ├── Swap.php          ← 🔄  SOL↔USDC swap execution (devnet + Jupiter)
│   │   ├── SPLToken.php      ← 🪙  USDC balance + SPL token transfers
│   │   ├── Price.php         ← 💰  CoinGecko live price (USD + NGN)
│   │   ├── NFT.php           ← 🖼️  Magic Eden NFT floor prices
│   │   ├── News.php          ← 📰  RSS Solana news aggregator
│   │   └── Domain.php        ← 🌐  Bonfida SNS .sol domain lookup
│   │
│   ├── Storage/
│   │   └── Database.php   ← 🗄️  SQLite wrapper + all migrations
│   │
│   └── Utils/
│       ├── Crypto.php     ← 🔒  AES-256-GCM encryption
│       └── Logger.php     ← 📋  Structured logging to SQLite
│
├── assets/
│   ├── css/               ← 🎨  Admin dark UI styles
│   └── js/                ← ⚡  Admin interactions
│
├── data/                  ← 📂  Auto-created at setup (DO NOT COMMIT)
│   ├── agent.db           ← SQLite database (auto-created by setup.php)
│   └── logs/              ← Daily log files
│
├── .gitignore             ← 🚫  Excludes config.php, data/, agent.db
├── README.md              ← 📖  This file
└── SKILL.md               ← 🤖  Agent capabilities reference
```

---

## 💬 Commands & Natural Language

### Wallet
| Command / Phrase | What happens |
|---------|-------------|
| `/wallet create` or say *"Create me a wallet"* or send a voice note | Create a new encrypted Solana wallet |
| `/wallet list` | List all your wallets |
| `/balance` | SOL + USDC balance with USD/NGN value |
| `/export` | Export keypair (Phantom-compatible) |

### Sending
| Command / Phrase | What happens |
|---------|-------------|
| `/send [address] [sol]` | Send SOL instantly |
| `"Send 1 SOL to [address] tomorrow morning"` | Scheduled send — fires at 7:00 AM next day |
| `"Send 0.5 SOL to [address] tonight"` | Scheduled send — fires at 9:00 PM |
| `"Send 0.5 SOL to [address] when SOL hits $120"` | Conditional send — triggers on price |

### DeFi Swaps
| Phrase | What happens |
|---------|-------------|
| `"Swap 1 SOL to USDC"` | Instant swap at live price |
| `"Swap 100 USDC to SOL now"` | Instant swap |
| `"Swap 50 USDC to SOL when SOL drops below $75"` | Conditional swap — waits for price |
| `"Sell 0.5 SOL when price hits $150"` | Conditional swap — triggers on price |

### Auto Trading
| Phrase | What happens |
|---------|-------------|
| `"Grow my SOL"` | Agent detects market condition and suggests best strategy type |
| `"Show me another strategy"` | Cycles through all 5 strategy types |
| `"Oya run am"` / `"yes"` / `"activate"` | Confirms and activates the strategy |
| `"Set trailing stop 3%"` | Attaches a trailing stop to an active strategy |
| `"Sell 0.2 SOL at $160, $180, $200"` | Sets a price cascade (staged sells) |
| `/strategies` | List all your active strategies |
| `"Cancel strategy #1"` | Cancels a running strategy |

### DCA
| Phrase | What happens |
|---------|-------------|
| `"DCA $10 into SOL daily"` | Creates daily recurring buy task |
| `"Buy SOL every week with $50"` | Weekly DCA task |
| `"Invest $20 every 3 days into SOL"` | 72-hour interval DCA |
| `"Show my DCA tasks"` | Lists all active DCA orders |
| `"Cancel DCA #2"` | Stops a DCA task |

### Portfolio & Market Intel
| Phrase | What happens |
|---------|-------------|
| `"Show my portfolio"` | Full snapshot in USD + NGN with P&L vs last check |
| `"Fear and greed index"` | Current crypto market sentiment |
| `"Whale activity"` | Recent large SOL movements (mainnet only) |
| `"Network status"` | Solana TPS, validators, slot height |
| `"Staking APY"` | Current Solana staking yield |

### Monitoring
| Phrase | What happens |
|---------|-------------|
| `"Watch my wallet"` | Alerts you if SOL balance drops unexpectedly |
| `"Stop watching"` | Disables wallet watcher |
| `"Send me price every hour"` | Recurring price + NGN report |
| `"Cancel price reports"` | Stops recurring reports |

### Tasks & Goals
| Phrase | What happens |
|---------|-------------|
| `"Show my tasks"` / `"What's running"` | Shows all active goals, strategies, DCA tasks, scheduled sends |
| `"My transaction history"` | Recent completed sends |
| `/tasks` | Same as "show my tasks" |

### Market & Data
| Command / Phrase | What happens |
|---------|-------------|
| `/price` | Live SOL price in USD & NGN |
| `"Alert me when SOL hits $200"` | Fires Telegram notification at threshold |
| `/nft [collection]` | NFT floor price & stats |
| `/news` | Latest Solana news |
| `/domain [name.sol]` | Look up .sol domain |
| `/airdrop [sol]` | Request devnet SOL |
| `/faucet` | Claim devnet USDC |

### Natural Language Examples
```
"Abeg check my balance"                          → shows SOL + USDC in USD & NGN
"Swap my 50 USDC to SOL when price dips"         → conditional swap goal
"Wetin be the SOL price?" (Pidgin)               → live price in USD & NGN
"Kí ni iye SOL?" (Yoruba)                        → live price
"Tell me when SOL reach $200"                    → price alert
"Send 1 SOL to ABC...XYZ tomorrow morning"       → scheduled send at 7 AM
"Oya grow my SOL for me"                         → market-aware strategy suggestion
"DCA 10 dollar SOL daily"                        → daily DCA task
"Show my portfolio value in naira"               → full portfolio snapshot
"Set trailing stop 3% on my strategy"            → trailing stop-loss
```

---

## 🔄 Cron Engine

`cron.php` runs every minute and fires in this order:

1. Fetch live SOL price from CoinGecko (USD + NGN + 24h change)
2. Fire price alerts that crossed their threshold
3. Execute conditional tasks (send/swap goals) whose price condition is now met
4. Advance trading strategies whose buy, sell, or stop-loss price was hit
5. Update trailing stops — move stop-loss up if price has risen
6. Execute price cascade targets — sell SOL at each level as it's hit
7. Check wallet monitors — alert users if SOL balance dropped
8. Execute scheduled sends whose time has arrived
9. Run DCA tasks that are due
10. Send recurring price reports to users who requested them
11. Send daily P&L digest (runs near midnight — strategies completed in last 24h)
12. Proactive balance check — warn users about underfunded tasks due in the next 10 minutes

---

## 📈 Trading Strategy Flow

```
Phase 1 — waiting_buy
  Cron checks: price ≤ buy_price?
  YES → USDC→SOL swap executes → notify user (USD + NGN) → phase = holding

Phase 2 — holding
  Cron checks: price ≥ sell_price?
  YES → SOL→USDC swap executes → notify user with P&L in USD & NGN → status = completed

  OR: price ≤ stop_loss? (or trailing stop triggers?)
  YES → SOL→USDC swap executes → notify user → status = stopped

  OR: user cancels
  → status = cancelled

  OR: USDC empty when buy triggers
  → strategy cancelled, friendly explanation sent, user asked to refill

Strategy types rotate: Conservative → Aggressive → Scalp → Momentum → Deep Value
Market auto-detection picks the recommended type based on 24h price change.
```

---

## 🔐 Security Design

```
User Private Key
      │
      ▼
  [PHP sodium Ed25519 generation]
      │
      ▼
  [AES-256-GCM encrypt with 32-char key]
      │
      ▼
  [Store in SQLite — encrypted blob only]
      │
      ▼  (at transaction time only)
  [Decrypt in memory — never written to disk]
      │
      ▼
  [Sign tx bytes via sodium_crypto_sign_detached]
      │
      ▼
  [Broadcast signed tx to Solana RPC]
```

**Key management principles:**
- Private keys encrypted at rest with AES-256-GCM
- Encryption key never stored in the database — only in config
- Keys only decrypted in memory when signing a transaction
- No plaintext key ever stored on disk or logged
- Webhook requests validated via `X-Telegram-Bot-Api-Secret-Token`
- Agent Management Panel protected by session auth with bcrypt password

---

## 🔄 DeFi Architecture (Devnet)

The bot operates a **liquidity wallet** holding both SOL and USDC-Dev:

- **SOL → USDC:** User sends SOL to liquidity wallet → wallet sends USDC-Dev to user's ATA
- **USDC → SOL:** User sends USDC-Dev to liquidity wallet → wallet sends SOL to user
- **DCA:** Recurring USDC→SOL buys routed through the same liquidity wallet on devnet

Price is live from CoinGecko on every swap. Both legs are real on-chain SPL token transactions verifiable on Solana Explorer.

> ⚠️ ATA addresses are **always queried from chain** via `getTokenAccountsByMint` — never derived in PHP. PHP GMP-based off-curve checks produce unreliable results for Solana PDA derivation.

**USDC-Dev Mint:** `Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr`

---

## 🗄️ Database Tables

| Table | Purpose |
|---|---|
| `users` | Telegram user accounts |
| `wallets` | AES-256-GCM encrypted keypairs |
| `transactions` | All executed sends and swaps with metadata |
| `price_alerts` | Notify-only price threshold triggers |
| `scheduled_tasks` | Time-based SOL sends pending execution |
| `conditional_tasks` | Price-triggered send and swap goals |
| `trading_strategies` | Auto buy/sell/stop-loss strategy state (includes `strategy_type`, `trailing_pct`) |
| `dca_tasks` | Recurring DCA buy orders with interval, next_run, runs_count |
| `chat_history` | Last 20 messages per user for AI context |
| `bot_logs` | Structured system and error logs |
| `settings` | Liquidity wallet keys, pending strategy params, DCA state, wallet watchers, price reports, trailing stops, price cascades |

All tables are **auto-created** by `setup.php` — no manual SQL required.

---

## 🖥️ Agent Management Panel

Access at `https://yourdomain.com/admin.php`

| Page | What you see |
|------|----------|
| **Dashboard** | Stats strip (agents, wallets, txs, goals, swaps, strategies, DCA tasks, monitors, SOL price), recent transactions, active goals, system log tail |
| **Agents** | Every user — wallet, TX count, goals, strategies, last activity |
| **Goals** | All conditional tasks — filter by Swap / Send / Watching / Executed |
| **DeFi** | Liquidity wallet live balances (SOL + USDC from chain), recent swap table, swap goals |
| **Strategies** | All trading strategies — strategy type badge, phase badge, prices, P&L, buy TX explorer link |
| **DCA Tasks** | All DCA orders — agent, amount, interval, next run time, total runs, status |
| **Monitor** | Wallet watchers, price report schedules, trailing stops, price cascades — all live |
| **Scheduler** | Pending scheduled sends, active DCA tasks, and active price alerts |
| **Transactions** | Full paginated transaction log |
| **Logs** | Live system and error log viewer |
| **Settings** | Webhook config, bot status (network, AI, timezone, extension checks), cron setup command |

---

## 🆓 Free APIs Used

| API | Purpose | Key Required |
|-----|---------|-------------|
| Telegram Bot API | Bot messaging | ✅ Free from BotFather |
| Groq API | AI primary + voice transcription | ✅ Free tier |
| Google Gemini API | AI fallback | ✅ Free tier |
| Cohere API | AI fallback 2 | ✅ Free trial |
| CoinGecko v3 | SOL price (USD + NGN) + live NGN rate | ❌ None |
| ExchangeRate-API | USD/NGN fallback rate | ❌ None |
| Alternative.me | Fear & Greed index | ❌ None |
| Solana Compass | Staking APY data | ❌ None |
| Magic Eden v2 | NFT floor prices | ❌ None |
| Cointelegraph RSS | Solana news | ❌ None |
| Bonfida SNS Proxy | .sol domain lookup | ❌ None |
| Solana RPC | All blockchain calls | ❌ None |
| Jupiter v6 | Mainnet swap routing | ❌ None |
| Helius (optional) | Enhanced RPC + whale alerts (100k/day) | ✅ Free tier |

---

## 🧪 Testing on Devnet

```
1. /wallet create                               → generates your encrypted wallet
2. /airdrop 2                                   → get 2 devnet SOL
3. /balance                                     → verify balance in USD & NGN
4. /faucet                                      → claim 100 devnet USDC
5. "Swap 1 SOL to USDC"                         → instant devnet swap
6. "Grow my SOL"                                → market-aware strategy suggestion
7. "Show me another strategy"                   → cycle through all 5 types
8. "DCA $10 into SOL daily"                     → set up recurring buy
9. "Show my portfolio"                          → snapshot in USD & NGN
10. "Watch my wallet"                           → enable balance monitor
11. "Send me price every hour"                  → recurring price reports
12. "Send 0.5 SOL to [addr] tomorrow morning"   → scheduled send (7:00 AM)
13. "Set trailing stop 3%"                      → trailing stop on active strategy
14. "Sell 0.1 SOL at $160, $180, $200"          → price cascade
15. "Show my tasks"                             → see all goals, DCA, strategies
16. /send [address] 0.1                         → send SOL, check Explorer link
```

---

## 🛠️ Technical Notes

### Why Pure PHP?
- Zero dependency deployment — upload and run, no Composer, no NPM, no build step
- Works on any shared hosting (cPanel, Plesk, etc.)
- Entire transaction signing stack implemented from scratch in PHP

### Transaction Signing
Implements the full Solana legacy transaction wire format in PHP:
- Compact-u16 encoding for account indices
- Correct account ordering (writable signers → readonly signers → writable → readonly)
- SystemProgram::Transfer and SPL Token::Transfer instructions
- Ed25519 signing via `sodium_crypto_sign_detached`

### AI Intent System
The system prompt instructs the AI to wrap actions in `<ACTION>...</ACTION>` XML tags with a JSON payload. This is parsed server-side and routed to the correct handler — keeping AI conversation completely separate from transaction execution.

After every action executes, the bot saves a `✓`-prefixed note to chat history (e.g. `✓ swap done`) instead of the AI's trigger phrase. This prevents the AI from re-reading its own trigger and re-firing the action on the user's next casual reply.

### Natural Language Time Parser
`Scheduler::parseTime()` converts plain phrases into scheduled timestamps — no ISO formatting required from the AI. All times are resolved in `Africa/Lagos` timezone (WAT). The AI passes the phrase as-is (e.g. `"tomorrow morning"`) and the parser converts it server-side:
- Day anchors: today, tomorrow, next [weekday], [weekday]
- Time slots: morning (07:00), afternoon (14:00), evening (18:00), night (21:00), midnight (00:00), noon (12:00)
- Explicit times: `at 3pm`, `at 09:30`
- Relative: `in 2 hours`, `in 3 days`

### Strategy Market Detection
`Strategy::recommendType(float $change24h)` reads the 24h SOL price change from CoinGecko and maps it to the optimal strategy type:
- `< −7%` → Deep Value 💎
- `−7% to −3%` → Aggressive 🔥
- `−3% to +2%` → Conservative 🛡️
- `+2% to +7%` → Scalp ⚡
- `> +7%` → Momentum 🚀

---

## 🔒 .htaccess

The project ships with a `.htaccess` file that handles all server-level security automatically:

- **Blocks direct web access** to `data/`, `config/`, and `src/` directories — no one can download your database or config by guessing a URL
- **Blocks sensitive file types** — `.db`, `.log`, `.lock`, and `.md` files return 403 Forbidden
- **Security headers** — sets `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, and `Referrer-Policy` on every response
- **Hides PHP errors** from browser output in production

> ⚠️ **Important:** Delete or restrict access to `setup.php` and `setup-defi.php` after your first setup run — they have no authentication.

---

## 📜 License

Open source — MIT License. Built for the Superteam Nigeria DeFi Developer Challenge.

---

<div align="center">

**Built for the Naija. Runs on the blockchain.**  
No app. No extension. Just chat. 🇳🇬

</div>