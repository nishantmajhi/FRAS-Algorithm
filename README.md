# FRAS — Field Rank And Sort

A small PHP library that ranks records against a search query. Give it a
query and an array of associative arrays, name the field to compare, and it
returns the records sorted best match first, each with a `similarityScore`
between 0 and 1.

```php
$ranked = (new FRAS('jon smith', $people))->look('name');
```

The score blends three signals — whether the field starts like the query,
how much of the query's character order survives in the field, and how many
edits apart the two are — with weights you can fix, pick by field type, or
learn from real usage with the companion
[fras-weight-trainer](<../fras-weight-trainer/README.md>).

The repository is also a research log. The first version was AI-generated,
then reviewed, tested and rewritten, and the original is kept runnable in
`legacy/` beside the rewrite so the two can be compared. That history is at
the end of this file.

## Requirements

- PHP 7.4 or newer (tested on 8.4)
- `ext-mbstring`, which every comparison depends on

## Installing

With Composer, from a checkout:

```sh
composer install
```

The classes are in the global namespace and autoloaded by classmap. Without
Composer, `require_once 'src/FRAS.php';` loads everything; it pulls in its
two siblings itself.

## Usage

```php
$records = [
    ['name' => 'Jonathan'],
    ['name' => 'John'],
    ['name' => 'Jon'],
];

// Weights chosen from the length of the query.
$ranked = (new FRAS('John', $records))->look('name');

// Weights for a known kind of field.
$ranked = (new FRAS('ABC123', $skus, null, null, null, 'sku'))->look('sku');

// Weights fixed by the caller (normalised to sum to 1).
$ranked = (new FRAS('John', $records, 0.5, 0.3, 0.2))->look('name');

// Weights learned from usage data, falling back as described below.
$fras = new FRAS('John', $records, null, null, null, 'name', '/path/to/weights.json');
$ranked = $fras->look('name');
```

The constructor, in order:

| Parameter | Default | |
| --- | --- | --- |
| `$query` | | The search string. |
| `$namedArray` | | The records: an array of associative arrays. |
| `$weightPrefix`, `$weightLCS`, `$weightLevenshtein` | `null` | Fixed weights. Used only if all three are given. |
| `$profile` | `null` | `sku`, `name`, `email`, `address`, `free_text`, or a profile in the weights file. |
| `$weightsFile` | `null` | Path to a learned-weights JSON file. |
| `$enableShortCircuit` | `false` | Skip the Levenshtein pass for pairs that cannot score well. See below. |
| `$shortCircuitThreshold` | `0.05` | What "cannot score well" means. |

`look($field)` returns the records sorted by `similarityScore`, highest
first, with four keys added to each: `similarityScore`, `LCSlength`,
`levenshteinDistance` and `levenshteinApproximated`. The array is
re-indexed. It throws `InvalidArgumentException` if there are no records or
any record lacks the field. Non-string field values are cast to string.

After `look()`, `getResolvedWeights()` returns the `[prefix, lcs,
levenshtein]` weights that were used, and `getResolvedWeightSource()` says
where they came from: `explicit`, `learned:profile`, `profile`,
`learned:global` or `heuristic`.

## How a score is computed

Both strings are lower-cased with `mb_strtolower` and split into
characters, so every comparison is case-insensitive and treats a multibyte
character as one character. Then, for a query of length *q* and a field of
length *f*:

**Prefix.** The length of the common prefix, divided by *q*. A query that
matches the start of a *later* word also gets credit, divided by one plus
the word's position, so `smith` against `john smith` scores 1/2 and against
`a b smith` scores 1/3. Words are split on whitespace (Unicode-aware) and
`- _ , . /`. The better of the two counts. A field with a leading delimiter
(` smith`, `.NET Framework`) still gets full credit for its first word.

**LCS.** The length of the longest common subsequence, divided by
min(*q*, *f*) — the most it could possibly be. So a short query found
in order inside a long field scores 1.

**Levenshtein.** One minus the edit distance, divided by max(*q*, *f*) —
the most it could possibly be.

The score is the weighted sum of the three. Because each component is
normalised against its own pair and not against the other records, a
record's score does not depend on what else is in the array.

Two shortcuts keep large record sets cheaper:

- If the query and field share no characters, the LCS is 0 and the DP is
  skipped. This is always on and never changes a result.
- With `$enableShortCircuit`, a pair whose length difference alone rules
  out a Levenshtein score of at least `$shortCircuitThreshold` skips the DP
  and is scored as if every character were an edit. The distance recorded
  is an upper bound, not the true value, and `levenshteinApproximated` is
  set. Off by default, so scores are exact unless you ask otherwise.

## Choosing weights

The first of these that applies wins:

1. **Explicit weights**, if all three were passed.
2. **`profiles.<profile>`** from the weights file.
3. **The built-in profile**, if `$profile` names one:

   | Profile | Prefix | LCS | Levenshtein | |
   | --- | --- | --- | --- | --- |
   | `sku` | 0.60 | 0.25 | 0.15 | short structured codes |
   | `name` | 0.40 | 0.35 | 0.25 | reordering and typos both matter |
   | `email` | 0.50 | 0.20 | 0.30 | the local part leads; typos common |
   | `address` | 0.25 | 0.45 | 0.30 | word order varies |
   | `free_text` | 0.20 | 0.40 | 0.40 | prefix means little |

4. **`global`** from the weights file.
5. **A heuristic on query length**: up to 3 characters is treated as
   autocomplete (0.70 / 0.20 / 0.10), 4 to 8 as balanced
   (0.50 / 0.30 / 0.20), and longer as typo-tolerant (0.30 / 0.40 / 0.30).

An unrecognised profile name is not an error; resolution carries on down
the list. Weights are normalised to sum to 1. Explicit weights that sum to
zero or less fall back to equal weighting.

