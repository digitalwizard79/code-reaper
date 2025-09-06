# 🕯️ Changelog – Code Reaper

All notable changes to this project will be documented here.  
This project follows [Semantic Versioning](https://semver.org/).

---

## [0.1.0] – 2025-09-06
### First Harvest 🌑
- Initial public release of **Code Reaper**.
- Static analysis of PHP projects using `nikic/php-parser`.
- Call graph + reachability analysis to identify dead symbols.
- Confidence scoring to separate weak suspicions from cold corpses.
- Outputs:
  - `dead_code.json` (full morgue report of dead symbols)
  - `delete_list.txt` (deduped list of files containing dead code)
  - `delete_list_high.txt` (files fully consumed by death, safe to reap)
- Config via `reaper.yaml` with:
  - `paths.include` / `paths.exclude`
  - `entry_points.files`
  - scoring + keep rules
- Debug mode to reveal scanned files.
- Edgy CLI branding:
  
      bin/reaper --config reaper.yaml --debug

- PHPUnit test suite with a haunted sandbox repo:
  - **PhpScannerTest**
  - **ReachabilityTest**
  - **ReporterTest**
  - **ScanCommandTest**

---

## Unreleased ☠️
- `reaper purge` → auto-delete high-confidence dead files with git safety checks.
- Framework adapters (Laravel/Symfony) to mark controllers & commands as alive.
- HTML graveyard report with filters and search.
- Optional TUI dashboard for interactive scything.
