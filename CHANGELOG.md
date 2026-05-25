# Changelog

## 2026-05-19

### Changed

- Added correct depth mapping for the three temperature sensors.
- Mapped board `Temperatur_2` to dashboard Sensor 1 / 0.5 m.
- Mapped board `Temperatur_3` to dashboard Sensor 2 / 1.5 m.
- Mapped board `Temperatur_1` to dashboard Sensor 3 / 2.5 m.
- Added calibrated pH calculation based on raw PH value and surface temperature.
- Added temperature-compensated TDS calculation based on `Leitwert`.
- Added moving average over 6 valid measurements for pH and TDS.
- Added raw debug values to the API output.
- Fixed history writing order for `history.jsonl`.
- Documented legacy iPad support using `kiosk_legacy.html`.
