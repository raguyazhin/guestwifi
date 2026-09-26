# Guest Wi-Fi OTP Portal

A captive-portal login page for the Sankara Nethralaya guest network. A visitor
lands on the page, enters a mobile number, receives an OTP by SMS, and is put
online for a fixed period.

**The rule the whole system is built around:** one OTP grants internet access to
exactly one device — the device that requested it. Forwarding the code to a
second phone does not work.

---

## 1. Quick start on XAMPP

1. Put this folder at `C:\xampp\htdocs\guestwifi` (or wherever your `htdocs` is).
2. Copy `config.example.php` to `config.php`, then set `APP_SECRET` to a fresh
   random value and `ADMIN_PASS_HASH` to a password hash:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   php -r "echo password_hash('your-admin-password', PASSWORD_DEFAULT);"
   ```
   `config.php` is deliberately not in version control — it holds every secret
   the portal uses.
3. Start **Apache** and **MySQL** from the XAMPP control panel.
4. Open <http://localhost/guestwifi/install.php>.
   It creates the database and tables and reports anything still unsafe.
5. Open <http://localhost/guestwifi/index.html> — the portal.

Out of the box `SMS_DRIVER` is `log`, so no SMS is sent and nothing is charged.
The message that *would* have been sent is written to `storage/sms.log` and
shown at <http://localhost/guestwifi/dev/inbox.php>, so you can walk the whole
flow on one machine.

### Test it end to end

| Step | What to do |
|------|------------|
| 1 | Open the portal, type any valid 10-digit number starting 6–9 |
| 2 | Open `dev/inbox.php` in a second tab and read the OTP |
| 3 | Type it into the portal — you land on the "connected" screen |
| 4 | Open the same OTP in a **different browser** — it is rejected |

---

## 2. Connecting a real SMS gateway

Edit `config.php`. For most Indian HTTP gateways only the `http` driver block
needs touching:

```php
define('SMS_DRIVER',   'http');
define('SMS_HTTP_URL', 'https://sms.timesapi.in/api/v1/send');
define('SMS_USERNAME', '...');
define('SMS_PASSWORD', '...');
define('SMS_SENDER_ID', 'SNALRT');
```

`SMS_HTTP_PARAMS` is the request your gateway expects. Rename the keys to match
its documentation; the values `{mobile} {message} {sender} {username} {password}`
are filled in at send time.

If your gateway answers HTTP 200 even when it rejects a message, list the text
that means success so failures are not silently treated as delivered:

```php
define('SMS_SUCCESS_MARKERS', json_encode(['"status":"success"']));
```

`msg91` and `twilio` drivers are also included — set `SMS_DRIVER` and fill in
that driver's credentials.

Every send is recorded in the `sms_log` table with the gateway's reply, visible
in the admin dashboard. The OTP itself is masked before it is stored.

---

## 3. The access rules

All of these live at the top of `config.php`.

| Rule | Setting | Default |
|------|---------|---------|
| OTP length / lifetime | `OTP_LENGTH`, `OTP_VALID_MINUTES` | 6 digits, 5 min |
| OTP usable only on the requesting device | `BIND_OTP_TO_DEVICE` | `true` |
| Wrong entries before the code is burned | `MAX_OTP_ATTEMPTS` | 5 |
| Gap between OTP requests | `RESEND_COOLDOWN_SEC` | 60 s |
| OTP requests per number per day | `MAX_OTP_PER_DAY` | 5 |
| OTP requests per source IP per hour | `MAX_OTP_PER_IP_HOUR` | 20 |
| Devices online per number | `MAX_ACTIVE_DEVICES` | 1 |
| …and what happens at that limit | `ON_DEVICE_LIMIT` | `reject` |
| Successful logins per number per day | `MAX_DAILY_SUCCESS` | 3 |
| Length of internet access | `SESSION_MINUTES` | 120 |
| Restrict to a pre-approved list | `REQUIRE_ALLOWED_LIST` | `false` |

`REQUIRE_ALLOWED_LIST` is `false`, i.e. an open guest portal: any valid number
may log in. Set it to `true` and manage the numbers under **Approved numbers**
in the admin dashboard to turn it into a staff-only portal.

### How "one OTP, one device" is enforced

1. When the OTP is created, the requesting device's id is stored on the row.
2. At verification the device id must match, and that check runs *before* the
   digits are compared — so a second device learns nothing about the code and
   cannot use up the real user's attempts.
3. The code is then spent with `UPDATE … WHERE used = 0`. Two simultaneous
   verifications cannot both succeed; only one can change the row.
4. The resulting session is tied to that same device id, and a second device on
   the same number is refused while it is open.

**Device id** is the MAC address the firewall passes in the redirect
(`usermac`). That survives a browser restart, private browsing and cleared
cookies. When there is no MAC — testing locally, or a firewall that does not
pass one — the portal falls back to a signed cookie plus a browser-generated id,
which is an *application* identifier, not proof of hardware identity. For the
guarantee to be real in production, the firewall must pass `usermac`.

---

## 4. FortiGate integration

The portal verifies the OTP. Opening the firewall session is a separate step,
controlled by `FORTIGATE_MODE`:

| Mode | What happens after a valid OTP |
|------|-------------------------------|
| `none` | Success screen only. Use for local testing. |
| `form_post` | The browser posts credentials back to the FortiGate URL that redirected it here — the standard FortiOS external-portal flow. |
| `api` | A local user is provisioned over the FortiOS REST API first, then the same form post. |

### Setting up `form_post`

On the FortiGate, point the captive portal at this page:

```
Security Profiles / Authentication > Captive Portal
  Portal type:   External
  Portal URL:    http://<xampp-server-ip>/guestwifi/index.html
