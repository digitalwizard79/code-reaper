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

---

## ⚙️ Setup
Clone it. Install dependencies. Unleash the Reaper.

```bash
git clone https://github.com/digitalwizard79/code-reaper.git
cd code-reaper
composer install
chmod +x bin/reaper
