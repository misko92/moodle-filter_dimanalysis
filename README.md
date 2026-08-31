# filter_dimanalysis

Renders dimensional-analysis (factor-label) working the way it is taught:
a given quantity, a horizontal train of fractions joined by "×" signs,
and a diagonal strike through any unit that cancels.

Self-contained HTML + CSS (`styles.css`), no JavaScript, no MathJax
dependency. If `filter_chemformula` is installed, substance labels
(`H2O`, `NaCl`, …) are passed through its formatter for subscripts.

## Syntax

```
GIVEN  x  ( NUM / DEN )  x  ( NUM / DEN )  …  [ = RESULT ]
```

- **Separator** between terms: `x` (space on both sides), or `×`, `*`,
  `·`, `⋅`, `∙` (no spaces needed).
- **Fraction bar**: `/` inside parentheses.
- **Grouping parentheses** are flattened: `(a x (b/c)) x (d/e)` ==
  `a x (b/c) x (d/e)`.
- **Each slot** is `value? unit? label?`, e.g. `2.4 g H2`, `6.02E23 molecules`,
  `1 mol`, `1.008 g`. `6.02E23` and `6.02x10^23` render as `6.02 × 10²³`.
- **Result** after `=` is optional and never participates in cancellation.

Examples that render:

```
5 L x (1 mol / 22.4 L) x (46 g / 1 mol)
(2.4 g H2 x (1 mol / 1.008 g)) x (6.02E23 molecules / 1 mol)
2 mol x (58.44 g / 1 mol) = 116.9 g
```

### Explicit block

Wrap anything the auto-detector would miss (a single factor, unusual
units) in `[da] … [/da]`. Add `nocancel` to suppress the strike-through
for that one expression:

```
[da] 8.0 g NaOH x (1 mol / 40.00 g) [/da]
[da nocancel] 5 L x (1 mol / 22.4 L) [/da]
```

## Cancellation

A numerator quantity (the given, or any factor numerator) cancels a
denominator quantity when the **units match exactly** (case sensitive:
`mg` ≠ `Mg`) and their **substance labels either match or at least one is
absent** — so `mol Na` cancels `mol Na` and `mol` cancels `mol Cu`, but
`mol Na` does **not** cancel `mol Cl`. Pairing is greedy, left to right,
one denominator per numerator.

## Settings

| Setting | Default | Effect |
|---|---|---|
| `autodetect` | on | Format undelimited expressions anywhere. Off ⇒ only `[da] … [/da]` blocks. |
| `defaultcancel` | on | Strike cancelled units unless a block says `nocancel`. |

## Known limitations (deliberate, v1)

- Parentheses nest **one level** deep.
- A bare (unparenthesised) quantity may not contain the ASCII letter `x`.
- The whole expression must sit in a **single text node** — do not split
  factors across editor line breaks; keep it on one line/paragraph.
- A single-factor expression written fully wrapped, `(a x (b/c))`, needs
  `[da]` or no outer parentheses. Multi-factor chains auto-detect fine.
- The aria-label is a **literal reading** of the source
  (`"5 L times 1 mol over 22.4 L …"`), not expanded to words like "litres".

## Tests

```
php public/admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --filter filter_dimanalysis
vendor/bin/behat --tags @filter_dimanalysis
```
