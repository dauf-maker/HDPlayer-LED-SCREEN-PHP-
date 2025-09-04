# HDPlayer-LED-SCREEN-PHP-
Simple PHP script which accepts GET commands to display text on any HDPlayer compliant LED screen.  D-Series.  Has a PHP script that accepts commands and updates the LED screen, and HTML pages that helps test and formulate GET commands. 

Border does not work - not sure why.
Sometimes text via HDPlayer looks better than sent via this script - also not sure why.  Play with different font sizes

# LED Sign Tester

A tiny PHP + HTML tool to push text to an HD Player–compatible LED sign over TCP, and a browser page to build/send requests and save reusable presets.

---

## Table of Contents

- [What’s in here](#whats-in-here)
- [How it works](#how-it-works)
- [PHP service (`led.php`)](#php-service-ledphp)
  - [GET parameters](#get-parameters)
  - [Behavior & response](#behavior--response)
  - [Sizing & appearance details](#sizing--appearance-details)
  - [Examples](#examples)
- [HTML tester (`led.html`)](#html-tester-ledhtml)
  - [What it does](#what-it-does)
  - [Presets (save & send)](#presets-save--send)
- [Deploy / run](#deploy--run)
- [Troubleshooting](#troubleshooting)
- [Security notes](#security-notes)
- [License](#license)

---

## What’s in here

- **`led.php`** – a minimal TCP client that:
  - handshakes with the controller,
  - opens the screen,
  - sends an XML “program” with one or two pages of text.
- **`led.html`** – a single-file web UI to build/send GET URLs to `led.php`, see responses, and save named presets in `localStorage`.

---

## How it works

`led.php` exposes a GET‐driven API. The HTML tester builds a URL like:

```
http://localhost/led.php?DEVICE_IP=10.0.0.146&PAGE_A_TEXT=System%20Down&PAGE_B_TEXT=Ask%20Cashier&COLOR=%23FFFF00&PROGRAM_DURATION=5
```

The PHP:

1. Connects to `DEVICE_IP:10001`.
2. Negotiates protocol & retrieves a GUID.
3. Sends `<in method="OpenScreen"/>`.
4. Builds and pushes an `<in method="AddProgram">` payload with your text.

You get back JSON with the device GUID, echoes of what you sent, and the exact XML pushed to the sign (`sentProgramXml`) for debugging.

---

## PHP service (`led.php`)

### GET parameters

| Param           | Type   | Default         | Description                                                                 |
|-----------------|--------|-----------------|-----------------------------------------------------------------------------|
| `DEVICE_IP`     | string | — (required)    | IPv4 address of the LED controller (e.g. `10.0.0.146`).                     |
| `PAGE_A_TEXT`   | string | `System Down`   | Page A text. URL-encode special chars.                                      |
| `PAGE_B_TEXT`   | string | *(omit)*        | Optional Page B text. If omitted, only one page is sent.                    |
| `PANEL_W`       | int    | `80`            | Logical panel width (px/LEDs) for sizing.                                   |
| `PANEL_H`       | int    | `20`            | Logical panel height.                                                       |
| `COLOR`         | string | `#FFFF00`       | Text color. Hex (`#RRGGBB`) or named. Encode `#` as `%23` if sending manually. |
| `FONT`          | string | `Courier`       | Font family name available to the controller.                               |
| `FONT_SIZE_A`   | int    | *(auto)*        | If provided, overrides auto-size for Page A.                                |
| `FONT_SIZE_B`   | int    | *(auto)*        | If provided, overrides auto-size for Page B.                                |
| `FONT_TYPE_A`   | string | *(normal)*      | If set to `bold` (or truthy like `true`/`1`), Page A uses bold.             |
| `FONT_TYPE_B`   | string | *(normal)*      | If set to `bold` (or truthy like `true`/`1`), Page B uses bold.             |
| `PROGRAM_DURATION` | int | `1` (seconds)   | Page flip duration (seconds). PHP converts to `HH:MM:SS`.                   |

### Behavior & response

- On success, you’ll see JSON like:

```json
{
  "ok": true,
  "device_ip": "10.0.0.146",
  "guid": "1A2B3C...",
  "panel": {"w":80, "h":20},
  "pages": 2,
  "color": "#FFFF00",
  "font": "Courier",
  "sentProgramXml": "<?xml version=\"1.0\" ...>",
  "debug": {
    "openScreenReplyXml": "<sdk .../>",
    "addProgramReplyXml": "<sdk .../>"
  }
}
```

- On error, you’ll get a JSON body with `ok:false` and a message.

### Sizing & appearance details

- **Auto font size**: calculated from panel width & height.
  - Width uses a glyph width ratio (`0.58` by default). Lower it (e.g., `0.52`) if text looks too small.
  - Height is capped at `panel_h - 1`.
- **Single line vs multi line**:
  - If text contains no `\n`, we set `singleLine="true"`.
  - With `\n`, multi-line mode is used.
- **Line spacing**: fixed at `0` for better fill.
- **Fonts**: Different families render with different heights; match the one HD Player uses.

### Examples

Single page, 30s rotation, Courier bold:

```
/led.php?DEVICE_IP=10.0.0.146&PAGE_A_TEXT=HELLO&PROGRAM_DURATION=30&FONT_TYPE_A=bold
```

Two pages, explicit font sizes:

```
/led.php?DEVICE_IP=10.0.0.146&PAGE_A_TEXT=SYSTEM%20DOWN&PAGE_B_TEXT=ASK%20CASHIER&FONT_SIZE_A=14&FONT_SIZE_B=12
```

Color hex:

```
/led.php?DEVICE_IP=10.0.0.146&PAGE_A_TEXT=HELLO&COLOR=%23FF0000
```

---

## HTML tester (`led.html`)

### What it does

- Lets you fill in all fields (device IP, panel size, text, font, color, duration).
- Builds the GET URL and displays it.
- Lets you **open** the URL in a new tab, **send** it via AJAX, and view:
  - JSON response
  - Exact XML sent (`sentProgramXml`)

### Presets (save & send)

- Save the current URL with a name.
- Stored in `localStorage` (`led_saved_presets_v2`).
- Each saved preset shows:
  - **Open**: opens the URL in a new tab.
  - **Send**: sends it and shows the response.
  - **Delete**: removes the preset.

---

## Deploy / run

### Local dev

1. Place both files under your web root:
   ```
   /var/www/html/led.php
   /var/www/html/led.html
   ```
2. Browse to:
   ```
   http://localhost/led.html
   ```
3. Enter the controller IP and send messages.

### PHP requirements

- PHP 7.4+ with socket functions enabled.
- Works with `php-fpm` or `mod_php`.

### CORS

- `led.php` sets `Access-Control-Allow-Origin: *` so you can host the HTML separately.

---

## Troubleshooting

**Text looks shorter than HD Player**

- HD Player auto-sizes more aggressively.
- Try:
  - Matching the same font (`&FONT=Arial` etc).
  - Using explicit `FONT_SIZE_A/B`.
  - Lowering width ratio in PHP for auto-fit.
  - Single-line mode for short messages.

**Connection errors**

- Confirm device IP/port.
- Check firewall rules.

**Presets missing**

- Uses `localStorage`. Not available in private/incognito mode.

---

## Security notes

- This service opens raw TCP sockets to a user-supplied IP.
- Do not expose it publicly without safeguards.
- Use network restrictions or wrap in authentication.

---

## License

MIT (or choose your preferred license).

