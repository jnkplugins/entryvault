<?php
/**
 * LicensorPro Client SDK
 *
 * Drop-in WordPress library for license activation, validation,
 * and auto-updates against a self-hosted LicensorPro instance.
 *
 * Usage:
 *   require_once __DIR__ . '/licensorpro-client/licensorpro-client.php';
 *   $client = new LicensorPro_Client([
 *       'api_url'        => 'https://your-licensor.example',
 *       'product_slug'   => 'your-plugin',
 *       'plugin_file'    => __FILE__,
 *       'plugin_version' => '1.0.0',
 *       'plugin_name'    => 'Your Plugin',
 *       'text_domain'    => 'your-plugin',
 *   ]);
 *   $client->init();
 *
 * @package LicensorPro
 */

if (!defined('ABSPATH')) {
    exit;
}

// The declaration MUST stay inside this `if`. Several plugins bundle identical
// copies of this file. A top-level `class` is bound when the file is compiled,
// and OPcache replays that binding every time the cached file loads, before
// any line runs, so an early `return` guard cannot stop the second copy and
// the site dies with "Cannot redeclare class". A class declared inside a block
// is only ever bound at runtime, which is what makes the guard real.
// Regression test: LicensorPro\Tests\Feature\SdkMultiCopyOpcacheTest.
if (!class_exists('LicensorPro_Client', false)) {

class LicensorPro_Client {

    /**
     * Base64 Ed25519 public key of the LicensorPro server that signs license
     * verdicts.
     *
     * Committed rather than injected at build time, which the previous comment
     * promised and nothing ever did — so every shipped build had verification
     * silently disabled while the marketing pages claimed otherwise. There is
     * nothing to protect here: it is a PUBLIC key, and the same value is already
     * in version control in Support/LicenseKeyring.php as 'jnk-2026'.
     *
     * Empty string disables verification. Overridable via the
     * `licensorpro_signing_pubkey` filter, which is how a self-hosted operator
     * running their own LicensorPro points this at their own key.
     */
    const SIGNING_PUBKEY = '24G03ne7LHrSBvLO9tJDLEYzsWm54f6o4UY6uMft/r4=';

    /**
     * Bundled-SDK version. Every JnK plugin ships a copy of this class; when two
     * are active, PHP loads whichever copy is required first (the class_exists
     * guard above blocks the redeclare fatal). Keep this file byte-identical
     * across all plugins so the "first one wins" copy is always API-complete —
     * a skew here is what causes "Call to undefined method" fatals. Bump on any
     * change and re-propagate to every plugin's pro/licensorpro-client/.
     * Audit sync with: LicensorPro_Client::SDK_VERSION.
     */
    const SDK_VERSION = '1.8.0';

    /** @var array */
    private $opts;

    /** @var string */
    private $api_url;

    /** @var string */
    private $slug;

    /** @var string */
    private $plugin_file;

    /** @var string */
    private $plugin_basename;

    /** @var string */
    private $version;

    /** @var string */
    private $plugin_name;

    /** @var string */
    private $text_domain;

    /** @var string */
    private $menu_slug;

    /** @var string */
    private $opt_key;

    /** @var string */
    private $opt_license;

    /** @var string */
    private $opt_remote;

    /** @var string */
    private $tr_validate;

    /** @var string */
    private $tr_update;

    /** @var string */
    private $channel = 'stable';

    /** @var int */
    private $grace_days = 7;

    /** @var int Days Pro admin tools keep working after a licence expires. */
    private $expiry_grace_days = 30;

    /** @var string */
    private $opt_lapse;

    /** @var string */
    private $opt_ever;

    /** @var string */
    private $cron_hook;

    /** @var bool This build ships the paid features (false: a free build that only self-updates). */
    private $pro_build = true;

    /**
     * @var bool The plugin SAID it is a Pro build (1.8.0+ callers pass pro_build).
     *
     * Older callers pass nothing, and the first SDK copy loaded serves every JnK
     * plugin on the site, so this class runs for plugins built before it
     * existed, free builds among them. Anything that would harm a free build if
     * guessed wrong (blocking its updates, a "Pro is installed" notice) waits
     * for an explicit true.
     */
    private $pro_build_declared = false;

    public function __construct(array $opts) {
        $defaults = [
            'api_url'            => 'https://api.jnkplugins.com',
            'product_slug'       => '',
            'plugin_file'        => '',
            'plugin_version'     => '0.0.0',
            'plugin_name'        => 'Plugin',
            'text_domain'        => 'licensorpro',
            'menu_slug'          => '',
            'update_channel'     => 'stable',   // stable|beta|rc|dev
            'offline_grace_days' => 7,
            // After expiry, Pro admin tools keep working this many days. See status().
            'expiry_grace_days'  => 30,
            // Expiry / revoke notices in wp-admin. Builds that use the SDK only for
            // updates (free builds) turn them off: they have no Pro to lose.
            'licence_notices'    => true,
            // Where "Renew" points. Defaults to the customer account on api_url.
            'renew_url'          => '',
            // ---- Plugin wiring (1.8.0). Everything a plugin used to hand-roll
            // around this class, so every plugin behaves the same. All optional.
            //
            // false for a free build that ships this class only to update itself:
            // its licence is a free one, it has no Pro to switch on, and it must
            // not show "enter your Pro key" prompts.
            'pro_build'          => true,
            // An action the plugin fires on its settings screen; the license card
            // renders there. Empty: the plugin calls render_settings_page() itself.
            'card_hook'          => '',
            // The admin URL of that screen, for the Plugins-row link and notices.
            'settings_url'       => '',
            // A Plugins-row action link key the plugin adds for upselling, removed
            // in a Pro build ("Upgrade" next to "Activate license" reads as a bug).
            'upsell_link'        => '',
            // Shown under the license card.
            'support_url'        => '',
            // Where buyers download Pro and manage sites. Defaults to the account.
            'account_url'        => '',
        ];
        $this->opts        = array_merge($defaults, $opts);
        /**
         * Filter: licensorpro_api_url. A self-hosted operator runs their own
         * LicensorPro, and the signing key already has the same escape hatch.
         */
        $this->api_url     = untrailingslashit(
            (string) apply_filters('licensorpro_api_url', $this->opts['api_url'], sanitize_key((string) $this->opts['product_slug']))
        );
        $this->slug        = sanitize_key((string) $this->opts['product_slug']);
        $this->plugin_file = (string) $this->opts['plugin_file'];
        $this->version     = (string) $this->opts['plugin_version'];
        $this->plugin_name = (string) $this->opts['plugin_name'];
        $this->text_domain = (string) $this->opts['text_domain'];
        $this->menu_slug   = $this->opts['menu_slug'] ? sanitize_key((string) $this->opts['menu_slug'])
                                                      : 'licensorpro-' . $this->slug;
        $allowed_channels   = ['stable', 'beta', 'rc', 'dev'];
        $chan               = strtolower((string) $this->opts['update_channel']);
        $this->channel      = in_array($chan, $allowed_channels, true) ? $chan : 'stable';
        $this->grace_days   = max(0, (int) $this->opts['offline_grace_days']);
        $this->expiry_grace_days = max(0, (int) apply_filters(
            'licensorpro_expiry_grace_days', (int) $this->opts['expiry_grace_days'], sanitize_key((string) $this->opts['product_slug'])
        ));

        $this->plugin_basename = $this->plugin_file ? plugin_basename($this->plugin_file) : '';
        $this->pro_build       = (bool) $this->opts['pro_build'];
        $this->pro_build_declared = array_key_exists('pro_build', $opts) && (bool) $opts['pro_build'];

        $this->opt_key      = 'licensorpro_' . $this->slug . '_key';
        $this->opt_license  = 'licensorpro_' . $this->slug . '_license';
        $this->opt_remote   = 'licensorpro_' . $this->slug . '_remote';
        $this->tr_validate  = 'licensorpro_' . $this->slug . '_validate';
        $this->tr_update    = 'licensorpro_' . $this->slug . '_update';
        $this->opt_lapse    = 'licensorpro_' . $this->slug . '_lapse';
        $this->opt_ever     = 'licensorpro_' . $this->slug . '_ever';
        $this->cron_hook    = 'licensorpro_' . $this->slug . '_daily_check';
    }

    public function init() {
        // Admin UI.
        add_action('admin_menu', [$this, 'register_menu']);
        // AJAX handlers.
        add_action('wp_ajax_licensorpro_' . $this->slug . '_activate',   [$this, 'ajax_activate']);
        add_action('wp_ajax_licensorpro_' . $this->slug . '_deactivate', [$this, 'ajax_deactivate']);
        add_action('wp_ajax_licensorpro_' . $this->slug . '_check',     [$this, 'ajax_check']);
        // One question on the way out. See deactivation_prompt().
        add_action('wp_ajax_licensorpro_' . $this->slug . '_feedback', [$this, 'ajax_feedback']);
        add_action('admin_footer-plugins.php', [$this, 'deactivation_prompt']);
        // Update injection.
        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        // Read-time guard: never show an update that is not newer than the files on disk.
        add_filter('site_transient_update_plugins', [$this, 'drop_stale_update']);
        add_filter('plugins_api', [$this, 'plugins_api'], 10, 3);
        // Clear update transient when license changes.
        add_action('upgrader_process_complete', [$this, 'clear_cache_after_upgrade'], 10, 2);
        // Daily re-check, so an expiry or refund is noticed without anyone clicking
        // "Check". Scheduled on every request when missing, so a lost cron event
        // comes back by itself instead of silently ending all checks.
        add_action($this->cron_hook, [$this, 'scheduled_check']);
        add_action('init', [$this, 'ensure_schedule']);
        if ($this->plugin_file !== '' && function_exists('register_deactivation_hook')) {
            register_deactivation_hook($this->plugin_file, [$this, 'unschedule']);
        }
        if (!empty($this->opts['licence_notices'])) {
            add_action('admin_notices', [$this, 'render_licence_notice']);
            if ($this->pro_build_declared) {
                add_action('admin_notices', [$this, 'render_unlicensed_notice']);
                add_action('admin_notices', [$this, 'render_activated_notice']);
            }
        }
        if ((string) $this->opts['card_hook'] !== '') {
            add_action((string) $this->opts['card_hook'], [$this, 'render_settings_page']);
        }
        if ($this->pro_build && $this->plugin_basename !== '' && (string) $this->opts['settings_url'] !== '') {
            add_filter('plugin_action_links_' . $this->plugin_basename, [$this, 'plugin_action_links'], 20);
        }

        // WP-CLI: register `wp licensorpro <slug> <subcommand>` if available.
        if (defined('WP_CLI') && WP_CLI) {
            $slug = $this->slug;
            $self = $this;
            \WP_CLI::add_command('licensorpro ' . $slug, function ($args, $assoc_args) use ($self) {
                $sub = isset($args[0]) ? (string) $args[0] : '';
                switch ($sub) {
                    case 'status':
                        $info = $self->get_license();
                        if (!$info) {
                            \WP_CLI::log('not licensed');
                            return;
                        }
                        \WP_CLI::log(json_encode($info, JSON_PRETTY_PRINT));
                        return;
                    case 'activate':
                        $key = isset($args[1]) ? (string) $args[1] : '';
                        if ($key === '') {
                            \WP_CLI::error('usage: wp licensorpro <slug> activate <license_key>');
                        }
                        $r = $self->activate($key);
                        if (!empty($r['ok'])) {
                            \WP_CLI::success('activated');
                        } else {
                            \WP_CLI::error($r['message'] ?? ($r['code'] ?? 'activation failed'));
                        }
                        return;
                    case 'deactivate':
                        $r = $self->deactivate();
                        \WP_CLI::success($r['ok'] ? 'deactivated' : ($r['message'] ?? 'failed'));
                        return;
                    case 'check':
                        $r = $self->check_license();
                        \WP_CLI::log(json_encode($r, JSON_PRETTY_PRINT));
                        return;
                    default:
                        \WP_CLI::error('subcommand must be one of: status, activate, deactivate, check');
                }
            });
        }
    }

    // ---- Signature verification -------------------------------------------

    /** Resolve the signing public key (constant, overridable via filter). */
    private function signing_pubkey() {
        return (string) apply_filters('licensorpro_signing_pubkey', (string) self::SIGNING_PUBKEY);
    }

    /**
     * Whether a valid signature is hard-required (fail-closed).
     *
     * Now TRUE. Until this flipped, a verdict with the signature stripped
     * entirely was accepted — so a cracked build could simply delete it and the
     * tamper detection never ran. Detecting forgery while ignoring deletion is
     * not much of a defence.
     *
     * The rollout hazard this used to guard against was an install still holding
     * a verdict cached before the server began signing: it would read as
     * unlicensed until its next successful validate. Checked before flipping —
     * every plugin licence issued belongs to us, activated only on our own test
     * sites, so there is nobody to strand.
     *
     * Still filterable: a self-hosted operator running their own LicensorPro
     * without signing keys configured turns it off here.
     */
    private function require_signature() {
        return (bool) apply_filters('licensorpro_require_signature', true);
    }

    /**
     * Site hash matching the server's LicenseService::siteHash(): sha256 of the
     * lowercased host with a leading "www." stripped.
     */
    private function client_site_hash($url) {
        $host = parse_url((string) $url, PHP_URL_HOST);
        if (!$host) {
            $host = (string) $url;
        }
        $host = preg_replace('/^www\./', '', strtolower(trim((string) $host)));
        return hash('sha256', (string) $host);
    }

    /**
     * Classify a server/cached response's signature relative to the entitlement body
     * it carries:
     *   'absent'  — no public key provisioned, or no signature present (cannot judge)
     *   'valid'   — signature verifies AND covers exactly this license body + this site
     *   'invalid' — signature present but forged, mismatched, or for another site/body
     *
     * The body-match check is essential: without it an attacker could staple a valid
     * signature for a cheap license onto a response body claiming a higher plan.
     */
    private function signature_status($resp) {
        $pk_b64 = $this->signing_pubkey();
        if ($pk_b64 === '' || !function_exists('sodium_crypto_sign_verify_detached')) {
            return 'absent';
        }
        if (empty($resp['signature']) || !is_array($resp['signature'])) {
            return 'absent';
        }
        $sig           = $resp['signature'];
        $pk            = base64_decode($pk_b64, true);
        $payload       = isset($sig['payload']) ? base64_decode((string) $sig['payload'], true) : false;
        $raw_signature = isset($sig['signature']) ? base64_decode((string) $sig['signature'], true) : false;

        if ($pk === false || $payload === false || $raw_signature === false
            || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($raw_signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return 'invalid';
        }
        if (!sodium_crypto_sign_verify_detached($raw_signature, $payload, $pk)) {
            return 'invalid';
        }
        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            return 'invalid';
        }
        // Must be signed for THIS site.
        if (!hash_equals($this->client_site_hash(home_url('/')), (string) (isset($claims['site_hash']) ? $claims['site_hash'] : ''))) {
            return 'invalid';
        }
        // ...and must vouch for exactly the license body the plugin will trust.
        $lic = (isset($resp['license']) && is_array($resp['license'])) ? $resp['license'] : [];
        foreach (['key', 'status', 'plan', 'plan_id', 'product_id', 'expires_at'] as $f) {
            $claimed = isset($claims[$f]) ? $claims[$f] : null;
            $actual  = isset($lic[$f]) ? $lic[$f] : null;
            if ((string) $claimed !== (string) $actual) {
                return 'invalid';
            }
        }

        // Entitlements decide what this plugin unlocks, so the body the plugin
        // reads must be the body that was signed. Without this an attacker could
        // leave the claims untouched and rewrite license.entitlements beside
        // them — the signature would still verify and the plugin would read the
        // rewritten copy.
        //
        // Compared canonically, not with a string cast: these are arrays, and
        // (string) on an array is "Array" — which would compare equal to every
        // other array and verify nothing at all.
        $claimedEnt = isset($claims['entitlements']) && is_array($claims['entitlements'])
            ? $claims['entitlements'] : array();
        $actualEnt = isset($lic['entitlements']) && is_array($lic['entitlements'])
            ? $lic['entitlements'] : array();
        if (self::canonical_json($claimedEnt) !== self::canonical_json($actualEnt)) {
            return 'invalid';
        }

        // Same for is_free (1.8.0): the server signs it on free licences only, so
        // deleting it from a stored verdict must not turn a free key into Pro.
        if ((int) !empty($claims['is_free']) !== (int) !empty($lic['is_free'])) {
            return 'invalid';
        }

        return 'valid';
    }

    /**
     * Whether an "ok" verdict is acceptable under the signature policy. A present but
     * INVALID signature is always rejected (tamper/forgery detection). A missing
     * signature is accepted unless signatures are hard-required.
     */
    private function signature_acceptable($resp) {
        $status = $this->signature_status($resp);
        if ($status === 'invalid') {
            return false;
        }
        if ($status === 'absent' && $this->require_signature()) {
            return false;
        }
        return true;
    }

    // ---- Public API --------------------------------------------------------

    /**
     * May the site owner use Pro tools in wp-admin right now?
     *
     * True while the licence is active and during the grace period after it
     * expires. False once that runs out, and straight away after a refund or a
     * revoke. This is the question every "Pro admin" gate should ask.
     *
     * Until SDK 1.7.0 this returned the last "OK" the server ever gave, forever:
     * a refusal never replaced it and expires_at was never read, so an expired,
     * refunded or revoked licence kept Pro on indefinitely.
     *
     * NOT the question for anything visitors see. That is was_ever_licensed().
     */
    public function is_licensed() {
        $state = $this->status()['state'];
        return $state === 'active' || $state === 'grace';
    }

    /**
     * Has this site ever held a valid Pro licence?
     *
     * The gate for everything that runs on the public site: forms with Pro fields,
     * payments, integrations fired on submit, front-end displays. A forgotten
     * renewal must never break a visitor's page, so once a site has been licensed
     * those keep running whatever happens to the licence later. Only the admin
     * tools (is_licensed()) stop.
     *
     * Survives "Deactivate this site" on purpose, for the same reason.
     */
    public function was_ever_licensed() {
        $ever = (bool) get_option($this->opt_ever);
        // Installs licensed before 1.7.0 have a stored verdict but no marker yet.
        if (!$ever && $this->valid_verdict($this->stored_verdict()) && !self::verdict_is_free($this->stored_verdict())) {
            update_option($this->opt_ever, time(), false);
            $ever = true;
        }
        /** Filter: licensorpro_was_ever_licensed. Tests use it to simulate a lapsed site. */
        return (bool) apply_filters('licensorpro_was_ever_licensed', $ever, $this->slug);
    }

    /**
     * Where the licence stands, for gates and for the notices.
     *
     *   state      'unlicensed' | 'active' | 'grace' | 'locked' | 'revoked'
     *              grace   = expired, Pro admin tools still on until grace_ends
     *              locked  = expired and the grace period is over
     *              revoked = refunded, cancelled, blocklisted, or this site is
     *                        no longer activated: admin tools lock straight away
     *   expired_at unix time the licence expired (grace / locked), else 0
     *   grace_ends unix time Pro admin tools lock (grace / locked), else 0
     *   days_left  whole days until grace_ends, rounded up (grace only)
     *   code       the server's reason, for 'revoked'
     *
     *   free       true when the stored licence is a free one (1.8.0). In a Pro
     *              build the state is then 'unlicensed'.
     *
     * @return array{state:string,expired_at:int,grace_ends:int,days_left:int,code:string,free:bool}
     */
    public function status() {
        /**
         * Filter: licensorpro_status. The computed status; tests use it to put a
         * site in any state without a licence server.
         */
        $st = apply_filters('licensorpro_status', $this->compute_status(), $this->slug);
        return is_array($st) && isset($st['state']) ? $st : $this->compute_status();
    }

    private function compute_status() {
        $out   = ['state' => 'unlicensed', 'expired_at' => 0, 'grace_ends' => 0, 'days_left' => 0, 'code' => '', 'free' => false];
        $lapse = get_option($this->opt_lapse, null);
        $lapse = is_array($lapse) ? $lapse : null;

        if ($lapse && ($lapse['kind'] ?? '') === 'revoked') {
            $out['state'] = 'revoked';
            $out['code']  = (string) ($lapse['code'] ?? '');
            return $out;
        }

        // Grace is for a licence that really was valid. A missing or tampered
        // verdict is simply unlicensed, whatever the lapse record says.
        $info = $this->get_license();
        if (!$this->valid_verdict($info)) {
            return $out;
        }
        // A free licence (1.8.0) only buys updates of the free build. In a Pro
        // build it is no licence at all: until then any free key, which anyone
        // can download, switched every Pro tool on.
        if (self::verdict_is_free($info)) {
            $out['free'] = true;
            if ($this->pro_build) {
                return $out;
            }
        }

        $now     = time();
        $expires = 0;
        if (is_array($info) && !empty($info['license']['expires_at'])) {
            $expires = (int) strtotime((string) $info['license']['expires_at']);
        }
        $expired_locally = $expires > 0 && $expires <= $now;

        if (!$lapse && !$expired_locally) {
            $out['state'] = 'active';
            return $out;
        }

        if (!$lapse) {
            // First time this site notices the expiry. The grace period runs from
            // the later of the expiry date and today, so a site that was already
            // long expired when it got this version is not locked on the spot.
            $lapse = ['kind' => 'expired', 'code' => 'expired', 'since' => $now, 'expires_at' => $expires];
            update_option($this->opt_lapse, $lapse, false);
        }

        // The expiry date is when it lapsed, unless the server said "expired"
        // before that date (the term was shortened on the server): then it is
        // the day this site was told.
        $since      = (int) ($lapse['since'] ?? $now);
        $expired_at = (int) ($lapse['expires_at'] ?? 0) ?: $expires;
        if ($expired_at <= 0 || $expired_at > $since) {
            $expired_at = $since;
        }
        $start      = max($expired_at, $since);
        $ends       = $start + $this->expiry_grace_days * DAY_IN_SECONDS;

        $out['expired_at'] = $expired_at;
        $out['grace_ends'] = $ends;
        $out['code']       = (string) ($lapse['code'] ?? 'expired');
        if ($now < $ends) {
            $out['state']     = 'grace';
            $out['days_left'] = (int) ceil(($ends - $now) / DAY_IN_SECONDS);
        } else {
            $out['state'] = 'locked';
        }
        return $out;
    }

    /** A stored "OK" verdict with a licence body and an acceptable signature. */
    private function valid_verdict($info) {
        return is_array($info) && !empty($info['ok']) && !empty($info['license'])
            // Re-verify the signature on the cached verdict so a tampered wp_options
            // row (or a forged one, when signatures are required) is not trusted.
            && $this->signature_acceptable($info);
    }

    private function stored_verdict() {
        return get_option($this->opt_license, null) ?: null;
    }

    /**
     * Server refusal codes and what they mean here. Anything not listed (an
     * unknown key, a malformed answer) changes nothing: only a definite, signed
     * "no" may lock the admin tools.
     *
     * Expiry and a failed renewal payment get the grace period. A refund, a
     * manual revoke or a blocklisting do not: those lock the admin tools at once,
     * or a refund would buy a free month of Pro.
     */
    private static function lapse_kind($code) {
        switch ((string) $code) {
            case 'expired':
            case 'license_expired':
            case 'license_suspended':
                return 'expired';
            case 'license_cancelled':
            case 'license_revoked':
            case 'license_refunded':
            case 'blocklisted':
            case 'site_not_activated':
            case 'invalid_license':
                return 'revoked';
        }
        return '';
    }

    /**
     * Whether a refusal really came from our server, for this key and this site.
     *
     * A refusal is signed like an approval (LicenseService::denial). Acting on an
     * unsigned one would let anything that can answer the request (a captive
     * portal, a hijacked DNS entry) lock a paying customer out of their tools.
     */
    private function denial_acceptable($resp, $key) {
        $pk_b64 = $this->signing_pubkey();
        $sig    = isset($resp['signature']) && is_array($resp['signature']) ? $resp['signature'] : null;
        if ($pk_b64 === '' || !$sig || !function_exists('sodium_crypto_sign_verify_detached')) {
            // Nothing to check against. Trust it only where signatures are optional.
            return !$this->require_signature();
        }
        $pk      = base64_decode($pk_b64, true);
        $payload = isset($sig['payload']) ? base64_decode((string) $sig['payload'], true) : false;
        $raw     = isset($sig['signature']) ? base64_decode((string) $sig['signature'], true) : false;
        if ($pk === false || $payload === false || $raw === false
            || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($raw, $payload, $pk)) {
            return false;
        }
        $claims = json_decode($payload, true);
        if (!is_array($claims)) {
            return false;
        }
        return (string) ($claims['ok'] ?? '') === 'false'
            && (string) ($claims['code'] ?? '') === (string) ($resp['code'] ?? '')
            && hash_equals((string) $key, (string) ($claims['key'] ?? ''))
            && hash_equals($this->client_site_hash(home_url('/')), (string) ($claims['site_hash'] ?? ''))
            && (int) ($claims['exp'] ?? 0) >= time();
    }

    /** Remember a definite refusal. The stored "OK" stays, as the record of the past. */
    private function record_lapse($code) {
        $kind = self::lapse_kind($code);
        if ($kind === '') {
            return;
        }
        $prev = get_option($this->opt_lapse, null);
        if (is_array($prev) && ($prev['kind'] ?? '') === $kind && ($prev['code'] ?? '') === $code) {
            return; // Keep the original date: the grace period must not restart daily.
        }
        $expires = 0;
        $info    = $this->stored_verdict();
        if (is_array($info) && !empty($info['license']['expires_at'])) {
            $expires = (int) strtotime((string) $info['license']['expires_at']);
        }
        $since = (is_array($prev) && ($prev['kind'] ?? '') === 'expired' && $kind === 'expired')
            ? (int) $prev['since'] : time();
        update_option($this->opt_lapse, [
            'kind'       => $kind,
            'code'       => (string) $code,
            'since'      => $since,
            'expires_at' => $expires,
        ], false);
        delete_transient($this->tr_validate);
    }

    // ---- Daily check ---------------------------------------------------------

    public function ensure_schedule() {
        if ((string) get_option($this->opt_key, '') === '') {
            return;
        }
        if (!wp_next_scheduled($this->cron_hook)) {
            // Spread sites over the day so they don't all call in at midnight.
            wp_schedule_event(time() + wp_rand(60, DAY_IN_SECONDS), 'daily', $this->cron_hook);
        }
    }

    public function unschedule() {
        wp_clear_scheduled_hook($this->cron_hook);
    }

    public function scheduled_check() {
        if ((string) get_option($this->opt_key, '') !== '') {
            $this->check_license();
        }
    }

    /**
     * Is the stored licence a free one? Free licences come with free builds,
     * from the public download page, and only fetch updates of the free build.
     * Read from the verdict, whose is_free the signature check has vouched for.
     */
    public function is_free_licence() {
        $info = $this->get_license();
        return $this->valid_verdict($info) && self::verdict_is_free($info);
    }

    /** Has a key been entered on this site (any key, free or Pro)? */
    public function has_key() {
        return (string) get_option($this->opt_key, '') !== '';
    }

    /** Is this the Pro build (the one with paid features), as the plugin said? */
    public function is_pro_build() {
        return $this->pro_build;
    }

    /** Where the buyer downloads Pro and manages their sites. */
    public function account_url() {
        $url = (string) $this->opts['account_url'];
        return $url !== '' ? $url : $this->api_url . '/account/licenses';
    }

    /** @param mixed $info */
    private static function verdict_is_free($info) {
        return is_array($info) && !empty($info['license']['is_free']);
    }

    public function renew_url() {
        $url = (string) $this->opts['renew_url'];
        return $url !== '' ? $url : $this->api_url . '/account';
    }

    // ---- Plugins screen ------------------------------------------------------

    /**
     * The Plugins-row link for a Pro build: Manage, Renew or Activate license,
     * pointing at the license card. Replaces the plugin's upsell link, which on a
     * Pro build that only lacks a key read as "buy it again".
     *
     * @param array<string,string> $links
     * @return array<string,string>
     */
    public function plugin_action_links($links) {
        $links = (array) $links;
        $url   = esc_url((string) $this->opts['settings_url']);
        $td    = $this->text_domain;
        $key   = 'licensorpro_' . $this->slug . '_license';
        if ($this->is_licensed()) {
            $links[$key] = '<a href="' . $url . '">' . esc_html__('Manage license', $td) . '</a>';
        } else {
            $label = $this->has_key() && !$this->is_free_licence()
                ? __('Renew license', $td)
                : __('Activate license', $td);
            $links[$key] = '<a href="' . $url . '" style="color:#b45309;font-weight:600;">' . esc_html($label) . '</a>';
        }
        $upsell = (string) $this->opts['upsell_link'];
        if ($upsell !== '') {
            unset($links[$upsell]);
        }
        return $links;
    }

    // ---- Admin notice --------------------------------------------------------

    /**
     * "Pro is on", once, after a Pro key activates. Until 1.8.0 the only signs
     * were indirect: upgrade links vanishing, tabs that started working.
     */
    public function render_activated_notice() {
        if (!$this->pro_build_declared || !current_user_can('manage_options')) {
            return;
        }
        $flag = 'licensorpro_' . $this->slug . '_activated';
        if (!get_transient($flag) || !$this->is_licensed()) {
            return;
        }
        delete_transient($flag);
        $msg = sprintf(
            /* translators: %s: plugin name */
            __('<strong>%s Pro is on.</strong> Thank you! Every Pro tool is switched on for this site, and updates come to your dashboard.', $this->text_domain),
            esc_html($this->plugin_name)
        );
        echo '<div class="notice notice-success is-dismissible"><p>' . wp_kses($msg, ['strong' => []]) . '</p></div>';
    }

    /** Is the current admin screen one where license notices belong? */
    private function notice_screen_here() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';
        return in_array($id, ['dashboard', 'plugins', 'update-core'], true)
            || ($this->slug !== '' && strpos($id, $this->slug) !== false)
            || ($this->menu_slug !== '' && strpos($id, $this->menu_slug) !== false);
    }

    /** Is this request the screen the license card is on? */
    private function on_settings_screen() {
        $url = (string) $this->opts['settings_url'];
        if ($url === '' || !isset($_GET['page'])) {
            return false;
        }
        $query = (string) wp_parse_url($url, PHP_URL_QUERY);
        parse_str($query, $args);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- screen routing only.
        return isset($args['page']) && (string) $args['page'] === sanitize_key(wp_unslash((string) $_GET['page']));
    }

    /**
     * "Pro is installed, but no Pro license is active." A Pro build uploaded over
     * the free one ends on WordPress's "Plugin updated" screen with nothing that
     * says where the key goes. Not shown on the license card's own screen, and
     * hidden in place the moment a key activates there.
     */
    public function render_unlicensed_notice() {
        if (!$this->pro_build_declared || !current_user_can('manage_options')) {
            return;
        }
        $st = $this->status();
        if ($st['state'] !== 'unlicensed' || $this->on_settings_screen()) {
            return;
        }
        $here = $this->notice_screen_here();
        if (!(bool) apply_filters('licensorpro_show_unlicensed_notice', $here, $this->slug)) {
            return;
        }
        $td   = $this->text_domain;
        $url  = (string) $this->opts['settings_url'];
        $msg  = sprintf(
            /* translators: %s: plugin name */
            __('<strong>%s Pro is installed, but no Pro license is active on this site.</strong> Enter your license key to switch on the Pro tools.', $td),
            esc_html($this->plugin_name)
        );
        echo '<div class="notice notice-warning licensorpro-unlicensed-notice" data-licensorpro="' . esc_attr($this->slug) . '"><p>'
            . wp_kses($msg, ['strong' => []]);
        if ($url !== '') {
            echo ' <a href="' . esc_url($url) . '">' . esc_html__('Enter license key', $td) . '</a>';
        }
        echo ' &middot; <a href="' . esc_url($this->account_url()) . '" target="_blank" rel="noopener">'
            . esc_html__('Find your key in your account', $td) . '</a></p></div>';
    }

    /**
     * The expiry notice. Shown to admins on the dashboard, the Plugins and
     * Updates screens and the plugin's own screens, not on every admin page.
     */
    public function render_licence_notice() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $st = $this->status();
        if (!in_array($st['state'], ['grace', 'locked', 'revoked'], true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $id     = $screen ? (string) $screen->id : '';
        $here   = $this->notice_screen_here();
        if (!(bool) apply_filters('licensorpro_show_licence_notice', $here, $this->slug, $id)) {
            return;
        }

        $td    = $this->text_domain;
        $name  = $this->plugin_name;
        $fmt   = get_option('date_format') ?: 'j F Y';
        $renew = '<a href="' . esc_url($this->renew_url()) . '" target="_blank" rel="noopener">'
            . esc_html__('Renew your license', $td) . '</a>';

        if ($st['state'] === 'grace') {
            $class = 'notice-warning';
            $msg   = sprintf(
                /* translators: 1: plugin name, 2: expiry date, 3: lock date, 4: days left */
                __('<strong>Your %1$s Pro license expired on %2$s.</strong> Pro keeps working as normal until %3$s (%4$d days left). Updates, including security fixes, and email support have stopped. After %3$s, everything you already set up keeps working and your visitors won\'t notice a thing, but the Pro tools in your dashboard will lock.', $td),
                esc_html($name),
                esc_html(date_i18n($fmt, $st['expired_at'])),
                esc_html(date_i18n($fmt, $st['grace_ends'])),
                (int) $st['days_left']
            );
        } elseif ($st['state'] === 'locked') {
            $class = 'notice-error';
            $msg   = sprintf(
                /* translators: 1: plugin name, 2: expiry date */
                __('<strong>%1$s Pro tools are locked because your license expired on %2$s.</strong> Your site, and everything visitors see, still works. Renew to unlock the Pro tools and get updates, including security fixes, again.', $td),
                esc_html($name),
                esc_html(date_i18n($fmt, $st['expired_at']))
            );
        } elseif ($st['code'] === 'site_not_activated') {
            $class = 'notice-error';
            $msg   = sprintf(
                /* translators: %s: plugin name */
                __('<strong>This site is no longer activated on your %s Pro license.</strong> The Pro tools in your dashboard are locked until you activate it again. Everything visitors see keeps working.', $td),
                esc_html($name)
            );
        } else {
            $class = 'notice-error';
            $msg   = sprintf(
                /* translators: %s: plugin name */
                __('<strong>Your %s Pro license is no longer active.</strong> It was refunded or cancelled, so the Pro tools in your dashboard are locked and updates have stopped. Everything visitors see keeps working.', $td),
                esc_html($name)
            );
        }
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . wp_kses($msg, ['strong' => []]) . ' ' . $renew . '</p></div>';
    }

    /**
     * Key-sorted JSON, recursively, so two equal maps always produce identical
     * bytes regardless of the order the server happened to serialise them in.
     */
    private static function canonical_json($value) {
        if (is_array($value)) {
            $isList = array_keys($value) === range(0, count($value) - 1);
            if (!$isList) {
                ksort($value);
            }
            foreach ($value as $k => $v) {
                $value[$k] = self::canonical_json_value($v);
            }
        }
        return json_encode($value);
    }

    private static function canonical_json_value($v) {
        if (!is_array($v)) {
            return $v;
        }
        $isList = array_keys($v) === range(0, count($v) - 1);
        if (!$isList) {
            ksort($v);
        }
        foreach ($v as $k => $vv) {
            $v[$k] = self::canonical_json_value($vv);
        }
        return $v;
    }

    /**
     * What this licence grants, as defined on the plan by the vendor.
     *
     * This is the point of SDK 1.5.0. Before it, the server sent a plan *slug*
     * and every plugin kept its own copy of the tier table:
     *
     *     if ($lp->plan() === 'pro') { $seats = 5; }
     *
     * which means a pricing change is a plugin release, and a plan the plugin
     * has never heard of grants nothing at all. Ask instead:
     *
     *     $seats = $lp->limit('seats');          // 0 means unlimited
     *     if ($lp->can('white_label')) { ... }
     *
     * Empty when unlicensed, or when the vendor has defined none — never
     * invented defaults, because granting what nobody granted is worse than
     * granting nothing.
     *
     * @return array
     */
    public function entitlements() {
        if (!$this->is_licensed()) {
            return array();
        }
        $info = $this->get_license();
        $ent = isset($info['license']['entitlements']) ? $info['license']['entitlements'] : null;
        return is_array($ent) ? $ent : array();
    }

    /** Boolean entitlement, e.g. $lp->can('white_label'). */
    public function can($feature) {
        $ent = $this->entitlements();
        return !empty($ent[$feature]);
    }

    /**
     * Numeric entitlement, e.g. $lp->limit('seats').
     *
     * By convention 0 means UNLIMITED, not zero — read the other way round the
     * most expensive tier, the one that lifts every cap, would be the most
     * restricted. $default is returned only when the key is absent entirely.
     */
    public function limit($feature, $default = 0) {
        $ent = $this->entitlements();
        return array_key_exists($feature, $ent) ? (int) $ent[$feature] : (int) $default;
    }

    /** Is $value among a list entitlement, e.g. $lp->allows('channels', 'beta'). */
    public function allows($feature, $value) {
        $ent = $this->entitlements();
        return isset($ent[$feature]) && is_array($ent[$feature]) && in_array($value, $ent[$feature], true);
    }

    public function get_license() {
        $cached = get_transient($this->tr_validate);
        if (is_array($cached)) {
            return $cached;
        }
        return get_option($this->opt_license, null) ?: null;
    }

    public function activate($key) {
        $key = trim((string) $key);
        if ($key === '') {
            return ['ok' => false, 'message' => __('Please enter a license key.', $this->text_domain)];
        }
        $resp = $this->post('/api/v1/license/activate', [
            'license_key'    => $key,
            'site_url'       => home_url('/'),
            'plugin_version' => $this->version,
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
        ]);
        if (!empty($resp['ok'])) {
            if (!$this->signature_acceptable($resp)) {
                return ['ok' => false, 'code' => 'signature_invalid',
                        'message' => __('License response failed signature verification.', $this->text_domain)];
            }
            $resp['_checked_at'] = time();
            update_option($this->opt_key, $key);
            update_option($this->opt_license, $resp);
            set_transient($this->tr_validate, $resp, 12 * HOUR_IN_SECONDS);
            delete_option($this->opt_lapse);
            if (!self::verdict_is_free($resp)) {
                update_option($this->opt_ever, time(), false);
                // Shown once, on the page the card reloads to. See render_activated_notice().
                set_transient('licensorpro_' . $this->slug . '_activated', 1, 10 * MINUTE_IN_SECONDS);
            }
            $this->ensure_schedule();
            $this->refresh_remote(true);
        }
        return $resp;
    }

    public function deactivate() {
        $key = (string) get_option($this->opt_key, '');
        if ($key === '') {
            return ['ok' => true, 'deactivated' => true];
        }
        $resp = $this->post('/api/v1/license/deactivate', [
            'license_key' => $key,
            'site_url'    => home_url('/'),
        ]);
        // Always clear local state on deactivate. The "ever licensed" marker stays:
        // what visitors see must not change because a key moved to another site.
        delete_option($this->opt_key);
        delete_option($this->opt_license);
        delete_option($this->opt_remote);
        delete_option($this->opt_lapse);
        delete_transient($this->tr_validate);
        delete_transient($this->tr_update);
        $this->unschedule();
        return $resp;
    }

    public function check_license() {
        $key = (string) get_option($this->opt_key, '');
        if ($key === '') {
            return ['ok' => false, 'code' => 'no_key', 'message' => __('No license key set.', $this->text_domain)];
        }
        $resp = $this->post('/api/v1/license/validate', [
            'license_key' => $key,
            'site_url'    => home_url('/'),
            // Sent on every validate, not only at activation. Without these the
            // server's record of what a site runs freezes at install time, and
            // the operator's Site Activations table answers "which version is
            // this customer on?" with a figure that can be a year stale while
            // looking like a fresh fact.
            'plugin_version' => $this->version,
            'wp_version'     => get_bloginfo('version'),
            'php_version'    => PHP_VERSION,
        ]);
        if (!empty($resp['ok'])) {
            if (!$this->signature_acceptable($resp)) {
                return ['ok' => false, 'code' => 'signature_invalid',
                        'message' => __('License response failed signature verification.', $this->text_domain)];
            }
            $resp['_checked_at'] = time();
            unset($resp['_offline']);
            update_option($this->opt_license, $resp);
            set_transient($this->tr_validate, $resp, 12 * HOUR_IN_SECONDS);
            // Renewed, or re-activated after a refund: whatever lapsed is over.
            // A licence still past its expiry date stays in its grace period;
            // status() reads expires_at from the verdict just stored.
            $lapse = get_option($this->opt_lapse, null);
            $still_expired = !empty($resp['license']['expires_at'])
                && strtotime((string) $resp['license']['expires_at']) <= time();
            if (is_array($lapse) && !($still_expired && ($lapse['kind'] ?? '') === 'expired')) {
                delete_option($this->opt_lapse);
            }
            if (!self::verdict_is_free($resp)) {
                update_option($this->opt_ever, time(), false);
            }
            return $resp;
        }
        // A definite, signed refusal: remember it. Before 1.7.0 this was dropped
        // and the old "OK" kept Pro on forever.
        $code = isset($resp['code']) ? (string) $resp['code'] : '';
        if (self::lapse_kind($code) !== '' && $this->denial_acceptable($resp, $key)) {
            $this->record_lapse($code);
            return $resp;
        }
        // Offline-grace fallback: if the API was unreachable and we have a recent cached "ok",
        // keep the plugin behaving as licensed for `offline_grace_days`.
        $is_network = ($code === 'http_error' || $code === 'bad_response');
        if ($is_network) {
            $cached = get_option($this->opt_license, null);
            if (is_array($cached) && !empty($cached['ok']) && !empty($cached['_checked_at'])) {
                $age = time() - (int) $cached['_checked_at'];
                if ($age <= $this->grace_days * DAY_IN_SECONDS) {
                    $cached['_offline']     = true;
                    $cached['_offline_age'] = $age;
                    set_transient($this->tr_validate, $cached, HOUR_IN_SECONDS);
                    return $cached;
                }
            }
        }
        return $resp;
    }

    public function clear_cache() {
        delete_transient($this->tr_validate);
        delete_transient($this->tr_update);
        delete_option($this->opt_remote);
    }

    /**
     * Cross-site linking: fetch the sibling sites activated under this license
     * plus the shared per-license peer key. Refreshes the (1h) activation token
     * first, since this is called from an infrequent background sync.
     *
     * @return array{sites: string[], peer_key: string}|null null if unlicensed
     *         or the request fails.
     */
    /**
     * Reasons offered on deactivation. Keys must match the server's
     * PluginFeedbackService::REASONS; an unknown key is filed as 'other' there
     * rather than dropped, so an older plugin can still report.
     *
     * @return array<string,string>
     */
    public function feedback_reasons() {
        return [
            'temporary'      => __('Only deactivating temporarily', $this->text_domain),
            'confusing'      => __('I could not work out how to use it', $this->text_domain),
            'missing'        => __('It is missing a feature I need', $this->text_domain),
            'not_working'    => __('It did not work', $this->text_domain),
            'broke_site'     => __('It broke something on my site', $this->text_domain),
            'found_better'   => __('I found a better plugin', $this->text_domain),
            'no_longer_need' => __('I no longer need it', $this->text_domain),
            'other'          => __('Something else', $this->text_domain),
        ];
    }

    /**
     * One question when somebody deactivates this plugin, asked on the plugins
     * screen itself.
     *
     * Deactivation is the only moment a departing user is still in front of us,
     * and until now it told us nothing at all: an uninstall looked exactly like
     * a quiet site. Everything here is optional — "Skip" deactivates
     * immediately — and the dialog says plainly what it sends.
     */
    public function deactivation_prompt() {
        if (!current_user_can('activate_plugins') || $this->plugin_basename === '') {
            return;
        }

        $modal_id = 'licensorpro-feedback-' . $this->slug;
        $reasons  = $this->feedback_reasons();
        ?>
        <div id="<?php echo esc_attr($modal_id); ?>" class="licensorpro-fb-overlay" style="display:none"
             role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr($modal_id); ?>-title">
            <div class="licensorpro-fb-box">
                <h2 id="<?php echo esc_attr($modal_id); ?>-title" style="margin-top:0">
                    <?php
                    /* translators: %s: plugin name. */
                    echo esc_html(sprintf(__('Before you turn %s off', $this->text_domain), $this->plugin_name));
                    ?>
                </h2>
                <p class="description">
                    <?php esc_html_e('If you have a moment, what went wrong? It is the only way we hear about it.', $this->text_domain); ?>
                </p>
                <div class="licensorpro-fb-reasons">
                    <?php foreach ($reasons as $key => $label) : ?>
                        <label style="display:block;margin:6px 0">
                            <input type="radio" name="<?php echo esc_attr($modal_id); ?>-reason" value="<?php echo esc_attr($key); ?>">
                            <?php echo esc_html($label); ?>
                        </label>
                    <?php endforeach; ?>
                </div>
                <textarea class="licensorpro-fb-message" rows="3" style="width:100%;margin-top:8px"
                          placeholder="<?php esc_attr_e('Anything else? (optional)', $this->text_domain); ?>"></textarea>
                <p class="description" style="margin-top:6px">
                    <?php esc_html_e('Sends your answer, the plugin version and your site address. Nothing else, and nothing from your site content.', $this->text_domain); ?>
                </p>
                <div style="margin-top:14px;display:flex;gap:8px;align-items:center">
                    <button type="button" class="button button-primary licensorpro-fb-send">
                        <?php esc_html_e('Send and deactivate', $this->text_domain); ?>
                    </button>
                    <button type="button" class="button licensorpro-fb-skip">
                        <?php esc_html_e('Skip and deactivate', $this->text_domain); ?>
                    </button>
                    <button type="button" class="button-link licensorpro-fb-cancel" style="margin-left:auto">
                        <?php esc_html_e('Cancel', $this->text_domain); ?>
                    </button>
                </div>
            </div>
        </div>
        <style>
            .licensorpro-fb-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:100100;
                display:flex;align-items:center;justify-content:center;padding:20px}
            .licensorpro-fb-box{background:#fff;border-radius:6px;padding:22px 24px;max-width:460px;width:100%;
                box-shadow:0 6px 30px rgba(0,0,0,.3)}
        </style>
        <script>
        (function () {
            var slug     = <?php echo wp_json_encode($this->slug); ?>;
            var basename = <?php echo wp_json_encode($this->plugin_basename); ?>;
            var overlay  = document.getElementById(<?php echo wp_json_encode($modal_id); ?>);
            if (!overlay) { return; }
            var target = null;

            // The deactivate link is the only thing we intercept, and only for
            // this plugin's own row. Anything else on the screen behaves normally.
            document.addEventListener('click', function (e) {
                var link = e.target.closest ? e.target.closest('a') : null;
                if (!link || !link.href) { return; }
                if (link.href.indexOf('action=deactivate') === -1) { return; }
                if (link.href.indexOf(encodeURIComponent(basename)) === -1) { return; }
                e.preventDefault();
                target = link.href;
                overlay.style.display = 'flex';
            });

            function go() { if (target) { window.location.href = target; } }

            overlay.querySelector('.licensorpro-fb-cancel').addEventListener('click', function () {
                overlay.style.display = 'none';
                target = null;
            });
            overlay.querySelector('.licensorpro-fb-skip').addEventListener('click', go);

            overlay.querySelector('.licensorpro-fb-send').addEventListener('click', function () {
                var btn = this;
                var picked = overlay.querySelector('input[type=radio]:checked');
                var body = new URLSearchParams();
                body.append('action', 'licensorpro_' + slug + '_feedback');
                body.append('_ajax_nonce', <?php echo wp_json_encode(wp_create_nonce('licensorpro_feedback_' . $this->slug)); ?>);
                body.append('reason', picked ? picked.value : 'other');
                body.append('message', overlay.querySelector('.licensorpro-fb-message').value || '');
                btn.disabled = true;

                // Deactivation goes ahead whatever the network does. Trapping
                // someone on this screen because our server is down would be a
                // far worse bug than losing one answer.
                fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                }).then(go).catch(go);
                setTimeout(go, 4000);
            });
        })();
        </script>
        <?php
    }

    /**
     * Relays one answer to LicensorPro. Server to server on purpose: the browser
     * never talks to our API directly, so there is no CORS to open up and the
     * licence key never leaves the site in a page the user can read.
     */
    public function ajax_feedback() {
        check_ajax_referer('licensorpro_feedback_' . $this->slug);
        if (!current_user_can('activate_plugins')) {
            wp_send_json_success(['sent' => false]);
        }

        $reason  = isset($_POST['reason']) ? sanitize_key(wp_unslash($_POST['reason'])) : 'other';
        $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';

        $res = $this->post('/api/v1/feedback/deactivation', [
            'product_slug' => $this->slug,
            'version'      => $this->version,
            'reason'       => $reason,
            'message'      => $message,
            'site_url'     => home_url(),
            'license_key'  => (string) get_option($this->opt_key, ''),
        ]);

        // Always a success to the browser: the only thing waiting on this reply
        // is a redirect to the deactivation the user already asked for.
        wp_send_json_success(['sent' => !empty($res['ok'])]);
    }

    public function fetch_siblings() {
        $key = (string) get_option($this->opt_key, '');
        if ($key === '') {
            return null;
        }
        $checked = $this->check_license();
        $token   = is_array($checked) ? (string) ($checked['token'] ?? '') : '';
        if ($token === '') {
            return null;
        }
        $resp = $this->get('/api/v1/license/siblings', [
            'license_key' => $key,
            'site_url'    => home_url('/'),
            'token'       => $token,
        ]);
        if (empty($resp['ok'])) {
            return null;
        }
        return [
            'sites'    => array_values((array) ($resp['sites'] ?? [])),
            'peer_key' => (string) ($resp['peer_key'] ?? ''),
        ];
    }

    // ---- HTTP --------------------------------------------------------------

    private function post($path, array $body) {
        if ($this->api_url === '') {
            return ['ok' => false, 'message' => __('API URL not configured.', $this->text_domain)];
        }
        $resp = wp_remote_post($this->api_url . $path, [
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'      => wp_json_encode($body),
        ]);
        return $this->parse_response($resp);
    }

    private function get($path, array $args) {
        if ($this->api_url === '') {
            return ['ok' => false, 'message' => __('API URL not configured.', $this->text_domain)];
        }
        $url = $this->api_url . $path . '?' . http_build_query($args);
        $resp = wp_remote_get($url, [
            'timeout'   => 15,
            'sslverify' => true,
            'headers'   => ['Accept' => 'application/json'],
        ]);
        return $this->parse_response($resp);
    }

    private function parse_response($resp) {
        if (is_wp_error($resp)) {
            return ['ok' => false, 'code' => 'http_error', 'message' => $resp->get_error_message()];
        }
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode((string) $body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'code' => 'bad_response',
                    'message' => __('Unexpected response from license server.', $this->text_domain)];
        }
        return $data;
    }

    // ---- Update integration -----------------------------------------------

    /**
     * Resolve a WordPress-shaped `icons` array for the update screen.
     *
     * Priority:
     *   1. Server-provided: an `icons` array on the cached remote payload
     *      (lets LicensorPro centrally serve icons). Falls back to a single
     *      `icon_url` if present (legacy field).
     *   2. Bundled assets next to the plugin: assets/icon.svg,
     *      assets/icon-256x256.png, assets/icon-128x128.png.
     *
     * Returns an empty array when nothing is found.
     *
     * @param array|null $remote The refresh_remote() payload, if already fetched.
     * @return array
     */
    private function plugin_icons($remote = null) {
        // 1. Server-provided icons.
        if (is_array($remote)) {
            if (!empty($remote['icons']) && is_array($remote['icons'])) {
                return array_map('strval', $remote['icons']);
            }
            if (!empty($remote['icon_url'])) {
                return ['default' => (string) $remote['icon_url']];
            }
        }

        // 2. Bundled assets next to the plugin file.
        if ($this->plugin_file === '') {
            return [];
        }
        $icons = [];
        if ($this->asset_exists('assets/icon.svg')) {
            $icons['svg'] = plugins_url('assets/icon.svg', $this->plugin_file);
        }
        if ($this->asset_exists('assets/icon-256x256.png')) {
            $icons['2x'] = plugins_url('assets/icon-256x256.png', $this->plugin_file);
        }
        if ($this->asset_exists('assets/icon-128x128.png')) {
            $url = plugins_url('assets/icon-128x128.png', $this->plugin_file);
            $icons['1x'] = $url;
            if (!isset($icons['default'])) {
                $icons['default'] = $url;
            }
        }
        return $icons;
    }

    /**
     * Resolve a WordPress-shaped `banners` array for the plugin info screen.
     *
     * Priority mirrors plugin_icons(): server-provided `banners`, else bundled
     * assets/banner-1544x500.png ('high') and assets/banner-772x250.png ('low').
     *
     * @param array|null $remote
     * @return array
     */
    private function plugin_banners($remote = null) {
        if (is_array($remote) && !empty($remote['banners']) && is_array($remote['banners'])) {
            return array_map('strval', $remote['banners']);
        }
        if ($this->plugin_file === '') {
            return [];
        }
        $banners = [];
        if ($this->asset_exists('assets/banner-1544x500.png')) {
            $banners['high'] = plugins_url('assets/banner-1544x500.png', $this->plugin_file);
        }
        if ($this->asset_exists('assets/banner-772x250.png')) {
            $banners['low'] = plugins_url('assets/banner-772x250.png', $this->plugin_file);
        }
        return $banners;
    }

    /**
     * True when a path relative to the plugin's own directory exists on disk.
     *
     * @param string $rel e.g. 'assets/icon-128x128.png'
     * @return bool
     */
    private function asset_exists($rel) {
        if ($this->plugin_file === '') {
            return false;
        }
        return is_readable(plugin_dir_path($this->plugin_file) . $rel);
    }

    private function refresh_remote($force = false) {
        if (!$force) {
            $cached = get_transient($this->tr_update);
            if (is_array($cached)) {
                return $cached;
            }
        }
        $key = (string) get_option($this->opt_key, '');
        if ($key === '') {
            return null;
        }
        $resp = $this->get('/api/v1/update/check', [
            'license_key'     => $key,
            'site_url'        => home_url('/'),
            'product_slug'    => $this->slug,
            'current_version' => $this->installed_version(),
            'channel'         => $this->channel,
        ]);
        if (!empty($resp['ok'])) {
            set_transient($this->tr_update, $resp, 12 * HOUR_IN_SECONDS);
            update_option($this->opt_remote, $resp);
            return $resp;
        }
        return null;
    }

    public function inject_update($transient) {
        if (empty($transient) || !is_object($transient)) {
            return $transient;
        }

        // This (Pro) build can share its slug with a free listing on wordpress.org.
        // Without this guard, w.org would offer the *free* build as an "update" over
        // Pro and silently downgrade it (stripping pro/). The Pro build also carries
        // an `Update URI:` header so WP 5.8+ excludes it from the w.org check; this
        // is the belt-and-suspenders for older cores and for the moment between the
        // w.org response and this filter. We own updates for this basename: drop any
        // w.org-provided entry and serve only our own (or none).
        if (isset($transient->response) && is_array($transient->response)
            && isset($transient->response[$this->plugin_basename])) {
            unset($transient->response[$this->plugin_basename]);
        }

        $remote     = $this->refresh_remote(false);
        $installed  = $this->installed_version();
        $has_update = $remote && !empty($remote['version']) && !empty($remote['has_update'])
            && version_compare((string) $remote['version'], $installed, '>')
            // The server answers a free licence with the free build. Installed over
            // a Pro build that would delete pro/ without a word (1.8.0).
            && !($this->pro_build_declared && $this->is_free_licence());

        $icons   = $this->plugin_icons($remote);
        $banners = $this->plugin_banners($remote);

        if (!$has_update) {
            // Mark as up-to-date so core shows no update for our slug.
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = [];
            }
            $transient->no_update[$this->plugin_basename] = (object) [
                'id'          => $this->plugin_basename,
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $installed,
                'url'         => '',
                'package'     => '',
                'icons'       => $icons,
                'banners'     => $banners,
            ];
            return $transient;
        }

        $obj = (object) [
            'id'            => $this->plugin_basename,
            'slug'          => $this->slug,
            'plugin'        => $this->plugin_basename,
            'new_version'   => (string) $remote['version'],
            'url'           => isset($remote['product']['homepage']) ? (string) $remote['product']['homepage'] : '',
            'package'       => isset($remote['download_url']) ? (string) $remote['download_url'] : '',
            'tested'        => isset($remote['tested_wp']) ? (string) $remote['tested_wp'] : '',
            'requires'      => isset($remote['requires_wp']) ? (string) $remote['requires_wp'] : '',
            'requires_php'  => isset($remote['requires_php']) ? (string) $remote['requires_php'] : '',
            'icons'         => $icons,
            'banners'       => $banners,
        ];
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = [];
        }
        $transient->response[$this->plugin_basename] = $obj;
        return $transient;
    }

    /**
     * The version of the plugin files on disk, not the one this request loaded.
     *
     * "Update now" runs in a request that loaded the OLD code. After the new files
     * are in place, WordPress rebuilds its update list in that same request; comparing
     * against $this->version (the old version) offered the release just installed as
     * an update again, and it stayed listed until the next check, up to 12 hours later.
     */
    private function installed_version() {
        if ($this->plugin_file !== '' && function_exists('get_file_data') && is_readable($this->plugin_file)) {
            $data = get_file_data($this->plugin_file, ['Version' => 'Version']);
            if (!empty($data['Version'])) {
                return (string) $data['Version'];
            }
        }
        return $this->version;
    }

    /**
     * Drop our entry from the update list when it is not newer than what is installed.
     *
     * Heals lists written by an older copy of this client during an update (see
     * installed_version()), which this release cannot change: that code ran before
     * these files existed.
     */
    public function drop_stale_update($transient) {
        if (!is_object($transient) || empty($transient->response[$this->plugin_basename])) {
            return $transient;
        }
        $entry = $transient->response[$this->plugin_basename];
        $new   = is_object($entry) && isset($entry->new_version) ? (string) $entry->new_version : '';
        if ($new !== '' && version_compare($new, $this->installed_version(), '<=')) {
            unset($transient->response[$this->plugin_basename]);
        }
        return $transient;
    }

    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }
        $key  = (string) get_option($this->opt_key, '');
        $resp = $this->get('/api/v1/update/info', [
            'product_slug' => $this->slug,
            'license_key'  => $key,
            'site_url'     => home_url('/'),
        ]);
        if (empty($resp['ok']) || empty($resp['info'])) {
            return $result;
        }
        $info = $resp['info'];
        $out = (object) [
            'name'          => $info['name'] ?? $this->plugin_name,
            'slug'          => $info['slug'] ?? $this->slug,
            'version'       => $info['version'] ?? '',
            'requires'      => $info['requires'] ?? '',
            'tested'        => $info['tested'] ?? '',
            'requires_php'  => $info['requires_php'] ?? '',
            'homepage'      => $info['homepage'] ?? '',
            'download_link' => $info['download_link'] ?? '',
            'sections'      => isset($info['sections']) && is_array($info['sections']) ? $info['sections'] : [],
        ];
        return $out;
    }

    public function clear_cache_after_upgrade($upgrader, $hook_extra) {
        if (empty($hook_extra['type']) || $hook_extra['type'] !== 'plugin') {
            return;
        }
        $this->clear_cache();
    }

    // ---- Admin UI ----------------------------------------------------------

    public function register_menu() {
        /*
         * Intentionally does NOT register a Settings → "<Plugin> License" page.
         * The license/activation UI is now rendered inline on each plugin's own
         * settings screen via render_settings_page() (called from the plugin's
         * settings-page extension hook), so license + settings live in one place
         * instead of being scattered under WP → Settings. Kept as a no-op so the
         * admin_menu hook wired in init() stays harmless.
         */
    }

    /**
     * Format a timestamp (unix int, MySQL datetime, or ISO-8601 like
     * "2026-07-23T19:59:53.000000Z") as a human-friendly US date/time, optionally
     * with a relative suffix, e.g. "Jul 23, 2026 7:59 PM (in 3 months)".
     */
    private function human_dt($value, $relative = true) {
        if ($value === null || $value === '') {
            return '';
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if (!$ts) {
            // strtotime can choke on fractional seconds — retry without them.
            $ts = strtotime(preg_replace('/\.\d+/', '', (string) $value));
        }
        if (!$ts) {
            return (string) $value;
        }
        $abs = function_exists('wp_date')
            ? wp_date('M j, Y g:i A', $ts)
            : date_i18n('M j, Y g:i A', $ts);
        if (!$relative) {
            return $abs;
        }
        $now = time();
        $rel = ($ts >= $now)
            ? sprintf(__('in %s', $this->text_domain), human_time_diff($now, $ts))
            : sprintf(__('%s ago', $this->text_domain), human_time_diff($ts, $now));
        return $abs . ' (' . $rel . ')';
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $st       = $this->status();
        $free     = !empty($st['free']);
        $licensed = $st['state'] !== 'unlicensed';
        $info     = $this->get_license();
        $key      = (string) get_option($this->opt_key, '');
        $masked   = $key !== '' ? $this->mask_key($key) : '';
        $nonce    = wp_create_nonce('licensorpro_' . $this->slug);
        $ajax_url = admin_url('admin-ajax.php');
        $td       = $this->text_domain;

        $license = is_array($info) && isset($info['license']) ? $info['license'] : [];
        $status     = isset($license['status']) ? (string) $license['status'] : '';
        $site_limit = isset($license['site_limit']) ? (int) $license['site_limit'] : 0;
        $expires_at = isset($license['expires_at']) ? (string) $license['expires_at'] : '';

        // Rendered inline within the plugin's own settings screen (not a standalone
        // wrap/page), so emit a section heading rather than an <h1> + .wrap.
        echo '<h2 id="lp-' . esc_attr($this->slug) . '-license">' . esc_html(sprintf(__('%s License', $td), $this->plugin_name)) . '</h2>';
        echo '<div id="lp-' . esc_attr($this->slug) . '-msg" style="margin:1em 0;"></div>';

        // "Deactivate this site" reloads the page, which used to take its own
        // confirmation with it. Say once, after the reload, what happened.
        $flag = 'licensorpro_' . $this->slug . '_deactivated_' . get_current_user_id();
        if (get_transient($flag)) {
            delete_transient($flag);
            if (!$licensed) {
                echo '<div class="notice notice-info inline"><p>'
                    . esc_html__('This site was deactivated, so its seat is free for another site. Enter the key again here to switch the Pro tools back on.', $td)
                    . '</p></div>';
            }
        }

        if ($free) {
            // A free licence: it came stamped into the free zip. There is nothing
            // to deactivate (it has no seats) and nothing Pro to switch off.
            $checkedAt = isset($info['_checked_at']) ? (int) $info['_checked_at'] : 0;
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>' . esc_html__('License key', $td) . '</th><td><code>' . esc_html($masked) . '</code></td></tr>';
            echo '<tr><th>' . esc_html__('Type', $td) . '</th><td>' . esc_html(
                $this->pro_build
                    ? __('Free license: updates of the free version only. It does not switch on Pro.', $td)
                    : __('Free license: updates arrive in your dashboard like any other plugin.', $td)
            ) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Last checked', $td) . '</th><td>' . esc_html($checkedAt ? $this->human_dt($checkedAt) : '—') . '</td></tr>';
            echo '</tbody></table>';
            if (!$this->pro_build) {
                echo '<p><button class="button" id="lp-' . esc_attr($this->slug) . '-check">'
                     . esc_html__('Check for updates now', $td) . '</button></p>';
                echo '<p>' . esc_html__('Bought Pro? Download the Pro version from your account and upload it over this one. Everything you set up stays.', $td)
                     . ' <a href="' . esc_url($this->account_url()) . '" target="_blank" rel="noopener">' . esc_html__('Go to your account', $td) . '</a></p>';
            }
        } elseif ($licensed) {
            $offline   = !empty($info['_offline']);
            $checkedAt = isset($info['_checked_at']) ? (int) $info['_checked_at'] : 0;
            $checkedStr = $checkedAt ? $this->human_dt($checkedAt) : '—';
            $graceUntil = $checkedAt ? date_i18n(get_option('date_format'), $checkedAt + ($this->grace_days * DAY_IN_SECONDS)) : '';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>' . esc_html__('License key', $td) . '</th><td><code>' . esc_html($masked) . '</code></td></tr>';
            $labels = [
                'active'  => $status ?: __('active', $td),
                /* translators: %s: date the Pro tools lock */
                'grace'   => sprintf(__('expired, Pro tools stay on until %s', $td), date_i18n(get_option('date_format'), $st['grace_ends'])),
                'locked'  => __('expired, Pro tools locked', $td),
                'revoked' => $st['code'] === 'site_not_activated'
                    ? __('this site is not activated', $td)
                    : __('no longer active (refunded or cancelled)', $td),
            ];
            echo '<tr><th>' . esc_html__('Status', $td) . '</th><td>' . esc_html($labels[$st['state']] ?? $status);
            if ($st['state'] !== 'active') {
                echo ' <a href="' . esc_url($this->renew_url()) . '" target="_blank" rel="noopener">' . esc_html__('Renew', $td) . '</a>';
            }
            if ($offline) {
                echo ' <span style="background:#f0b849;color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;margin-left:6px">'
                     . esc_html__('Offline mode', $td) . '</span>';
                if ($graceUntil !== '') {
                    echo ' <small>' . esc_html(sprintf(__('cached license valid until %s', $td), $graceUntil)) . '</small>';
                }
            }
            echo '</td></tr>';
            echo '<tr><th>' . esc_html__('Update channel', $td) . '</th><td><code>' . esc_html($this->channel) . '</code></td></tr>';
            echo '<tr><th>' . esc_html__('Site limit', $td) . '</th><td>' . esc_html((string) $site_limit) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Expires', $td) . '</th><td>' . esc_html($expires_at ? $this->human_dt($expires_at) : __('Never', $td)) . '</td></tr>';
            echo '<tr><th>' . esc_html__('Last checked', $td) . '</th><td>' . esc_html($checkedStr) . '</td></tr>';
            echo '</tbody></table>';
            echo '<p>';
            echo '<button class="button button-secondary" id="lp-' . esc_attr($this->slug) . '-deactivate">'
                 . esc_html__('Deactivate this site', $td) . '</button> ';
            echo '<button class="button" id="lp-' . esc_attr($this->slug) . '-check">'
                 . esc_html__('Check for updates now', $td) . '</button>';
            echo '</p>';
        }
        // The key box. In a Pro build: no licence, a free one, or a lapsed one. In
        // a free build only when there is no key at all (the stamp did not take).
        $show_box = $this->pro_build
            ? (!$licensed || in_array($st['state'], ['locked', 'revoked'], true))
            : !$this->has_key();
        if ($show_box) {
            // Wrapped so a successful activation can take the prompt away. Leaving
            // "Enter your license key" and the Activate button under "License
            // activated." read as if the key had not been accepted.
            echo '<div id="lp-' . esc_attr($this->slug) . '-unlicensed">';
            echo '<p>' . esc_html(
                $free
                    ? __('Have a Pro license key? Enter it here to switch on the Pro tools. It replaces the free license on this site.', $td)
                    : __('Enter your license key to activate this site.', $td)
            ) . '</p>';
            echo '<table class="form-table"><tbody><tr>';
            echo '<th><label for="lp-' . esc_attr($this->slug) . '-key">' . esc_html__('License key', $td) . '</label></th>';
            echo '<td><input type="text" class="regular-text" id="lp-' . esc_attr($this->slug) . '-key" autocomplete="off" /> ';
            echo '<button class="button button-primary" id="lp-' . esc_attr($this->slug) . '-activate">'
                 . esc_html__('Activate', $td) . '</button></td>';
            echo '</tr></tbody></table>';
            if ($this->pro_build) {
                echo '<p class="description">' . esc_html__('Your key is in your purchase email and in your account.', $td)
                     . ' <a href="' . esc_url($this->account_url()) . '" target="_blank" rel="noopener">' . esc_html__('Go to your account', $td) . '</a></p>';
            }
            echo '</div>';
        }

        $support = (string) $this->opts['support_url'];
        if ($support !== '') {
            echo '<p class="description">' . esc_html__('Need a hand?', $td)
                 . ' <a href="' . esc_url($support) . '" target="_blank" rel="noopener">' . esc_html__('Contact support', $td) . '</a></p>';
        }

        // Inline JS for AJAX flow.
        $slug_js = esc_js($this->slug);
        ?>
        <script>
        (function(){
            var slug = '<?php echo $slug_js; ?>';
            var ajaxUrl = '<?php echo esc_url($ajax_url); ?>';
            var nonce   = '<?php echo esc_js($nonce); ?>';
            var $msg    = document.getElementById('lp-' + slug + '-msg');
            function setMsg(text, ok){
                if (!$msg) return;
                var div = document.createElement('div');
                div.className = 'notice notice-' + (ok ? 'success' : 'error');
                var p = document.createElement('p');
                p.textContent = text || '';
                div.appendChild(p);
                $msg.textContent = '';
                $msg.appendChild(div);
            }
            function post(action, extra){
                var body = 'action=' + encodeURIComponent(action) +
                           '&_wpnonce=' + encodeURIComponent(nonce);
                if (extra) {
                    Object.keys(extra).forEach(function(k){
                        body += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(extra[k]);
                    });
                }
                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type':'application/x-www-form-urlencoded'},
                    body: body
                }).then(function(r){ return r.json(); });
            }
            // Raw server strings tell a user WHAT happened but never what to DO about
            // it. Map the codes the licensing API actually returns to a next step, and
            // fall back to the server message for anything unrecognised so a new code
            // is never swallowed.
            function recoveryFor(code){
                switch (code) {
                    case 'site_limit_reached':
                        return 'Every seat on this license is in use. Deactivate one of your other sites from your account, then try again.';
                    case 'expired':
                        return 'This license has expired. Renewing it from your account reactivates this site immediately — your settings and data are untouched.';
                    case 'invalid_license':
                        return 'That key was not recognised. Check for a missing character, or copy it again from your purchase email.';
                    case 'blocklisted':
                        return 'This key has been revoked. If you believe that is a mistake, reply to your purchase email and we will sort it out.';
                    case 'http_error':
                    case 'bad_response':
                        return 'Could not reach the licensing server. Your site may be blocking outbound requests — this is usually temporary, so try again in a minute.';
                    case 'too_many_requests':
                        return 'Too many attempts in a short window. Wait a minute and try again.';
                    case 'server_error':
                        return 'The licensing server had a problem on its side. Your key was not rejected. Try again in a few minutes, and if it keeps happening, reply to your purchase email.';
                    default:
                        return '';
                }
            }
            function setResult(d, fallback){
                var ok   = !!(d && d.ok);
                var text = (d && d.message) ? d.message : fallback;
                if (!ok) {
                    var hint = recoveryFor(d && d.code ? String(d.code) : '');
                    if (hint) { text = hint; }
                }
                setMsg(text, ok);
            }
            var actBtn = document.getElementById('lp-' + slug + '-activate');
            if (actBtn) {
                actBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    var keyEl = document.getElementById('lp-' + slug + '-key');
                    // A key copied out of an email routinely carries a trailing newline
                    // or a stray space, which the server rejects as invalid. Normalise
                    // it here and write it back so the field shows what was sent.
                    var key = keyEl ? String(keyEl.value).replace(/\s+/g, '') : '';
                    if (keyEl) { keyEl.value = key; }
                    if (!key) { setMsg('Enter your license key first.', false); return; }
                    actBtn.disabled = true;
                    post('licensorpro_' + slug + '_activate', {license_key: key}).then(function(d){
                        actBtn.disabled = false;
                        setResult(d, 'Done.');
                        // Reloading 800ms after success threw away the confirmation
                        // before it could be read — the one moment the plugin has the
                        // user's full attention. Show the result, and let them move on
                        // when they are ready.
                        if (d && d.ok) {
                            // Take the key prompt away: under "License activated." it
                            // read as if the key had not been accepted. style.display,
                            // not el.hidden, because wp-admin styles can override
                            // [hidden] on form tables.
                            var unlicensed = document.getElementById('lp-' + slug + '-unlicensed');
                            if (unlicensed) { unlicensed.style.display = 'none'; }
                            // Plugins print their own "no license key yet" notices outside
                            // this box; this lets them hide those without a reload.
                            try {
                                document.dispatchEvent(new CustomEvent('licensorpro:activated', {detail: {slug: slug}}));
                            } catch (err) { /* very old browsers: the reload link still works */ }
                            document.querySelectorAll('.licensorpro-unlicensed-notice[data-licensorpro="' + slug + '"]').forEach(function(n){
                                n.style.display = 'none';
                            });
                            // The Pro screens only appear after a reload. Do it for
                            // them, after long enough to read "License activated.";
                            // the button stays for a browser that blocks it.
                            var again = document.createElement('p');
                            var a = document.createElement('a');
                            a.href = location.href;
                            a.className = 'button button-primary';
                            a.textContent = 'Reloading to switch on the Pro tools…';
                            again.appendChild(a);
                            if ($msg) { $msg.appendChild(again); }
                            actBtn.disabled = true;
                            setTimeout(function(){ location.reload(); }, 1500);
                        }
                    }).catch(function(){
                        actBtn.disabled = false;
                        setMsg('Could not reach the licensing server. Check your connection and try again.', false);
                    });
                });
            }
            var deBtn = document.getElementById('lp-' + slug + '-deactivate');
            if (deBtn) {
                deBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    // Deactivation frees a seat and turns premium features off. It is
                    // one click next to buttons that do not, so confirm it.
                    if (!window.confirm('Deactivate this license on this site? Premium features switch off here and the seat is freed for another site.')) {
                        return;
                    }
                    deBtn.disabled = true;
                    post('licensorpro_' + slug + '_deactivate', {}).then(function(d){
                        deBtn.disabled = false;
                        setResult(d, 'Deactivated.');
                        setTimeout(function(){ location.reload(); }, 1500);
                    }).catch(function(){
                        deBtn.disabled = false;
                        setMsg('Could not reach the licensing server. Check your connection and try again.', false);
                    });
                });
            }
            var chkBtn = document.getElementById('lp-' + slug + '-check');
            if (chkBtn) {
                chkBtn.addEventListener('click', function(e){
                    e.preventDefault();
                    chkBtn.disabled = true;
                    post('licensorpro_' + slug + '_check', {}).then(function(d){
                        chkBtn.disabled = false;
                        setMsg(d && d.message ? d.message : 'Checked.', !!(d && d.ok));
                    });
                });
            }
        })();
        </script>
        <?php
    }

    public function ajax_activate() {
        $this->verify_ajax();
        $key = isset($_POST['license_key']) ? sanitize_text_field((string) wp_unslash($_POST['license_key'])) : '';
        $resp = $this->activate($key);
        if (!empty($resp['ok']) && empty($resp['message'])) {
            $resp['message'] = __('License activated.', $this->text_domain);
        }
        wp_send_json($resp);
    }

    public function ajax_deactivate() {
        $this->verify_ajax();
        $resp = $this->deactivate();
        if (!empty($resp['ok'])) {
            set_transient('licensorpro_' . $this->slug . '_deactivated_' . get_current_user_id(), 1, 5 * MINUTE_IN_SECONDS);
        }
        if (!empty($resp['ok']) && empty($resp['message'])) {
            $resp['message'] = __('Site deactivated.', $this->text_domain);
        }
        wp_send_json($resp);
    }

    public function ajax_check() {
        $this->verify_ajax();
        $this->clear_cache();
        $resp = $this->check_license();
        $this->refresh_remote(true);
        if (!empty($resp['ok']) && empty($resp['message'])) {
            $resp['message'] = __('License is valid. Update info refreshed.', $this->text_domain);
        }
        wp_send_json($resp);
    }

    private function verify_ajax() {
        if (!current_user_can('manage_options')) {
            wp_send_json(['ok' => false, 'message' => __('Permission denied.', $this->text_domain)], 403);
        }
        check_ajax_referer('licensorpro_' . $this->slug, '_wpnonce');
    }

    private function mask_key($key) {
        $key = (string) $key;
        $len = strlen($key);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($key, 0, 4) . str_repeat('*', max(0, $len - 8)) . substr($key, -4);
    }
}

} // end if (!class_exists('LicensorPro_Client', false)); see the note above the class.
