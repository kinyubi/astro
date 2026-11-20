#!/usr/bin/env python3
"""
run_and_mail.py

Runs a command and emails you what it printed -- for scheduled jobs, so a
run is never silent. If the command fails (non-zero exit, or it can't be
started) the email says so in the subject.

    run_and_mail.py "Visibility windows" -- python visibility_windows.py -a --ending 14
    run_and_mail.py --test                 # send a test email to check the settings

Mail settings are read from astro/.env.prod (the folder above the web root,
same file as the Telescopius key); environment variables of the same name win:

    MAIL_TO=you@gmail.com                 # where reports go
    SMTP_USER=you@gmail.com               # account that sends them
    SMTP_PASSWORD=abcdefghijklmnop        # Gmail: an app password, not your normal one
    SMTP_HOST=smtp.gmail.com              # optional, this is the default
    SMTP_PORT=587                         # optional, default 587
    SMTP_SECURITY=starttls                # optional: starttls (default), ssl or none
    MAIL_FROM=Astro <you@gmail.com>       # optional, default SMTP_USER

Exit code is the command's own, so cron or a caller still sees a failure.
"""
import os
import smtplib
import socket
import subprocess
import sys
import time
from email.message import EmailMessage
from pathlib import Path

ENV_FILE = Path(__file__).resolve().parent.parent / '.env.prod'


def settings():
    s = {}
    if ENV_FILE.exists():
        for line in ENV_FILE.read_text(encoding='utf-8').splitlines():
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            k, v = line.split('=', 1)
            s[k.strip()] = v.strip().strip('"').strip("'")
    for k in ('MAIL_TO', 'MAIL_FROM', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_HOST', 'SMTP_PORT', 'SMTP_SECURITY'):
        if os.environ.get(k):
            s[k] = os.environ[k]
    missing = [k for k in ('MAIL_TO', 'SMTP_USER', 'SMTP_PASSWORD') if not s.get(k)]
    if missing:
        sys.exit(f"Can't send email: {', '.join(missing)} not set in {ENV_FILE} (or the environment).")
    return s


def send(subject, body):
    s = settings()
    msg = EmailMessage()
    msg['Subject'] = subject
    msg['From'] = s.get('MAIL_FROM') or s['SMTP_USER']
    msg['To'] = s['MAIL_TO']
    msg.set_content(body)
    host = s.get('SMTP_HOST', 'smtp.gmail.com')
    port = int(s.get('SMTP_PORT', 587))
    security = s.get('SMTP_SECURITY', 'starttls').lower()
    if security == 'ssl':
        server = smtplib.SMTP_SSL(host, port, timeout=30)
    else:
        server = smtplib.SMTP(host, port, timeout=30)
        if security == 'starttls':
            server.starttls()
    with server:
        if security != 'none':          # 'none' is only for a local test server
            server.login(s['SMTP_USER'], s['SMTP_PASSWORD'])
        server.send_message(msg)


def main():
    args = sys.argv[1:]
    host = socket.gethostname()
    if args == ['--test']:
        send(f"[astro] Test email from {host}",
             f"run_and_mail.py on {host} can send email. Scheduled job reports will arrive like this.\n")
        print("Test email sent.")
        return 0
    if len(args) < 3 or args[1] != '--':
        sys.exit(__doc__)
    name, cmd = args[0], args[2:]

    started = time.time()
    try:
        r = subprocess.run(cmd, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                           stderr=subprocess.STDOUT, text=True, encoding='utf-8', errors='replace')
        output, code = r.stdout, r.returncode
    except OSError as e:
        output, code = f"Couldn't start the command: {e}\n", 127
    mins, secs = divmod(int(time.time() - started), 60)
    took = f"{mins} min {secs} s" if mins else f"{secs} s"

    status = 'done' if code == 0 else f'FAILED (exit code {code})'
    body = output.rstrip() + f"\n\n--\n{name} on {host}, {status}, took {took}.\n"
    if code != 0:
        body += "Command: " + ' '.join(cmd) + "\n"
    try:
        send(f"[astro] {name}: {status}", body)
    except Exception as e:  # still show the output in cron's own log
        print(output, end='')
        print(f"Couldn't send the email: {e}", file=sys.stderr)
        return code or 1
    return code


if __name__ == '__main__':
    sys.exit(main())
