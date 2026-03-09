<?php
namespace SolanaAgent\Bot;

use SolanaAgent\AI\AIManager;
use SolanaAgent\AI\VoiceHandler;
use SolanaAgent\Solana\{WalletManager, Base58};
use SolanaAgent\Features\{Price, NFT, News, Domain, Scheduler, Swap, SPLToken, Strategy, BalanceGuard};
use SolanaAgent\Storage\Database;
use SolanaAgent\Utils\{Logger, Crypto};

/**
 * Handler — routes all Telegram updates to the correct feature
 */
class Handler
{
    private Telegram      $telegram;
    private AIManager     $ai;
    private VoiceHandler  $voice;
    private WalletManager $walletManager;
    private Database      $db;
    private Scheduler     $scheduler;
    private array         $config;

    public function __construct(
        Telegram      $telegram,
        AIManager     $ai,
        WalletManager $walletManager,
        Database      $db,
        Scheduler     $scheduler,
        array         $config
    ) {
        $this->telegram      = $telegram;
        $this->ai            = $ai;
        $this->walletManager = $walletManager;
        $this->db            = $db;
        $this->scheduler     = $scheduler;
        $this->config        = $config;
        $this->voice         = new VoiceHandler(
            $config['telegram']['bot_token'] ?? '',
            $config['ai']['groq']['api_key'] ?? ''
        );
    }

    // ─── Entry point ──────────────────────────────────────────────────────────

    public function handle(array $update): void
    {
        try {
            if ($update['type'] === 'callback') {
                $this->handleCallback($update);
            } elseif ($update['type'] === 'voice') {
                $this->handleVoice($update);
            } else {
                $this->handleMessage($update);
            }
        } catch (\Throwable $e) {
            Logger::error('Handler error: ' . $e->getMessage());
            try {
                $this->telegram->sendMessage($update['chat_id'], "❌ Something went wrong. Please try again.");
            } catch (\Throwable $ignored) {}
        }
    }

    // ─── Voice ────────────────────────────────────────────────────────────────

    private function handleVoice(array $update): void
    {
        $chatId   = $update['chat_id'];
        $fileId   = $update['voice_file_id'] ?? '';
        $duration = (int)($update['voice_duration'] ?? 0);
        $userId   = $this->ensureUser($update);

        if (!$fileId) {
            $this->telegram->sendMessage($chatId, "❌ Could not read voice message.");
            return;
        }

        $groqKey = $this->config['ai']['groq']['api_key'] ?? '';
        if (empty($groqKey)) {
            $this->telegram->sendMessage($chatId,
                "❌ <b>Voice notes need a Groq API key</b>\n\n"
                . "Get a free one at https://console.groq.com\n"
                . "Add it to config.php under <code>ai → groq → api_key</code>\n\n"
                . "For now please type your message! ✍️");
            return;
        }

        if ($duration > 120) {
            $this->telegram->sendMessage($chatId, "⚠️ Voice note too long (max 2 minutes).");
            return;
        }

        $this->telegram->sendRecordingAction($chatId);
        $statusMsg = $this->telegram->sendMessage($chatId, "🎙️ <i>Transcribing…</i>");

        try {
            $transcribed = $this->voice->transcribe($fileId, 'english');

            $heardMsg = "🎙️ <b>I heard:</b> <i>\"" . htmlspecialchars($transcribed) . "\"</i>\n\n⏳ Processing…";
            if (isset($statusMsg['result']['message_id'])) {
                $this->telegram->editMessage($chatId, $statusMsg['result']['message_id'], $heardMsg);
            }

            $this->telegram->sendTyping($chatId);
            $this->db->addChatMessage($userId, 'user', "[Voice] $transcribed");
            $history  = $this->db->getChatHistory($userId, 6);
            $aiResult = $this->ai->chat($transcribed, $history);
            $action   = $aiResult['action'] ?? null;
            $reply    = '';

            if ($action && !empty($action['intent']) && $action['intent'] !== 'general_chat') {
                $actionReply = $this->executeAction($chatId, $userId, $action, $update);
                if ($actionReply !== '') $reply = $actionReply;
            }
            if ($reply === '') $reply = $aiResult['text'] ?? '';
            if (empty($reply)) $reply = "I heard you! But couldn't generate a response. Please try again.";

            $this->telegram->sendMessage($chatId,
                "🎙️ <b>I heard:</b> <i>\"" . htmlspecialchars($transcribed) . "\"</i>\n\n" . $reply);
            $this->db->addChatMessage($userId, 'assistant', $reply);

        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (stripos($msg, 'rate limit') !== false) {
                $userMsg = "⚠️ <b>Voice rate limit reached</b> — please type your message! ✍️";
            } elseif (stripos($msg, 'api key') !== false || stripos($msg, '401') !== false) {
                $userMsg = "❌ <b>Groq API key issue</b> — check https://console.groq.com";
            } else {
                $userMsg = "❌ <b>Voice failed</b>\n\n" . htmlspecialchars($msg);
            }
            $this->telegram->sendMessage($chatId, $userMsg);
        }
    }

    // ─── Text ─────────────────────────────────────────────────────────────────

    private function handleMessage(array $update): void
    {
        $chatId = $update['chat_id'];
        $text   = $update['text'];
        $userId = $this->ensureUser($update);

        $this->telegram->sendTyping($chatId);

        if (isset($text[0]) && $text[0] === '/') {
            $this->handleCommand($chatId, $userId, $text, $update);
            return;
        }

        // Strip any <ACTION> blocks from history so old intents never re-fire
        $rawHistory = $this->db->getChatHistory($userId, 8);
        $history    = array_map(function(array $msg): array {
            $msg['content'] = preg_replace('/<ACTION>.*?<\/ACTION>/s', '', $msg['content'] ?? '');
            $msg['content'] = trim($msg['content']);
            return $msg;
        }, $rawHistory);

        $aiResult = $this->ai->chat($text, $history);
        $this->db->addChatMessage($userId, 'user', $text);

        $actionText   = '';
        $actionIntent = $aiResult['action']['intent'] ?? null;

        if ($aiResult['action']) {
            $actionText = $this->executeAction($chatId, $userId, $aiResult['action'], $update, $aiResult);
        }

        // Sentinel returns — action already sent its own messages
        if ($actionText === '__swap_handled__') {
            $this->db->addChatMessage($userId, 'assistant', '✓ swap done');
            return;
        }
        if ($actionText === '__tasks_handled__') {
            $this->db->addChatMessage($userId, 'assistant', '✓ tasks shown');
            return;
        }

        $responseText = '';
        if ($actionText !== '' && $aiResult['text']) {
            $responseText = $aiResult['text'] . "\n\n" . $actionText;
        } elseif ($actionText !== '') {
            $responseText = $actionText;
        } else {
            $responseText = $aiResult['text'] ?? '';
        }

        // When an action was executed, save a ✓-prefixed note.
        // The system prompt teaches the AI: "✓ messages = completed, never repeat on casual reply."
        if ($actionIntent && $actionIntent !== 'general_chat') {
            $doneNote = match($actionIntent) {
                'swap_tokens'       => '✓ swap done',
                'send_sol'          => '✓ SOL sent',
                'schedule_send'     => '✓ send scheduled',
                'conditional_swap'  => '✓ swap goal set',
                'set_conditional'   => '✓ conditional goal set',
                'price_alert'       => '✓ price alert set',
                'faucet'            => '✓ faucet requested',
                'request_airdrop'   => '✓ airdrop requested',
                'check_balance'     => '✓ balance checked',
                'check_price'       => '✓ price checked',
                'get_history'       => '✓ history shown',
                'get_tasks'         => '✓ tasks shown',
                'get_news'          => '✓ news shown',
                'check_nft'         => '✓ NFT checked',
                'check_domain'      => '✓ domain checked',
                'suggest_strategy'  => '✓ strategy suggested — waiting for user confirmation',
                'next_strategy'     => '✓ next strategy shown',
                'activate_strategy' => '✓ strategy activated',
                'cancel_strategy'   => '✓ strategy cancelled',
                default             => '✓ action done',
            };
            $this->db->addChatMessage($userId, 'assistant', $doneNote);
        } else {
            // Pure chat — save full conversational response
            $this->db->addChatMessage($userId, 'assistant', $aiResult['text'] ?? $responseText);
        }

        if ($responseText) $this->telegram->sendMessage($chatId, $responseText);
    }

    // ─── Commands ─────────────────────────────────────────────────────────────

    private function handleCommand(int $chatId, int $userId, string $text, array $update): void
    {
        $parts   = explode(' ', $text, 2);
        $command = strtolower(explode('@', $parts[0])[0]);
        $args    = trim($parts[1] ?? '');

        switch ($command) {
            case '/start':    $this->cmdStart($chatId, $update['first_name']); break;
            case '/help':     $this->cmdHelp($chatId); break;
            case '/wallet':   $this->cmdWallet($chatId, $userId, $args); break;
            case '/switch':   $this->sendWalletList($chatId, $userId, true); break;
            case '/balance':  $this->cmdBalance($chatId, $userId); break;
            case '/send':     $this->cmdSend($chatId, $userId, $args); break;
            case '/swap':     $this->cmdSwap($chatId, $userId, $args); break;
            case '/token':    $this->cmdToken($chatId, $userId, $args); break;
            case '/faucet':   $this->cmdFaucet($chatId, $userId); break;
            case '/price':    $this->cmdPrice($chatId); break;
            case '/nft':      $this->cmdNFT($chatId, $args); break;
            case '/news':     $this->cmdNews($chatId); break;
            case '/domain':   $this->cmdDomain($chatId, $userId, $args); break;
            case '/schedule': $this->cmdSchedule($chatId, $userId, $args, $update); break;
            case '/alert':    $this->cmdAlert($chatId, $userId, $args, $update); break;
            case '/goal':     $this->cmdGoal($chatId, $userId, $args, $update); break;
            case '/airdrop':  $this->cmdAirdrop($chatId, $userId, $args); break;
            case '/history':  $this->cmdHistory($chatId, $userId); break;
            case '/export':   $this->cmdExport($chatId, $userId); break;
            case '/tasks':      $this->cmdTasks($chatId, $userId); break;
            case '/defi':       $this->cmdDeFi($chatId); break;
            case '/strategies': $this->telegram->sendMessage($chatId, $this->agentListStrategies($userId)); break;
            default:
                $this->telegram->sendMessage($chatId, "❓ Unknown command. Type /help for all commands.");
        }
    }