```

FortiOS then redirects clients with `?post=…&magic=…&usermac=…&4Tredir=…`
appended. The portal captures those, and after a valid OTP the browser posts
`magic`, `username`, `password` and `4Tredir` to the firewall's `post` URL.

In `config.php`:

```php
define('FORTIGATE_MODE', 'form_post');
define('FORTIGATE_ALLOWED_HOSTS', json_encode(['192.168.1.99']));  // your firewall
define('FORTIGATE_SHARED_USER', 'guestwifi');
define('FORTIGATE_SHARED_PASS', '<the local user password>');
```

`FORTIGATE_ALLOWED_HOSTS` is a safety catch, not a formality: without it, a
crafted `?post=https://attacker.tld` would make the portal hand your firewall
password to someone else. Requests to an unlisted host are refused and logged
to `storage/error.log`.

With `FORTIGATE_CRED_MODE = 'shared'` every guest authenticates to FortiOS with
one local account, and this portal is the real gatekeeper. Create that account
on the firewall (`User & Authentication > Local Users`) and put it in the group
your captive-portal policy allows.

If you need per-guest firewall accounts instead, set
`FORTIGATE_CRED_MODE = 'mobile'` together with `FORTIGATE_MODE = 'api'` and fill
in the `FORTIGATE_API_*` settings. Endpoint paths differ between FortiOS
versions — check them in the firewall's own API browser before enabling it.

### Hosting the page on the firewall

Use **`portal-standalone.html`** for this. A FortiGate replacement message is a
single HTML document — it will not serve `style.css` and `app.js` as separate
files alongside the page — so that file has the CSS, the JavaScript and the logo
all inlined. It is the only file that goes on the firewall; everything else
stays on the XAMPP server.

Upload it under *System > Replacement Messages > Authentication > Login Page*.
It is about 25 KB, within the usual limit.

Unlike the external-portal model, when the firewall serves the page itself there
are no `usermac`/`magic` query parameters. FortiOS substitutes its own tags
instead, and the file reads them from the hidden login form:

| Tag | Used for |
|-----|----------|
| `%%FORTIGATE%%` | where the credentials are posted |
| `%%MAGIC%%` | the session key FortiOS expects back |
| `%%PROTURI%%` | the page the guest originally asked for |

The same file still works when opened from XAMPP — unsubstituted tags are
detected and ignored — so you can test it before uploading.

Four things to set up:

1. In `portal-standalone.html`, point the API at this server (IP, not hostname —
   DNS is usually blocked before the guest authenticates):
   ```js
   window.GW_API_BASE = 'http://192.168.1.50/guestwifi/api.php';
   ```
2. In `config.php`, allow that origin and enable the handoff:
   ```php
   define('PORTAL_ALLOWED_ORIGINS', json_encode(['https://192.168.1.99:1003']));
   define('FORTIGATE_MODE', 'form_post');
   define('FORTIGATE_ALLOWED_HOSTS', json_encode(['192.168.1.99']));
   ```
