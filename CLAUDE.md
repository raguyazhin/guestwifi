# Guest Wi-Fi OTP portal (Sankara Nethralaya)

Mobile-OTP captive portal for FortiGate guest Wi-Fi. Plain PHP on XAMPP, no framework.
See README.md for setup; this file records the non-obvious parts.

## Environment
- Server: 172.20.10.56, XAMPP on Windows Server 2022, **PHP 5.6.3** / Apache 2.4.10.
  No `random_bytes`, no PHP 7 syntax. CLI: `D:\xampp\php\php.exe -l <file>` to lint.
- MySQL `guestwifi` DB on 172.20.10.56. FortiGate: 172.20.10.70.
- Apache already serves HTTPS on 443 for all of htdocs with `netmon.cer`
  (issued by internal CA SNM-RootCA, SAN includes IP 172.20.10.56). Do not
  replace it with a self-signed cert - NetMon uses it too.
- `config.php` is gitignored (secrets). `config.-old.php` is a local backup with
  secrets - never commit it.

## Files
- `portal.php` - all logic (OTP policy, SMS drivers, FortiGate handoff, cookies).
- `api.php` - JSON API used by the page; CORS limited to `PORTAL_ALLOWED_ORIGINS`.
- `portal-standalone.html` - the guest page. Uploaded to FortiGate as the Login Page
  replacement message, and also works served from `/guestwifi/`.
- `storage/error.log` - first place to look when "could not send the OTP" or
  similar shows; `storage/sms.log` holds OTPs when `SMS_DRIVER` is `log`.

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

## FortiGate-hosted page gotchas
- FortiOS rewrites the page when serving it as a replacement message:
  anything shaped like `%%...%%` is substituted. Only the three real tags
  (`%%FORTIGATE%%`, `%%MAGIC%%`, `%%PROTURI%%`) may appear literally.
- Keep the page's scripts free of regex literals and backslashes - a mangled regex
  ("Invalid regular expression: missing /") kills the whole script.
- Bump the version in both the line-2 comment and the footer `#page-ver` on every
  change. The footer shows `· OK` only when the full file loaded and the main
  script ran, so it tells you which copy the FortiGate serves and whether it was truncated.
- `window.onerror` prints script errors into the page's alert box for diagnosis.
- The page on https://172.20.10.70 calls https://172.20.10.56 cross-site: the API
  must be HTTPS (mixed content is blocked silently) and cookies are
  `SameSite=None; Secure` over HTTPS (`gw_samesite()`).
- Guests need a FortiGate exempt list / policy to reach 172.20.10.56 on 80/443
  before auth. If Apache logs show no requests from guest IPs, it is the firewall.
- Preferred long-term setup: FortiOS external captive portal pointing at
  `https://172.20.10.56/guestwifi/portal-standalone.html` (no rewriting, same-origin).

## Conventions
- Files use CRLF line endings; keep them (sed -i on Git Bash converts to LF).