    // ─── AI Action Executor ───────────────────────────────────────────────────

    private function executeAction(int $chatId, int $userId, array $action, array $update, array $aiResult = []): string
    {
        $intent = $action['intent'] ?? 'general_chat';
        $params = $action['params'] ?? [];

        switch ($intent) {
            case 'create_wallet':
                return $this->doCreateWallet($userId);

            case 'check_balance':
                return $this->doBalance($userId);

            case 'check_token_balance':
                $this->doTokenBalance($chatId, $userId); return '';

            case 'send_sol':
                return $this->agentDecideSend($chatId, $userId, $params);

            case 'schedule_send':
                return $this->agentDecideSchedule($chatId, $userId, $update, $params);

            case 'set_conditional':
                return $this->agentSetConditional($chatId, $userId, $update, $params);

            case 'swap_tokens':
                $from   = strtoupper($params['from'] ?? 'SOL');
                $to     = strtoupper($params['to']   ?? 'USDC');
                $amount = (float)($params['amount']  ?? 0);
                if ($amount <= 0) return "How much do you want to swap? E.g. swap 1 SOL to USDC 🔄";
                $this->cmdSwap($chatId, $userId, "{$amount} {$from} {$to}", $aiResult['text'] ?? '');
                return '__swap_handled__';

            case 'conditional_swap':
                return $this->agentSetConditionalSwap($chatId, $userId, $update, $params);

            case 'suggest_strategy':
                return $this->agentSuggestStrategy($chatId, $userId, $params);

            case 'next_strategy':
                return $this->agentNextStrategy($chatId, $userId);

            case 'activate_strategy':
                return $this->agentActivateStrategy($chatId, $userId, $update, $params);

            case 'list_strategies':
                return $this->agentListStrategies($userId);

            case 'cancel_strategy':
                return $this->agentCancelStrategy($userId, (int)($params['id'] ?? 0));

            case 'faucet':
                $this->cmdFaucet($chatId, $userId); return '';

            case 'price_alert':
                return $this->doPriceAlert($userId, $update['telegram_id'], $params);

            case 'check_price':
                return $this->doPrice();

            case 'check_nft':
                $q = $params['collection'] ?? '';
                if (!$q) return "Which NFT collection? E.g. \"check degods NFT\" 🖼️";
                return NFT::lookup($q);

            case 'check_domain':
                $d = $params['domain'] ?? '';
                if (!$d) return "Which .sol domain? 🌐";
                return Domain::formatLookupMessage($d, Domain::resolve($d));

            case 'buy_domain':
                $d = $params['domain'] ?? '';
                if (!$d) return "Which domain do you want to buy?";
                if ($this->config['solana']['network'] !== 'mainnet')
                    return "🔴 Domain buying is mainnet only. You're on devnet.";
                $slug = Domain::normalize($d);
                $owner = Domain::resolve($slug);
                if ($owner) return Domain::formatLookupMessage($slug, $owner);
                return "To register <code>{$slug}.sol</code>, use: <code>/domain buy {$slug}.sol</code>";

            case 'get_news':
                return News::formatNewsMessage(News::getLatestNews(5));

            case 'request_airdrop':
                return $this->agentDecideAirdrop($userId);

            case 'get_tasks':
                $this->cmdTasks($chatId, $userId);
                return '__tasks_handled__';

            case 'get_history':
                return $this->doHistory($userId);

            case 'export_wallet':
                return $this->doExportWallet($userId);

            default:
                return '';
        }
    }

    // ─── Agent Decision Layer ─────────────────────────────────────────────────

    private function agentDecideSend(int $chatId, int $userId, array $params): string
    {
        $to     = trim($params['to']     ?? '');
        $amount = (float)($params['amount'] ?? 0);

        if (!$to || $amount <= 0)
            return "I need a recipient address and an amount.\nExample: \"Send 0.5 SOL to ABC...XYZ\"";

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet)
            return "🤖 No wallet found. Let me create one:\n\n" . $this->doCreateWallet($userId);

        if (!Base58::isValidAddress($to))
            return "🤖 <code>" . htmlspecialchars($to) . "</code> — not a valid Solana address. Please check and try again.";

        try {
            $balance  = $this->walletManager->getBalance($wallet['public_key']);
            $required = $amount + 0.000005;
            $solBal   = (float)$balance['sol'];

            if ($solBal < $required) {
                $network = $this->config['solana']['network'];
                $msg  = "🤖 Checked your balance — not enough SOL.\n\n";
                $msg .= "💰 Balance: <b>{$solBal} SOL</b>\n";
                $msg .= "💸 Required: <b>" . round($required, 6) . " SOL</b>\n";
                $msg .= "⚠️ Shortfall: <b>" . round($required - $solBal, 6) . " SOL</b>\n\n";
                $msg .= $network === 'devnet'
                    ? "Say \"airdrop me some SOL\" and I'll top you up."
                    : "Top up your wallet and I'll send immediately.";
                return $msg;
            }

            // Enough total balance — but check if any is committed to active tasks
            $guard     = new BalanceGuard($this->db, $this->walletManager, $this->telegram, $this->config);
            $committed = $guard->getCommitted($userId);
            if ($committed['sol'] > 0) {
                $freeSol = $solBal - $committed['sol'] - 0.001;
                if ($freeSol < $amount) {
                    $msg = "⚠️ <b>Your SOL is busy!</b>\n\n"
                        . "💰 Total balance: <b>" . number_format($solBal, 4) . " SOL</b>\n"
                        . "🔒 Committed to tasks: <b>" . number_format($committed['sol'], 4) . " SOL</b>\n"
                        . "✅ Free: <b>" . number_format(max(0, $freeSol), 4) . " SOL</b>\n"
                        . "💸 You want to send: <b>{$amount} SOL</b>\n\n"
                        . "🔒 <b>Currently at work:</b>\n";
                    foreach ($committed['items'] as $item) {
                        if ($item['sol'] > 0) $msg .= "  • " . $item['desc'] . "\n";
                    }
                    $msg .= "\nFund your wallet or cancel a task first. Say <i>show my tasks</i> to manage them.";
                    return $msg;
                }
            }
        } catch (\Throwable $e) {
            Logger::warn('Balance check failed: ' . $e->getMessage());
        }

