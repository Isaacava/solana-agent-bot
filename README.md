# 🌟 9ja Solana Agent Bot

> An autonomous AI-powered Solana DeFi agent hosted on Telegram — built in **pure PHP 8.1+**, zero Composer dependencies.

Submission for **Superteam Nigeria — DeFi Developer Challenge: Agentic Wallets for AI Agents**

---

## 🎥 Demo

**Twitter Thread + Bot Demo:**  
🔗 [https://twitter.com/mark67857](https://twitter.com/mark67857) — follow for live demos and updates

> **Screenshots:** (place these files in `screenshots/` in the repo to display)
>
> - `screenshots/dashboard.jpg`
> - `screenshots/goals.jpg`
> - `screenshots/defi.jpg`
> - `screenshots/strategies.jpg`
> - `screenshots/transactions.jpg`

[![Agent Management Panel](screenshots/dashboard.jpg)](https://ibb.co/nNynbygc)

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

### ⛓️ Core Integrations & Data Sources
This project uses live price data, on-chain RPCs, swap routing and SNS (domain) lookups. (See *Free APIs Used* section below for details.)

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

---

### 🔄 DeFi — SOL ↔ USDC Swaps
Instant swaps at the live CoinGecko price. On devnet the bot runs its own liquidity wallet with real on-chain SPL token