# Guest Wi-Fi OTP portal (Sankara Nethralaya)

Mobile-OTP captive portal for FortiGate guest Wi-Fi. Plain PHP on XAMPP, no framework.
See README.md for setup; this file records the non-obvious parts.

## Environment
- Current deployment: portal server 192.168.20.3 (XAMPP, **PHP 5.6.3** / Apache 2.4.10),
  FortiGate 192.168.20.1, guest subnet 192.168.20.x. No `random_bytes`, no PHP 7
  syntax. CLI: `D:\xampp\php\php.exe -l <file>` to lint.
- Apache on 443 here serves XAMPP's default self-signed `CN=localhost` cert (expired
  2019), so guest browsers reject https to this server. Guests use http on port 80.
- Older notes describe a different host: server 172.20.10.56 (Windows Server 2022,
  `netmon.cer` from internal CA SNM-RootCA, shared with NetMon - do not replace that
  cert) and FortiGate 172.20.10.70. Check `config.php` for the addresses in use.
- `config.php` is gitignored (secrets). `config.-old.php` is a local backup with
  secrets - never commit it.

## Files
- `portal.php` - all logic (OTP policy, SMS drivers, FortiGate handoff, cookies).
- `api.php` - JSON API used by the page; CORS limited to `PORTAL_ALLOWED_ORIGINS`.
- `portal-standalone.html` - the guest page. Served from `/guestwifi/` by Apache as
  the FortiGate's external captive portal, so edits go live with no upload.
- `storage/error.log` - first place to look when "could not send the OTP" or
  similar shows; `storage/sms.log` holds OTPs when `SMS_DRIVER` is `log`;
  `storage/handoff.log` records where each firewall login was posted (never the
  password), to compare with FortiGate's User Events when a login is rejected.

## SMS (timesapi)
- PHP 5.6 has no CA bundle: `CA_BUNDLE_FILE` (config) points cURL at
  `D:/xampp/php/extras/ssl/cacert.pem`. Without it every send fails with
  "unable to get local issuer certificate". Keep TLS verification on.
- timesapi takes the bare 10-digit number: `SMS_HTTP_PARAMS` uses `'to' => '{local}'`.
- Site DNS is intermittently slow; the http driver uses longer timeouts and retries
  once on resolve/connect failures only (never after the request reached the gateway).
- DLT/TRAI: only the network-alert template is approved for sender SNALRT, so
  `SMS_TEMPLATE` is that fixed text without `{otp}` until an OTP template is approved.
  With `SMS_DRIVER = 'log'` the OTP is appended to the sms.log line for testing.
- Failed sends (`status = 'send_failed'`) do not count toward `MAX_OTP_PER_DAY`.

## FortiGate setup (external captive portal)
- Working setup: guest SSID in Captive Portal mode, Authentication Portal = External,
  URL `http://192.168.20.3/guestwifi/portal-standalone.html`, plus a Firewall-type user
  group containing the single Local User `guestwifi`. Its password must equal
  `FORTIGATE_SHARED_PASS`. Keep the URL on http: guests do not trust the cert.
- FortiOS redirects to the page with `?login&post=http://192.168.20.1:1000/fgtauth&magic=<16 hex>&usermac=...&apmac=...&ssid=...`.
  The page forwards these on every API call. `usermac` gives a `mac:` device id, so
  the per-device limits are tied to hardware here rather than a browser id.
- Do not go back to uploading the page as a Login Page replacement message. On this
  FortiGate it arrived with `%%MAGIC%%` substituted blank (`[/ q0 blank]` on the page,
  `Portal page has no magic` in error.log), and every login post was rejected.
- Exempt 192.168.20.3 port 80 so guests can reach the portal before login. The exempt
  rule must sit above the guest internet policy and must not require the user group.
- The guest-to-WAN policy can use Source `all` with no user group, because captive
  portal on the SSID enforces login. Without captive portal that policy is open to all.
- If Apache logs show no requests from guest IPs, it is the firewall - or a rejected
  TLS cert, which fails the same way ("Cannot reach the sign-in server").
- `FORTIGATE_POST_URL` is only a fallback and must be the captive-portal port (1000),
  never the admin GUI on 443; posting there lands guests on the firewall admin login.
- "You are connected" means the OTP was accepted, not that the firewall opened access.
  The firewall's answer comes from the form post. A verified device whose post failed
  is handed off again on its next visit with a fresh magic (`gw_session_rehandoff()`).
  The page allows one automatic post per minute, then shows "firewall login rejected".

## Page rules
- Keep these rules even though the page is no longer hosted on the FortiGate: FortiOS
  rewrites a replacement message, substituting anything shaped like `%%...%%`, and
  regex literals or backslashes in the scripts can be mangled ("Invalid regular
  expression: missing /" kills the whole script). The code for that mode is still there.
- Bump the version in both the line-2 comment and the footer `#page-ver` on every
  change. The footer shows `· OK` only when the full file loaded and the main
  script ran.
- `window.onerror` prints script errors into the page's alert box for diagnosis.
- Served from `/guestwifi/`, the page calls the API same-origin (`api.php`). Elsewhere it
  uses `GW_PORTAL_SERVER`, built from `location.protocol`, because an https page cannot
  call an http API. Cookies are `SameSite=None; Secure` only over HTTPS (`gw_samesite()`).

## Conventions
- Files use CRLF line endings; keep them (sed -i on Git Bash converts to LF).
