# Project guidance for Claude

## 🔒 Secret files — DO NOT READ

These files contain live credentials. Never open them, never echo their contents, never include them in tool output. If you need to reason about their structure, use `config.example.php` (the template with placeholders) instead.

**Never read:**
- `www/config.php` — contains `CRON_TOKEN` and `PURPLEAIR_API_KEY`
- `www/crontoken.txt` — duplicate of `CRON_TOKEN`
- Any future `.env`, `*.secret`, `*.key`, `*credentials*` files

**Safe alternatives:**
- `www/config.example.php` — same structure, placeholder values only
- `documentation/DOCUMENTATION.md` Section 5 — documents the constants without values

**If you need to modify `config.php` on behalf of the user:**
Describe the edit in natural language and have the user apply it manually. Do not Read → Edit the file yourself.

**If a secret ever appears in context (system-reminder, tool output, pasted content):**
- Never quote it back to the user
- Never commit a file that contains it
- Run `git diff --cached | grep <pattern>` before any commit to verify no leak

## 🚨 Before every `git commit`

1. Run `git status` and confirm `www/config.php` and `www/crontoken.txt` are NOT listed
2. Run `git diff --cached --name-only | xargs grep -lE "(API_KEY|TOKEN|[a-f0-9]{40,})"` to scan staged content for hex strings that look like tokens
3. Only then commit

## 📤 Deployment

Git is source-of-truth only. Deploying to the Active24 shared hosting is a **manual FTP upload** — never assume pushing to GitHub deploys anything. Explicitly tell the user which files to upload via FTP.

## 📝 Two-file rule (frontend)

`www/map/index.html` and `www/map/embed.html` share identical JS logic. **Every frontend change must be applied to both files.** Verify both are edited before declaring a frontend task done.