        $this->telegram->sendTyping($chatId);
        $this->telegram->sendMessage($chatId, "🤖 Checks passed. Sending <b>{$amount} SOL</b> now…");
        return $this->doSend($userId, $to, $amount);
    }

    private function agentDecideSchedule(int $chatId, int $userId, array $update, array $params): string
    {
        $to      = trim($params['to']     ?? '');
        $amount  = (float)($params['amount'] ?? 0);
        $timeStr = trim($params['time']   ?? '');

        if (!$to || $amount <= 0 || !$timeStr)
            return "I need a recipient address, amount, and time.\nExample: \"Schedule 0.5 SOL to [address] in 2 hours\"";

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet)
            return "🤖 No wallet found.\n\n" . $this->doCreateWallet($userId);

        if (!Base58::isValidAddress($to))
            return "🤖 <code>" . htmlspecialchars($to) . "</code> — not a valid address. Please check.";

        try { $executeAt = Scheduler::parseTime($timeStr); }
        catch (\InvalidArgumentException $e) {
            return "🤖 Couldn't understand the time \"<i>{$timeStr}</i>\".\nTry: \"in 5 minutes\", \"in 2 hours\", \"in 3 days\"";
        }

        $taskId = $this->scheduler->scheduleTask($userId, (string)$update['telegram_id'], 'send_sol',
            ['to' => $to, 'amount' => $amount], $executeAt);

        $short = substr($to, 0, 8) . '...' . substr($to, -6);
        return "🤖 Wallet verified, address valid, time confirmed.\n\n"
            . "⏰ <b>Scheduled</b>\n"
            . "💸 <b>{$amount} SOL</b> → <code>{$short}</code>\n"
            . "🕐 At: <b>{$executeAt}</b>\n"
            . "🆔 Task #{$taskId}\n\n"
            . "I'll execute automatically and notify you when done.";
    }

    private function agentSetConditional(int $chatId, int $userId, array $update, array $params): string
    {
        $condition = $params['condition'] ?? '';
        $price     = (float)($params['price']  ?? 0);
        $to        = trim($params['to']   ?? '');
        $amount    = (float)($params['amount'] ?? 0);

        if (!in_array($condition, ['price_above', 'price_below'], true) || $price <= 0)
            return "I need a price condition — e.g. \"when SOL goes above \$90\".";

        if (!$to || $amount <= 0)
            return "I need a recipient address and amount.\nExample: \"When SOL hits \$90, send 0.5 SOL to ABC...XYZ\"";

        if (!Base58::isValidAddress($to))
            return "🤖 <code>" . htmlspecialchars($to) . "</code> — not a valid address.";

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet)
            return "🤖 No wallet found.\n\n" . $this->doCreateWallet($userId);

        $dir    = $condition === 'price_above' ? 'above' : 'below';
        $taskId = $this->scheduler->addConditionalTask(
            $userId, (string)$update['telegram_id'],
            $condition, $price, 'send_sol',
            ['to' => $to, 'amount' => $amount],
            "Send {$amount} SOL when SOL {$dir} \${$price}"
        );

        $short = substr($to, 0, 8) . '...' . substr($to, -6);
        $emoji = $condition === 'price_above' ? '📈' : '📉';
        return "🤖 Goal set. Wallet verified, address valid.\n\n"
            . "🎯 <b>Conditional Task #{$taskId}</b>\n"
            . "─────────────────\n"
            . "{$emoji} Trigger: SOL {$dir} <b>\${$price}</b>\n"
            . "⚡ Send: <b>{$amount} SOL</b> → <code>{$short}</code>\n"
            . "─────────────────\n\n"
            . "Monitoring price every minute. I'll execute automatically when triggered.\n"
            . "Use /tasks to view or cancel.";
    }

    private function agentSetConditionalSwap(int $chatId, int $userId, array $update, array $params): string
    {
        $condition  = $params['condition']   ?? '';
        $price      = (float)($params['price']     ?? 0);
        $fromSym    = strtoupper($params['from']   ?? 'SOL');
        $toSym      = strtoupper($params['to']     ?? 'USDC');
        $amount     = (float)($params['amount']    ?? 0);
        $amountType = strtolower($params['amount_type'] ?? 'token'); // 'token' or 'usd'

        if (!in_array($condition, ['price_above', 'price_below'], true) || $price <= 0)
            return "I need a price condition — e.g. \"when SOL reaches \$90\".";

        if ($amount <= 0)
            return "I need an amount. E.g. \"swap 1 SOL to USDC when price hits \$200\"";

        if (!in_array($fromSym, ['SOL', 'USDC']) || !in_array($toSym, ['SOL', 'USDC']))
            return "For conditional swaps I support SOL ↔ USDC on devnet.";

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet)
            return "🤖 No wallet found.\n\n" . $this->doCreateWallet($userId);

        $actionType = ($fromSym === 'SOL') ? 'swap_sol_usdc' : 'swap_usdc_sol';
        $dir   = $condition === 'price_above' ? 'above' : 'below';
        $emoji = $condition === 'price_above' ? '📈' : '📉';

        $taskId = $this->scheduler->addConditionalTask(
            $userId, (string)$update['telegram_id'],
            $condition, $price, $actionType,
            ['from' => $fromSym, 'to' => $toSym, 'amount' => $amount, 'amount_type' => $amountType],
            "Swap {$amount} {$fromSym}→{$toSym} when SOL {$dir} \${$price}"
        );

        return "🤖 DeFi goal set! Wallet verified.\n\n"
            . "🎯 <b>Conditional Swap #{$taskId}</b>\n"
            . "─────────────────\n"
            . "{$emoji} Trigger: SOL {$dir} <b>\${$price}</b>\n"
            . "🔄 Swap: <b>{$amount} {$fromSym}</b> → <b>{$toSym}</b>\n"
            . "─────────────────\n\n"
            . "I'm monitoring price every minute. When SOL hits \${$price}, I'll execute the swap automatically.\n"
            . "Use /tasks to view or cancel.";
    }

    private function agentDecideAirdrop(int $userId): string
    {
        if ($this->config['solana']['network'] !== 'devnet')
            return "🤖 Airdrops only work on devnet. You're on mainnet.";

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet)
            return "🤖 No wallet found.\n\n" . $this->doCreateWallet($userId);

        return $this->doAirdrop($userId, 1.0);
    }

    // ─── Command Implementations ──────────────────────────────────────────────

    private function cmdStart(int $chatId, string $name): void
    {
        $network  = $this->config['solana']['network'];
        $netLabel = $network === 'mainnet' ? '🟢 Mainnet' : '🟡 Devnet (testnet)';

        $msg  = "🌟 <b>Welcome to Solana Agent, {$name}!</b>\n\n";
        $msg .= "Your autonomous AI-powered Solana DeFi agent. Here's what I can do:\n\n";
        $msg .= "💼 <b>Wallet</b> — Create & manage Solana wallets\n";
        $msg .= "💰 <b>Balance</b> — Check SOL & USDC balances\n";
        $msg .= "📤 <b>Send</b> — Transfer SOL instantly\n";
        $msg .= "🔄 <b>Swap</b> — SOL ↔ USDC (real on-chain)\n";
        $msg .= "🎯 <b>Goals</b> — Conditional DeFi automation:\n";
        $msg .= "   • <i>\"Buy \$10 SOL when price drops to \$80\"</i>\n";
        $msg .= "   • <i>\"Swap SOL to USDC when price hits \$200\"</i>\n";
        $msg .= "⏰ <b>Schedule</b> — Send SOL at a future time\n";
        $msg .= "📈 <b>Price</b> — Real-time SOL price in USD & NGN\n";
        $msg .= "🔔 <b>Alerts</b> — Notify when SOL hits a target\n";
        $msg .= "🖼️ <b>NFT / Domains / News</b>\n\n";
        $msg .= "Network: <b>{$netLabel}</b>\n";
        if ($network === 'devnet') {
            $msg .= "💡 Free testnet tokens: /faucet\n";
        }
        $msg .= "\nUnderstands 🇳🇬 English, Yoruba, Igbo, Hausa, Pidgin!\n";
        $msg .= "🎙️ Voice notes supported — just hold the mic and speak!";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::button('💼 Create Wallet', 'wallet_create'), Telegram::button('🔄 DeFi', 'defi_info')],
            [Telegram::button('📈 SOL Price', 'check_price'),        Telegram::button('❓ Help', 'help')],
        ]);
        $this->telegram->sendMessage($chatId, $msg, $keyboard);
    }

    private function cmdHelp(int $chatId): void
    {
        $network = $this->config['solana']['network'];
        $msg  = "📖 <b>SolanaAgent Commands</b>\n\n";
        $msg .= "<b>💼 Wallet</b>\n";
        $msg .= "/wallet create — Create new wallet\n";
        $msg .= "/wallet list — List wallets\n";
        $msg .= "/balance — SOL + USDC balances\n";
        $msg .= "/export — Export keypair\n\n";
        $msg .= "<b>💸 Transactions</b>\n";
        $msg .= "/send [address] [sol] — Send SOL\n";
        $msg .= "/schedule send [addr] [amt] at [time] — Schedule send\n";
        $msg .= "/history — Transaction history\n";
        if ($network === 'devnet') $msg .= "/airdrop [sol] — Free devnet SOL\n\n";
        $msg .= "\n<b>🔄 DeFi</b>\n";
        $msg .= "/swap [amt] SOL USDC — Swap SOL → USDC\n";
        $msg .= "/swap [amt] USDC SOL — Swap USDC → SOL\n";
        $msg .= "/token balance — Check USDC balance\n";
        $msg .= "/token send [addr] [amt] — Send USDC\n";
        $msg .= "/faucet — Get free devnet SOL & USDC links\n";
        $msg .= "/defi — DeFi overview & commands\n\n";
        $msg .= "<b>🎯 Automation (Goal-Driven)</b>\n";
        $msg .= "/goal — Set price-triggered actions\n";
        $msg .= "/alert above|below [price] — Price notification\n";
        $msg .= "/tasks — View all active tasks & goals\n\n";
        $msg .= "<b>🌐 Web3</b>\n";
        $msg .= "/domain [name.sol] — Lookup .sol domain\n";
        $msg .= "/nft [collection] — NFT collection stats\n";
        $msg .= "/price — SOL price (USD & NGN)\n";
        $msg .= "/news — Latest Solana news\n\n";
        $msg .= "💬 Or just <b>chat naturally</b> in any Nigerian language!\n";
        $msg .= "🎙️ Voice notes fully supported!\n\n";
        $msg .= "<b>Goal examples:</b>\n";
        $msg .= "<i>\"Buy \$10 of SOL when price hits \$80\"</i>\n";
        $msg .= "<i>\"Swap 1 SOL to USDC when price reaches \$200\"</i>\n";
        $msg .= "<i>\"Send 0.5 SOL to [addr] when SOL drops below \$100\"</i>";
        $this->telegram->sendMessage($chatId, $msg);
    }

    private function cmdDeFi(int $chatId): void
    {
        $network = $this->config['solana']['network'];
        $isDevnet = $network === 'devnet';
        $msg  = "🔄 <b>SolanaAgent DeFi</b>\n\n";

        if ($isDevnet) {
            $msg .= "Network: <b>Devnet</b> — Real on-chain transactions, test tokens\n\n";
            $msg .= "<b>🪙 Get Test Tokens</b>\n";
            $msg .= "• SOL: /airdrop or /faucet\n";
            $msg .= "• USDC: /faucet (Circle faucet link)\n\n";
        } else {
            $msg .= "Network: <b>Mainnet</b> — Real money, Jupiter best rates\n\n";
        }

        $msg .= "<b>🔄 Swap</b>\n";
        $msg .= "/swap 1 SOL USDC — Sell SOL, get USDC\n";
        $msg .= "/swap 50 USDC SOL — Buy SOL with USDC\n\n";
        $msg .= "<b>💰 Balances</b>\n";
        $msg .= "/balance — SOL balance\n";
        $msg .= "/token balance — USDC balance\n\n";
        $msg .= "<b>🎯 Conditional / Goal-Driven DeFi</b>\n";
        $msg .= "Set up automatic trades triggered by price:\n\n";
        $msg .= "• <i>\"Swap 1 SOL to USDC when SOL hits \$200\"</i>\n";
        $msg .= "• <i>\"Buy \$20 of SOL when price drops to \$80\"</i>\n";
        $msg .= "• <i>\"Swap 50 USDC to SOL when price is below \$90\"</i>\n\n";
        $msg .= "Just say it naturally or use /goal\n\n";
        $msg .= "Use /tasks to view all active goals.";

        $keyboard = Telegram::inlineKeyboard([
            [Telegram::button('🔄 Swap SOL→USDC', 'swap_sol_usdc'), Telegram::button('🔄 Swap USDC→SOL', 'swap_usdc_sol')],
            [Telegram::button('💰 Token Balance', 'token_balance'), Telegram::button('🪙 Get Tokens', 'faucet_info')],
        ]);
        $this->telegram->sendMessage($chatId, $msg, $keyboard);
    }

    private function cmdFaucet(int $chatId, int $userId): void
    {
        $network = $this->config['solana']['network'];
        $wallet  = $this->db->getActiveWallet($userId);
        $addr    = $wallet ? $wallet['public_key'] : null;

        $msg  = "🪙 <b>Get Free Test Tokens</b>\n\n";

        if ($network === 'devnet') {
            $msg .= "<b>🔵 Devnet SOL</b>\n";
            $msg .= "• Bot airdrop: /airdrop (up to 2 SOL)\n";
            $msg .= "• <a href=\"https://faucet.solana.com/\">faucet.solana.com</a> — official\n\n";
            $msg .= "<b>🟡 USDC-Dev (unlimited)</b>\n";
            $msg .= "• Auto-claim: just tap the button below 👇\n";
            $msg .= "• Web: <a href=\"https://spl-token-faucet.com/?token-name=USDC-Dev\">spl-token-faucet.com</a>\n";
            $msg .= "• Mint: <code>Gh9ZwEmdLJ8DscKNTkTqPbNwLNNBjuSzaG9Vp2KGtKJr</code>\n\n";
            if ($addr) {
                $msg .= "📋 <b>Your wallet address:</b>\n<code>{$addr}</code>\n\n";
                $msg .= "Copy the address above and paste it into any faucet!\n\n";
                // Also try auto-faucet
                $this->telegram->sendMessage($chatId, $msg);
                $this->telegram->sendTyping($chatId);
                $this->telegram->sendMessage($chatId, "⏳ Also trying auto-USDC faucet for you…");
                $spl    = $this->makeSPLToken();
                $result = $spl->requestFaucet($addr, 100.0);
                if ($result['success']) {
                    $sig = substr($result['signature'] ?? '', 0, 20);
                    $this->telegram->sendMessage($chatId,
                        "✅ <b>100 USDC-Dev sent to your wallet!</b>\n"
                        . "Sig: <code>{$sig}...</code>\n\n"
                        . "Check balance: /token balance\n"
                        . "Swap to SOL: /swap 100 USDC SOL");
                } else {
                    $this->telegram->sendMessage($chatId,
                        "ℹ️ Auto-faucet wasn't available right now.\n"
                        . "Use the links above to claim USDC manually.");
                }
                return;
            }
            $msg .= "Create a wallet first with /wallet create, then come back here!";
        } else {
            $msg .= "You're on <b>Mainnet</b> — this uses real money, no faucets needed.\n\n";
            $msg .= "To get SOL:\n";
            $msg .= "• Buy on any exchange (Coinbase, Binance, etc.)\n";
            $msg .= "• Transfer to your wallet address\n\n";
            if ($addr) $msg .= "Your address:\n<code>{$addr}</code>";
        }

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function cmdSwap(int $chatId, int $userId, string $args, string $agentText = ''): void
    {
        if (!$args) {
            $this->telegram->sendMessage($chatId,
                "🔄 <b>Swap tokens</b>\n\n"
                . "Just tell me what you want, for example:\n"
                . "<i>Swap 1 SOL to USDC</i>\n"
                . "<i>Convert 50 USDC to SOL</i>\n\n"
                . "Or use: <code>/swap [amount] [FROM] [TO]</code>\n"
                . "Examples:\n"
                . "<code>/swap 1 SOL USDC</code>\n"
                . "<code>/swap 50 USDC SOL</code>");
            return;
        }

        $parts = preg_split('/\s+/', trim($args));
        if (count($parts) < 3) {
            $this->telegram->sendMessage($chatId,
                "I need an amount and a direction — e.g. <code>/swap 1 SOL USDC</code> or just say\n<i>swap 1 SOL to USDC</i> and I'll handle it.");
            return;
        }

        $amount = (float)$parts[0];
        $from   = strtoupper($parts[1]);
        $to     = strtoupper($parts[2]);

        if ($amount <= 0) {
            $this->telegram->sendMessage($chatId, "That amount doesn't look right — try something like <code>/swap 1 SOL USDC</code>");
            return;
        }

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->telegram->sendMessage($chatId,
                "You don't have a wallet yet. Say <i>create a wallet</i> and I'll set one up for you.");
            return;
        }

        // ── Pre-flight balance check ──────────────────────────────────────────
        if ($from === 'SOL') {
            try {
                $bal = $this->walletManager->getBalance($wallet['public_key']);
                if ((float)$bal['sol'] < $amount + 0.001) {
                    $have     = $bal['sol'];
                    $need     = number_format($amount + 0.001, 6);
                    $shortfall= number_format(($amount + 0.001) - (float)$have, 6);
                    $hint     = $this->config['solana']['network'] === 'devnet'
                        ? "\n\nSay <i>airdrop me some SOL</i> and I'll top you up."
                        : "\n\nTop up your wallet and I'll execute immediately.";
                    $this->telegram->sendMessage($chatId,
                        "🤖 Checked your balance — not enough SOL for this swap.\n\n"
                        . "💰 You have: <b>{$have} SOL</b>\n"
                        . "💸 Need: <b>{$need} SOL</b> (incl. fees)\n"
                        . "⚠️ Short by: <b>{$shortfall} SOL</b>{$hint}");
                    return;
                }
            } catch (\Throwable $e) {
                Logger::warn('Swap balance check failed: ' . $e->getMessage());
            }
        } elseif ($from === 'USDC') {
            try {
                $spl     = $this->makeSPLToken();
                $usdcBal = $spl->getUsdcBalance($wallet['public_key']);
                if ($usdcBal < $amount) {
                    $hint = $this->config['solana']['network'] === 'devnet'
                        ? "\n\nSay <i>get me some USDC</i> and I'll request from the faucet."
                        : "\n\nTop up your USDC and I'll swap straight away.";
                    $this->telegram->sendMessage($chatId,
                        "🤖 Checked your balance — not enough USDC.\n\n"
                        . "💰 You have: <b>" . number_format($usdcBal, 2) . " USDC</b>\n"
                        . "💸 Need: <b>" . number_format($amount, 2) . " USDC</b>{$hint}");
                    return;
                }
            } catch (\Throwable $e) {
                Logger::warn('Swap USDC check failed: ' . $e->getMessage());
            }
        }

        // ── Get live quote ────────────────────────────────────────────────────
        $this->telegram->sendTyping($chatId);
        $swap   = $this->makeSwap();
        $result = $swap->getQuote($from, $to, $amount);

        if (!$result['ok']) {
            $this->telegram->sendMessage($chatId,
                "🤖 Couldn't get a quote right now — " . ($result['error'] ?? 'please try again in a moment.'));
            return;
        }

        $quoteData = $result['data'];
        $toAmt     = $quoteData['toAmount'] ?? '?';
        $rate      = $quoteData['solPrice'] ?? null;

        // ── Agent-style "doing it now" message (prepend AI personality text if present) ──
        $rateStr   = $rate ? " at <b>\${$rate}/SOL</b>" : '';
        $statusMsg = "🤖 Balance confirmed. Swapping <b>{$amount} {$from}</b> → <b>~{$toAmt} {$to}</b>{$rateStr} now…";
        if ($agentText) {
            $statusMsg = $agentText . "\n\n" . $statusMsg;
        }
        $this->telegram->sendMessage($chatId, $statusMsg);

        $this->telegram->sendTyping($chatId);

        // ── Execute ───────────────────────────────────────────────────────────
        try {
            $keypair = $this->walletManager->getKeypair($wallet);
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId, "Couldn't load your wallet keypair: " . $e->getMessage());
            return;
        }

        $execResult = $swap->executeSwap($quoteData, $keypair, $wallet['public_key']);

        if (!$execResult['success']) {
            $err = htmlspecialchars($execResult['error'] ?? 'Unknown error');
            $this->telegram->sendMessage($chatId,
                "Something went wrong with the swap:\n\n<i>{$err}</i>\n\nYour funds are safe — try again or contact support.");
            return;
        }

        $network = $this->config['solana']['network'];
        $cluster = $network === 'mainnet' ? '' : '?cluster=devnet';
        $sig     = $execResult['signature'];
        $short   = substr($sig, 0, 20) . '...';

        // ── Log swap to transactions table ────────────────────────────────────
        $wallet = $this->db->getActiveWallet($userId);
        if ($wallet) {
            $solAmt = ($from === 'SOL') ? $amount : $toAmt;
            $this->db->insert('transactions', [
                'user_id'   => $userId,
                'wallet_id' => $wallet['id'],
                'type'      => 'swap',
                'amount_sol'=> $solAmt,
                'from_addr' => $wallet['public_key'],
                'to_addr'   => $wallet['public_key'],
                'signature' => $sig,
                'status'    => 'submitted',
                'network'   => $network,
                'meta'      => json_encode([
                    'from'     => $from,
                    'to'       => $to,
                    'from_amt' => $amount,
                    'to_amt'   => $toAmt,
                    'rate'     => $rate,
                ]),
            ]);
        }

        $this->telegram->sendMessage($chatId,
            "✅ <b>Swap done!</b>\n\n"
            . "💱 <b>{$amount} {$from}</b> → <b>{$toAmt} {$to}</b>\n"
            . ($rate ? "📈 Rate: <b>1 SOL = \${$rate}</b>\n" : '')
            . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View on Explorer</a>\n\n"
            . "Signature:\n<code>{$short}</code>");
    }

    private function cmdToken(int $chatId, int $userId, string $args): void
    {
        $parts  = preg_split('/\s+/', trim($args));
        $subCmd = strtolower($parts[0] ?? '');

        switch ($subCmd) {
            case 'balance':
                $this->doTokenBalance($chatId, $userId);
                break;
            case 'send':
                $to     = $parts[1] ?? '';
                $amount = (float)($parts[2] ?? 0);
                if (!$to || $amount <= 0) {
                    $this->telegram->sendMessage($chatId,
                        "Usage: <code>/token send [address] [amount]</code>\nExample: <code>/token send ABC...XYZ 50</code>");
                    return;
                }
                $this->doTokenTransfer($chatId, $userId, $to, $amount);
                break;
            case 'faucet':
                $this->cmdFaucet($chatId, $userId);
                break;
            default:
                $spl = $this->makeSPLToken();
                $this->telegram->sendMessage($chatId, $spl->helpMessage());
        }
    }

    private function cmdGoal(int $chatId, int $userId, string $args, array $update): void
    {
        if (!$args) {
            $msg  = "🎯 <b>Goal-Driven Automation</b>\n\n";
            $msg .= "Set price-triggered actions — I'll execute automatically:\n\n";
            $msg .= "<b>Send SOL when price hits a target:</b>\n";
            $msg .= "<i>\"When SOL reaches \$90, send 0.5 SOL to [address]\"</i>\n\n";
            $msg .= "<b>Swap based on price:</b>\n";
            $msg .= "<i>\"Swap 1 SOL to USDC when price hits \$200\"</i>\n";
            $msg .= "<i>\"Buy \$20 worth of SOL when price drops below \$80\"</i>\n";
            $msg .= "<i>\"Swap 50 USDC to SOL when price is below \$90\"</i>\n\n";
            $msg .= "Just say it naturally in the chat — I'll understand!\n\n";
            $msg .= "Or use: <code>/goal send [address] [sol] when [above|below] [price]</code>";
            $this->telegram->sendMessage($chatId, $msg);
            return;
        }

        // Parse: /goal send [addr] [sol] when [above|below] [price]
        if (preg_match('/^send\s+(\S+)\s+([\d.]+)\s+when\s+(above|below)\s+([\d.]+)/i', trim($args), $m)) {
            $result = $this->agentSetConditional($chatId, $userId, $update, [
                'condition' => 'price_' . strtolower($m[3]),
                'price'     => $m[4],
                'to'        => $m[1],
                'amount'    => $m[2],
            ]);
            if ($result) $this->telegram->sendMessage($chatId, $result);
            return;
        }

        // Parse: /goal swap [amt] [FROM] [TO] when [above|below] [price]
        if (preg_match('/^swap\s+([\d.]+)\s+(\w+)\s+(\w+)\s+when\s+(above|below)\s+([\d.]+)/i', trim($args), $m)) {
            $result = $this->agentSetConditionalSwap($chatId, $userId, $update, [
                'condition' => 'price_' . strtolower($m[4]),
                'price'     => $m[5],
                'from'      => strtoupper($m[2]),
                'to'        => strtoupper($m[3]),
                'amount'    => $m[1],
            ]);
            if ($result) $this->telegram->sendMessage($chatId, $result);
            return;
        }

        $this->telegram->sendMessage($chatId,
            "I couldn't parse that goal. Try:\n"
            . "<code>/goal send [address] [sol] when above|below [price]</code>\n"
            . "<code>/goal swap [amount] SOL USDC when above [price]</code>\n\n"
            . "Or just describe it naturally: \"Swap 1 SOL to USDC when price hits \$200\"");
    }

    private function cmdAlert(int $chatId, int $userId, string $args, array $update): void
    {
        $parts = preg_split('/\s+/', trim($args));
        if (count($parts) < 2) {
            $this->telegram->sendMessage($chatId,
                "Usage: <code>/alert [above|below] [price]</code>\nExample: <code>/alert above 200</code>");
            return;
        }
        $direction = strtolower($parts[0]);
        $price     = (float)$parts[1];
        if (!in_array($direction, ['above', 'below'], true) || $price <= 0) {
            $this->telegram->sendMessage($chatId, "❌ Use: <code>/alert above 200</code> or <code>/alert below 100</code>");
            return;
        }
        $this->scheduler->addPriceAlert($userId, (string)$update['telegram_id'], $price, $direction);
        $emoji = $direction === 'above' ? '📈' : '📉';
        $this->telegram->sendMessage($chatId,
            "🔔 Alert set! {$emoji} I'll notify you when SOL goes {$direction} <b>\${$price}</b>.\n"
            . "Use /tasks to manage.");
    }

    private function cmdSchedule(int $chatId, int $userId, string $args, array $update): void
    {
        if (!$args || !preg_match('/^send\s+(\S+)\s+([\d.]+)\s+at\s+(.+)$/i', trim($args), $m)) {
            $this->telegram->sendMessage($chatId,
                "Usage: <code>/schedule send [address] [amount] at [time]</code>\n"
                . "Example: <code>/schedule send ABC...XYZ 0.5 at in 2 hours</code>");
            return;
        }
        $result = $this->agentDecideSchedule($chatId, $userId, $update, [
            'to' => $m[1], 'amount' => $m[2], 'time' => $m[3],
        ]);
        if ($result) $this->telegram->sendMessage($chatId, $result);
    }

    private function cmdWallet(int $chatId, int $userId, string $args): void
    {
        $subCmd = strtolower(trim($args)) ?: 'info';
        switch ($subCmd) {
            case 'create':
                $this->telegram->sendMessage($chatId, $this->doCreateWallet($userId)); break;
            case 'list':
                $this->sendWalletList($chatId, $userId, false); break;
            case 'switch':
                $this->sendWalletList($chatId, $userId, true); break;
            default:
                $wallet  = $this->db->getActiveWallet($userId);
                $network = $this->config['solana']['network'];
                if (!$wallet) {
                    $this->telegram->sendMessage($chatId, "No wallet yet. Use /wallet create 🚀"); return;
                }
                $allWallets = $this->db->getUserWallets($userId);
                $msg  = "💼 <b>Active Wallet</b>\n\n";
                $msg .= "Label: <b>" . htmlspecialchars($wallet['label']) . "</b>\n";
                $msg .= "Network: <b>" . strtoupper($network) . "</b>\n";
                $msg .= "Address:\n<code>{$wallet['public_key']}</code>\n\n";
                $msg .= "🔗 <a href=\"https://explorer.solana.com/address/{$wallet['public_key']}"
                      . ($network === 'devnet' ? '?cluster=devnet' : '') . "\">View on Explorer</a>";
                $rows = [];
                if (count($allWallets) > 1) $rows[] = [Telegram::button('🔄 Switch Wallet', 'wallet_switch')];
                $rows[] = [Telegram::button('💰 Balance', 'check_balance'), Telegram::button('➕ New Wallet', 'wallet_create')];
                $this->telegram->sendMessage($chatId, $msg, Telegram::inlineKeyboard($rows));
        }
    }

    private function sendWalletList(int $chatId, int $userId, bool $switchMode): void
    {
        $wallets = $this->db->getUserWallets($userId);
        if (empty($wallets)) {
            $this->telegram->sendMessage($chatId, "No wallets yet. Use /wallet create 🚀"); return;
        }
        $msg = $switchMode ? "🔄 <b>Switch Wallet</b>:\n\n" : "💼 <b>Your Wallets</b>\n\n";
        foreach ($wallets as $i => $w) {
            $active = $w['is_active'] ? ' ✅' : '';
            $short  = substr($w['public_key'], 0, 6) . '...' . substr($w['public_key'], -6);
            $msg   .= ($i+1) . ". <b>" . htmlspecialchars($w['label']) . "</b>{$active}\n   <code>{$short}</code>\n\n";
        }
        if ($switchMode) {
            $rows = [];
            foreach ($wallets as $w)
                $rows[] = [Telegram::button($w['label'] . ($w['is_active'] ? ' ✅' : ''), 'switch_wallet_' . $w['id'])];
            $this->telegram->sendMessage($chatId, $msg, Telegram::inlineKeyboard($rows));
        } else {
            $this->telegram->sendMessage($chatId, $msg, Telegram::inlineKeyboard([[
                Telegram::button('🔄 Switch', 'wallet_switch'),
                Telegram::button('➕ New Wallet', 'wallet_create'),
            ]]));
        }
    }

    private function cmdBalance(int $chatId, int $userId): void
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->telegram->sendMessage($chatId, "No wallet yet. Use /wallet create 🚀");
            return;
        }

        $network = $this->config['solana']['network'];
        $addr    = $wallet['public_key'];
        $short   = substr($addr, 0, 8) . '…' . substr($addr, -8);
        $cluster = $network === 'devnet' ? '?cluster=devnet' : '';

        // SOL balance
        $solBal   = '?';
        $usdValue = '';
        try {
            $balance  = $this->walletManager->getBalance($addr);
            $solBal   = $balance['sol'];
            try {
                $price    = \SolanaAgent\Features\Price::getSolPrice();
                $usd      = round($solBal * $price['usd'], 2);
                $ngn      = round($solBal * ($price['ngn'] ?? 0));
                $usdValue = " ≈ <b>\${$usd}</b>" . ($ngn > 0 ? " / <b>₦" . number_format($ngn) . "</b>" : "");
            } catch (\Throwable $ignored) {}
        } catch (\Throwable $e) {
            $solBal = "error fetching";
            Logger::warn("cmdBalance SOL: " . $e->getMessage());
        }

        // USDC-Dev — never silently fail
        $usdcBal   = null;
        $usdcError = null;
        try {
            $spl     = $this->makeSPLToken();
            $usdcBal = $spl->getUsdcBalance($addr);
        } catch (\Throwable $e) {
            $usdcError = $e->getMessage();
            Logger::warn("cmdBalance USDC: " . $e->getMessage());
        }

        $msg  = "💰 <b>Wallet Balance</b>\n\n";
        $msg .= "Network: <b>" . strtoupper($network) . "</b>\n";
        $msg .= "Address: <code>{$short}</code>\n\n";
        $msg .= "◎ <b>SOL:</b> <b>{$solBal}</b>{$usdValue}\n";

        if ($usdcError !== null) {
            $msg .= "🪙 <b>USDC-Dev:</b> ⚠️ " . htmlspecialchars(substr($usdcError, 0, 100)) . "\n";
        } else {
            $msg .= "🪙 <b>USDC-Dev:</b> <b>" . number_format($usdcBal, 2) . " USDC</b>\n";
        }

        $msg .= "\n🔗 <a href=\"https://explorer.solana.com/address/{$addr}{$cluster}\">View on Explorer</a>";

        if ($usdcBal !== null && $usdcBal > 0) {
            $msg .= "\n💡 <code>/swap " . (int)$usdcBal . " USDC SOL</code> — swap to SOL";
        } elseif ($network === 'devnet' && ($usdcBal === null || $usdcBal == 0)) {
            $msg .= "\n💡 <code>/faucet</code> — get free USDC-Dev & SOL";
        }

        $this->telegram->sendMessage($chatId, $msg);
    }

    private function cmdSend(int $chatId, int $userId, string $args): void
    {
        $parts = preg_split('/\s+/', trim($args));
        if (count($parts) < 2) {
            $this->telegram->sendMessage($chatId,
                "Usage: <code>/send [address] [sol]</code>\nExample: <code>/send ABC...XYZ 0.5</code>");
            return;
        }
        $result = $this->agentDecideSend($chatId, $userId, ['to' => $parts[0], 'amount' => (float)$parts[1]]);
        if ($result) $this->telegram->sendMessage($chatId, $result);
    }

    private function cmdPrice(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, $this->doPrice());
    }

    private function cmdNFT(int $chatId, string $args): void
    {
        if (!$args) {
            $this->telegram->sendMessage($chatId, "Usage: <code>/nft [collection]</code>\nExample: <code>/nft degods</code>");
            return;
        }
        $this->telegram->sendMessage($chatId, NFT::lookup($args));
    }

    private function cmdNews(int $chatId): void
    {
        $this->telegram->sendMessage($chatId, News::formatNewsMessage(News::getLatestNews(5)));
    }

    private function cmdDomain(int $chatId, int $userId, string $args): void
    {
        if (!$args) {
            $this->telegram->sendMessage($chatId,
                "🌐 <b>Domain Commands</b>\n\n"
                . "<code>/domain name.sol</code> — Lookup\n"
                . "<code>/domain buy name.sol</code> — Register (mainnet only)");
            return;
        }
        $parts = explode(' ', trim($args), 2);
        if (strtolower($parts[0]) === 'buy') {
            $name = trim($parts[1] ?? '');
            if (!$name) { $this->telegram->sendMessage($chatId, "Usage: <code>/domain buy name.sol</code>"); return; }
            $this->doDomainBuy($chatId, $userId, $name);
            return;
        }
        $domain = Domain::normalize($parts[0]);
        $this->telegram->sendMessage($chatId, Domain::formatLookupMessage($domain, Domain::resolve($domain)));
    }

    private function doDomainBuy(int $chatId, int $userId, string $domain): void
    {
        if ($this->config['solana']['network'] !== 'mainnet') {
            $this->telegram->sendMessage($chatId,
                "🔴 Domain buying requires mainnet.\n\nYou're on devnet — switch in config.php.");
            return;
        }
        $slug  = Domain::normalize($domain);
        $this->telegram->sendTyping($chatId);
        $owner = Domain::resolve($slug);
        if ($owner !== null) { $this->telegram->sendMessage($chatId, Domain::formatLookupMessage($slug, $owner)); return; }
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) { $this->telegram->sendMessage($chatId, "❌ No wallet. Use /wallet create"); return; }
        try { $balance = $this->walletManager->getBalance($wallet['public_key']); $balSol = $balance['sol']; }
        catch (\Throwable $ignored) { $balSol = 0.0; }
        $confirm = Domain::formatBuyConfirmation($slug, $balSol);
        if (empty($confirm['keyboard'])) { $this->telegram->sendMessage($chatId, $confirm['text']); return; }
        $this->telegram->sendMessage($chatId, $confirm['text'], Telegram::inlineKeyboard([[
            Telegram::button('✅ Buy ' . $slug . '.sol', 'buy_domain_' . $slug),
            Telegram::button('❌ Cancel', 'cancel_domain'),
        ]]));
    }

    private function cmdAirdrop(int $chatId, int $userId, string $args): void
    {
        $sol = max(0.1, min((float)($args ?: 1.0), 2.0));
        $result = $this->agentDecideAirdrop($userId);
        // If checks passed but used default 1.0, re-run with requested amount
        if ($sol !== 1.0 && strpos($result, '🪂') !== false)
            $result = $this->doAirdrop($userId, $sol);
        $this->telegram->sendMessage($chatId, $result);
    }

    private function cmdHistory(int $chatId, int $userId): void
    {
        $this->telegram->sendMessage($chatId, $this->doHistory($userId));
    }

    private function cmdExport(int $chatId, int $userId): void
    {
        $this->telegram->sendMessage($chatId, $this->doExportWallet($userId));
    }

    private function cmdTasks(int $chatId, int $userId): void
    {
        $tasks       = $this->scheduler->listTasks($userId);
        $alerts      = $this->scheduler->listAlerts($userId);
        $conditional = $this->scheduler->listConditionalTasks($userId);

        if (empty($tasks) && empty($alerts) && empty($conditional)) {
            $this->telegram->sendMessage($chatId,
                "📋 No active tasks or goals.\n\n"
                . "• /schedule — schedule a timed send\n"
                . "• /alert — set a price notification\n"
                . "• /goal — set a price-triggered action\n"
                . "• Say \"swap SOL to USDC when price hits \$X\" — DeFi goal");
            return;
        }

        $msg = "📋 <b>Your Active Tasks</b>\n\n";

        if (!empty($conditional)) {
            $msg .= "🎯 <b>Goals (Price-triggered):</b>\n";
            foreach ($conditional as $c) {
                $dir   = $c['condition_type'] === 'price_above' ? '📈 above' : '📉 below';
                $price = '$' . number_format((float)$c['condition_value'], 2);
                $pl    = json_decode($c['action_payload'], true) ?? [];
                $at    = $c['action_type'];

                if (in_array($at, ['swap_sol_usdc', 'swap_usdc_sol'])) {
                    $msg .= "• #{$c['id']} — When SOL {$dir} <b>{$price}</b>\n";
                    $msg .= "  🔄 Swap <b>{$pl['amount']} {$pl['from']}</b> → <b>{$pl['to']}</b>\n\n";
                } else {
                    $short = $pl['to'] ? substr($pl['to'], 0, 8) . '...' . substr($pl['to'], -4) : '?';
                    $msg  .= "• #{$c['id']} — When SOL {$dir} <b>{$price}</b>\n";
                    $msg  .= "  ⚡ Send <b>{$pl['amount']} SOL</b> → <code>{$short}</code>\n\n";
                }
            }
        }

        if (!empty($tasks)) {
            $msg .= "⏰ <b>Scheduled Sends:</b>\n";
            foreach ($tasks as $t) {
                $data   = json_decode($t['payload'] ?? '{}', true) ?? [];
                $amount = $data['amount'] ?? '?';
                $to     = $data['to'] ?? '';
                $short  = $to ? substr($to, 0, 8) . '...' . substr($to, -4) : '?';
                $msg .= "• #{$t['id']} — <b>{$amount} SOL</b> → <code>{$short}</code>\n";
                $msg .= "  📅 {$t['execute_at']}\n\n";
            }
        }

        if (!empty($alerts)) {
            $msg .= "🔔 <b>Price Alerts:</b>\n";
            foreach ($alerts as $a) {
                $emoji = $a['direction'] === 'above' ? '📈' : '📉';
                $msg  .= "• #{$a['id']} — {$emoji} SOL {$a['direction']} \${$a['target_price']}\n";
            }
            $msg .= "\n";
        }

        // ── Active trading strategies ─────────────────────────────────────────
        $strategies = $this->db->getUserStrategies($userId);
        $active     = array_filter($strategies, fn($s) => $s['status'] === 'active');
        if (!empty($active)) {
            $msg .= "🤖 <b>Trading Strategies:</b>\n";
            foreach ($active as $s) {
                $phase = match($s['phase']) {
                    'waiting_buy' => "⏳ waiting to buy below \${$s['buy_price']}",
                    'holding'     => "📦 holding — sell at \${$s['sell_price']}",
                    default       => $s['phase'],
                };
                $msg .= "• #{$s['id']} — {$s['amount_sol']} SOL | {$phase}\n";
                $msg .= "  🎯 Sell \${$s['sell_price']}  🛑 Stop \${$s['stop_loss']}\n\n";
            }
        }

        if (empty($tasks) && empty($alerts) && empty($conditional) && empty($active)) {
            $this->telegram->sendMessage($chatId,
                "📋 No active tasks, goals, or strategies right now.\n\n"
                . "• Say \"send 1 SOL in 5 minutes\" — scheduled send\n"
                . "• Say \"alert me when SOL hits \$X\" — price alert\n"
                . "• Say \"send SOL when price hits \$X\" — conditional goal\n"
                . "• Say \"grow my SOL\" — auto trading strategy");
            return;
        }

        $msg .= "\nSay <i>cancel task #ID</i> or <i>cancel strategy #ID</i> to stop any of these.";
        $this->telegram->sendMessage($chatId, $msg);
    }

    // ─── Callbacks ────────────────────────────────────────────────────────────

    private function handleCallback(array $update): void
    {
        $chatId     = $update['chat_id'];
        $userId     = $this->ensureUser($update);
        $data       = $update['data'];
        $callbackId = $update['callback_id'];
        $this->telegram->answerCallbackQuery($callbackId);

        switch ($data) {
            case 'wallet_create':   $this->telegram->sendMessage($chatId, $this->doCreateWallet($userId)); break;
            case 'wallet_switch':   $this->sendWalletList($chatId, $userId, true); break;
            case 'check_price':     $this->telegram->sendMessage($chatId, $this->doPrice()); break;
            case 'check_balance':   $this->cmdBalance($chatId, $userId); break;
            case 'get_news':        $this->telegram->sendMessage($chatId, News::formatNewsMessage(News::getLatestNews(5))); break;
            case 'help':            $this->cmdHelp($chatId); break;
            case 'defi_info':       $this->cmdDeFi($chatId); break;
            case 'token_balance':   $this->doTokenBalance($chatId, $userId); break;
            case 'faucet_info':     $this->cmdFaucet($chatId, $userId); break;
            case 'swap_sol_usdc':   $this->cmdSwap($chatId, $userId, '1 SOL USDC'); break;
            case 'swap_usdc_sol':   $this->cmdSwap($chatId, $userId, '10 USDC SOL'); break;
            case 'cancel_domain': $this->telegram->sendMessage($chatId, "✖️ Cancelled."); break;
            // exec_swap_ is legacy — swap is now autonomous, no confirm needed
            default:
                if (strpos($data, 'switch_wallet_') === 0) {
                    $this->doSwitchWallet($chatId, $userId, (int)substr($data, strlen('switch_wallet_')));
                } elseif (strpos($data, 'buy_domain_') === 0) {
                    $this->doExecuteDomainBuy($chatId, $userId, substr($data, strlen('buy_domain_')));
                }
        }
    }

    // ─── Core action methods ──────────────────────────────────────────────────

    private function doCreateWallet(int $userId): string
    {
        try {
            $wallet  = $this->walletManager->createWallet($userId);
            $network = strtoupper($wallet['network']);
            $msg  = "🎉 <b>Wallet Created!</b>\n\n";
            $msg .= "Network: <b>{$network}</b>\n";
            $msg .= "Address:\n<code>{$wallet['public_key']}</code>\n\n";
            if ($wallet['is_active'] === 1) $msg .= "✅ Set as your active wallet.\n\n";
            $msg .= "⚠️ <b>Keep your address safe!</b>";
            if ($wallet['network'] === 'devnet')
                $msg .= "\n\n💡 Get free test tokens: /faucet";
            return $msg;
        } catch (\Throwable $e) {
            return "❌ Failed to create wallet: " . $e->getMessage();
        }
    }

    private function doSwitchWallet(int $chatId, int $userId, int $walletId): void
    {
        try {
            $wallet = $this->walletManager->switchWallet($userId, $walletId);
            $short  = substr($wallet['public_key'], 0, 8) . '…' . substr($wallet['public_key'], -8);
            $this->telegram->sendMessage($chatId,
                "✅ Switched to: <b>" . htmlspecialchars($wallet['label']) . "</b>\n"
                . "Address: <code>{$short}</code>");
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId, "❌ Could not switch wallet: " . $e->getMessage());
        }
    }

    private function doBalance(int $userId): string
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) return "No wallet yet. Use /wallet create 🚀";
        try {
            $balance  = $this->walletManager->getBalance($wallet['public_key']);
            $network  = $this->config['solana']['network'];
            $usdValue = '';
            try {
                $price    = Price::getSolPrice();
                $usd      = round($balance['sol'] * $price['usd'], 2);
                $ngn      = round($balance['sol'] * ($price['ngn'] ?? 0), 0);
                $usdValue = " ≈ <b>\${$usd}</b>" . ($ngn > 0 ? " / <b>₦" . number_format($ngn) . "</b>" : "");
            } catch (\Throwable $ignored) {}
            $addr = substr($wallet['public_key'], 0, 8) . '…' . substr($wallet['public_key'], -8);
            return "💰 <b>Wallet Balance</b>\n\n"
                . "Network: <b>" . strtoupper($network) . "</b>\n"
                . "Address: <code>{$addr}</code>\n\n"
                . "◎ SOL: <b>{$balance['sol']}</b>{$usdValue}";
        } catch (\Throwable $e) {
            return "❌ Could not fetch balance: " . $e->getMessage();
        }
    }

    private function doSend(int $userId, string $to, float $amount): string
    {
        try {
            $result = $this->walletManager->sendSol($userId, $to, $amount);
            return "✅ <b>Transaction Sent!</b>\n\n"
                . "💸 Amount: <b>{$amount} SOL</b>\n"
                . "📤 To: <code>{$to}</code>\n"
                . "🔗 <a href=\"{$result['explorer']}\">View on Explorer</a>\n\n"
                . "Signature: <code>" . substr($result['signature'], 0, 20) . "...</code>";
        } catch (\Throwable $e) {
            return "❌ Transfer failed: " . $e->getMessage();
        }
    }

    // ─── Strategy methods ────────────────────────────────────────────────────

    private function agentSuggestStrategy(int $chatId, int $userId, array $params): string
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            return "You need a wallet first! Say create a wallet and I'll set one up.";
        }

        try {
            $priceData = Price::getSolPrice();
            $current   = (float)$priceData['usd'];
            $change24h = (float)($priceData['change_24h'] ?? 0.0);
        } catch (\Throwable $e) {
            return "Could not fetch SOL price right now. Try again in a moment.";
        }

        $amountSol = max(0.1, (float)($params['amount_sol'] ?? 1.0));

        // Recommend the best strategy type for current market conditions
        $recommended = Strategy::recommendType($change24h);

        // Start rotation at the recommended type (index 0 of shown list)
        $this->savePendingStrategy($userId, $recommended, $amountSol, $current, $change24h, [$recommended]);

        $s   = Strategy::generateByType($current, $recommended);
        $msg = Strategy::formatSuggestion($s, $amountSol, $change24h, 1, true);

        return $msg;
    }

    private function agentNextStrategy(int $chatId, int $userId): string
    {
        $row = $this->db->fetch(
            "SELECT value FROM settings WHERE key_name=?",
            ["pending_strategy_{$userId}"]
        );
        if (!$row) {
            return "No strategy session found. Say <b>grow my SOL</b> and I'll start fresh.";
        }

        $pending   = json_decode($row['value'], true) ?? [];
        $shown     = $pending['shown_types'] ?? [];
        $amountSol = (float)($pending['amount_sol'] ?? 1.0);
        $current   = (float)($pending['current_price'] ?? 0);
        $change24h = (float)($pending['change_24h'] ?? 0);
        $recommended = $pending['recommended'] ?? 'CONSERVATIVE';

        if ($current <= 0) {
            try {
                $priceData = Price::getSolPrice();
                $current   = (float)$priceData['usd'];
                $change24h = (float)($priceData['change_24h'] ?? 0.0);
            } catch (\Throwable $e) {
                return "Could not fetch SOL price right now. Try again in a moment.";
            }
        }

        // Find next type not yet shown
        $next = null;
        foreach (Strategy::ROTATION_ORDER as $type) {
            if (!in_array($type, $shown, true)) {
                $next = $type;
                break;
            }
        }

        // All shown — wrap around
        if ($next === null) {
            $shown = [];
            $next  = Strategy::ROTATION_ORDER[0];
        }

        $shown[] = $next;
        $index   = count($shown);
        $isRec   = ($next === $recommended);

        $this->savePendingStrategy($userId, $recommended, $amountSol, $current, $change24h, $shown);

        $s   = Strategy::generateByType($current, $next);
        return Strategy::formatSuggestion($s, $amountSol, $change24h, $index, $isRec);
    }

    private function savePendingStrategy(
        int    $userId,
        string $recommended,
        float  $amountSol,
        float  $currentPrice,
        float  $change24h,
        array  $shownTypes
    ): void {
        // Get the current (last shown) type's params to store for activation
        $lastType = end($shownTypes) ?: $recommended;
        $s        = Strategy::generateByType($currentPrice, $lastType);

        $this->db->query(
            "INSERT OR REPLACE INTO settings (key_name, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
            [
                "pending_strategy_{$userId}",
                json_encode([
                    'buy_price'     => $s['buy_price'],
                    'sell_price'    => $s['sell_price'],
                    'stop_loss'     => $s['stop_loss'],
                    'amount_sol'    => $amountSol,
                    'strategy_type' => $lastType,
                    'current_price' => $currentPrice,
                    'change_24h'    => $change24h,
                    'recommended'   => $recommended,
                    'shown_types'   => $shownTypes,
                ])
            ]
        );
    }

    private function agentActivateStrategy(int $chatId, int $userId, array $update, array $params): string
    {
        // If params already have explicit values, use them
        // Otherwise load the pending strategy from settings
        $buyPrice  = (float)($params['buy_price']  ?? 0);
        $sellPrice = (float)($params['sell_price'] ?? 0);
        $stopLoss  = (float)($params['stop_loss']  ?? 0);
        $amountSol = (float)($params['amount_sol'] ?? 0);

        $strategyType = $params['strategy_type'] ?? '';

        if ($buyPrice <= 0 || $sellPrice <= 0) {
            // Load from pending
            $row = $this->db->fetch(
                "SELECT value FROM settings WHERE key_name=?",
                ["pending_strategy_{$userId}"]
            );
            if (!$row) {
                return "No pending strategy found. Say grow my SOL and I will generate one for you first.";
            }
            $pending      = json_decode($row['value'], true) ?? [];
            $buyPrice     = $buyPrice     ?: (float)($pending['buy_price']    ?? 0);
            $sellPrice    = $sellPrice    ?: (float)($pending['sell_price']   ?? 0);
            $stopLoss     = $stopLoss     ?: (float)($pending['stop_loss']    ?? 0);
            $amountSol    = $amountSol    ?: (float)($pending['amount_sol']   ?? 1.0);
            $strategyType = $strategyType ?: ($pending['strategy_type'] ?? 'CONSERVATIVE');
        }

        if ($buyPrice <= 0 || $sellPrice <= $buyPrice) {
            return "The strategy parameters do not look right. Say grow my SOL and I will generate a fresh one.";
        }

        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            return "No active wallet found. Create one first with create a wallet.";
        }

        $strategy = new Strategy(
            $this->db,
            $this->walletManager,
            $this->telegram,
            $this->config
        );

        $id = $strategy->create(
            $userId,
            (string)$update['telegram_id'],
            $buyPrice,
            $sellPrice,
            $stopLoss,
            $amountSol,
            '',
            $strategyType ?: 'CONSERVATIVE'
        );

        // Clear pending
        $this->db->query(
            "DELETE FROM settings WHERE key_name=?",
            ["pending_strategy_{$userId}"]
        );

        return "✅ <b>Strategy #{$id} activated!</b>\n\n"
            . "🟢 Buy at: <b>\${$buyPrice}</b>\n"
            . "🎯 Sell at: <b>\${$sellPrice}</b>\n"
            . "🛑 Stop loss: <b>\${$stopLoss}</b>\n"
            . "💸 Amount: <b>{$amountSol} SOL</b>\n\n"
            . "I'm monitoring price every minute. I'll execute the buy when SOL drops to \${$buyPrice}, "
            . "then hold and sell automatically at your targets.\n\n"
            . "Use /strategies to track progress or say cancel strategy #{$id} to stop.";
    }

    private function agentListStrategies(int $userId): string
    {
        $strategies = $this->db->getUserStrategies($userId);
        if (empty($strategies)) {
            return "You have no strategies yet. Say grow my SOL and I will set one up for you.";
        }

        $strategy = new Strategy(
            $this->db,
            $this->walletManager,
            $this->telegram,
            $this->config
        );

        $msg   = "🤖 <b>Your Strategies</b>\n\n";
        $active = 0;
        foreach ($strategies as $s) {
            if ($s['status'] === 'active') {
                $msg .= $strategy->formatActive($s) . "\n\n";
                $active++;
            } else {
                $statusEmoji = match($s['status']) {
                    'completed' => '✅',
                    'stopped'   => '🛑',
                    'cancelled' => '❌',
                    default     => '•',
                };
                $msg .= "{$statusEmoji} <b>#{$s['id']}</b> {$s['label']} — {$s['status']}\n";
            }
        }

        if ($active === 0) {
            $msg .= "No active strategies. Say grow my SOL to start one.";
        }

        return $msg;
    }

    private function agentCancelStrategy(int $userId, int $strategyId): string
    {
        if ($strategyId <= 0) {
            return "Which strategy do you want to cancel? Say cancel strategy #1 (or whichever number).";
        }

        $ok = $this->db->cancelStrategy($strategyId, $userId);
        if ($ok) {
            return "✅ Strategy #{$strategyId} cancelled. Your funds are safe — no more automatic trades.";
        }
        return "Could not find strategy #{$strategyId}. Use /strategies to see your active ones.";
    }

        private function doPrice(): string
    {
        try { return Price::formatPriceMessage(Price::getSolPrice()); }
        catch (\Throwable $e) { return "❌ Could not fetch price: " . $e->getMessage(); }
    }

    private function doPriceAlert(int $userId, string $telegramId, array $params): string
    {
        if (empty($params['target']) || empty($params['direction']))
            return "To set an alert say: \"Alert me when SOL goes above \$200\"";
        $this->scheduler->addPriceAlert($userId, $telegramId, (float)$params['target'], $params['direction']);
        $emoji = $params['direction'] === 'above' ? '📈' : '📉';
        return "🔔 Alert set! {$emoji} I'll notify you when SOL goes {$params['direction']} <b>\${$params['target']}</b>.";
    }

    private function doAirdrop(int $userId, float $sol): string
    {
        try {
            $sig = $this->walletManager->requestAirdrop($userId, $sol);
            return "🪂 <b>Airdrop requested!</b> <b>{$sol} SOL</b> coming to your wallet.\n"
                . "Signature: <code>" . substr($sig, 0, 20) . "...</code>\n"
                . "Check balance in ~30 seconds: /balance";
        } catch (\Throwable $e) { return "❌ " . $e->getMessage(); }
    }

    private function doHistory(int $userId): string
    {
        $txs = $this->walletManager->getHistory($userId, 8);
        if (empty($txs)) return "📋 No transactions yet.\nSend SOL with /send or try /airdrop";
        $msg = "📋 <b>Transaction History</b>\n\n";
        foreach ($txs as $tx) {
            $type = $tx['type'] === 'send_sol' ? '📤' : '📥';
            $msg .= "{$type} {$tx['amount_sol']} SOL → " . substr($tx['to_addr'], 0, 8) . "...\n";
            $msg .= "   {$tx['status']} · " . date('M d H:i', strtotime($tx['created_at'])) . "\n";
        }
        return $msg;
    }

    private function doExportWallet(int $userId): string
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) return "No wallet. Use /wallet create";
        try {
            $keypair = $this->walletManager->getKeypair($wallet);
            $json    = json_encode($keypair->exportAsArray());
            return "🔑 <b>Wallet Export</b>\n\n"
                . "⚠️ <b>KEEP THIS SECRET! Never share your private key.</b>\n\n"
                . "Public Key:\n<code>{$wallet['public_key']}</code>\n\n"
                . "Private Key (Phantom-compatible):\n<code>{$json}</code>\n\n"
                . "🗑️ Delete this message after saving!";
        } catch (\Throwable $e) { return "❌ Export failed: " . $e->getMessage(); }
    }

    private function doTokenBalance(int $chatId, int $userId): void
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) {
            $this->telegram->sendMessage($chatId, "❌ No wallet found. Use /wallet create");
            return;
        }
        try {
            $spl     = $this->makeSPLToken();
            $network = $this->config['solana']['network'];
            $short   = substr($wallet['public_key'], 0, 8) . '…' . substr($wallet['public_key'], -8);

            // Use getAllBalances for full picture, then also explicitly check USDC-Dev
            $usdcBal  = $spl->getUsdcBalance($wallet['public_key']);
            $allBals  = $spl->getAllBalances($wallet['public_key']);

            $msg  = "🪙 <b>Token Balance</b>\n\n";
            $msg .= "Address: <code>{$short}</code>\n";
            $msg .= "Network: <b>" . strtoupper($network) . "</b>\n\n";

            // Always show USDC-Dev explicitly
            $msg .= "USDC-Dev: <b>" . number_format($usdcBal, 2) . " USDC</b>\n";

            // Show any other tokens
            foreach ($allBals as $token) {
                if (strpos($token['mint'], 'Gh9ZwEmd') === 0) continue; // already shown above
                if (strpos($token['mint'], 'EPjFWdd5') === 0) continue; // mainnet USDC shown separately
                $msg .= "{$token['symbol']}: <b>" . number_format($token['balance'], 4) . "</b>\n";
            }

            if ($usdcBal > 0) {
                $msg .= "\n💡 Swap to SOL: <code>/swap " . number_format($usdcBal, 0) . " USDC SOL</code>";
            } else {
                $msg .= "\n💡 Get free USDC-Dev: /faucet";
            }

            $this->telegram->sendMessage($chatId, $msg);

        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId,
                "❌ Could not fetch token balance: " . $e->getMessage());
        }
    }

    private function doTokenTransfer(int $chatId, int $userId, string $to, float $amount): void
    {
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) { $this->telegram->sendMessage($chatId, "❌ No wallet found."); return; }
        if (!Base58::isValidAddress($to)) {
            $this->telegram->sendMessage($chatId, "❌ Invalid recipient address."); return;
        }
        $this->telegram->sendTyping($chatId);
        try {
            $spl     = $this->makeSPLToken();
            $keypair = $this->walletManager->getKeypair($wallet);
            $result  = $spl->transfer($keypair, $wallet['public_key'], $to, $amount);
            if (!$result['success']) {
                $this->telegram->sendMessage($chatId, "❌ USDC transfer failed: " . ($result['error'] ?? 'Unknown')); return;
            }
            $network = $this->config['solana']['network'];
            $cluster = $network === 'devnet' ? '?cluster=devnet' : '';
            $sig     = $result['signature'];
            $this->telegram->sendMessage($chatId,
                "✅ <b>USDC Sent!</b>\n\n"
                . "💸 Amount: <b>" . number_format($amount, 2) . " USDC</b>\n"
                . "📤 To: <code>{$to}</code>\n"
                . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View on Explorer</a>");
        } catch (\Throwable $e) {
            $this->telegram->sendMessage($chatId, "❌ " . $e->getMessage());
        }
    }

    private function doExecuteDomainBuy(int $chatId, int $userId, string $slug): void
    {
        if ($this->config['solana']['network'] !== 'mainnet') {
            $this->telegram->sendMessage($chatId, "🔴 Domain buying requires mainnet."); return;
        }
        $this->telegram->sendTyping($chatId);
        $wallet = $this->db->getActiveWallet($userId);
        if (!$wallet) { $this->telegram->sendMessage($chatId, "❌ No active wallet."); return; }
        $owner = Domain::resolve($slug);
        if ($owner !== null) {
            $this->telegram->sendMessage($chatId, "❌ <b>{$slug}.sol</b> was just taken!\nOwner: <code>{$owner}</code>"); return;
        }
        $this->telegram->sendMessage($chatId, "⏳ <i>Submitting domain registration…</i>");
        try {
            $keypair   = $this->walletManager->getKeypair($wallet);
            $secretHex = bin2hex($keypair->getSecretKeyBytes());
            $pubKey    = $wallet['public_key'];
        } catch (\Throwable $e) { $this->telegram->sendMessage($chatId, "❌ Could not load keypair."); return; }
        $result = Domain::registerDomain($slug, $pubKey, $secretHex, $this->walletManager->getRpc());
        if (!empty($result['deeplink'])) {
            $this->telegram->sendMessage($chatId,
                "⚠️ <b>Bonfida API unavailable</b>\n\nRegister directly:\n🔗 <a href=\"{$result['url']}\">{$result['url']}</a>"); return;
        }
        if (!$result['success']) {
            $this->telegram->sendMessage($chatId, "❌ <b>Registration failed</b>\n\n" . htmlspecialchars($result['error'] ?? '')); return;
        }
        $cluster = $this->config['solana']['network'] === 'mainnet' ? '' : '?cluster=devnet';
        $sig     = $result['signature'];
        $this->telegram->sendMessage($chatId,
            "🎉 <b>Domain Registered!</b>\n\nDomain: <code>{$slug}.sol</code>\n"
            . "🔗 <a href=\"https://explorer.solana.com/tx/{$sig}{$cluster}\">View Transaction</a>\n"
            . "🔗 <a href=\"https://naming.bonfida.org/domain/{$slug}\">View on Bonfida</a>");
    }

    // ─── Factories ────────────────────────────────────────────────────────────

    private function makeSwap(): Swap
    {
        return new Swap(
            $this->walletManager->getRpc(),
            $this->db,
            new Crypto($this->config['security']['encryption_key']),
            $this->config['solana']['network']
        );
    }

    private function makeSPLToken(): SPLToken
    {
        return new SPLToken($this->walletManager->getRpc(), $this->config['solana']['network']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function ensureUser(array $update): int
    {
        return $this->db->upsertUser([
            'telegram_id' => $update['telegram_id'],
            'username'    => $update['username'] ?? null,
            'first_name'  => $update['first_name'] ?? 'User',
        ]);
    }
}
