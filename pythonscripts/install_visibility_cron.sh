#!/bin/bash
# ============================================================
# install_visibility_cron.sh -- run once on the VPS, with sudo:
#
#     sudo bash pythonscripts/install_visibility_cron.sh            # show, ask, install
#     sudo bash pythonscripts/install_visibility_cron.sh --what-if  # show only
#     sudo bash pythonscripts/install_visibility_cron.sh -a         # install without asking
#
# Installs /etc/cron.d/astro-visibility-windows: every Sunday at 8 am
# Boise time, visibility_windows.py -a --ending 14 recalculates the DSO and
# planet visibility windows (the /vis/span/ page), and run_and_mail.py
# emails you what it did plus what stops being visible within 14 nights.
# A failed run is emailed too.
#
# Uses the paths where this script sits (no editing needed), the venv's
# Python (pythonscripts/venv/bin/python, same as /vis), and runs as the
# owner of the pythonscripts folder. Needs MAIL_TO, SMTP_USER and
# SMTP_PASSWORD in astro/.env.prod -- see run_and_mail.py.
# Re-running replaces the cron file; delete the file to stop the job.
# ============================================================
# Started with sh (dash)? Re-run under bash, which this script needs.
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi
set -euo pipefail

MODE=ask
case "${1:-}" in
    --what-if|-w) MODE=whatif ;;
    -a|--apply)   MODE=apply ;;
    "")           ;;
    *) echo "Usage: sudo bash $0 [--what-if | -a]"; exit 2 ;;
esac

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ASTRO="$(dirname "$DIR")"
PY="$DIR/venv/bin/python"
ENV_FILE="$ASTRO/.env.prod"
CRON_FILE=/etc/cron.d/astro-visibility-windows
RUN_AS="$(stat -c %U "$DIR")"

problems=()
[ -x "$PY" ] || problems+=("No Python at $PY (the venv /vis uses).")
[ -f "$DIR/visibility_windows.py" ] || problems+=("visibility_windows.py isn't in $DIR -- upload it first.")
[ -f "$DIR/run_and_mail.py" ] || problems+=("run_and_mail.py isn't in $DIR -- upload it first.")
if [ ! -f "$ENV_FILE" ]; then
    problems+=("No $ENV_FILE.")
else
    for k in MAIL_TO SMTP_USER SMTP_PASSWORD; do
        grep -q "^$k=." "$ENV_FILE" || problems+=("$ENV_FILE has no $k= line (see run_and_mail.py).")
    done
    runuser -u "$RUN_AS" -- test -r "$ENV_FILE" || problems+=("$RUN_AS can't read $ENV_FILE.")
fi
if [ ${#problems[@]} -gt 0 ]; then
    echo "Not installed:"
    printf '  - %s\n' "${problems[@]}"
    exit 1
fi

# 8 am in Boise as the hour cron uses: the system time zone, so TZ is ignored
# (8 or 9 am in Boise across daylight saving if the server is on UTC)
HOUR="$(env -u TZ "$PY" -c "import datetime as d, zoneinfo as z; print(d.datetime.now(z.ZoneInfo('America/Boise')).replace(hour=8, minute=0).astimezone().hour)")"
SERVER_TZ="$(cat /etc/timezone 2>/dev/null || date +%Z)"

CONTENT="# Weekly DSO/planet visibility windows (/vis/span/), emailed to MAIL_TO in .env.prod.
# Installed by $DIR/install_visibility_cron.sh -- delete this file to stop it.
SHELL=/bin/sh
0 $HOUR * * 0 $RUN_AS cd '$DIR' && '$PY' run_and_mail.py 'Visibility windows' -- '$PY' visibility_windows.py -a --ending 14
"

echo "Cron file $CRON_FILE$( [ -f "$CRON_FILE" ] && echo ' (replaces the existing one)'):"
echo "----"
printf '%s' "$CONTENT"
echo "----"
echo "Runs Sundays at $HOUR:00 server time ($SERVER_TZ), as $RUN_AS."

[ "$MODE" = whatif ] && { echo "What-if: nothing installed."; exit 0; }
if [ "$MODE" = ask ]; then
    read -r -p "Install it? [y/N] " yn || yn=
    case "$yn" in [yY]|[yY][eE][sS]) ;; *) echo "Not installed."; exit 0 ;; esac
fi

printf '%s' "$CONTENT" > "$CRON_FILE"
chmod 644 "$CRON_FILE"
chown root:root "$CRON_FILE"
echo "Installed $CRON_FILE."

echo "Sending a test email..."
if runuser -u "$RUN_AS" -- "$PY" "$DIR/run_and_mail.py" --test; then
    echo "Check your inbox (and spam folder) for '[astro] Test email'."
else
    echo "The test email failed -- check the SMTP settings in $ENV_FILE. The cron job is installed but its emails won't arrive until that's fixed."
fi