3. A firewall policy letting **unauthenticated** guests reach the XAMPP server on
   TCP 80 (or 443), above the captive-portal policy. Without it the guest cannot
   call the API and no OTP is ever sent.
4. Matching schemes on both sides. A page FortiOS serves over HTTPS **cannot**
   call an `http://` API — the browser blocks it as mixed content, silently, with
   nothing the guest can see. Either put the XAMPP server behind HTTPS with a
   certificate guests trust, or use the FortiGate's HTTP auth listener.

The firewall password is never written into the page. The browser receives it
only after an OTP has been verified, so a guest reading the page source learns
nothing.

Note that in this model the firewall passes no client MAC, so device binding
falls back to a browser-stored identifier. See
[How "one OTP, one device" is enforced](#how-one-otp-one-device-is-enforced).

---

## 5. API

All responses are JSON: `{ success, message, code, … }`. `code` is stable and
machine-readable; `message` is the text shown to the guest.

| Endpoint | Body | Purpose |
|----------|------|---------|
| `POST api.php?action=init` | firewall redirect params | Capture context, report whether this device is already online |
| `POST api.php?action=send_otp` | `{ mobile }` | Apply the policy checks, generate and send an OTP |
| `POST api.php?action=verify_otp` | `{ mobile, otp }` | Verify, open a session, return the firewall handoff |
| `GET api.php?action=status` | — | Session state for this device |
| `POST api.php?action=logout` | — | End this device's session |

Useful `code` values: `otp_sent`, `invalid_mobile`, `not_allowed`, `cooldown`,
`device_limit`, `daily_limit`, `already_connected`, `sms_failed`, `invalid_otp`,
`otp_expired`, `device_mismatch`, `too_many_attempts`, `otp_already_used`,
`verified`.

---

## 6. Admin dashboard

<http://localhost/guestwifi/admin/> — default credentials `admin` / `admin123`.

Shows who is online, recent OTP requests and their status, every allow/reject
decision, the SMS gateway log, and the approved-number list. Any device can be
disconnected from here.

Change the password before going live:

```
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
```

and paste the result into `ADMIN_PASS_HASH`.

---

## 7. Before going live

- [ ] `APP_DEBUG` → `false` (this also disables `dev/inbox.php`)
- [ ] `APP_SECRET` → a fresh random value (`php -r "echo bin2hex(random_bytes(32));"`).
      Changing it later logs everyone out.
- [ ] `ADMIN_PASS_HASH` → a new password
- [ ] `SMS_DRIVER` → your real gateway, and send one live test
- [ ] `FORTIGATE_MODE` → `form_post` (or `api`), with `FORTIGATE_ALLOWED_HOSTS` set
- [ ] Delete `install.php` and the `dev/` folder from the server
- [ ] Put the portal behind HTTPS, or accept that the OTP travels in clear text
      on the guest VLAN
- [ ] Confirm the firewall passes `usermac`, otherwise device binding is
      cookie-based only
- [ ] Decide how long to keep `login_history` / `otp_requests` under your data
      retention policy — nothing is purged automatically

Sessions and OTPs expire on their own; every API call clears anything stale, so
no cron job is required.

---

## 8. Files

```
index.html            the portal page (mobile → OTP → connected)
assets/               style.css, app.js, logo — all static, no build step
portal-standalone.html  the same portal as ONE self-contained file, for
                      uploading to the FortiGate replacement message
api.php               JSON API: init, send_otp, verify_otp, status, logout
config.php            every setting lives here
install.php           creates/upgrades the schema, checks the configuration
lib/
  util.php            time, JSON, mobile/MAC normalising, signing, logging
  db.php              PDO connection, schema installer, stale-record cleanup
  portal.php          firewall redirect params, device identity, FortiGate handoff
  otp.php             issue and verify — all policy rules live here
  guest_session.php   internet sessions
  sms.php             SMS drivers: log, http, msg91, twilio
admin/index.php       dashboard
dev/inbox.php         test inbox (debug + 'log' driver + localhost only)
database/guestwifi.sql  schema, if you prefer phpMyAdmin over install.php
storage/              sms.log, error.log, security.log
```

To use the official logo, drop the PNG in as `assets/logo.png` — the page
prefers it and falls back to the bundled `assets/logo.svg` if it is missing.
