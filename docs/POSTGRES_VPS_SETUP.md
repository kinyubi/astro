# PostgreSQL + WireGuard Setup on the IONOS VPS (Ubuntu 24.04)

Goal: PostgreSQL running on the VPS, reachable only through a private
WireGuard tunnel from your home Windows machine — never exposed on the
open internet. DDL/admin work happens over plain SSH (no tunnel needed for
that). All commands below are run over SSH on the VPS unless marked
**[Windows]**.

Do the phases in order — each one is tested before moving to the next, so
if something breaks you know exactly which phase to debug.

---

## Phase 0: Before you start

- Confirm you have SSH access to the VPS with sudo (`ssh you@your-vps-ip`).
- **Before enabling any firewall changes below, confirm SSH is already
  allowed.** It's easy to lock yourself out of a VPS by enabling ufw
  without an explicit SSH rule — this guide adds the SSH rule first, but
  double-check `sudo ufw status` shows SSH allowed before you `ufw enable`.
- Check the IONOS Cloud Panel for a separate cloud-level firewall (distinct
  from ufw on the box itself). If IONOS has one active, you'll need to
  allow TCP 22 (SSH) and UDP 51820 (WireGuard, set up in Phase 3) there
  too, and make sure 5432 (Postgres) is NOT opened there.

---

## Phase 1: Install PostgreSQL

```bash
sudo apt update
sudo apt install -y postgresql postgresql-contrib
sudo systemctl enable postgresql
sudo systemctl status postgresql
```

Ubuntu 24.04's default repo ships PostgreSQL 16 — matches what the
migration script was tested against.

---

## Phase 2: Create the database and an app user

```bash
sudo -u postgres psql
```

In the `psql` prompt:

```sql
CREATE DATABASE astro;
CREATE USER astro_app WITH PASSWORD 'CHOOSE_A_STRONG_PASSWORD_HERE';
GRANT ALL PRIVILEGES ON DATABASE astro TO astro_app;
\c astro
GRANT ALL ON SCHEMA public TO astro_app;
\q
```

Use a real generated password (a password manager, not something typed
from memory) and don't reuse it anywhere else — this credential will end
up in PHP/Python config on both the VPS and your home machine.

**Note:** when you run the migration script later, connect as `astro_app`
(not `postgres`) so `astro_app` owns every table it creates. That avoids a
second round of `GRANT` statements on each individual table.

Quick local sanity check (still just on the VPS, no networking involved
yet):

```bash
psql -h localhost -U astro_app -d astro -c "SELECT 1;"
```

---

## Phase 3: Install and configure WireGuard on the VPS

```bash
sudo apt install -y wireguard
cd /etc/wireguard
umask 077
wg genkey | sudo tee server_private.key | wg pubkey | sudo tee server_public.key
```

Note the contents of `server_public.key` — you'll need it for the Windows
client config in Phase 4.

Create `/etc/wireguard/wg0.conf`:

```bash
sudo nano /etc/wireguard/wg0.conf
```

```ini
[Interface]
Address = 10.8.0.1/24
ListenPort = 51820
PrivateKey = <contents of server_private.key>
SaveConfig = false

[Peer]
# home-windows-pc — public key filled in after Phase 4
PublicKey = <client public key, from Phase 4>
AllowedIPs = 10.8.0.2/32
```

You'll come back and fill in the client's public key after Phase 4 —
that's fine, leave a placeholder for now.

Enable and start it:

```bash
sudo systemctl enable wg-quick@wg0 --now
sudo systemctl status wg-quick@wg0
```

### Firewall (ufw)

```bash
sudo ufw allow OpenSSH        # confirm this BEFORE enabling ufw
sudo ufw allow 51820/udp      # WireGuard handshake port
sudo ufw allow in on wg0      # trust all traffic that's already inside the tunnel
sudo ufw status                # verify SSH is listed as ALLOW before continuing
sudo ufw enable
```

Postgres itself is deliberately **not** given a ufw rule — it'll only ever
listen on `localhost` and the WireGuard interface (Phase 5), so there's
nothing on the public interface to allow or block.

---

## Phase 4: Install and configure WireGuard on Windows **[Windows]**

1. Download WireGuard for Windows from the official site: https://www.wireguard.com/install/
2. Open the app → **Add Tunnel** → **Add empty tunnel...** — this
   auto-generates a keypair and shows you the client's public key. Copy it.
3. Go back to the VPS and paste that public key into the `[Peer]` section
   of `/etc/wireguard/wg0.conf` (replacing the placeholder), then:
   ```bash
   sudo systemctl restart wg-quick@wg0
   ```
4. Back in the Windows WireGuard app, replace the tunnel's config with:

   ```ini
   [Interface]
   PrivateKey = <auto-filled by the app, leave as-is>
   Address = 10.8.0.2/32

   [Peer]
   PublicKey = <contents of server_public.key from Phase 3>
   Endpoint = <VPS_PUBLIC_IP>:51820
   AllowedIPs = 10.8.0.1/32
   PersistentKeepalive = 25
   ```

   `AllowedIPs = 10.8.0.1/32` is important — it's a *split tunnel*, meaning
   only traffic to the VPS's WireGuard IP goes through the tunnel. Your
   normal internet traffic is unaffected.

5. Activate the tunnel (toggle it on in the app).
6. Test from a Windows command prompt:
   ```
   ping 10.8.0.1
   ```
   A successful reply means the tunnel is up end-to-end.

---

## Phase 5: Lock Postgres to the tunnel only

Back on the VPS:

```bash
sudo nano /etc/postgresql/16/main/postgresql.conf
```

Find `listen_addresses` and set:

```
listen_addresses = 'localhost,10.8.0.1'
```

```bash
sudo nano /etc/postgresql/16/main/pg_hba.conf
```

Add a line restricting access to exactly your home machine's tunnel IP,
this database, and this user:

```
host    astro    astro_app    10.8.0.2/32    scram-sha-256
```

Restart Postgres:

```bash
sudo systemctl restart postgresql
```

---

## Phase 6: Test from home **[Windows]**

With the WireGuard tunnel active:

```bash
psql -h 10.8.0.1 -U astro_app -d astro -c "SELECT 1;"
```

or from Python:

```python
import psycopg2
conn = psycopg2.connect(
    host="10.8.0.1", dbname="astro", user="astro_app",
    password="...", sslmode="prefer",
)
```

`sslmode="prefer"` is fine here — WireGuard already encrypts everything
inside the tunnel, so there's no need to also issue and manage TLS
certificates for Postgres itself on top of that.

---

## Phase 7: Run the migration

```bash
python migrate_sqlite_to_postgres.py --pg-host 10.8.0.1 --pg-db astro --pg-user astro_app --pg-password "..." --pg-sslmode prefer --reset
```

`--reset` is safe here since this is a brand-new, empty database.

---

## What's deliberately NOT covered here (next steps)

- Nightly `pg_dump` backup cron job + off-VPS copy (rclone to Google Drive
  or scp to home) — happy to write this once Postgres is confirmed working.
- Migration-script convention + `schema_migrations` tracking table for
  future DDL changes (mirrors the existing `migrate_*.py` pattern in
  `dsodb/migrations/`).
- Refactoring the PHP admin app and Python scripts to actually connect to
  this database instead of `astro.db`.
