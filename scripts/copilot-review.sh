#!/usr/bin/env bash
# scripts/copilot-review.sh
#
# Manages GitHub Copilot code reviews for the current branch's PR.
#
# MODES
#   (no flag)          Fetch inline comments → paste into Claude Code for analysis
#   --resolve REPLIES  Post fix replies and resolve all open Copilot threads,
#                      then show the GitHub "Re-request review" link
#
# USAGE (in Claude Code — the ! prefix streams output into the conversation)
#
#   Step 1 – After creating the PR, fetch Copilot comments:
#     ! bash scripts/copilot-review.sh [PR_NUMBER]
#     → requests Copilot review if not yet posted (waits up to 90 s)
#     → prints inline comments for Claude Code to analyze
#
#   Step 2 – After Claude Code fixes the issues, resolve the threads:
#     ! bash scripts/copilot-review.sh [PR_NUMBER] --resolve \
#         "Comment 1 fix description" \
#         "Comment 2 fix description" \
#         ...
#     → posts a reply to each open Copilot thread (one reply per argument)
#     → resolves all open Copilot threads via GitHub GraphQL API
#     → prints a direct link to click "Re-request review" in GitHub
#
#   Step 3 – Click the "Re-request review" link printed by --resolve,
#            then run with no flag again to check for new comments.

set -euo pipefail

REPO=$(gh repo view --json nameWithOwner -q .nameWithOwner)
# Review body is posted by copilot-pull-request-reviewer[bot];
# inline comments are posted by Copilot (no [bot] suffix).
BOT_LOGIN="copilot-pull-request-reviewer[bot]"
BOT_INLINE="Copilot"

# ── Parse arguments ───────────────────────────────────────────────────────────
PR=""
MODE="fetch"
REPLIES=()

while [ $# -gt 0 ]; do
  case "$1" in
    --resolve)
      MODE="resolve"
      shift
      while [ $# -gt 0 ]; do
        REPLIES+=("$1")
        shift
      done
      ;;
    *)
      if [ -z "$PR" ]; then
        PR="$1"
      fi
      shift
      ;;
  esac
done

if [ -z "$PR" ]; then
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
echo "🤖  Copilot review — PR #$PR"
echo "    $PR_URL"
echo ""

