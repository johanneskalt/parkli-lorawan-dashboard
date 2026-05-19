# LoRaWAN lake sensor dashboard

This repository contains a small example project for displaying live LoRaWAN sensor data from **The Things Network** on a public iPad dashboard.

The setup was built for a lake sensor buoy that measures water temperature at three depths, pH, TDS/conductivity, battery status and LoRaWAN signal quality.

## Architecture

```text
Sensor buoy
  ↓ LoRaWAN
The Things Network
  ↓ Webhook
Small PHP endpoint on webspace
  ↓ JSON API
HTML dashboard
  ↓
iPad kiosk display
```

## Repository structure

```text
dashboard/
  index.html
  kiosk_legacy.html
  assets/

api/
  ttn/uplink/index.php
  parkli/latest/index.php
  admin/reset-history.php
  data/

examples/
  sample-ttn-payload.json
  sample-api-response.json
```

## Files

- `dashboard/index.html`: modern dashboard version for newer iPads and browsers.
- `dashboard/kiosk_legacy.html`: legacy version for older iPads. It avoids modern JavaScript features such as `fetch`, `const`, `let`, arrow functions and template strings.
- `api/ttn/uplink/index.php`: webhook receiver for The Things Network.
- `api/parkli/latest/index.php`: JSON API endpoint used by the dashboard.
- `api/admin/reset-history.php`: optional helper endpoint to reset the chart history.

## Deployment on simple PHP webspace

Upload the files like this:

```text
webroot/
  index.html
  kiosk_legacy.html
  assets/
    your-logo-files...

  api/
    data/
    ttn/uplink/index.php
    parkli/latest/index.php
    admin/reset-history.php
```

If you use the repository structure directly, copy:

```text
dashboard/index.html        → webroot/index.html
dashboard/kiosk_legacy.html → webroot/kiosk_legacy.html
dashboard/assets/           → webroot/assets/
api/                        → webroot/api/
```

## TTN webhook settings

Create a custom webhook in The Things Network:

```text
Base URL: https://YOUR-SUBDOMAIN.example.org
Path: /api/ttn/uplink/
Webhook format: JSON
Downlink API key: empty
Basic authentication: disabled
Event types: Uplink message only
```

The final POST target should be:

```text
https://YOUR-SUBDOMAIN.example.org/api/ttn/uplink/
```

## Expected TTN payload fields

This example expects these fields in `uplink_message.decoded_payload`:

```json
{
  "BatterieProzent": 90,
  "Leitwert": 0,
  "PH": 1178,
  "Temperatur_1": 18.87,
  "Temperatur_2": 20.06,
  "Temperatur_3": 19.37
}
```

Mapping:

```text
Temperatur_1 → Sensor 1, 0.5 m
Temperatur_2 → Sensor 2, 1.5 m
Temperatur_3 → Sensor 3, 2.5 m
PH           → pH raw value, divided by 100 in this example
Leitwert     → TDS / conductivity value
```

Adjust `api/ttn/uplink/index.php` if your payload formatter uses different field names or scaling.

## Resetting the chart history

The chart history is stored in:

```text
api/data/history.jsonl
```

To restart the recording manually, delete or empty that file.

You can also use the optional admin endpoint:

```text
https://YOUR-SUBDOMAIN.example.org/api/admin/reset-history.php?secret=CHANGE_ME_TO_A_LONG_RANDOM_SECRET
```

Change the secret in `api/admin/reset-history.php` before deploying.

## iPad kiosk usage

For a clean fullscreen view:

1. Open the dashboard on the iPad.
2. Use Safari → Share → Add to Home Screen.
3. Start the dashboard from the Home Screen icon.
4. Enable Guided Access if available.

For very old iPads, use:

```text
https://YOUR-SUBDOMAIN.example.org/kiosk_legacy.html
```

instead of the root `index.html`.

## Notes

- Do not put TTN API keys into frontend JavaScript.
- Keep secrets out of GitHub.
- For long-term use, consider replacing the flat JSON files with a database.
- pH and TDS values may need sensor-specific calibration.

Tutorial done with ChatGPT, tested by real humans.
