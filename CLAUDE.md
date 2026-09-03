# tds_v2 — project conventions

Laravel port of a legacy Keitaro-like TDS (`backend/`), a separate
high-throughput click-processing engine (`traffic-core/`, its own
Composer project — no shared code with `backend/`), and a live
contract-test suite comparing both against the real legacy app
(`tests-contract/`, runs against legacy port 8090 and the new backend).
Legacy reference source lives outside this repo — see
`docs/BACKEND_REMAINING_WORK.md`'s header for the exact path and the
NBSP-in-folder-name trap.

History/process conventions (live verification via Docker, fixture
cleanup, attribution, `php -l`/Pest before commit) are already documented
in `docs/BACKEND_REMAINING_WORK.md`'s header and `docs/PORTING_LOG.md`'s
own footer — this file does **not** repeat those. This file exists
specifically for **token-efficiency conventions**, added 2026-09-03 after
a session-size review found real, avoidable waste. Follow these on every
session, not just when reminded.

## Docblocks: terse, not narrative

**The problem, concretely observed**: controller docblocks accumulated
paragraph-long "CORRECTION (2026-09-03): a prior version of this claimed
X, which was wrong — verified live against legacy port 8090 that Y,
because Z..." essays. `StreamsController.php` is 922 lines this way.
Every future session that reads or edits that file pays for the full
essay again, every time — that cost compounds across dozens of files and
sessions, and the history is *already* captured in `docs/PORTING_LOG.md`
and git log, which is the one designated place for it.

**Rule**: a docblock states what the code does and why, for a reader with
zero session history. It does **not** narrate the investigation that led
to it. If a past mistake is worth flagging so nobody re-introduces it,
one line is enough, pointing at the record of truth:

```php
// Ported action names literally match legacy (getAll/add/delete) — see
// PORTING_LOG.md 2026-09-03 "Все 11 оставшихся..." for why that matters.
```

not:

```php
// CORRECTION (2026-09-03): a prior version of this docblock claimed
// "same divergence as Users/Groups" — that was WRONG, verified by
// reading the real legacy source directly. Component\Users\Controller\
// UsersController's legacy action names already ARE index/create/...,
// so there was no divergence to be consistent with. ObjectDispatch...
// [20 more lines]
```

This applies going forward, to code touched in a session — **not**
retroactive cleanup of existing files (explicitly decided 2026-09-03; the
existing essays are wrong-shaped but not wrong-content, and rewriting
~40 controllers purely for verbosity isn't worth the risk/time on its
own). If you're already editing a method whose docblock has this problem,
trim it down while you're there — don't leave it worse than you found it,
but don't go looking for these across files you have no other reason to
touch.

## Don't dump raw debug output into context

Every error response in this project (legacy and the new backend, by
design — it's a literal contract-fidelity requirement) includes a real
PHP `stacktrace` field, often 30-60 lines. Curling one of these for a
quick status/shape check and letting the full raw body land in context
is pure waste when only the status code or the `error` message actually
matters. Prefer:

```bash
curl -s ... -w "\nstatus: %{http_code}\n" -o /dev/null   # status only
curl -s ... | python3 -c "import json,sys; print(json.load(sys.stdin).get('error'))"   # just the message
```

over piping the raw body straight into your own output. Reach for the
full raw body only when you're actually debugging that stacktrace itself.

## docs/PORTING_LOG.md: compact entries, archive proactively

Append-only by design (continuity across `/clear` is the whole point —
don't stop doing that). But keep each entry a compact diff: what was
wrong, the live proof, the fix, the verification result — a few
paragraphs, not a full incident report. When the file crosses roughly
~1500 lines, or a chunk of it covers a closed, multi-session-old body of
work (a full traffic-core phase set, a fully-closed backlog section),
move that chunk into `docs/PORTING_LOG_ARCHIVE.md` (already started
2026-09-03 with traffic-core Phases 1-17) with a one-paragraph pointer
left behind. Nobody needs the archive for day-to-day work; it exists so
"where did this decision come from" is still answerable later.

## Read narrowly

Prefer `grep -n`/targeted `Read` offsets over reading a 500+ line
controller end-to-end when you only need one method. Use the `Explore`
agent for "where is X" questions instead of grepping around manually
across many files.
