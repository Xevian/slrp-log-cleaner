# SLRP Log Cleaner

A PHP web app for cleaning and summarising Second Life roleplay chat logs. Strips system noise, tracks per-participant stats, and produces a formatted downloadable log.

## Features

- **File upload or paste** — drop a `.txt` log file or paste directly into the text box
- **Noise filtering** with presets:
  - **Light** — online/offline notices, now-playing notifications
  - **Medium** — adds system messages, item deliveries, combat HUD (CCS/MTR), RP tool messages, object notices
  - **Full** — adds OOC comment stripping `(( ))`
  - **Custom** — pick individual filters and/or add your own keyword/regex patterns
- **Split post merging** — SL sometimes breaks long posts across multiple lines at the same timestamp; these are rejoined into one post for accurate counts
- **Per-participant stats** — post count, estimated time in scene (first → last post), arrival time
- **Minimum post threshold** — exclude drive-by participants from the summary header (default: 2 posts)
- **Custom filters** — saved in your browser across sessions; accepts plain strings or `/regex/flags`
- **Download** — export the cleaned log as a `.txt` file

## Summary format

```
DATE: 29/04/2026
TIME: 20:35 – 01:04
DURATION: 4 hours 29 minutes

PARTICIPANTS:
  Name                                  Posts  Est.     Arrived
  ---------------------------------------------------
  Aria Blackwood (aria.blackwood)          15  2h 25m   21:29
  Cael Merrow (cael.merrow)                12  2h 22m   21:32
  Lyra Ashfen (lyra.ashfen)                 8  1h 43m   22:38
```

## Requirements

- PHP 8.0 or later
- No dependencies or composer required

## Running locally

```bash
cd slrp-log-cleaner
php -S localhost:8080
```

Then open `http://localhost:8080` in your browser.

## Second Life log format

The app expects the standard SL local chat log format:

```
[YYYY/MM/DD HH:MM]  Display Name (username): message content
```

Logs are typically found at:
- **Windows:** `C:\Users\<you>\AppData\Roaming\Second Life\logs\`
- **Mac:** `~/Library/Application Support/Second Life/logs/`

## Filters reference

| Filter | What it removes |
|---|---|
| Online/Offline | `Name: is online. / is offline.` |
| System Messages | All `Second Life:` entries (except music, handled separately) |
| Now Playing | `Second Life: Now playing: …` |
| Item Deliveries | `Second Life: … gave you …` and redelivery notices |
| Combat (CCS/MTR) | Lines from `*CCS`, `*MTR`, `*METER` objects |
| RP Tool | Lines from `RP Tool:` |
| OOC Comments | Lines matching `(( … ))` or ending with `))` without an opening `((` |
| Strip Inline OOC | Removes `(( ))` segments from within otherwise IC posts |
| Object Notices | Lines from named objects (Redelivery, CasperTech, Allomancy, etc.) |
