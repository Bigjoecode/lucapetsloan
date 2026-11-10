<?php
/* A plain, printable document page: "Print / Save as PDF" turns it into a PDF in any browser. */

function print_head(string $title): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<style>
  :root { --ink: #14231d; --muted: #5d6b65; --line: #dfe6e2; --brand: #0b4f3c; }
  * { box-sizing: border-box; }
  body { margin: 0; background: #eef2f0; color: var(--ink); font: 14px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif; }
  .sheet { max-width: 820px; margin: 1.5rem auto; background: #fff; padding: 2.2rem 2.4rem; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
  .bar { max-width: 820px; margin: 1rem auto 0; display: flex; gap: .6rem; justify-content: flex-end; padding: 0 1rem; }
  .bar a, .bar button { font: inherit; padding: .55rem 1rem; border-radius: 999px; border: 1px solid var(--line); background: #fff; color: var(--ink); cursor: pointer; text-decoration: none; }
  .bar button { background: var(--brand); color: #fff; border-color: var(--brand); }
  header.doc { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid var(--brand); padding-bottom: 1rem; margin-bottom: 1.2rem; gap: 1rem; }
  header.doc h1 { margin: 0; font-size: 1.35rem; color: var(--brand); }
  .muted { color: var(--muted); }
  h2 { font-size: 1rem; margin: 1.4rem 0 .5rem; color: var(--brand); }
  table { width: 100%; border-collapse: collapse; margin: .4rem 0; }
  th, td { text-align: left; padding: .45rem .5rem; border-bottom: 1px solid var(--line); vertical-align: top; }
  th { font-size: .78rem; text-transform: uppercase; letter-spacing: .03em; color: var(--muted); }
  td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
  .facts td:first-child { color: var(--muted); width: 45%; }
  .box { border: 1px solid var(--line); border-radius: 8px; padding: .8rem 1rem; background: #f8faf9; }
  ol li { margin-bottom: .4rem; }
  @media (max-width: 600px) { .sheet { padding: 1.2rem; margin: .6rem; } header.doc { flex-direction: column; } }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; } }
</style>
</head>
<body>
<?php
}

function print_bar(string $back): void
{
    ?><div class="bar"><a href="<?= e(url($back)) ?>">← Back</a><button onclick="window.print()">Print / Save as PDF</button></div><?php
}
