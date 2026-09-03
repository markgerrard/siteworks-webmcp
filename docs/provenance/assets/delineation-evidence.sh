#!/bin/bash
# Regenerates docs/provenance/delineation-evidence.md from git. Run from the repo root on dev.
set -u
START='2026-08-25T19:00:00+01:00'   # WebMCP Challenge submission period opens 25 Aug 2026 11:00 PT
BASE=$(git rev-list -1 --before="$START" origin/dev)
{
echo "# Delineation evidence — WebMCP work vs the submission-period start"
echo
echo "Generated $(date -u +%FT%TZ) by \`docs/provenance/assets/delineation-evidence.sh\` (re-run to refresh)."
echo
echo "Submission period (webmcp.devpost.com/rules): **25 Aug 2026 11:00 PT – 3 Sept 2026 1:00 pm PT**."
echo "Cut-off used here: \`$START\` (= 25 Aug 11:00 PT). Last \`dev\` commit before the cut-off: \`$BASE\` ($(git log -1 --format=%ci $BASE))."
echo
echo "## 1. State of the repo at the cut-off (pre-existing)"
echo
echo "No WebMCP code, docs, or strings existed before the cut-off:"
echo '```'
echo "grep -c 'modelContext|registerTool|webmcp|WebMCP' across the tree at $BASE:"
git grep -c -iE 'modelContext|registerTool|webmcp' $BASE -- . ':!*.lock' 2>/dev/null | wc -l | sed 's/^/files matching: /'
echo '```'
echo
echo "MCP server code on dev at the cut-off (any kind, WebMCP or not):"
echo '```'
echo "  files under app/Mcp or named *Mcp* at $BASE: $(git ls-tree -r --name-only $BASE | grep -cE '(^|/)app/Mcp/|Mcp[A-Z][a-z]' || true)"
echo '```'
echo
echo "The shop is pre-existing work; the WebMCP entry extends it."
echo "## 2. First WebMCP commit (after the cut-off)"
echo '```'
git log --all --reverse --format='%h %ci %s' -S'registerTool' | head -1
echo '```'
echo

} > docs/provenance/delineation-evidence.md
echo "wrote docs/provenance/delineation-evidence.md ($(wc -l < docs/provenance/delineation-evidence.md) lines)"