### The weights file

```json
{
  "profiles": {
    "name": { "prefix": 0.38, "lcs": 0.37, "levenshtein": 0.25 },
    "sku": { "prefix": 0.63, "lcs": 0.22, "levenshtein": 0.15 }
  },
  "global": { "prefix": 0.42, "lcs": 0.33, "levenshtein": 0.25 }
}
```

Both top-level keys are optional, and a profile that is not in the file
falls through to the next rule. But an entry that *is* present must be
well formed, or `look()` throws `InvalidArgumentException` naming it: an
object with all three keys, each a finite, non-negative number, not all
zero. They need not sum to 1. A path that is missing, unreadable, a
directory, or not valid JSON also throws.

`fras-weight-trainer` writes this format from logs of which result a user
actually picked.

## Tests

```sh
composer install
vendor/bin/phpunit
php tests/against-original.php
```

PHPUnit runs 113 tests. Of those, 46 come from
`tests/fixtures/scoring-vectors.json`, which is shared with
fras-weight-trainer: that project reimplements the scoring in C to fit
weights, and both sides assert the same numbers. When the trainer is checked
out beside this project, a further test checks that the two copies of the
file are identical.

The tests in the `regression` group each pin a defect fixed in `src/`.
`tests/against-original.php` runs that group against `src/` as first
committed, taken from `main` with git, and fails unless every one of them
fails there.

If `mbstring` is compiled but not enabled in your `php.ini`, pass
`-d extension=mbstring` (and `-d extension_dir=...` if needed) to each
`php` command.

## Limitations

- **Unicode normalisation is not applied.** `café` typed as `e` plus a
  combining accent is five characters and does not equal the precomposed
  four-character `café`. Normalising needs `ext-intl`, which this library
  does not require.
- **Lower-casing is not full case folding.** `mb_strtolower` leaves `ß` as
  `ß`, so `STRASSE` and `straße` differ by more than case.
- **Scripts written without spaces get no word bonus.** `太郎` inside
  `田中太郎` is found by the LCS but earns nothing on prefix.
- **On PHP 7.x, records with equal scores come back in no particular
  order.** `usort` is stable from PHP 8.0.

## Layout

```
src/        the library: FRAS, LCS, Levenshtein
legacy/     the original implementation, runnable, in namespace Legacy
tests/      PHPUnit suite, the shared vectors, and the regression runner
demo.php    legacy and rewrite side by side: php demo.php
```

## Research log

### The original (`legacy/`)

The first version is kept with no logic changes. Its `require_once` paths
were pointed at local files and it was wrapped in a `Legacy` namespace so
that both versions can load in one PHPUnit process. `LegacyFRASTest.php`
tests its actual behaviour, bugs included, so the fixes have a concrete
baseline. Review found three problems.

**Scores depended on the rest of the batch.** LCS and Levenshtein were
normalised against the largest value among the records passed in, so adding
an unrelated record changed everyone else's score. From `php demo.php`:

```
-- Legacy FRAS, small batch --
  John         score=1.0000
  Jon          score=0.4750
-- Legacy FRAS, same query/records + 1 unrelated outlier record --
  John         score=1.0000
  Jon          score=0.6712
```

That makes scores unstable across pages, uncacheable, and hard to test.

**Strings were compared as bytes.** `strlen()` and `$str[$i]` count bytes,
so `Levenshtein('café', 'cafe')` was 2, because `é` is two bytes in UTF-8.

**One set of weights served every kind of field.** The defaults
(0.5 / 0.3 / 0.2) applied alike to SKUs, where the prefix is nearly
everything, and to addresses, where word order varies and it is nearly
nothing.

### The rewrite (`src/`)

Each component is normalised against its own pair's bound — min(*q*, *f*)
for LCS, max(*q*, *f*) for Levenshtein — which makes a score a function of
the query and field alone; `Jon` scores 0.7000 in both runs above.
Comparisons split strings with `mb_str_split`, so `café` against `cafe` is
one edit. Weights became adaptive: explicit, then profile, then query-length
heuristic. That is a deliberate change from the original, which always used
0.5 / 0.3 / 0.2 unless told otherwise; pass all three weights for the old
behaviour.

Three features followed: the word-boundary prefix bonus, the two
short-circuits, and learned weights from a JSON file. The three-signal
design, the `look()` signature and its validation were kept.

### 2026-09-30: what the rewrite still got wrong

A second review pass, with tests written against the unfixed code first,
found three defects in the rewrite:

- **Case folding was half done.** The query and field were lower-cased for
  the prefix check and the character-overlap shortcut, but the original-case
  strings went to `LCS` and `Levenshtein`. `JOHN` against `john` scored 0 on
  both, and for the query `john`, `Jonathan` outranked `JOHN`. The
  weight trainer found this first: it folded case consistently, and 722 of
  2,000 random mixed-case pairs disagreed with this library.
- **A leading delimiter hid the first word.** The word bonus skipped the
  first word on the grounds that the literal prefix covers it, which holds
  only if the field starts with it. For the query `net`, `.NET Framework`
  scored 0 on prefix and `Microsoft .NET` scored 0.5. The trainer had
  copied the same skip, so comparing the two could not reveal it.
- **Learned weights were trusted.** Every value was cast with `(float)`,
  so `"abc"` and `null` became 0, a negative weight gave scores of 5.0,
  and `1e400` made every score `NAN`.

Also added then: `composer.json`, the shared vectors, and the regression
runner.

## Licence

Free for personal, non-commercial use. You may use, study and modify this
software for yourself, but you may not use it commercially, and you may not
share or publish it, in its original form or modified. See [LICENSE](LICENSE)
for the full terms.
