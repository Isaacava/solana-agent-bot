<?php
/**
 * ============================================================
 * INDEX.PHP — Public Landing Page
 * Hardcoded configuration + CoinGecko Live Price Feed
 * ============================================================
 */

// --- CONFIGURATION (Hardcoded) ---
$botUsername = 'Solana9ja_bot';
$appName     = 'Solana AI Agent';
$botLink     = "https://t.me/{$botUsername}";
$githubLink  = "https://github.com/YOUR_GITHUB_USERNAME/YOUR_REPO_NAME"; // ← Update after creating GitHub repo
$twitterLink = "https://twitter.com/mark67857";

// --- LIVE PRICE FETCH (CoinGecko) ---
$solPrice = 0;
$solNGN   = 0;

try {
    $url     = "https://api.coingecko.com/api/v3/simple/price?ids=solana&vs_currencies=usd,ngn";
    $options = ["http" => ["method" => "GET", "header" => "User-Agent: SolanaAiAgent/1.0\r\n"]];
    $context  = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);
    if ($response) {
        $data     = json_decode($response, true);
        $solPrice = $data['solana']['usd'] ?? 0;
        $solNGN   = $data['solana']['ngn'] ?? 0;
    }
} catch (\Throwable $e) {
    $solPrice = 0;
    $solNGN   = 0;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= htmlspecialchars($appName) ?> | DeFi for Nigeria</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;600;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; background-color: #050505; color: #fafafa; }
        .grain {
            position: fixed; top: 0; left: 0; height: 100%; width: 100%;
            pointer-events: none; z-index: 50; opacity: 0.03;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noiseFilter'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noiseFilter)'/%3E%3C/svg%3E");
        }
        .glass { background: rgba(255,255,255,0.03); backdrop-filter: blur(10px); border: 1px solid rgba(255,255,255,0.08); }
        .solana-gradient { background: linear-gradient(135deg, #9945FF 0%, #14F195 100%); }
        .text-gradient { background: linear-gradient(to right, #fff, #14F195); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body class="antialiased">
    <div class="grain"></div>

    <!-- NAV -->
    <nav class="fixed w-full z-40 border-b border-white/5 bg-black/50 backdrop-blur-xl">
        <div class="max-w-7xl mx-auto px-6 h-20 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 solana-gradient rounded-xl flex items-center justify-center shadow-lg shadow-emerald-500/20">
                    <i data-lucide="zap" class="text-black w-6 h-6 fill-current"></i>
                </div>
                <span class="font-extrabold text-xl tracking-tight italic"><?= strtoupper(htmlspecialchars($appName)) ?></span>
            </div>
            <div class="hidden md:flex items-center gap-8 text-sm font-medium text-white/60">
                <a href="#features" class="hover:text-emerald-400 transition-colors">Features</a>
                <a href="#how" class="hover:text-emerald-400 transition-colors">How it Works</a>
                <a href="<?= $botLink ?>" class="bg-white text-black px-6 py-2.5 rounded-full font-bold hover:bg-emerald-400 transition-all active:scale-95">
                    Launch Agent
                </a>
            </div>
        </div>
    </nav>

    <!-- HERO -->
    <section class="relative pt-44 pb-32 px-6 overflow-hidden">
        <div class="absolute top-0 left-1/2 -translate-x-1/2 w-full h-[600px] bg-emerald-500/10 blur-[120px] rounded-full pointer-events-none"></div>
        <div class="max-w-7xl mx-auto relative z-10">
            <div class="flex flex-col items-center text-center">
                <div class="inline-flex items-center gap-2 px-4 py-2 rounded-full glass border border-emerald-500/30 text-emerald-400 text-xs font-bold mb-8 tracking-widest uppercase">
                    <span class="relative flex h-2 w-2">
                        <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                        <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-500"></span>
                    </span>
                    Built for Superteam Nigeria
                </div>
                <h1 class="text-5xl md:text-8xl font-extrabold leading-[1.1] mb-8 max-w-5xl tracking-tighter">
                    Every Nigerian deserves an <span class="text-gradient italic">AI Agent</span>
                </h1>
                <p class="text-lg md:text-xl text-white/50 max-w-2xl mb-12 leading-relaxed font-light italic">
                    Command your Solana wallet using voice notes in <span class="text-white">Pidgin, Yoruba, Igbo, or Hausa.</span> No apps. No seed phrases. Just Telegram.
                </p>
                <div class="flex flex-col sm:flex-row gap-4">
                    <a href="<?= $botLink ?>" class="group solana-gradient p-[1px] rounded-2xl transition-transform hover:scale-105">
                        <div class="bg-black group-hover:bg-transparent transition-colors rounded-2xl px-10 py-5 flex items-center gap-3">
                            <i data-lucide="send" class="w-5 h-5 text-emerald-400 group-hover:text-black"></i>
                            <span class="font-bold text-lg group-hover:text-black">Open on Telegram</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>
    </section>

    <!-- PRICE TICKER -->
    <div class="border-y border-white/5 bg-white/[0.02] backdrop-blur-md">
        <div class="max-w-7xl mx-auto px-6 py-6 flex flex-wrap justify-center gap-x-12 gap-y-4">
            <div class="flex items-center gap-3">
                <span class="text-white/40 text-xs font-bold uppercase tracking-widest">SOL/USD</span>
                <span class="font-mono text-emerald-400 font-bold">$<?= number_format($solPrice, 2) ?></span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-white/40 text-xs font-bold uppercase tracking-widest">SOL/NGN</span>
                <span class="font-mono text-emerald-400 font-bold">₦<?= number_format($solNGN, 2) ?></span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-white/40 text-xs font-bold uppercase tracking-widest">Data Source</span>
                <span class="text-xs font-bold text-white/60">CoinGecko API</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-white/40 text-xs font-bold uppercase tracking-widest">Status</span>
                <span class="flex items-center gap-1.5 text-xs font-bold text-purple-400">
                    <div class="w-1.5 h-1.5 bg-purple-400 rounded-full"></div> DEVNET
                </span>
            </div>
        </div>
    </div>

    <!-- FEATURES -->
    <section id="features" class="py-32 px-6">
        <div class="max-w-7xl mx-auto">
            <div class="flex flex-col md:flex-row justify-between items-end mb-20 gap-8">
                <div class="max-w-2xl">
                    <h2 class="text-4xl md:text-5xl font-extrabold tracking-tighter mb-6 italic">Built differently.</h2>
                    <p class="text-white/50 text-lg">We stripped away the complexity of DeFi. No complex charts, just a conversation with an agent that understands you.</p>
                </div>
                <div class="glass px-6 py-4 rounded-2xl flex items-center gap-4">
                    <div class="flex -space-x-3">
                        <div class="w-10 h-10 rounded-full border-2 border-black bg-emerald-600 flex items-center justify-center text-[10px] font-bold">N</div>
                        <div class="w-10 h-10 rounded-full border-2 border-black bg-purple-600 flex items-center justify-center text-[10px] font-bold">SOL</div>
                        <div class="w-10 h-10 rounded-full border-2 border-black bg-zinc-800 flex items-center justify-center"><i data-lucide="plus" class="w-4 h-4 text-white"></i></div>
                    </div>
                    <span class="text-xs font-bold text-white/60">Multilingual & Fast</span>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="glass p-8 rounded-[2rem] group hover:border-emerald-500/50 transition-all">
                    <div class="w-14 h-14 rounded-2xl bg-emerald-500/10 flex items-center justify-center mb-8 group-hover:scale-110 transition-transform">
                        <i data-lucide="mic" class="text-emerald-400 w-7 h-7"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-4 italic">Nigerian Dialects</h3>
                    <p class="text-white/50 leading-relaxed">Chat in Pidgin, Yoruba, Igbo or Hausa. Our AI identifies the context and executes the trade instantly.</p>
                </div>
                <div class="glass p-8 rounded-[2rem] group hover:border-purple-500/50 transition-all">
                    <div class="w-14 h-14 rounded-2xl bg-purple-500/10 flex items-center justify-center mb-8 group-hover:scale-110 transition-transform">
                        <i data-lucide="calendar" class="text-purple-400 w-7 h-7"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-4 italic">Autonomous Triggers</h3>
                    <p class="text-white/50 leading-relaxed">"Buy 0.5 SOL when the price hits $140." Your agent watches the market so you don't have to.</p>
                </div>
                <div class="glass p-8 rounded-[2rem] group hover:border-blue-500/50 transition-all">
                    <div class="w-14 h-14 rounded-2xl bg-blue-500/10 flex items-center justify-center mb-8 group-hover:scale-110 transition-transform">
                        <i data-lucide="shield-check" class="text-blue-400 w-7 h-7"></i>
                    </div>
                    <h3 class="text-xl font-bold mb-4 italic">No-Seed Security</h3>
                    <p class="text-white/50 leading-relaxed">Leverages Telegram's secure interface to manage your keys. AES-256-GCM encrypted. Perfect for onboarding new users safely.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- HOW IT WORKS -->
    <section id="how" class="py-32 bg-zinc-950/50 border-y border-white/5 px-6">
        <div class="max-w-7xl mx-auto grid md:grid-cols-2 gap-20 items-center">
            <div>
                <span class="text-emerald-400 font-bold tracking-[0.2em] text-xs uppercase mb-6 block">The Interface</span>
                <h2 class="text-4xl md:text-6xl font-extrabold tracking-tighter mb-8 leading-tight italic">Talk to your wallet like a <span class="text-gradient">friend.</span></h2>
                <div class="space-y-6">
                    <div class="flex gap-4">
                        <div class="w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0 mt-1">
                            <i data-lucide="check" class="text-emerald-400 w-3 h-3"></i>
                        </div>
                        <p class="text-white/70">"Wetin be the price of SOL right now?"</p>
                    </div>
                    <div class="flex gap-4">
                        <div class="w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0 mt-1">
                            <i data-lucide="check" class="text-emerald-400 w-3 h-3"></i>
                        </div>
                        <p class="text-white/70">"Send 0.5 SOL to this address in 2 hours"</p>
                    </div>
                    <div class="flex gap-4">
                        <div class="w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0 mt-1">
                            <i data-lucide="check" class="text-emerald-400 w-3 h-3"></i>
                        </div>
                        <p class="text-white/70">"Swap my USDC to SOL when price drops below $80"</p>
                    </div>
                    <div class="flex gap-4">
                        <div class="w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0 mt-1">
                            <i data-lucide="check" class="text-emerald-400 w-3 h-3"></i>
                        </div>
                        <p class="text-white/70">"Kini iroyin Solana loni?" (What's the Solana news today?)</p>
                    </div>
                    <div class="flex gap-4">
                        <div class="w-6 h-6 rounded-full bg-emerald-500/20 flex items-center justify-center flex-shrink-0 mt-1">
                            <i data-lucide="check" class="text-emerald-400 w-3 h-3"></i>
                        </div>
                        <p class="text-white/70">"Oya grow my SOL for me" — activates auto trading strategy</p>
                    </div>
                </div>
            </div>
            <div class="relative">
                <div class="absolute -inset-4 bg-emerald-500/20 blur-3xl rounded-full opacity-30"></div>
                <div class="relative bg-[#0d0d0d] rounded-[2.5rem] border border-white/10 shadow-2xl overflow-hidden">
                    <div class="bg-white/5 p-6 border-b border-white/10 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full solana-gradient flex items-center justify-center font-bold text-black">A</div>
                            <div>
                                <h4 class="font-bold text-sm"><?= htmlspecialchars($appName) ?></h4>
                                <span class="text-[10px] text-emerald-400 font-bold uppercase tracking-widest leading-none">Agent Online</span>
                            </div>
                        </div>
                        <i data-lucide="more-horizontal" class="text-white/30"></i>
                    </div>
                    <div class="p-8 space-y-6 min-h-[400px]">
                        <div class="flex justify-end">
                            <div class="bg-emerald-600 text-white rounded-2xl rounded-tr-none px-5 py-3 text-sm max-w-[80%] shadow-lg">
                                Wetin be SOL price today?
                            </div>
                        </div>
                        <div class="flex justify-start">
                            <div class="bg-white/5 border border-white/10 rounded-2xl rounded-tl-none px-5 py-3 text-sm max-w-[80%]">
                                <p class="text-white/60 mb-1">SOL price na <strong>$<?= number_format($solPrice, 2) ?> USD</strong> today,</p>
                                <p class="text-white/60">wey be roughly <strong>₦<?= number_format($solNGN) ?> NGN</strong>. E don go up small! 🚀</p>
                            </div>
                        </div>
                        <div class="flex justify-end">
                            <div class="bg-emerald-600 text-white rounded-2xl rounded-tr-none px-5 py-3 text-sm max-w-[80%] shadow-lg">
                                Oya grow my SOL for me
                            </div>
                        </div>
                        <div class="flex justify-start">
                            <div class="bg-white/5 border border-white/10 rounded-2xl rounded-tl-none px-5 py-3 text-sm max-w-[80%]">
                                <p class="text-white/60 mb-1">No wahala! Based on current price I suggest:</p>
                                <p class="text-white/60">Buy at <strong class="text-emerald-400">$<?= number_format($solPrice * 0.975, 2) ?></strong> · Sell at <strong class="text-emerald-400">$<?= number_format($solPrice * 1.07, 2) ?></strong> · Stop <strong class="text-red-400">$<?= number_format($solPrice * 0.98, 2) ?></strong></p>
                                <p class="text-white/40 text-xs mt-1">Say "Oya run am" to activate 🤖</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="py-20 px-6 border-t border-white/5 mt-20">
        <div class="max-w-7xl mx-auto flex flex-col md:flex-row justify-between items-center gap-10">
            <div class="flex items-center gap-3 opacity-50 grayscale hover:grayscale-0 transition-all cursor-pointer">
                <div class="w-8 h-8 solana-gradient rounded-lg flex items-center justify-center shadow-lg">
                    <i data-lucide="zap" class="text-black w-5 h-5 fill-current"></i>
                </div>
                <span class="font-extrabold text-lg italic"><?= strtoupper(htmlspecialchars($appName)) ?></span>
            </div>
            <p class="text-white/30 text-xs font-medium tracking-widest uppercase text-center md:text-left">
                &copy; <?= date('Y') ?> Nigerian DeFi Agent &bull; Data by CoinGecko
            </p>
            <div class="flex gap-6">
                <a href="<?= $githubLink ?>" target="_blank" rel="noopener" class="text-white/40 hover:text-white transition-colors" title="GitHub">
                    <i data-lucide="github" class="w-5 h-5"></i>
                </a>
                <a href="<?= $twitterLink ?>" target="_blank" rel="noopener" class="text-white/40 hover:text-white transition-colors" title="Twitter / X">
                    <i data-lucide="twitter" class="w-5 h-5"></i>
                </a>
            </div>
        </div>
    </footer>

    <script>lucide.createIcons();</script>
</body>
</html>
