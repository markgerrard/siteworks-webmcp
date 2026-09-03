# Delineation evidence — WebMCP work vs the submission-period start

Generated 2026-09-01T22:31:05Z by `docs/provenance/assets/delineation-evidence.sh` (re-run to refresh).

Submission period (webmcp.devpost.com/rules): **25 Aug 2026 11:00 PT – 3 Sept 2026 1:00 pm PT**.
Cut-off used here: `2026-08-25T19:00:00+01:00` (= 25 Aug 11:00 PT). Last `dev` commit before the cut-off: `427fcd5adbae53e65b404c5343826c66a79b8df5` (2026-08-25 12:13:46 +0100).

## 1. State of the repo at the cut-off (pre-existing)

No WebMCP code, docs, or strings existed before the cut-off:
```
grep -c 'modelContext|registerTool|webmcp|WebMCP' across the tree at 427fcd5adbae53e65b404c5343826c66a79b8df5:
files matching: 0
```

MCP server code on dev at the cut-off (any kind, WebMCP or not):
```
  files under app/Mcp or named *Mcp* at 427fcd5adbae53e65b404c5343826c66a79b8df5: 0
```

The shop is pre-existing work; the WebMCP entry extends it.

## 2. First WebMCP commit (after the cut-off)
```
e5c36ed4 2026-08-28 02:53:01 +0100 docs(webmcp): editor tools design spec v1 + decision log
```

The full private history is available to the Sponsor, Administrator, or Judges on request for verification.
