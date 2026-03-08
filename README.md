# 🌟 Solana Agent Bot

> An autonomous AI-powered Solana wallet agent hosted on Telegram — built in **pure PHP 7.4+**, zero Composer dependencies.

Submission for **Superteam Nigeria — DeFi Developer Challenge: Agentic Wallets for AI Agents**

---

## ✨ Features

### 🤖 Agentic Wallet (Autonomous)
- Generate Solana keypairs (Ed25519 via PHP sodium)
- Sign transactions without human input
- Broadcast transactions to devnet or mainnet
- AES-256-GCM encrypted private key storage

### 🧠 Multi-AI Engine (All Free Tiers)
| Provider | Model | Speed |
|----------|-------|-------|
| **Groq** | llama-3.3-70b-versatile | ⚡ Fastest |
| **Google Gemini** | gemini-1.5-flash | ✅ Reliable |
| **Cohere** | command-r | ✅ Good fallback |

AI providers auto-fallback if one fails.

### 🇳🇬 Nigerian Language Support
Chat naturally in **English, Yoruba, Igbo, Hausa, or Pidgin** — the bot responds in your language!

### ⛓️ Solana Features
| Feature | How |
|---------|-----|
| Real-time SOL price (USD & NGN) | CoinGecko free API |
| Send SOL instantly or scheduled | Solana RPC + pure PHP tx builder |
| Check wallet balance | Solana JSON-RPC |
| NFT collection floor prices | Magic Eden free API |
| Latest Solana ecosystem news | RSS feeds |
| .sol domain name lookup | Bonfida SNS Proxy |
| Devnet SOL airdrop | Solana devnet RPC |

### ⏰ Automation (Agentic)
- **"Alert me when SOL goes above $200"** — fires Telegram notification automatically
- **"Send 0.5 SOL to [address] in 2 hours"** — executes autonomously via cron

---

## 📋 Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 7.4 or higher |
| `sodium` extension | Built-in PHP 7.2+ |
| `pdo_sqlite` | Standard PHP |
| `openssl` | Standard PHP |
| `curl` | Standard PHP |
| `simplexml` | Standard PHP |
| `gmp` | Usually bundled |
| HTTPS web server | Required for Telegram webhook |

---

## 🚀 Installation

### Step 1 — Upload Files
Upload the entire `solana-agent/` folder to your web hosting.

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
- AI API keys (Groq + Gemini recommended)
- Admin username & password
- Encryption key (32+ chars, **never change after wallets created**)

### Step 4 — Set Telegram Webhook
In Admin Panel → Settings → click **Set Webhook**

Or via curl:
```bash
curl -X POST "https://api.telegram.org/bot{TOKEN}/setWebhook" \
  -H "Content-Type: application/json" \
  -d '{"url":"https://yourdomain.com/webhook.php"}'
```

### Step 5 — Configure Cron
```bash
# Open crontab
crontab -e

# Add this line (runs every minute)
* * * * * php /full/path/to/solana-agent/cron.php >> /full/path/to/data/logs/cron.log 2>&1
```

