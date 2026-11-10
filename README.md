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

Optional environment variables: `APP_NAME`, `SUPPORT_PHONE`, `SUPPORT_EMAIL`, `APP_TIMEZONE`.

**Roles.** The first admin is the *super admin*: they manage staff and their permissions (Staff & roles), change loan and savings rates, payment options and bank details (Settings), and read the audit log of every staff action. Other staff only see the pages their permissions allow.

**Flutterwave.** Set `FLW_SECRET_KEY` (a `FLWSECK_TEST-...` key while testing) and `FLW_SECRET_HASH` (any long random string). In the Flutterwave dashboard, set the webhook URL to `https://<your-site>/flw_webhook.php` with the same secret hash. Customers can then pay deposits and repayments online, and staff can send withdrawals to customers' banks. Withdrawals need the customer's bank picked from the list (Verification or Profile → Payout account).

Password reset emails: set `RESEND_API_KEY` (from resend.com) and `MAIL_FROM` (for example `LucaPet Loan <no-reply@yourdomain.com>`, on a domain verified in Resend). Without a key, reset requests notify the admins, who create a one-time link under Customers → Reset link and send it by SMS or WhatsApp.

**SMS (Termii).** Set `TERMII_API_KEY` and `TERMII_SENDER_ID` (a sender ID approved by Termii). Customers then get texts for approvals, pay-outs, payments, due dates, security changes and password reset links.

**Daily job.** `vercel.json` runs `/cron.php` every morning (08:00 Lagos). It collects auto-save and auto-repay payments from saved cards, sends due-date reminders and re-checks stuck payments. Set `CRON_SECRET` to any long random string so only Vercel can trigger it. A super admin can also run it from Settings → Daily job.

After deploying an update, open `/install.php` once: it applies any database changes and leaves your data alone.

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
