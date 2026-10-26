# LucaPet Loan

Digital daily savings + loans. Customers save daily, complete verification (including a physical home visit), then borrow and monitor their interest live.

## Run locally (XAMPP)

1. Start **Apache** and **MySQL** in the XAMPP control panel.
2. Check the defaults in `config.php` (DB port `3307` on this machine; bank details, currency, support contacts).
3. Open `http://localhost/lucapetloan/`. You'll be sent to the installer to create the admin account.

## Deploy on Vercel

The app runs on Vercel through the community PHP runtime (`vercel-php`, see `vercel.json`). All requests go to `api/index.php`, which only serves the public pages. Vercel has no database or disk, so:

- **Database:** use any hosted MySQL (TiDB Cloud, Aiven, Railway, cPanel MySQL with remote access, and so on). Set `DATABASE_URL=mysql://user:pass@host:port/dbname` in the Vercel project's environment variables (or `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`). TLS is on by default on Vercel (`DB_SSL=1`).
- **Logins** are stored in the `sessions` table, and **verification documents** in the `documents` table. Both are created automatically.
- **First admin:** set `SETUP_KEY` to a secret value, then open `/install.php` on the live site and enter it. Once an admin exists, the installer is locked.

Optional environment variables: `APP_NAME`, `BANK_NAME`, `BANK_ACCOUNT_NAME`, `BANK_ACCOUNT_NUMBER`, `SUPPORT_PHONE`, `SUPPORT_EMAIL`, `APP_TIMEZONE`.

## Flow

| Step | Customer | Staff (`/admin`) |
|---|---|---|
| Save | Daily savings plans, deposits by transfer/card/USSD/agent, withdrawals | Confirm deposits, pay withdrawals (Payments) |
| Verify | ID, BVN, home address + GPS, work, bank, next of kin, guarantor, documents, preferred visit date | Schedule visit (officer, date, time) → record visit checklist → verify / reject |
| Borrow | Apply (only after verification) | Approve → pay out (interest starts) |
| Monitor | Live payoff amount, daily interest, chart, schedule, late fees; repay from savings or by transfer | Loan book, overdue list, record cash repayments, mark defaulted |

## Interest rules (`loan_snapshot()` in `includes/functions.php`)

- Simple interest at the product's **monthly rate**, accrued **daily** (30-day months) from the pay-out date, capped at the loan term.
- Early payoff costs only the interest built up so far.
- After the due date, a daily late fee (% of the unpaid balance) is added.
- The loan closes automatically when payments cover the payoff amount.
