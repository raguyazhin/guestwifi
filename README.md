# Guest Wi-Fi OTP Portal

A captive-portal login for the Sankara Nethralaya guest network. A visitor
lands on the page, accepts the terms, enters a mobile number, receives an OTP
by SMS, and is put online for a fixed period.

**The rule the whole system is built around:** one OTP grants internet access to
exactly one device — the device that requested it. Forwarding the code to a
second phone does not work.

Runs on **PHP 5.6.3 or newer**, including PHP 7 and 8.

---

## 1. Files

The whole application is seven files.

```
portal-standalone.html   the guest portal - ONE self-contained file (CSS, JS,
                         logo all inlined). Test it locally, then upload this
                         single file to the FortiGate. Nothing else goes there.
api.php                  JSON API: init, send_otp, verify_otp, status, logout
portal.php               all the logic - compat, database, OTP policy, sessions,
                         SMS drivers, FortiGate handoff
config.php               every setting (not in git - holds the secrets)
config.example.php       template: copy to config.php
install.php              creates the database, audits the server and config
admin.php                dashboard: who is online, OTP log, SMS gateway replies
storage/                 sms.log, error.log, security.log (created at runtime)
```

Everything on the server side lives in `htdocs\guestwifi`. Only
`portal-standalone.html` is ever copied to the firewall.

---

## 2. Quick start on XAMPP

1. Put this folder at `C:\xampp\htdocs\guestwifi`.
2. Copy `config.example.php` to `config.php`, then set `APP_SECRET` and
   `ADMIN_PASS_HASH`:
   ```
   php -r "echo bin2hex(random_bytes(32));"
   php -r "echo password_hash('your-admin-password', PASSWORD_DEFAULT);"
   ```
3. Start **Apache** and **MySQL** in the XAMPP control panel.
4. Open <http://localhost/guestwifi/install.php> — it creates the database and
   reports anything unsafe.
5. Open <http://localhost/guestwifi/portal-standalone.html>.

### Testing without an SMS gateway

`SMS_DRIVER` ships as `log`: no SMS is sent and nothing is charged. The message
that *would* have gone out is written to `storage/sms.log` and shown in full —
OTP included — in the **SMS gateway log** on <http://localhost/guestwifi/admin.php>
(default login `admin` / `admin123`).

| Step | What to do |
|------|------------|
| 1 | Open the portal, accept the terms, enter any number starting 6–9 |
| 2 | Open `admin.php` in a second tab and read the OTP from the SMS log |
| 3 | Type it into the portal — you reach the "connected" screen |
| 4 | Try that same OTP in a **different browser** — it is rejected |

---

## 3. The access rules

All in `config.php`.

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

`REQUIRE_ALLOWED_LIST` is `false`, i.e. an open guest portal. Set it to `true`
and manage numbers under **Approved numbers** in the dashboard to make it
staff-only.

### How "one OTP, one device" is enforced

1. When the OTP is created, the requesting device's id is stored on the row.
2. At verification the device id must match, and that check runs *before* the
   digits are compared — so a second device learns nothing about the code and
   cannot use up the real user's attempts.
3. The code is then spent with `UPDATE … WHERE used = 0`. Two simultaneous
   verifications cannot both succeed.
4. The session is tied to that same device id, and a second device on the same
   number is refused while it is open.

**Device id** is the MAC the firewall passes as `usermac`, which survives a
browser restart, private browsing and cleared cookies. Without a MAC — testing
locally, or a firewall that does not send one — it falls back to a signed
cookie plus a browser-generated id. That is an *application* identifier, not
proof of hardware identity. For the guarantee to be real in production the
firewall must pass `usermac`.

---

## 4. Putting the page on the FortiGate

Upload **`portal-standalone.html`** under
*System > Replacement Messages > Authentication > Login Page*. It is about
31 KB, within the usual limit, and needs no other files.

The page works in both places without editing: served from `/guestwifi/` it
calls `api.php` next to it; anywhere else it uses the absolute URL at the top
of the file. Set that URL to your server's fixed IP (not a hostname — DNS is
usually blocked before a guest signs in):

```js
window.GW_PORTAL_SERVER = 'http://192.168.0.101/guestwifi/api.php';
```

When FortiOS serves the page it substitutes its own tags, which the file reads
from a hidden login form:

| Tag | Used for |
|-----|----------|
| `%%FORTIGATE%%` | where the credentials are posted |
| `%%MAGIC%%` | the session key FortiOS expects back |
| `%%PROTURI%%` | the page the guest originally asked for |

Then in `config.php`:

```php
define('PORTAL_ALLOWED_ORIGINS', json_encode(['https://192.168.1.99:1003']));
define('FORTIGATE_MODE', 'form_post');
define('FORTIGATE_ALLOWED_HOSTS', json_encode(['192.168.1.99']));
define('FORTIGATE_SHARED_PASS', '<password of the guestwifi local user>');
```

Two more things that are easy to miss:

