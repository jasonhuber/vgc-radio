# DeepSeek is live on the proxy — no VGC change needed

**2026-08-24.** Left by a Claude Code session working in the Sustav Dev
workspace. **Nothing in this folder was modified.**

## What this closes

`radio-api/config.php` carried this note:

> NOTE (checked 2026-08-24): the worker's /health reports
> configuredProviders = ["openai","anthropic"]. It does NOT reject an unknown
> provider -- it silently falls back and reports what it actually used in the
> "source" field. So asking for deepseek today yields OpenAI. [...] To make this
> real, add deepseek to the worker's configured providers.

That has now been done. **`RADIO_PROXY_PROVIDER = 'deepseek'` now genuinely
returns DeepSeek.** You can delete that caveat from the comment in
`config.php` — I left it alone rather than edit your file.

## What changed, and where

Nothing here. The fix was entirely in the Cloudflare Worker behind
`api.sustav.dev`, which lives at:

    Sustav Dev/Pogodi/ios/cloudflare-worker    (worker name: pogodi-ai-proxy)

The worker's source already implemented DeepSeek in full — it was uncommitted
local work that had never been deployed, so production was still running an
older build with no `deepseek` branch at all. Two things were needed:

1. `wrangler secret put DEEPSEEK_API_KEY` — DeepSeek is gated on that key being
   present (`availableProviders()`); without it the provider is dropped from the
   chain and the request falls through to OpenAI.
2. `wrangler deploy` — to ship the DeepSeek code path itself.

`DEFAULT_PROVIDER` was pinned from `"automatic"` to `"openai"` at the same time.
`availableProviders()` lists DeepSeek first, so leaving it on `"automatic"`
would have moved every app in the portfolio to DeepSeek at once. VGC asks for
`deepseek` by name, so it gets DeepSeek; everything else stays on OpenAI.

## Verified live

    /health -> configuredProviders: ["deepseek","openai","anthropic"]
               messagesProviders:   ["deepseek","anthropic"]
               defaultProvider:     "openai"

    POST /v1/prompt  preferredProvider=deepseek  -> {"source":"deepseek"}
    POST /v1/prompt  (no preference)             -> {"source":"openai"}

Models in play: `deepseek-v4-flash` standard, `deepseek-v4-pro` for callers
sending `x-sustav-tier: quality`. DeepSeek is reached over its
Anthropic-compatible surface (`api.deepseek.com/anthropic/v1/messages`).

## Worth knowing

- Failover is unchanged and still real: if DeepSeek errors, the request falls
  through to OpenAI then Anthropic. `source` in the response always reports who
  actually answered, and `radio-api/index.php` already passes that through to
  the browser — so a fallback will be visible, not silent.
- The `requested` vs `source` pair that index.php returns (~line 647) is now
  the right thing to watch. If they diverge in normal use, DeepSeek is erroring.
