# Code Review — FRAS (Field Rank And Sort)

First reviewed 2026-09-04. **Remediated 2026-09-30** on branch
`cleanup/2026-09-30`; this file is now a record rather than a to-do list.
The first review's findings are listed at the end with what happened to
each.

## Corrections to the first review

**"The gaps are packaging, not algorithms" was wrong.** The review read the
DP classes closely, praised them, and they are correct. It did not follow
what `look()` hands them. Three defects in the scoring itself, all fixed:

1. **Two of the three components were case-sensitive.** `look()` lower-cased
   the query and field, used the lower-cased copies for the prefix check and
   for the character-overlap short-circuit, then passed the *original-case*
   strings to `new LCS(...)` and `new Levenshtein(...)`. `JOHN` against
   `john` scored prefix 1, lcs 0, levenshtein 0; for the query `john`,
   `Jonathan` outranked `JOHN`. This one was already known — the
   `fras-weight-trainer` pass found it with a differential and left it here
   deliberately — and its consequence reached that project: weights it
   fitted from mixed-case data were fitted to a function this library did
   not run.
2. **A leading delimiter hid the field's first word.** The token bonus
   skipped token 0 because "the literal prefix check covers it", which is
   true only when the field starts with token 0. For ` smith`,
   `.NET Framework` or `-acme corp`, neither check saw the first word. For
   the query `net`, `.NET Framework` scored 0 on prefix while
   `Microsoft .NET` scored 0.5 and ranked above it. The trainer mirrors the
   PHP, so it had the same skip, and a differential between two
   implementations cannot see a bug they share. Found by reading.
3. **Learned weight values were not validated,** which the review did name,
   at P3. It is worse than "silently wrong ranking": a negative weight gave
   a `similarityScore` of 5.0 and inverted the order, and `1e400` (read by
   `json_decode` as `INF`) made every score `NAN`.

**Finding 3, the length prefilter, was half wrong.** It already exists, as
the opt-in `$enableShortCircuit`, for Levenshtein, where the length
difference is a lower bound on the distance. The review's version — skip
"the expensive comparison" entirely when a 5-character query meets a
40-character field — would be wrong for the LCS, which is normalised by the
*shorter* length: `smith` inside a 40-character company name is a perfect
LCS score. Not done; nothing to do.

**Finding 1 said `tests/bootstrap.php` "works here and nowhere else".** It
uses `__DIR__`-relative paths and works from any checkout. The real gap was
that nothing declared the PHP version or `ext-mbstring`, and that PHPUnit
had to be installed globally.

## What was fixed

Five commits of work, each tested green on its own, and a sixth for the
README and this file. 113 PHPUnit tests, up from 50;
every one of the 14 in the `regression` group fails against `main`, and
`tests/against-original.php` asserts that on every run.

| Commit | |
| --- | --- |
| `composer.json` | PHP >= 7.4, `ext-mbstring`, PHPUnit ^9.6 as a dev dependency, classmap autoload |
| Case folding | the two arguments; 4 regression tests |
| Leading delimiter | token 0 included in the bonus at full weight; 2 regression tests |
| Weight validation | values must be finite non-negative JSON numbers, not all zero; a present entry must be an object; a directory is "not found or not readable"; 8 regression tests, and one test that the trainer's exact output is still accepted |
| Shared vectors | `tests/fixtures/scoring-vectors.json`, 46 pairs, asserted by `SharedVectorsTest` |

Why the fixes are the shape they are:

- **Case: the two arguments, nothing more.** The lengths used for
  normalisation were already taken from the lower-cased strings. That
  matters because `mb_strtolower('İ')` is two code points, so passing the
  original strings also made the denominator disagree with the DP.
- **Token 0 is included, not special-cased.** When a field starts with
  token 0, the literal comparison walks the same characters from the same
  place and can only run further, so it is always at least the token score
  and `max()` keeps it. Only fields with a leading delimiter move. The
  existing test named `testTokenBoundaryBonusDoesNotApplyToFirstToken` was
  renamed; what it asserted still holds.
- **Weights need not sum to 1**, because `normalize()` already divides by
  the sum, and the trainer writes four-decimal values that may not sum
  exactly. **Constructor weights are not validated**: `FRASAdvancedTest`
  documents negative explicit weights as the caller's choice, and a float
  in code is not the risk that a file on disk is.
