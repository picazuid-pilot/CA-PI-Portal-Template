# Contributing

Contributions from any C.A. district, area, or region are very welcome — that's the reason this template exists as a separate, public repository.

## Ways to help

- **Translation** — either continuing the Dutch → English pass (see [`docs/translation-plan.md`](docs/translation-plan.md) for the planned order and what "done" means for a file), or translating the English version into your own language for your own district's deployment.
- **New features** rooted in the WSCPI Handbook that aren't built yet — e.g. a Chit tracking system, a Helpline coverage rota, or tagging campaigns as the annual "Professionals Week" / "Global Poster Week" events. Open an issue to discuss scope before building something large.
- **Bug reports** — please include which page/action, what you expected, and what happened instead. Screenshots help a lot.
- **Hosting-quirk notes** — if you find a workaround needed for a specific host (like the InfinityFree quirks already documented in `docs/setup.md`), add it there.

## Before submitting a translation pull request

1. Pick one file (or one small, clearly-related group) from the translation plan.
2. Search the **entire codebase** for every place that calls a function/variable you're renaming — a simple `grep -rn "oldFunctionName" .` before you start, and again before you submit, to make sure nothing was missed.
3. Test the affected page(s) by hand — click every button, submit every form, since a broken reference from a rename usually fails silently (no error, the action just doesn't do anything).
4. Update the status table in `docs/translation-plan.md` for the file(s) you touched.
5. Keep role keys, and any already-established data-folder paths, unchanged unless you've discussed a migration plan for them in an issue first.

## Code style

- Match the existing style in the file you're editing (this codebase is plain PHP + vanilla JS, no framework, no build step — keep it that way).
- Prefer clear, slightly verbose naming over cleverness — most contributors won't be professional developers, just C.A. members who happen to code a bit.
- Keep comments explaining *why*, not just *what* — especially around hosting quirks and anything that looks unusual at first glance.
