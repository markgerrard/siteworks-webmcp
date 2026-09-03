# webmcp-evals — case rework + multi-step baseline, 2026-09-01

Supersedes the 2026-09-01 05:22 baseline (7/20 steps, the single-turn backend, the default backend model).
That baseline's A/B dispositions are executed below; its numbers are NOT comparable to
these (different backend semantics, different step denominators).

## Harness findings (why the baseline mismeasured)

1. **the single-turn backend is SINGLE-TURN.** One `generateContent` call; no tool loop, mock
   outputs never served. Any case whose correct trajectory is read-then-write could
   never fully pass. The multi-step backend is `-b vercel` (AI SDK agent loop, max 6
   steps, MockResolver serves `mockOutput` per expected node).
2. **The CLI defaults the model to `the default backend model`.** The baseline ran on it
   unnoticed. Runs are pinned to one backend model so results stay comparable.
3. **AI-SDK→Gemini rejects nullable union types** (`"type": ["string","null"]`, 17
   sites in our schemas). The tools.json converter (README step 2) now collapses them.
4. **Multi-step needs `mockOutput` on every expected node.** A `{}` tool result reads
   as a failed call and the model spirals into retries/exploration, which score as
   "Unexpected tool call".

## Case rework (dispositions A/B/D executed)

- **A (protocol adherence scored as failure)** — every mutation case now wraps an
  `unordered` group: optional orientation reads (with realistic mocks) around the
  required terminal write. Skill ops mock a real protocol payload.
- **B (`expectedCall: []` too absolute)** — the three no-write cases are now groups of
  optional reads only: zero calls passes, any read passes, any write fails.
- **D (arguments mismatch) — resolved as case bugs, not model errors.** Actual args
  showed the model passing `catalogue_revision` (op-level REQUIRED field) where cases
  hard-pinned `expected_revision`. Cases now assert the unambiguous lock
  (`product_revision` where schema-required) and drop the contested name.
  `near-duplicate` no longer demands refusing an explicit user override (seeded turns
  end before the override); `missing-price` accepts an optional protocol-clean commit
  (dry_run:false + idempotency_key) after its dry run.
- `update-draft-carries-expected-revision` renamed → `update-draft-carries-product-revision`.

## Last completed run (the default backend model, `-b vercel`)

**32/43 steps (74.4%), 16/20 cases fully green.** Three case fixes landed after it
(missing-price optional commit, near-duplicate absorbers, list-before-draft mock
categories); the validating full run has not been re-run since the case rework. Expected end-state: 18/20 green with two deliberate reds:

## Genuine Layer-0 gaps (the description-pass levers — both reproduce on the backend model)

1. **`get_brand_context` vs `get_brand_system`** — model picks legacy context for a
   brand-system question. Descriptions don't disambiguate legacy context vs effective
   palette/token system.
2. **Commit without `idempotency_key`** — model commits with `plan_token` only. Proof
   it's a description gap: in `missing-price` (where it called
   `describe_import_products` itself) it DID supply `idempotency_key` — the rule lives
   only in describe's output, not in `import_products`' description.
3. **Revision-name seam** — ops require `catalogue_revision`, skill protocol prose
   says `expected_revision` (`SkillImportCatalogueFromSourceOperation` step 8,
   `SkillAddProductWithImageryOperation` step 5). Models follow the schema. Unify on
   one name in the description pass.