# ══════════════════════════════════════════════════════════════════════════════
# MODE: resolve — post replies + resolve threads + show re-request link
# ══════════════════════════════════════════════════════════════════════════════
if [ "$MODE" = "resolve" ]; then
  # Fetch open Copilot threads (unresolved, root-level comments only)
  THREADS_JSON=$(gh api graphql -f query="
  {
    repository(owner: \"$(echo "$REPO" | cut -d/ -f1)\", name: \"$(echo "$REPO" | cut -d/ -f2)\") {
      pullRequest(number: $PR) {
        reviewThreads(first: 50) {
          nodes {
            id
            isResolved
            comments(first: 1) {
              nodes {
                databaseId
                author { login }
                body
              }
            }
          }
        }
      }
    }
  }" 2>/dev/null)

  # Extract open Copilot thread IDs and root comment databaseIds
  OPEN_THREAD_DATA=$(echo "$THREADS_JSON" | python3 -c "
import sys, json
d = json.load(sys.stdin)
threads = d['data']['repository']['pullRequest']['reviewThreads']['nodes']
result = []
for t in threads:
    if t['isResolved']:
        continue
    c = t['comments']['nodes'][0] if t['comments']['nodes'] else {}
    author = c.get('author', {}).get('login', '')
    if author in ('Copilot', 'copilot-pull-request-reviewer'):
        result.append({'thread_id': t['id'], 'comment_db_id': c.get('databaseId', 0)})
for r in result:
    print(r['thread_id'], r['comment_db_id'])
")

  THREAD_COUNT=$(echo "$OPEN_THREAD_DATA" | grep -c . || true)
  echo "── Open Copilot threads: $THREAD_COUNT ──────────────────────────────────"

  if [ "$THREAD_COUNT" -eq 0 ]; then
    echo "✅  No open Copilot threads to resolve."
  else
    REPLY_IDX=0
    while IFS=' ' read -r THREAD_ID COMMENT_DB_ID; do
      [ -z "$THREAD_ID" ] && continue

      # Post reply if a message was supplied for this index
      if [ "$REPLY_IDX" -lt "${#REPLIES[@]}" ]; then
        REPLY_TEXT="${REPLIES[$REPLY_IDX]}"
        gh api "repos/$REPO/pulls/$PR/comments" \
          --method POST \
          --field "body=$REPLY_TEXT" \
          --field "in_reply_to=$COMMENT_DB_ID" > /dev/null 2>&1 && \
          echo "  💬  Reply posted to thread $(( REPLY_IDX + 1 ))"
      fi

      # Resolve the thread via GraphQL
      RESULT=$(gh api graphql -f query="
mutation {
  resolveReviewThread(input: {threadId: \"$THREAD_ID\"}) {
    thread { id isResolved }
  }
}" 2>/dev/null)
      RESOLVED=$(echo "$RESULT" | python3 -c "
import sys,json
d=json.load(sys.stdin)
print(d['data']['resolveReviewThread']['thread']['isResolved'])
" 2>/dev/null || echo "error")
      echo "  ✅  Thread $(( REPLY_IDX + 1 )) resolved (isResolved=$RESOLVED)"
      REPLY_IDX=$(( REPLY_IDX + 1 ))
    done <<< "$OPEN_THREAD_DATA"
  fi

  echo ""
  echo "── Re-request Copilot review ────────────────────────────────────────────"
  echo "  GitHub API does not support programmatic re-request for Copilot bot."
  echo "  👉  Click 'Re-request review' next to Copilot here (refresh if needed):"
  echo "      $PR_URL"
  echo ""
  echo "  After Copilot posts the new review, run:"
  echo "      bash scripts/copilot-review.sh $PR"
  exit 0
fi

# ══════════════════════════════════════════════════════════════════════════════
# MODE: fetch — request review if needed, then print comments
# ══════════════════════════════════════════════════════════════════════════════

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

# ── Open inline comments (root-level, unresolved, from Copilot) ──────────────
THREADS_JSON=$(gh api graphql -f query="
{
  repository(owner: \"$(echo "$REPO" | cut -d/ -f1)\", name: \"$(echo "$REPO" | cut -d/ -f2)\") {
    pullRequest(number: $PR) {
      reviewThreads(first: 50) {
        nodes {
          isResolved
          comments(first: 1) {
            nodes {
              databaseId
              author { login }
              path
              line
              originalLine
              body
              url
            }
          }
        }
      }
    }
  }
}" 2>/dev/null)

INLINE_JSON=$(echo "$THREADS_JSON" | python3 -c "
import sys, json
d = json.load(sys.stdin)
threads = d['data']['repository']['pullRequest']['reviewThreads']['nodes']
result = []
for t in threads:
    if t['isResolved']:
        continue
    c = t['comments']['nodes'][0] if t['comments']['nodes'] else {}
    author = c.get('author', {}).get('login', '')
    if author in ('Copilot', 'copilot-pull-request-reviewer'):
        result.append({
            'path': c.get('path', ''),
            'line': c.get('line') or c.get('originalLine'),
            'body': c.get('body', ''),
            'url':  c.get('url', ''),
        })
import json as j
print(j.dumps(result))
")

COUNT=$(echo "$INLINE_JSON" | python3 -c "import sys,json; print(len(json.load(sys.stdin)))")

echo "── Open inline comments: $COUNT ─────────────────────────────────────────"

if [ "$COUNT" -eq 0 ]; then
  echo "✅  No open inline comments from Copilot. Nothing to fix."
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
    for line in c['body'].split('\n'):
        print(f'  {line}')
    print()
    print('─' * 60)
    print()
"

echo ""
echo "👉  After fixing, resolve threads with:"
echo "    bash scripts/copilot-review.sh $PR --resolve \\"
echo '        "Fix 1: description" \'
echo '        "Fix 2: description" ...'