**No shell access?** Use [cron-job.org](https://cron-job.org) (free) to call:
```
GET https://yourdomain.com/cron.php?secret=YOUR_CRON_SECRET
```

### Step 6 — Test the Bot
Open Telegram, find your bot, and send `/start` 🎉

---

## 📁 Project Structure

```
solana-agent/
│
├── index.php              ← 🖥️  Admin dashboard (web page)
├── webhook.php            ← 📡  Telegram webhook handler
├── setup.php              ← ⚙️  First-time setup wizard
├── cron.php               ← ⏰  Scheduler runner (cron/HTTP)
│
├── config/
│   └── config.php         ← 🔧  All configuration (edited by setup)
│
├── src/
│   ├── autoload.php       ← 📦  PSR-4 autoloader (no Composer)
│   │
│   ├── Solana/
│   │   ├── Base58.php     ← 🔤  Base58 encode/decode (pure PHP + GMP)
│   │   ├── Keypair.php    ← 🔑  Ed25519 key generation (sodium)
│   │   ├── RPC.php        ← 🌐  Solana JSON-RPC 2.0 client
│   │   ├── Transaction.php← 📝  Tx builder & signer (pure PHP)
│   │   └── WalletManager.php ← 💼  High-level wallet operations
│   │
│   ├── AI/
│   │   ├── AIManager.php  ← 🧠  Multi-provider router + intent parser
│   │   ├── Groq.php       ← ⚡  Groq (llama-3.3-70b)
│   │   ├── Gemini.php     ← 🔵  Google Gemini 1.5 Flash
│   │   └── Cohere.php     ← 🟡  Cohere command-r
│   │
│   ├── Bot/
│   │   ├── Telegram.php   ← 📱  Telegram Bot API wrapper
│   │   └── Handler.php    ← 🎮  All commands + intent execution
│   │
│   ├── Features/
│   │   ├── Price.php      ← 📈  CoinGecko price data
│   │   ├── NFT.php        ← 🖼️  Magic Eden NFT stats
│   │   ├── News.php       ← 📰  RSS news aggregator
│   │   ├── Domain.php     ← 🌐  SNS .sol domain lookup
│   │   └── Scheduler.php  ← ⏰  Price alerts & scheduled sends
│   │
│   ├── Storage/
│   │   └── Database.php   ← 🗄️  SQLite wrapper (all persistence)
│   │
│   └── Utils/
│       ├── Crypto.php     ← 🔒  AES-256-GCM encryption
│       └── Logger.php     ← 📋  File + DB logging
│
├── assets/
│   ├── css/style.css      ← 🎨  Admin dark cyber UI
│   └── js/admin.js        ← ⚡  Admin interactions
│
├── data/                  ← 📂  Auto-created at setup
│   ├── agent.db           ← SQLite database
│   └── logs/              ← Daily log files
│
├── README.md              ← 📖  This file
└── SKILLS.md              ← 🤖  Machine-readable capabilities
```

---

## 💬 Bot Commands

### Wallet
| Command | Description |
|---------|-------------|
| `/wallet create` | Create a new Solana wallet |
| `/wallet list` | List all your wallets |
| `/wallet info` | View active wallet address |
| `/balance` | Check SOL balance (with USD/NGN value) |
| `/export` | Export keypair (Phantom-compatible) |

### Transactions
| Command | Description |
|---------|-------------|
| `/send [address] [sol]` | Send SOL instantly |
| `/airdrop [sol]` | Request devnet SOL (testing) |
| `/history` | View recent transactions |

### Market & Data
| Command | Description |
|---------|-------------|
| `/price` | Live SOL price in USD & NGN |
| `/nft [collection]` | NFT floor price & stats |
| `/news` | Latest Solana ecosystem news |
| `/domain [name.sol]` | Look up .sol domain |

### Automation
| Command | Description |
|---------|-------------|
| `/alert above\|below [price]` | Set price alert |
| `/schedule send [addr] [sol] at [time]` | Schedule a send |
| `/tasks` | View active alerts & tasks |

### Natural Language Examples
```
"What's the price of SOL in naira?"
"Create a wallet for me"
"Send 0.1 SOL to ABC...XYZ"
"Alert me when SOL drops below $150"
"Send 1 SOL to [address] in 3 hours"
"What's the floor price of Okay Bears?"
"Check if bonfida.sol is available"
"Wetin be the SOL price?" (Pidgin)
"Kí ni iye SOL?" (Yoruba)
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
  [AES-256-GCM encrypt with derived key]
      │
      ▼
  [Store in SQLite — encrypted blob]
      │
      ▼ (at tx time)
  [Decrypt in memory only]
      │
      ▼
  [Sign tx bytes — key never leaves server]
      │
      ▼
  [Broadcast signed tx to Solana RPC]
```

**Key management principles:**
- Private keys encrypted at rest with AES-256-GCM
- Encryption key derived from user-configured secret (never stored in DB)
- Keys only decrypted in memory when signing a transaction
- No plaintext key ever stored on disk or transmitted

---

## 🆓 Free APIs Used

| API | Purpose | Key Required |
|-----|---------|-------------|
| Telegram Bot API | Bot messaging | ✅ (free from BotFather) |
| Groq API | AI (primary) | ✅ (free tier) |
| Google Gemini API | AI (fallback) | ✅ (free tier) |
| Cohere API | AI (fallback 2) | ✅ (free trial) |
| CoinGecko v3 | SOL price | ❌ None |
| Magic Eden v2 | NFT data | ❌ None |
| Cointelegraph RSS | Solana news | ❌ None |
| Bonfida SNS Proxy | .sol domains | ❌ None |
| Solana RPC | Blockchain | ❌ None |
| Helius (optional) | Enhanced RPC | ✅ (100k/day free) |

---

## 🧪 Testing on Devnet

1. Create a wallet: `/wallet create`
2. Request airdrop: `/airdrop 2`
3. Check balance: `/balance`
4. Send to another address: `/send [address] 0.5`
5. View history: `/history`

---

## 🛠️ Admin Panel

Access at `https://yourdomain.com/index.php`

| Page | Features |
|------|----------|
| Dashboard | Stats, recent txs, live logs, SOL price ticker |
| Users | All Telegram users, language, wallet count |
| Transactions | Full transaction log with status |
| Wallets | All wallets (public keys only, never private) |
| Scheduler | Active price alerts and scheduled sends |
| Logs | System logs (info/warn/error) |
| Settings | Webhook config, bot status, cron setup |

---

## 📜 License

Open source — MIT License. Built for the Superteam Nigeria bounty.

---

## 👨‍💻 Technical Notes

### Why Pure PHP?
- Zero dependency deployment — just upload and run
- Works on any shared hosting (cPanel, Plesk, etc.)
- No Composer, no NPM, no build step

### Transaction Signing
Implements the full Solana legacy transaction wire format in PHP:
- Compact-u16 encoding
- Account ordering (signers first)
- SystemProgram::Transfer instruction (type 2)
- Ed25519 signing via `sodium_crypto_sign_detached`

### AI Intent Detection
The system prompt instructs the AI to wrap actions in `<ACTION>...</ACTION>` XML tags with JSON payload. This is parsed server-side and routed to the correct handler, keeping the AI response separate from the action execution.