- **A firewall policy letting unauthenticated guests reach the portal server**
  on TCP 80, above the captive-portal policy. Without it the guest cannot call
  the API and no OTP is ever sent. This is the most common failure.
- **Matching schemes.** A page FortiOS serves over HTTPS cannot call an
  `http://` API — the browser blocks it as mixed content, silently. Either put
  the portal server behind HTTPS with a certificate guests trust, or use the
  FortiGate's HTTP auth listener.

### FORTIGATE_MODE

| Mode | What happens after a valid OTP |
|------|-------------------------------|
| `none` | Success screen only; the firewall keeps blocking. Local testing. |
| `form_post` | The browser posts credentials to the FortiGate. **Use this.** |
| `api` | Provisions a local user over the FortiOS REST API first, then posts. |

With `FORTIGATE_CRED_MODE = 'shared'` every guest authenticates to FortiOS with
one local account. The firewall is the gate; this portal is the lock. Create
that account under *User & Authentication > User Definition*, put it in the
group your captive-portal policy allows, and set its password in `config.php`.

`FORTIGATE_ALLOWED_HOSTS` is a safety catch, not a formality: the firewall
supplies the post URL in the query string, a guest can edit that, and without
the list the portal would hand your firewall password to whatever server they
named. Unlisted hosts are refused and logged to `storage/error.log`.

---

## 5. SMS gateway

```php
define('SMS_DRIVER',   'http');
define('SMS_HTTP_URL', 'https://sms.timesapi.in/api/v1/send');
define('SMS_USERNAME', '...');
define('SMS_PASSWORD', '...');
define('SMS_SENDER_ID', 'SNALRT');
```

`SMS_HTTP_PARAMS` is the request your gateway expects — rename the keys to match
its documentation; `{mobile} {message} {sender} {username} {password}` are filled
in at send time.

If the gateway answers HTTP 200 even when it rejects a message, list the text
that means success, or failures will be recorded as delivered:

```php
define('SMS_SUCCESS_MARKERS', json_encode(['"status":"success"']));
```

`msg91` and `twilio` drivers are also built in. Every send is recorded in
`sms_log` with the gateway's reply, visible in the dashboard. The OTP is masked
before storage unless the driver is `log` and `APP_DEBUG` is on.

---

## 6. API

All responses are JSON: `{ success, message, code, … }`. `code` is stable and
machine-readable; `message` is what the guest sees.

| Endpoint | Body | Purpose |
|----------|------|---------|
| `POST api.php?action=init` | firewall params | Capture context; is this device already online? |
| `POST api.php?action=send_otp` | `{ mobile }` | Apply policy, generate and send an OTP |
| `POST api.php?action=verify_otp` | `{ mobile, otp }` | Verify, open a session, return the handoff |
| `GET api.php?action=status` | — | Session state for this device |
| `POST api.php?action=logout` | — | End this device's session |

Codes: `otp_sent`, `invalid_mobile`, `not_allowed`, `cooldown`, `device_limit`,
`daily_limit`, `already_connected`, `sms_failed`, `invalid_otp`, `otp_expired`,
`device_mismatch`, `too_many_attempts`, `otp_already_used`, `verified`.

---

## 7. Running on PHP 5.6

The code avoids `??`, type declarations, `match` and `str_contains`, so it parses
on 5.6. Two functions the OTP depends on — `random_bytes()` and `random_int()` —
do not exist before PHP 7 and are polyfilled at the top of `portal.php`.

Those polyfills use **`openssl_random_pseudo_bytes()`** and throw rather than
fall back to `mt_rand()`. That is deliberate: `mt_rand()`'s state can be
recovered from a handful of outputs, so an attacker collecting a few OTPs could
predict the next one. **The OpenSSL extension must be enabled on PHP 5.6** or
the portal will refuse to issue codes. `install.php` checks this explicitly.

PHP 5.6 itself has had no security updates since December 2018. Moving that
server to PHP 8 remains the better answer when it becomes possible; nothing in
this code needs to change for it.

---

## 8. Before going live

- [ ] `APP_DEBUG` → `false`
- [ ] `APP_SECRET` → a fresh random value (changing it later logs everyone out)
- [ ] `ADMIN_PASS_HASH` → a new password
- [ ] `SMS_DRIVER` → your real gateway, and send one live test
- [ ] `FORTIGATE_MODE` → `form_post`, with `FORTIGATE_ALLOWED_HOSTS` set
- [ ] Delete `install.php` from the server
- [ ] Give the portal server a fixed IP — the firewall config hardcodes it
- [ ] Put the portal behind HTTPS, or accept that the OTP travels in clear text
      on the guest VLAN
- [ ] Confirm the firewall passes `usermac`, otherwise device binding is
      cookie-based only
- [ ] Decide a retention period for `login_history` and `otp_requests` —
      nothing is purged automatically

Sessions and OTPs expire on their own; every API call clears stale records, so
no cron job is needed.
