# ☠️ Code Reaper

> *Your repo is haunted. Code Reaper brings the scythe.*

Every project carries ghosts: dead functions, abandoned classes, zombie methods that nobody dares to touch.  
They clutter your repo, slow you down, and rot your codebase from the inside.  

**Code Reaper** hunts them down and tells you what’s safe to bury for good.

---

## 🔪 What It Does
- ⚔️ **Scans** your PHP files and builds a call graph of what’s alive vs. dead.  
- 👻 **Reveals** functions, classes, and methods never touched by your app.  
- 💀 **Scores** confidence: not everything is safe to reap — Reaper tells you which bodies are cold.  
- 📝 **Reports** in JSON + flat lists so you can delete with precision.  
- ☠️ **High-confidence list** = files you can nuke outright.  
- ⚰️ **Purge command** to safely auto-delete dead files (dry-run by default).  

---

## ⚙️ Setup
Clone it. Install deps. Unleash.

```bash
git clone https://github.com/yourname/code-reaper.git
cd code-reaper
composer install
chmod +x bin/reaper
```

---

## 🔥 Usage

### Scan

1. Drop a `reaper.yaml` in your project root:

```yaml
paths:
  include:
    - "src"
    - "public"
  exclude:
    - "vendor"
    - "tests"
entry_points:
  files:
    - "public/index.php"
scoring:
  delete_threshold: 5
output_dir: "out"
```

2. Scan:

```bash
bin/reaper scan --config reaper.yaml
```

3. Debug mode (see what’s on the chopping block):

```bash
bin/reaper scan --config reaper.yaml --debug
```

---

### Purge (Safe Deletion)

Dry-run (default):

```bash
bin/reaper purge --report out/dead_code.json --mode all --threshold 6
```

Apply on a cleanup branch with auto-commit:

```bash
bin/reaper purge \
  --report out/dead_code.json \
  --mode all --threshold 7 \
  --apply --branch reap/cleanup \
  --commit-message "Reap: remove high-confidence dead files" \
  --yes
```

Modes:
- `all` → delete only if **all** symbols in a file are above the threshold. (safer)  
- `any` → delete if **any** symbol in a file is above the threshold. (aggressive)  

---

## 📂 Output
When the smoke clears, you’ll find:

- `out/dead_code.json` → full morgue report, every unused symbol.  
- `out/delete_list.txt` → one line per file touched by death.  
- `out/delete_list_high.txt` → files completely consumed by rot (safe to scythe).  

---

## 🧪 Tests: Prove the Reaper Works

Code Reaper ships with a sandbox and PHPUnit tests.  
They build a fake haunted repo (`tests/Fixtures/sandbox`) with dead methods and ghost classes —  
then unleash the Reaper to make sure it finds the bodies.

### Run all tests

```bash
composer test
```

or

```bash
vendor/bin/phpunit
```

### What gets tested
- **PhpScannerTest** → Reaper can sniff out functions, classes, methods.  
- **ReachabilityTest** → ensures dead symbols really stay dead.  
- **ReporterTest** → confirms reports are written, deduped, and high-confidence lists are correct.  
- **ScanCommandTest** → end-to-end run against the sandbox.  
- **PurgeCommandTest** → dry-run, apply, include/exclude, and git branch/commit flows.  

If tests pass, you’ll see ✅ and the Reaper is sharp.  
If they fail, sharpen your scythe (check config, paths, or fixtures).

---

## 🧪 Example
```php
class Math {
    public static function sum($a, $b) { return $a + $b; }
    public static function product($a, $b) { return $a * $b; } // never called
}
```

Reaper calls it out. `product()` is dead. Bury it.  
`sum()` survives another harvest.

---

## 🛠 Roadmap of Doom
- Framework seers → Laravel / Symfony route parsing so no ghost goes unnoticed.  
- HTML graveyard report with filters and search.  
- Optional TUI dashboard straight from the underworld.  

---

## 🧾 License
MIT.  
Use it to clean your repo. Or feed your enemies’ repos to the Reaper.

---

**Reap your repo. Ship leaner. Fear nothing.**  
*Don’t Fear the Reaper.*
