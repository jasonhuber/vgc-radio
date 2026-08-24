<?php
declare(strict_types=1);

// N7WGP radio API — configuration TEMPLATE.
// Copy to config.php (gitignored) and fill in real values.
//
//   cp config.example.php config.php
//   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # for each secret below

// ---- Required -------------------------------------------------------------

// Admin token. Mints invite codes and nothing else. This is NOT a user
// account -- there is no admin login, just this Bearer token used from curl.
const RADIO_ADMIN_TOKEN = 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET';

// Pepper mixed into session-token hashing. Changing it logs everyone out,
// which is the intended panic button if a database copy ever leaks.
const RADIO_TOKEN_PEPPER = 'CHANGE_ME_TO_A_DIFFERENT_LONG_RANDOM_SECRET';

// ---- Optional knobs -------------------------------------------------------

// Where the SQLite database lives. Default: ./radio-data next to this file.
// .htaccess denies web access to it; keep it that way.
const RADIO_DATA_DIR = __DIR__ . '/radio-data';

// Origins allowed to call this API with credentials. The page is served from
// the same origin, so this is belt-and-braces -- never widen it to '*', which
// would let any site on the internet spend a logged-in user's session.
const RADIO_ALLOWED_ORIGINS = ['https://n7wgp.com', 'https://www.n7wgp.com'];

// How long a session token stays valid, in days. Deliberately long: this is a
// field tool that must keep working for weeks without a signal, and a login
// wall between a parked operator and their channel list is a bug.
const RADIO_SESSION_DAYS = 180;

// Failed logins per IP per 15 minutes before the endpoint starts refusing.
const RADIO_LOGIN_ATTEMPTS = 10;

// ---- AI list checking -----------------------------------------------------

// The Sustav prompt proxy -- a Cloudflare Worker on the api. subdomain, NOT
// the bare sustav.dev (which is the static marketing site and 404s). Same
// convention the iOS apps use:
//   POST { systemPrompt, userPrompt, preferredProvider } -> { text, source }
const RADIO_PROXY_URL = 'https://api.sustav.dev/v1/prompt';

// Provider the proxy should route to.
//
// NOTE (checked 2026-08-24): the worker's /health reports
// configuredProviders = ["openai","anthropic"]. It does NOT reject an unknown
// provider -- it silently falls back and reports what it actually used in the
// "source" field. So asking for deepseek today yields OpenAI. This API passes
// "source" straight through to the browser and the UI shows it, so the answer
// is never quietly from a different model than the one requested. To make this
// real, add deepseek to the worker's configured providers.
const RADIO_PROXY_PROVIDER = 'deepseek';

// Per-user cap on list checks per day. Every logged-in account spends tokens
// through the proxy, so this is the abuse budget.
const RADIO_CHECKS_PER_DAY = 25;

// Largest channel list accepted for one check, to bound the prompt size.
const RADIO_CHECK_MAX_CHANNELS = 200;