- **Classmap, not PSR-4, and no namespace** (the first review's P3). The
  README and the trainer's `tools/check_php.php` both construct
  `new FRAS(...)`, and the trainer loads `src/FRAS.php` by path, which is
  why its `require_once` lines stay.
- **`composer.lock` is not committed.** Resolved on 8.4 it pins
  `doctrine/instantiator` 2.x, which needs PHP 8.1, contradicting the
  declared 7.4 floor.
- **The shared vectors are a copy, not a path into the sibling.** Both
  copies are asserted against this library, and a third test requires them
  to be identical when both projects are checked out side by side.

## The companion project was changed too

The rule is one project at a time, and this is the exception, because the
trainer's only value is agreeing with this library. One commit on
`fras-weight-trainer`'s existing `cleanup/2026-09-18` branch:

- the token-0 skip removed from `src/scoring.c`, with four checks that fail
  first; 394 checks now, up from 386;
- `tests/vectors.json` regenerated: the 8 divergent vectors moved to
  `agreed` with the trainer's numbers unchanged, and 4 leading-delimiter
  pairs added. Compared entry by entry, nothing else moved;
- `vectors_test.c` now requires the divergent group to be **empty**;
- `tools/differential.php`, the random differential, committed: 4,000 and
  20,000 pairs agree completely. Against this project's `main` it reports
  732 mixed-case pairs and 15 prefix mismatches.

Details are in that project's `review.md` under "Follow-up, 2026-09-30".

## Deliberately not done

- **No `LICENSE`.** Choosing one is the author's decision; `composer.json`
  has no licence field for the same reason.
- **No namespace.** See above.
- **Unicode normalisation (NFC/NFD).** Precomposed and decomposed `é` are
  different characters here. Fixing it needs `ext-intl`, a new hard
  requirement, and the trainer would have to follow. Listed as a limitation
  in the README.
- **Full case folding** (`ß` against `ss`). `mb_strtolower` is what the
  trainer's Unicode tables are generated from; changing it is a coordinated
  change to both projects, not a fix.
- **An unknown profile name is still silent.** `FRASTest` asserts it falls
  back to the heuristic, and the README documents it. It is a design
  choice, not a slip.
- **`$shortCircuitThreshold` is not range-checked.** Above 1 it
  approximates every pair, including identical strings, but only a caller
  who asked for it gets it.
- **`legacy/` is untouched**, as the first review asked.

## Notes for whoever is next

**Tested on PHP 8.4 only.** The 7.4 floor comes from the features the code
uses (`mb_str_split`, typed properties, arrow functions), not from running
it on 7.4. On 7.x, `usort` is not stable, so records with equal scores come
back in no particular order; the README says so.

**On this machine** mbstring is compiled but not enabled, and Composer is
at `C:\Users\manis\.config\herd-lite\bin\` with its own PHP. The install
put its home and cache on `D:`:

```sh
set COMPOSER_HOME=D:\dev-tools\composer-home
set COMPOSER_CACHE_DIR=D:\dev-tools\composer-cache
C:\Users\manis\.config\herd-lite\bin\php.exe C:\Users\manis\.config\herd-lite\bin\composer.phar install
```

then, with the PHP on `D:`:

```sh
php -d extension_dir=D:\php-8.4.14-Win32-vs17-x64\ext -d extension=mbstring vendor/phpunit/phpunit/phpunit
php -d extension_dir=D:\php-8.4.14-Win32-vs17-x64\ext -d extension=mbstring tests/against-original.php
```

Expect `OK (113 tests, 255 assertions)` and `14 of 14 regression tests
fail against main`.

**If you change the scoring**, change `fras-weight-trainer/src/scoring.c`
to match, run `make vectors` there (on Windows, pass the probe as
`tests\build\probe.exe`; see that project's `review.md`), copy
`tests/vectors.json` to `tests/fixtures/scoring-vectors.json` here, and run
both suites. `SharedVectorsTest` fails until the copies match.

## First review's findings, and their outcome

| # | Finding | Outcome |
| --- | --- | --- |
| 1 | P2 — no `composer.json` | Done; see the correction about `bootstrap.php` |
| 2 | P2 — shared scoring vectors checked by both suites | Done; 46 vectors, asserted from both ends |
| 3 | P3 — namespace the classes | Not done; would break every caller |
| 4 | P3 — length prefilter before the DP passes | Already existed for Levenshtein; wrong for LCS |
| 5 | P3 — validate learned weight values | Done, and it was worse than described |
| 6 | P3 — no `LICENSE` | Left to the author |
| — | "keep `legacy/`" | Kept, untouched |

## Licence, 2026-10-02

The author chose one licence for all of their projects: free for personal,
non-commercial use, modifiable for the user's own use, and not to be
distributed, original or modified. No standard licence says that, so `LICENSE`
is a short custom text, the same in every project apart from the year and what
it excludes. The README's licence section says the same, and `composer.json`
declares `proprietary`, Composer's word for a licence with no SPDX identifier.
Anything above that calls the licence an open question, or calls the project
MIT, is superseded.

## CI, 2026-10-02

`.github/workflows/ci.yml` runs `composer install` and PHPUnit on PHP 8.4 with
mbstring, on `ubuntu-latest`. Nothing has been pushed, so it has not run on
GitHub yet. Every job was run before committing, from a fresh clone of this
branch, the way a runner would run it: Linux jobs in a throwaway Ubuntu 24.04
(the image `ubuntu-latest` uses) with the same tool versions. 113, with the
one trainer-dependent test skipping.
