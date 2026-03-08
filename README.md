# 🌟 9ja Solana Agent Bot

> An autonomous AI-powered Solana DeFi agent hosted on Telegram — built in **pure PHP 8.1+**, zero Composer dependencies.

Submission for **Superteam Nigeria — DeFi Developer Challenge: Agentic Wallets for AI Agents**

---

## 🎥 Demo

**Twitter Thread + Bot Demo:**
> 🔗 [https://twitter.com/mark67857](https://twitter.com/mark67857) — follow for live demos and updates

![Agent Management Panel](https://raw.githubusercontent.com/Isaacava/solana-agent-bot/main/assets/Screenshot_20260307_155859_Chrome.jpg)

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
| Schedule SOL sends | Cron-based task engine |
| SOL ↔ USDC swaps (devnet) | Bot liquidity wallet — real on-chain SPL transfers |
| SOL ↔ USDC swaps (mainnet) | Jupiter Aggregator v6 |
| Conditional goals (price-triggered sends & swaps) | Cron monitors price every minute |
| Auto trading strategies (buy/hold/sell/stop-loss) | Fully autonomous multi-phase strategy engine |
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

### 🤖 Auto Trading Strategy (Fully Autonomous)
The most autonomous feature. One confirmation from you — the agent trades for days:

```
You: "Grow my SOL" / "Set up auto trading for me"

Agent: Buy at $82.50 (−2.5% from now)
       Sell at $88.28 (+7% from entry)
       Stop loss: $80.85 (−2% from entry)
       Risk/reward: 1:3.5  — shall I activate?

You: "Oya run am" / "yes" / "do it"

→ Agent watches price every minute
→ Buys when price dips to target (USDC→SOL swap)
→ Holds and watches for sell target or stop loss
→ Exits position automatically and reports P&L
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
- **"Send 0.5 SOL to [address] in 2 hours"** — executes autonomously via cron
- **"Send 1 SOL to [address] when SOL hits $120"** — price-triggered execution
- **"Grow my SOL"** — autonomous buy/sell strategy runs for days

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
├── management.php         ← 🖥️  Agent Management Panel (9 pages)
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
│   │   ├── Scheduler.php     ← ⏰  Price alerts, scheduled sends, conditional goals
│   │   ├── Strategy.php      ← 📈  Auto trading strategy lifecycle
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
| `"Send 1 SOL to [address] in 3 hours"` | Scheduled send — fires automatically |
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
| `"Grow my SOL"` | Agent suggests buy/sell/stop-loss strategy |
| `"Trade for me"` | Same as above |
| `"Oya run am"` / `"yes"` / `"activate"` | Confirms and activates the strategy |
| `/strategies` | List all your active strategies |
| `"Cancel strategy #1"` | Cancels a running strategy |

### Tasks & Goals
| Phrase | What happens |
|---------|-------------|
| `"Show my tasks"` / `"What's running"` | Shows all active goals, strategies, scheduled sends |
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
"Abeg check my balance"                    → shows SOL + USDC
"Swap my 50 USDC to SOL when price dips"   → conditional swap goal
"Wetin be the SOL price?" (Pidgin)         → live price
"Kí ni iye SOL?" (Yoruba)                  → live price
"Tell me when SOL reach $200"              → price alert
"Send 1 SOL to ABC...XYZ in 2 hours"       → scheduled send
"Oya grow my SOL for me"                   → trading strategy
```

---

## 🔄 Cron Engine

`cron.php` runs every minute and fires in this order:

1. Fetch live SOL price from CoinGecko
2. Fire price alerts that crossed their threshold
3. Execute conditional tasks (send/swap goals) whose price condition is now met
4. Advance trading strategies whose buy, sell, or stop-loss price was hit
5. Execute scheduled sends whose time has arrived
6. Proactive balance check — warn users about underfunded tasks due in the next 10 minutes

---

## 📈 Trading Strategy Flow

```
Phase 1 — waiting_buy
  Cron checks: price ≤ buy_price?
  YES → USDC→SOL swap executes → notify user → phase = holding

Phase 2 — holding
  Cron checks: price ≥ sell_price?
  YES → SOL→USDC swap executes → notify user with P&L → status = completed

  OR: price ≤ stop_loss?
  YES → SOL→USDC swap executes → notify user → status = stopped

  OR: user cancels
  → status = cancelled

  OR: USDC empty when buy triggers
  → strategy cancelled, friendly explanation sent, user asked to refill
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
| `trading_strategies` | Auto buy/sell/stop-loss strategy state |
| `chat_history` | Last 20 messages per user for AI context |
| `bot_logs` | Structured system and error logs |
| `settings` | Liquidity wallet keys, pending strategy params, warning timestamps |

All tables are **auto-created** by `setup.php` — no manual SQL required.

---

## 🖥️ Agent Management Panel

Access at `https://yourdomain.com/management.php`

### Dashboard
Stats strip, recent transactions, active goals, and live system log tail.

![Dashboard](https://raw.githubusercontent.com/Isaacava/solana-agent-bot/main/assets/Screenshot_20260307_155859_Chrome.jpg)

---

### Agents
Every user — wallet address, TX count, active goals, strategies, last activity.

![Agents](https://raw.githubusercontent.com/Isaacava/solana-agent-bot/main/assets/Screenshot_20260307_155931_Chrome.jpg)

---

### DeFi
Liquidity wallet live balances (SOL + USDC fetched from chain), recent swap transaction table, conditional swap goals.

![DeFi](https://raw.githubusercontent.com/Isaacava/solana-agent-bot/main/assets/Screenshot_20260307_155957_Chrome.jpg)

---

### Goals
All conditional tasks with filter tabs — Swap Goals / Send Goals / Watching / Executed.

![Goals](https://raw.githubusercontent.com/Isaacava/solana-agent-bot/main/assets/Screenshot_20260307_160050_Chrome.jpg)

---

### Strategies
All trading strategies — phase badge, buy/sell/stop prices, SOL amount, estimated P&L, buy TX explorer link.

![Strategies](https://raw.githubusercontent.com/Isaacava/solana-agent-bot/main/assets/Screenshot_20260307_160120_Chrome.jpg)

---

### Scheduler / Transactions / Logs / Settings
Scheduled sends, price alerts, full transaction history, live error logs, and webhook configuration.

![Scheduler](https://raw.githubusercontent.com/Isaacava/solana-agent-bot/main/assets/Screenshot_20260308_043457_Chrome.jpg)

---

| Page | What you see |
|------|----------|
| **Dashboard** | Stats strip, recent transactions, active goals, system log tail |
| **Agents** | Every user — wallet, TX count, goals, strategies, last activity |
| **Goals** | All conditional tasks — filter by Swap / Send / Watching / Executed |
| **DeFi** | Liquidity wallet live balances (SOL + USDC from chain), recent swap table, swap goals |
| **Strategies** | All trading strategies — phase badge, prices, P&L, buy TX explorer link |
| **Scheduler** | Pending scheduled sends and active price alerts |
| **Transactions** | Full paginated transaction log |
| **Logs** | Live system and error log viewer |
| **Settings** | Webhook config, bot status, cron setup command |

---

## 🆓 Free APIs Used

| API | Purpose | Key Required |
|-----|---------|-------------|
| Telegram Bot API | Bot messaging | ✅ Free from BotFather |
| Groq API | AI primary + voice transcription | ✅ Free tier |
| Google Gemini API | AI fallback | ✅ Free tier |
| Cohere API | AI fallback 2 | ✅ Free trial |
| CoinGecko v3 | SOL price (USD + NGN) | ❌ None |
| Magic Eden v2 | NFT floor prices | ❌ None |
| Cointelegraph RSS | Solana news | ❌ None |
| Bonfida SNS Proxy | .sol domain lookup | ❌ None |
| Solana RPC | All blockchain calls | ❌ None |
| Jupiter v6 | Mainnet swap routing | ❌ None |
| Helius (optional) | Enhanced RPC (100k/day) | ✅ Free tier |

---

## 🧪 Testing on Devnet

```
1. /wallet create          → generates your encrypted wallet
2. /airdrop 2              → get 2 devnet SOL
3. /balance                → verify balance
4. /faucet                 → claim 100 devnet USDC
5. "Swap 1 SOL to USDC"    → instant devnet swap
6. "Grow my SOL"           → set up auto trading strategy
7. "Show my tasks"         → see all active goals
8. /send [address] 0.1     → send SOL, check Explorer link
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

**Built for the naija streets. Runs on the blockchain.**  
No app. No extension. Just chat. 🇳🇬

</div>