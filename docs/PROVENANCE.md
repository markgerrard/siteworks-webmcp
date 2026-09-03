# Provenance — prior work vs the WebMCP submission

The platform (renderer, shop, client portal) predates the OpenAI WebMCP Challenge. Judges should evaluate the **WebMCP layer** and the **demo integration**, not the existence of a site builder.

Submission period (webmcp.devpost.com/rules): **25 Aug 2026 11:00 PT – 3 Sept 2026 1:00 pm PT**. Cut-off used for git evidence: `2026-08-25T19:00:00+01:00` (= 25 Aug 11:00 PT).

Regenerable dump from the private SiteWorks repo: [provenance/delineation-evidence.md](provenance/delineation-evidence.md), produced by [provenance/assets/delineation-evidence.sh](provenance/assets/delineation-evidence.sh). Regenerated for this file on 2026-09-01 against a read-only checkout of the private repository (`GIT_DIR` pointed at that repo; the script wrote only into this worktree).

## Private repo at the cut-off (prior work)

Last `origin/dev` commit before the cut-off:

```
427fcd5adbae53e65b404c5343826c66a79b8df5  2026-08-25 12:13:46 +0100
Project detail hero joins the glass overlay; work-voice photo copy
```


At that commit, WebMCP did not exist on `dev`:

```
git grep -iE 'modelContext|registerTool|webmcp' 427fcd5a -- . ':!*.lock'
# 0 files
```

Files under `app/Mcp` or named `*Mcp*` at `427fcd5a`: **0**.The shop itself is prior work: the WebMCP entry extends it, it did not invent it.

## First WebMCP work (all after the cut-off)

Verified on the private SiteWorks repository:

| Hash | When (UK) | What |
| --- | --- | --- |
| `e5c36ed4` | 2026-08-28 02:53:01 +0100 | First WebMCP commit: editor tools design spec v1 + decision log + Stage-1 review brief |

`git log --all --reverse -S'registerTool'` starts at `e5c36ed4`.



## This public repo

The public branch is a single release commit of the gate-approved tree (see “Public history is squashed” below). The working history that produced it — import of the private platform, strip, per-track extraction, integration gates — stays private.

## What to score

- **Prior work:** SiteWorks renderer, shop, portal, seed content for one fictional bakery.
- **Submission-period work:** in-page `registerTool` layer, MCP HTTP server, shared `EditorOperations`, approval envelope, and this public demo (strip, SQLite boot, docs).

## Public history is squashed

The public GitHub branch is a single release commit of the gate-approved tree. The working history of this demo repository began as a full import of the private SiteWorks platform, which was then stripped down track by track; publishing that history would publish the private platform, so it stays private. The delineation evidence above therefore cites commit hashes and dates from the private repository, not from this one.

## Verification on request

The two hashes above — the last `dev` commit before the cut-off (`427fcd5a`) and the first WebMCP commit (`e5c36ed4`) — bound the claim “nothing before, everything after”. The full private history is available to the Sponsor, Administrator, or Judges on request for verification.
