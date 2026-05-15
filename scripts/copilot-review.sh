#!/usr/bin/env bash
# scripts/copilot-review.sh
#
# Requests a GitHub Copilot review (if not yet posted) and fetches inline
# comments for the current branch's PR, formatted for Claude Code analysis.
#
# Usage (in Claude Code prompt — the ! prefix streams output into the chat):
#   ! bash scripts/copilot-review.sh          # auto-detect PR from current branch
#   ! bash scripts/copilot-review.sh 4        # specify PR number explicitly
#
# Workflow:
#   1. gh pr create  (create the PR)
#   2. In Claude Code: ! bash scripts/copilot-review.sh
#      → automatically requests Copilot review if not yet requested
#      → waits up to 90 s for the review to appear
#      → prints inline comments
#   3. Claude Code reads comments and proposes fixes
#   4. Approve or reject each fix manually

set -euo pipefail

REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner)
# Review body is posted by copilot-pull-request-reviewer[bot];
# inline comments are posted by Copilot (no [bot] suffix).
BOT_LOGIN="copilot-pull-request-reviewer[bot]"
BOT_INLINE="Copilot"

# ── Resolve PR number ────────────────────────────────────────────────────────
if [ "${1:-}" != "" ]; then
  PR="$1"
else
  BRANCH=$(git rev-parse --abbrev-ref HEAD)
  PR=$(gh pr list --head "$BRANCH" --json number -q '.[0].number' 2>/dev/null || true)
  if [ -z "$PR" ]; then
    echo "❌  No open PR found for branch '$BRANCH'."
    echo "    Either push and open a PR first, or pass the PR number:"
    echo "    bash scripts/copilot-review.sh <PR_NUMBER>"
    exit 1
  fi
fi

PR_URL="https://github.com/$REPO/pull/$PR"
echo "🤖  Copilot review comments — PR #$PR"
echo "    $PR_URL"
echo ""

# ── Request Copilot review if not yet posted ─────────────────────────────────
EXISTING=$(gh api "repos/$REPO/pulls/$PR/reviews" \
  --jq "[.[] | select(.user.login == \"$BOT_LOGIN\" or .user.login == \"$BOT_INLINE\")] | length" 2>/dev/null || echo "0")

if [ "$EXISTING" = "0" ]; then
  echo "📨  Requesting Copilot review..."
  gh api "repos/$REPO/pulls/$PR/requested_reviewers" \
    --method POST \
    --field 'reviewers[]=Copilot' > /dev/null 2>&1 || true

  echo "⏳  Waiting for Copilot to post review (up to 90 s)..."
  for i in $(seq 1 9); do
    sleep 10
    COUNT=$(gh api "repos/$REPO/pulls/$PR/reviews" \
      --jq "[.[] | select(.user.login == \"$BOT_LOGIN\" or .user.login == \"$BOT_INLINE\")] | length" 2>/dev/null || echo "0")
    if [ "$COUNT" != "0" ]; then
      echo "✅  Copilot review posted."
      break
    fi
    echo "    ... still waiting (${i}0 s elapsed)"
  done
else
  echo "✅  Copilot review already exists."
fi
echo ""

# ── Latest Copilot review body (summary) ────────────────────────────────────
LATEST_REVIEW=$(gh api "repos/$REPO/pulls/$PR/reviews" \
  --jq "[.[] | select(.user.login == \"$BOT_LOGIN\" or .user.login == \"$BOT_INLINE\")] | last // {}")

REVIEW_STATE=$(echo "$LATEST_REVIEW" | python3 -c "
import sys, json
r = json.load(sys.stdin)
print(r.get('state', 'NONE'))
" 2>/dev/null || echo "NONE")

REVIEW_BODY=$(echo "$LATEST_REVIEW" | python3 -c "
import sys, json
r = json.load(sys.stdin)
print(r.get('body', ''))
" 2>/dev/null || echo "")

if [ "$REVIEW_STATE" = "NONE" ]; then
  echo "⚠️   No Copilot review found yet. Wait ~30-60 s after pushing and try again."
  exit 0
fi

echo "── Review state: $REVIEW_STATE ──────────────────────────────────────────"
if [ -n "$REVIEW_BODY" ]; then
  # Print only the overview section (before "### Reviewed changes") to keep output short
  echo "$REVIEW_BODY" | python3 -c "
import sys
lines = sys.stdin.read().split('\n')
for line in lines:
    if line.startswith('### Reviewed changes'):
        break
    print(line)
"
fi
echo ""

# ── Inline comments (root-level only, from Copilot) ─────────────────────────
INLINE_JSON=$(gh api "repos/$REPO/pulls/$PR/comments" \
  --jq "[.[] | select((.user.login == \"$BOT_LOGIN\" or .user.login == \"$BOT_INLINE\") and .in_reply_to_id == null)
        | {path, line: (.line // .original_line), body, url: .html_url}]")

COUNT=$(echo "$INLINE_JSON" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))")

echo "── Inline comments: $COUNT ──────────────────────────────────────────────"

if [ "$COUNT" -eq 0 ]; then
  echo "✅  No inline comments from Copilot. Nothing to fix."
  exit 0
fi

echo ""
echo "$INLINE_JSON" | python3 -c "
import sys, json

comments = json.load(sys.stdin)
for i, c in enumerate(comments, 1):
    print(f'[{i}/{len(comments)}] {c[\"path\"]}  (line {c[\"line\"]})')
    print(f'URL: {c[\"url\"]}')
    print()
    # Indent the body for readability
    for line in c['body'].split('\n'):
        print(f'  {line}')
    print()
    print('─' * 60)
    print()
"

echo ""
echo "👉  Paste or run this output into Claude Code to analyze and stage fixes."
echo "    After Claude Code prepares the edits, review them and commit manually."
