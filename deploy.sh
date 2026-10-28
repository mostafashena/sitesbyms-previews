#!/bin/bash
# Run by cPanel cron every 10 minutes: pull latest from GitHub and publish to public_html.
REPO="$HOME/repos/sitesbyms-previews"
cd "$REPO" || exit 1
git pull -q origin main || exit 1
cp -a "$REPO/." "$HOME/public_html/"
